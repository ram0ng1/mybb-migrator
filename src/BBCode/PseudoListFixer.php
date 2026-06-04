<?php

namespace Ramon\MybbMigrator\BBCode;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Conserta "pseudo-listas": linhas de texto puro do MyBB que começavam com
 * `- `, `* `, `+ ` ou `N. ` / `N) ` e que o litedown (flarum/markdown) do Flarum
 * interpretou como listas markdown (`<LIST>`/`<LI>`), gerando indentação e
 * espaçamento que NÃO existiam no MyBB (que renderiza tudo literal, com nl2br).
 *
 * A correção reescreve apenas os blocos `<LIST>` derivados de markdown para
 * texto literal — preservando o marcador (`1. `, `- `, …) como texto visível —,
 * mantendo o resto do XML do s9e/TextFormatter byte-a-byte intacto (menções,
 * quotes, bold, imagens, etc não são reprocessados).
 *
 * Listas vindas de BBCode `[list]`/`[*]` são reconhecidas (têm o marcador
 * `<s>[list…]</s>` logo após `<LIST>`) e deixadas EXATAMENTE como estão.
 *
 * Fidelidade de espaçamento:
 *  - lista "loose" (itens separados por linha em branco → cada `<LI>` tem `<p>`):
 *    vira um parágrafo `<p>` por item (mantém o espaço entre itens).
 *  - lista "tight" (itens em linhas consecutivas → `<LI>` sem `<p>`):
 *    vira um único `<p>` com os itens separados por `<br/>` (sem espaço extra).
 */
final class PseudoListFixer
{
    /**
     * Pré-filtro barato: só posts `<r>` com um `<LIST…>` seguido direto de `<LI`
     * (assinatura de lista markdown) precisam de conserto.
     */
    public static function isEligible(string $xml): bool
    {
        return str_contains($xml, '<LIST')
            && preg_match('/<LIST\b[^>]*>\s*<LI\b/', $xml) === 1;
    }

    public static function fix(string $xml): string
    {
        if (! self::isEligible($xml)) {
            return $xml;
        }

        $out = '';
        $offset = 0;
        $length = strlen($xml);

        while ($offset < $length) {
            $block = self::findListBlock($xml, $offset);

            if ($block === null) {
                $out .= substr($xml, $offset);
                break;
            }

            $out .= substr($xml, $offset, $block['start'] - $offset);
            $blockStr = substr($xml, $block['start'], $block['end'] - $block['start']);

            // Markdown se o `<LIST…>` é seguido diretamente por `<LI` (sem o
            // marcador `<s>[list…]</s>` que o BBCode injeta).
            if (preg_match('/^<LIST\b[^>]*>\s*<LI\b/', $blockStr) === 1) {
                $out .= self::transformBlock($blockStr);
            } else {
                $out .= $blockStr;
            }

            $offset = $block['end'];
        }

        return $out;
    }

    /**
     * Acha o próximo bloco `<LIST…>…</LIST>` balanceado a partir de $offset.
     *
     * @return array{start:int,end:int}|null
     */
    private static function findListBlock(string $text, int $offset): ?array
    {
        if (! preg_match('/<LIST\b[^>]*>/i', $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            return null;
        }

        $start = (int) $m[0][1];
        $cursor = $start + strlen((string) $m[0][0]);
        $depth = 1;
        $length = strlen($text);

        while ($cursor < $length && $depth > 0) {
            $nextOpen = preg_match('/<LIST\b[^>]*>/i', $text, $om, PREG_OFFSET_CAPTURE, $cursor) ? (int) $om[0][1] : null;
            $nextClose = preg_match('#</LIST>#i', $text, $cm, PREG_OFFSET_CAPTURE, $cursor) ? (int) $cm[0][1] : null;

            if ($nextClose === null) {
                return null; // XML malformado — aborta esse bloco
            }

            if ($nextOpen !== null && $nextOpen < $nextClose) {
                $depth++;
                $cursor = $nextOpen + strlen((string) $om[0][0]);
                continue;
            }

            $depth--;
            $cursor = $nextClose + strlen((string) $cm[0][0]);
        }

        if ($depth !== 0) {
            return null;
        }

        return ['start' => $start, 'end' => $cursor];
    }

    /**
     * Reescreve um bloco `<LIST>` markdown (string XML de raiz única) para a
     * sequência de nós literais equivalente, serializada de volta para string.
     */
    private static function transformBlock(string $blockStr): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML('<?xml version="1.0" encoding="UTF-8"?>' . $blockStr);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $doc->documentElement instanceof DOMElement) {
            return $blockStr; // não conseguiu parsear — deixa como está
        }

        $replacement = self::transformList($doc->documentElement, $doc);

