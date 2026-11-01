<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Counts each provider's spend today, in millionths of a US dollar, from the token usage every
 * model call reports and the list prices in config/triagedesk.php. A provider that reaches its
 * daily limit is skipped until midnight in the budget time zone; when every provider has, live
 * runs stop and visitors see recorded runs.
 */
class AiBudget
{
    /**
     * Count one model call and return what it cost, in micro-dollars.
     */
    public static function recordUsage(string $provider, string $model, TextUsage $usage): int
    {
        $micros = static::costMicros($model, $usage);

        static::recordCost($micros, $provider);

        return $micros;
    }

    public static function recordCost(int $micros, string $provider = 'anthropic'): void
    {
        if ($micros <= 0) {
            return;
        }

        $key = static::key('ai-spend:'.$provider);

        Cache::add($key, 0, static::resetsAt()->addHour());
        Cache::increment($key, $micros);
    }

    /**
     * Price a call. Tokens times dollars per million tokens is micro-dollars, so no scaling is needed.
     */
    public static function costMicros(string $model, TextUsage $usage): int
    {
        $prices = static::pricesFor($model);

        if (isset($prices['long_over'], $prices['long']) && $usage->inputTokens > $prices['long_over']) {
            $prices = $prices['long'];
        }

        $input = (float) $prices['input'];

        return (int) ceil(
            $usage->uncachedInputTokens() * $input
            + ($usage->cacheReadInputTokens ?? 0) * (float) ($prices['cache_read'] ?? $input * 0.1)
            + ($usage->cacheWriteInputTokens ?? 0) * (float) ($prices['cache_write'] ?? $input * 1.25)
            + $usage->outputTokens * (float) $prices['output']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function pricesFor(string $model): array
    {
        $prices = config('triagedesk.prices');
        $match = collect(array_keys($prices))
            ->filter(fn (string $prefix) => $prefix !== 'unknown' && str_starts_with($model, $prefix))
            ->sortByDesc(fn (string $prefix) => strlen($prefix))
            ->first();

        return $prices[$match ?? 'unknown'];
    }

    public static function spentTodayMicros(string $provider): int
    {
        return (int) Cache::get(static::key('ai-spend:'.$provider), 0);
    }

    public static function spentTodayUsd(string $provider): float
    {
        return static::spentTodayMicros($provider) / 1_000_000;
    }

    public static function limitUsd(string $provider): float
    {
        return (float) config("triagedesk.providers.{$provider}.daily_limit_usd", 0);
    }

    /**
     * A provider with no limit set, or a limit of 0, is never used.
     */
    public static function exhausted(string $provider): bool
    {
        $limit = static::limitUsd($provider);

        return $limit <= 0 || static::spentTodayMicros($provider) >= (int) round($limit * 1_000_000);
    }

    /**
     * Count a live run against the day's run cap.
     */
    public static function startRun(): void
    {
        $key = static::key('ai-runs');

        Cache::add($key, 0, static::resetsAt()->addHour());
        Cache::increment($key);
    }

    public static function runsToday(): int
    {
        return (int) Cache::get(static::key('ai-runs'), 0);
    }

    public static function runsExhausted(): bool
    {
        $cap = (int) config('triagedesk.demo.live_runs_per_day');

        return $cap > 0 && static::runsToday() >= $cap;
    }

    /**
     * Midnight at the start of the next budget day, in the budget time zone.
     */
    public static function resetsAt(): CarbonImmutable
    {
        return static::now()->addDay()->startOfDay();
    }

    protected static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('triagedesk.budget_timezone'));
    }

    protected static function key(string $name): string
    {
        return $name.':'.static::now()->toDateString();
    }
}
