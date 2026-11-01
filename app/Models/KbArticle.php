<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'body'])]
class KbArticle extends Model
{
    /**
     * Rank articles by how many of the query's words they contain.
     *
     * Plain keyword matching keeps the demo on SQLite; swap in full-text or vector search for real data.
     */
    public static function search(string $query, int $limit = 3): array
    {
        $words = collect(preg_split('/\W+/', strtolower($query)))
            ->filter(fn (string $word) => strlen($word) > 2)
            ->unique()
            ->values();

        if ($words->isEmpty()) {
            return [];
        }

        return static::query()
            ->where(function (Builder $builder) use ($words) {
                foreach ($words as $word) {
                    $builder->orWhere('title', 'like', "%{$word}%")->orWhere('body', 'like', "%{$word}%");
                }
            })
            ->get()
            ->map(fn (KbArticle $article) => [
                'article' => $article,
                'score' => $words->filter(fn (string $word) => str_contains(strtolower($article->title.' '.$article->body), $word))->count(),
            ])
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('article')
            ->all();
    }
}
