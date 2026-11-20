<?php

namespace Ramon\MybbMigrator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ramon\MybbMigrator\Support\ReviewMath;

/**
 * As regras que o mybb:reviews reproduz da ernestdefoe/community-reviews.
 */
class ReviewMathTest extends TestCase
{
    /** Média simples quando todos os pesos são 1 (o padrão = MyBB). */
    public function test_rating_is_plain_average_with_unit_weights(): void
    {
        $this->assertSame(4.67, ReviewMath::rating([10 => 5, 11 => 4, 12 => 5], [10 => 1, 11 => 1, 12 => 1]));
    }

    /** Review com critério faltando: média só do que tem nota. */
    public function test_rating_with_missing_criterion(): void
    {
        $this->assertSame(3.5, ReviewMath::rating([10 => 3, 12 => 4], [10 => 1, 11 => 1, 12 => 1]));
    }

    /** Peso ponderado e peso 0 tratado como 1, como no ReviewWriter. */
    public function test_rating_weighted_and_minimum_weight(): void
    {
        $this->assertSame(4.33, ReviewMath::rating([1 => 5, 2 => 3], [1 => 2, 2 => 1]));
        $this->assertSame(4.0, ReviewMath::rating([1 => 5, 2 => 3], [1 => 0]));
    }

    public function test_rating_without_scores_is_zero(): void
    {
        $this->assertSame(0.0, ReviewMath::rating([], []));
    }

    public function test_score_bounds(): void
    {
        $this->assertNull(ReviewMath::score(0));
        $this->assertNull(ReviewMath::score('abc'));
        $this->assertSame(1, ReviewMath::score('1'));
        $this->assertSame(5, ReviewMath::score(9));
    }

    public function test_unique_slug_appends_counter(): void
    {
        $slug = fn (string $n): string => trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $n)), '-');
        $this->assertSame('razors', ReviewMath::uniqueSlug('Razors', [], $slug));
        $this->assertSame('razors-2', ReviewMath::uniqueSlug('Razors!', ['razors' => true], $slug));
        $this->assertSame('razors-3', ReviewMath::uniqueSlug('Razors', ['razors' => true, 'razors-2' => true], $slug));
        $this->assertSame('category', ReviewMath::uniqueSlug('☀️', [], $slug));
    }

    public function test_photo_url_resolution(): void
    {
        $this->assertSame('https://i.imgur.com/abcde.jpg', ReviewMath::photoUrl(' https://i.imgur.com/abcde.jpg '));
        $this->assertSame('https://i.imgur.com/x.png', ReviewMath::photoUrl('//i.imgur.com/x.png'));
        $this->assertNull(ReviewMath::photoUrl('uploads/reviews/a.jpg'));
        $this->assertSame('https://forum.test/uploads/reviews/a.jpg', ReviewMath::photoUrl('/uploads/reviews/a.jpg', 'https://forum.test/'));
        $this->assertNull(ReviewMath::photoUrl(''));
    }
}
