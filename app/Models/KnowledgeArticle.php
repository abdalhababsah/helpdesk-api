<?php

namespace App\Models;

use Database\Factories\KnowledgeArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $title
 * @property string $body
 * @property string $keywords
 * @property string|null $category_id
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class KnowledgeArticle extends Model
{
    /** @use HasFactory<KnowledgeArticleFactory> */
    use Concerns\HasMillisecondTimestamps, HasFactory, HasUlids;

    protected $fillable = ['title', 'body', 'keywords', 'category_id', 'is_active'];

    protected $attributes = ['is_active' => true, 'keywords' => ''];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Natural language match, best first, with a LIKE fallback.
     *
     * MySQL does not index a token shorter than innodb_ft_min_token_size, so a
     * short term against the index returns nothing at all, which reads as a
     * genuine miss. The fallback is slower and correct.
     *
     * InnoDB adds a row to the index when its transaction commits, so a search
     * cannot find rows written by a transaction that is still open. Tests that
     * assert on matches truncate rather than wrap.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $tokens = preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return;
        }

        $minimum = (int) config('tickets.search.min_token_size');
        $indexable = array_filter($tokens, fn (string $token): bool => mb_strlen($token) >= $minimum) === $tokens;

        if (! $indexable) {
            $query->where(function (Builder $outer) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.addcslashes($token, '%_\\').'%';

                    $outer->where(fn (Builder $inner) => $inner
                        ->where('title', 'like', $like)
                        ->orWhere('body', 'like', $like)
                        ->orWhere('keywords', 'like', $like));
                }
            });

            return;
        }

        $expression = implode(' ', array_map(fn (string $token): string => $token.'*', $tokens));

        $query->whereFullText(['title', 'body', 'keywords'], $expression, ['mode' => 'boolean'])
            ->orderByRaw('MATCH(title, body, keywords) AGAINST (? IN BOOLEAN MODE) DESC', [$expression]);
    }
}
