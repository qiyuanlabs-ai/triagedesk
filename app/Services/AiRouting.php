<?php

namespace App\Services;

/**
 * Picks which providers an agent may use right now, in order of preference.
 */
class AiRouting
{
    /**
     * The providers for the given role ("triage" or "support") that have an API key and budget left,
     * as the provider => model list the SDK takes. A null model means the provider's cheapest.
     *
     * @return array<string, string|null>
     */
    public static function chain(string $role): array
    {
        return collect(config("triagedesk.{$role}_providers", []))
            ->unique()
            ->filter(fn (string $provider) => static::configured($provider) && ! AiBudget::exhausted($provider))
            ->mapWithKeys(fn (string $provider) => [$provider => config("triagedesk.providers.{$provider}.model")])
            ->all();
    }

    /**
     * Whether any provider can take a live run now.
     */
    public static function available(): bool
    {
        return static::chain('support') !== [] && static::chain('triage') !== [];
    }

    /**
     * Providers with an API key, whatever their budget.
     *
     * @return list<string>
     */
    public static function configuredProviders(): array
    {
        return collect(config('triagedesk.providers'))
            ->keys()
            ->filter(fn (string $provider) => static::configured($provider))
            ->values()
            ->all();
    }

    public static function configured(string $provider): bool
    {
        // Bedrock signs with AWS credentials and Ollama runs locally, so neither has an API key.
        $hasCredentials = filled(config("ai.providers.{$provider}.key"))
            || in_array(config("ai.providers.{$provider}.driver"), ['bedrock', 'ollama'], true);

        return $hasCredentials && static::limit($provider) > 0;
    }

    protected static function limit(string $provider): float
    {
        return AiBudget::limitUsd($provider);
    }
}
