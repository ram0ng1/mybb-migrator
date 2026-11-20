<?php

namespace Ramon\MybbMigrator\Support;

/**
 * Regras puras (sem banco, sem Flarum) que o `mybb:reviews` precisa reproduzir
 * EXATAMENTE como a extensão ernestdefoe/community-reviews faz, isoladas aqui
 * para poderem ser testadas sozinhas:
 *
 *  - rating de uma review = média ponderada das notas por critério, arredondada
 *    em 2 casas, com peso mínimo 1 (Write\ReviewWriter::rating());
 *  - nota por critério válida = inteiro de 1 a 5 (Write\ReviewWriter::scores());
 *  - slug de categoria = Str::slug(nome) ou "category", com sufixo -2, -3... até
 *    ficar único (Api\CategoryResource::slug()); slug de item = Str::slug(nome)
 *    ou "item", SEM unicidade (Write\ItemWriter::name() — a rota usa id-slug);
 *  - URL de foto: absoluta como veio; relativa (caminho local do MyBB) só com
 *    uma URL base configurada.
 */
final class ReviewMath
{
    /**
     * @param array<int, int> $scores  criterion_id => nota (1-5)
     * @param array<int, int> $weights criterion_id => peso (padrão 1)
     */
    public static function rating(array $scores, array $weights): float
    {
        $points = 0.0;
        $total = 0;
        foreach ($scores as $criterion => $score) {
            $w = max(1, (int) ($weights[$criterion] ?? 1));
            $points += $score * $w;
            $total += $w;
        }

        return $total > 0 ? round($points / $total, 2) : 0.0;
    }

    /** Nota de origem → nota aceita pela extensão (1-5), ou null se "sem nota". */
    public static function score(mixed $raw): ?int
    {
        if (! is_numeric($raw)) {
            return null;
        }
        $v = (int) round((float) $raw);
        if ($v < 1) {
            return null; // 0 no MyBB = critério não avaliado
        }

        return min(5, $v);
    }

    /**
     * Slug único entre $taken (slug => true), no mesmo formato da extensão.
     * $slugger recebe o nome e devolve o slug base (Str::slug no comando; nos
     * testes, qualquer função equivalente).
     *
     * @param array<string, true> $taken
     * @param callable(string): string $slugger
     */
    public static function uniqueSlug(string $name, array $taken, callable $slugger, string $fallback = 'category', int $max = 100): string
    {
        $base = mb_substr($slugger($name) ?: $fallback, 0, $max - 4);
        $slug = $base;
        for ($n = 2; isset($taken[$slug]); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    /**
     * URL pronta para `community_review_photos.url`, ou null se não der para
     * resolver (vazia, ou relativa sem base configurada).
     */
    public static function photoUrl(?string $raw, string $baseUrl = ''): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, '//')) {
            return 'https:' . $raw;
        }
        if (preg_match('#^https?://#i', $raw) === 1) {
            return $raw;
        }
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl . '/' . ltrim($raw, '/');
    }
}
