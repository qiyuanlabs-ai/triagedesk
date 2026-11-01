<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Counts tokens spent today so a public demo can stop before the bill grows.
 */
class AiBudget
{
    public static function record(int $tokens): void
    {
        Cache::add(static::key(), 0, now()->endOfDay());
        Cache::increment(static::key(), $tokens);
    }

    public static function spentToday(): int
    {
        return (int) Cache::get(static::key(), 0);
    }

    public static function exhausted(): bool
    {
        $budget = (int) config('triagedesk.demo.daily_token_budget');

        return $budget > 0 && static::spentToday() >= $budget;
    }

    protected static function key(): string
    {
        return 'ai-tokens:'.now()->toDateString();
    }
}