        $serialized = '';
        foreach ($replacement as $node) {
            $serialized .= $doc->saveXML($node);
        }

        return $serialized;
    }

    /**
     * Transforma um elemento `<LIST>` markdown em um array de nós que o
     * substituem (parágrafos para listas loose; um único `<p>` com `<br/>` para
     * listas tight). Listas markdown aninhadas são transformadas recursivamente.
     *
     * @return list<DOMNode>
     */
    private static function transformList(DOMElement $list, DOMDocument $doc): array
    {
        $items = [];
        foreach (iterator_to_array($list->childNodes) as $child) {
            if ($child instanceof DOMElement && strtoupper($child->nodeName) === 'LI') {
                $items[] = $child;
            }
        }

        // loose = algum <LI> contém um <p> direto.
        $loose = false;
        foreach ($items as $li) {
            foreach ($li->childNodes as $c) {
                if ($c instanceof DOMElement && strtolower($c->nodeName) === 'p') {
                    $loose = true;
                    break 2;
                }
            }
        }

        if ($loose) {
            return self::transformLoose($items, $doc);
        }

        return self::transformTight($items, $doc);
    }

    /**
     * @param list<DOMElement> $items
     * @return list<DOMNode>
     */
    private static function transformLoose(array $items, DOMDocument $doc): array
    {
        $result = [];

        foreach ($items as $idx => $li) {
            $marker = self::takeMarker($li);

            // Expande listas markdown aninhadas que sejam filhas diretas do LI.
            self::expandNestedLists($li, $doc);

            // Move os filhos do LI para o nível de cima; injeta o marcador no
            // primeiro <p> (ou cria um se não houver bloco inicial).
            $blocks = iterator_to_array($li->childNodes);
            $markerInjected = ($marker === '');

            foreach ($blocks as $block) {
                if (! $markerInjected && $block instanceof DOMElement && strtolower($block->nodeName) === 'p') {
                    $block->insertBefore($doc->createTextNode($marker), $block->firstChild);
                    $markerInjected = true;
                }
            }

            if (! $markerInjected) {
                // Nenhum <p> no item (ex.: só uma sublista) — cria parágrafo com o marcador.
                $p = $doc->createElement('p');
                $p->appendChild($doc->createTextNode($marker));
                $result[] = $p;
            }

            if ($idx > 0) {
                $result[] = $doc->createTextNode("\n\n");
            }
            foreach ($blocks as $block) {
                $result[] = $block;
            }
        }

        return $result;
    }

    /**
     * @param list<DOMElement> $items
     * @return list<DOMNode>
     */
    private static function transformTight(array $items, DOMDocument $doc): array
    {
        $p = $doc->createElement('p');
        $first = true;

        foreach ($items as $li) {
            $marker = self::takeMarker($li);
            self::expandNestedLists($li, $doc);

            if (! $first) {
                $p->appendChild($doc->createElement('br'));
                $p->appendChild($doc->createTextNode("\n"));
            }
            $first = false;

            if ($marker !== '') {
                $p->appendChild($doc->createTextNode($marker));
            }
            foreach (iterator_to_array($li->childNodes) as $node) {
                $p->appendChild($node);
            }
        }

        return [$p];
    }

    /**
     * Remove o `<s>marcador</s>` inicial do `<LI>` e devolve o texto do marcador
     * (ex.: "1. ", "- ", "* "). Se não houver, devolve string vazia.
     */
    private static function takeMarker(DOMElement $li): string
    {
        $first = $li->firstChild;
        if ($first instanceof DOMElement && strtolower($first->nodeName) === 's') {
            $marker = $first->textContent;
            $li->removeChild($first);
            return $marker;
        }
        return '';
    }

    /**
     * Substitui in-place qualquer `<LIST>` markdown que seja filho direto do LI
     * pela sua sequência transformada (suporte a aninhamento).
     */
    private static function expandNestedLists(DOMElement $li, DOMDocument $doc): void
    {
        foreach (iterator_to_array($li->childNodes) as $child) {
            if ($child instanceof DOMElement
                && strtoupper($child->nodeName) === 'LIST'
                && self::isMarkdownList($child)) {
                $nodes = self::transformList($child, $doc);
                foreach ($nodes as $node) {
                    $li->insertBefore($node, $child);
                }
                $li->removeChild($child);
            }
        }
    }

    private static function isMarkdownList(DOMElement $list): bool
    {
        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $name = strtolower($child->nodeName);
                if ($name === 's' || $name === 'e') {
                    return false; // marcador BBCode [list]/[/list]
                }
                if (strtoupper($child->nodeName) === 'LI') {
                    return true;
                }
            }
        }
        return false;
    }
}
