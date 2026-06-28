<?php

namespace Ramon\MybbMigrator\Gui;

use Ramon\MybbMigrator\Support\Charset;
use Ramon\MybbMigrator\Support\TapatalkEmoji;

/**
 * Renderiza o BBCode do MyBB para HTML aproximando a saída do tema padrão do
 * MyBB — usado na aba "Comparar" para mostrar o post ANTIGO renderizado a partir
 * do banco (a conexão MyBB), sem depender do site no ar.
 *
 * Pontos-chave de fidelidade:
 *  - aplica os mesmos consertos da migração: {@see Charset::fix} (mojibake) e
 *    {@see TapatalkEmoji::convert};
 *  - usa nl2br: o MyBB renderiza CADA quebra de linha como <br> e nunca colapsa
 *    linhas em branco (é exatamente o espaçamento que a migração preserva);
 *  - cobre os MyCodes comuns (b/i/u/s, color/size/font, align, url/email/img,
 *    quote/code/php, list/*, hr, video).
 *
 * É uma APROXIMAÇÃO (não o parser real do MyBB), boa o suficiente para conferir
 * negrito/cor/tamanho/citação/lista/imagem lado a lado com o render do Flarum.
 */
class MybbBbcodeRenderer
{
    public function render(string $bbcode): string
    {
        $text = Charset::fix($bbcode);
        $text = TapatalkEmoji::convert($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // 1) protege [code]/[php] (verbatim, escapado)
        $blocks = [];
        $text = (string) preg_replace_callback('#\[(code|php)\](.*?)\[/\1\]#is', function ($m) use (&$blocks) {
            $key = "\x01B" . count($blocks) . "\x01";
            $blocks[$key] = '<pre class="mybb-code">' . htmlspecialchars($m[2], ENT_QUOTES) . '</pre>';
            return $key;
        }, $text);

        // 2) escapa o resto (BBCode [ ] sobrevive; < > & " ' viram entidades)
        $text = htmlspecialchars($text, ENT_QUOTES);

        // 3) MyCodes inline/bloco (várias passadas para aninhamento)
        $text = $this->applyTags($text);

        // 4) nl2br (MyBB) — depois das tags de bloco para o espaçamento bater
        $text = nl2br($text, false);

        // 5) restaura code/php
        $text = strtr($text, $blocks);

        return $text;
    }

    private function applyTags(string $t): string
    {
        // tags simples
        $simple = [
            '#\[b\](.*?)\[/b\]#is'      => '<strong>$1</strong>',
            '#\[i\](.*?)\[/i\]#is'      => '<em>$1</em>',
            '#\[u\](.*?)\[/u\]#is'      => '<span style="text-decoration:underline">$1</span>',
            '#\[s\](.*?)\[/s\]#is'      => '<span style="text-decoration:line-through">$1</span>',
            '#\[hr\]#i'                 => '<hr>',
        ];

        // tags que podem aninhar → loop até estabilizar
        for ($i = 0; $i < 8; $i++) {
            $before = $t;

            $t = (string) preg_replace($simple === [] ? '//' : array_keys($simple), array_values($simple), $t);

            // color / size / font
            $t = (string) preg_replace_callback('#\[color=([^\]]+)\](.*?)\[/color\]#is', fn ($m) => '<span style="color:' . $this->cssVal($m[1]) . '">' . $m[2] . '</span>', $t);
            $t = (string) preg_replace_callback('#\[size=([^\]]+)\](.*?)\[/size\]#is', fn ($m) => '<span style="font-size:' . $this->sizeVal($m[1]) . '">' . $m[2] . '</span>', $t);
            $t = (string) preg_replace_callback('#\[font=([^\]]+)\](.*?)\[/font\]#is', fn ($m) => '<span style="font-family:' . $this->cssVal($m[1]) . '">' . $m[2] . '</span>', $t);
            $t = (string) preg_replace_callback('#\[align=(left|right|center|justify)\](.*?)\[/align\]#is', fn ($m) => '<div style="text-align:' . strtolower($m[1]) . '">' . $m[2] . '</div>', $t);

            // links / email / imagem
            $t = (string) preg_replace_callback('#\[url=([^\]]+)\](.*?)\[/url\]#is', fn ($m) => '<a href="' . $this->urlVal($m[1]) . '" target="_blank" rel="noopener">' . $m[2] . '</a>', $t);
            $t = (string) preg_replace_callback('#\[url\](.*?)\[/url\]#is', fn ($m) => '<a href="' . $this->urlVal($m[1]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>', $t);
            $t = (string) preg_replace_callback('#\[email=([^\]]+)\](.*?)\[/email\]#is', fn ($m) => '<a href="mailto:' . $this->urlVal($m[1]) . '">' . $m[2] . '</a>', $t);
            $t = (string) preg_replace_callback('#\[email\](.*?)\[/email\]#is', fn ($m) => '<a href="mailto:' . $this->urlVal($m[1]) . '">' . $m[1] . '</a>', $t);
            $t = (string) preg_replace_callback('#\[img(?:=[^\]]*)?\](.*?)\[/img\]#is', fn ($m) => '<img src="' . $this->urlVal($m[1]) . '" style="max-width:100%;height:auto" alt="">', $t);

            // vídeo → link
            $t = (string) preg_replace_callback('#\[video=[^\]]*\](.*?)\[/video\]#is', fn ($m) => '<a href="' . $this->urlVal($m[1]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>', $t);

            // citações — [quote=Autor pid='..' dateline='..'] ... [/quote]
            $t = (string) preg_replace_callback('#\[quote=(.*?)\](.*?)\[/quote\]#is', function ($m) {
                $author = $this->quoteAuthor($m[1]);
                $body = trim($m[2], "\n");
                return '<blockquote class="mybb-quote">'
                    . ($author !== '' ? '<cite>' . $author . '</cite>' : '')
                    . $body . '</blockquote>';
            }, $t);
            $t = (string) preg_replace_callback('#\[quote\](.*?)\[/quote\]#is', fn ($m) => '<blockquote class="mybb-quote">' . trim($m[1], "\n") . '</blockquote>', $t);

            // listas
            $t = (string) preg_replace_callback('#\[list=1\](.*?)\[/list\]#is', fn ($m) => '<ol>' . $this->listItems($m[1]) . '</ol>', $t);
            $t = (string) preg_replace_callback('#\[list(?:=[^\]]*)?\](.*?)\[/list\]#is', fn ($m) => '<ul>' . $this->listItems($m[1]) . '</ul>', $t);

            if ($t === $before) {
                break;
            }
        }

        return $t;
    }

    private function listItems(string $inner): string
    {
        $parts = preg_split('#\[\*\]#', $inner) ?: [];
        $out = '';
        foreach ($parts as $p) {
            $p = trim($p, "\n ");
            if ($p !== '') {
                $out .= '<li>' . $p . '</li>';
            }
        }
        return $out;
    }

    /**
     * Extrai só o nome do autor de um atributo de [quote=...] (o texto já está
     * escapado, então as aspas viraram &#039; / &quot;). Descarta pid/dateline/etc.
     */
    private function quoteAuthor(string $attr): string
    {
        if (preg_match('/^(.*?)\s+\w+=/s', $attr, $m)) {
            $attr = $m[1];
        }
        $attr = trim($attr);
        $attr = (string) preg_replace('/^(?:&#0?39;|&quot;|[\'"])+/', '', $attr);
        $attr = (string) preg_replace('/(?:&#0?39;|&quot;|[\'"])+$/', '', $attr);

        return trim($attr);
    }

    /** Valor de CSS seguro (já passou por htmlspecialchars; remove ; e {} extras). */
    private function cssVal(string $v): string
    {
        return trim(str_replace([';', '{', '}'], '', $v));
    }

    private function sizeVal(string $v): string
    {
        $v = trim($v);
        if (preg_match('/^\d+$/', $v)) {
            $n = (int) $v;
            if ($n >= 1 && $n <= 7) {
                return ['0.7em', '0.8em', '1em', '1.2em', '1.5em', '2em', '2.5em'][$n - 1];
            }
            return $n . 'px';
        }
        return $this->cssVal($v);
    }

    /** URL já escapada por htmlspecialchars; barra esquemas perigosos. */
    private function urlVal(string $v): string
    {
        $v = trim($v);
        if (preg_match('#^\s*javascript:#i', html_entity_decode($v))) {
            return '#';
        }
        return $v;
    }
}
