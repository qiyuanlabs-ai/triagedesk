<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Decides whether this visitor may start live model runs.
 *
 * Outside demo mode everyone may. In demo mode only a session that entered DEMO_ACCESS_CODE may;
 * everyone else browses recorded runs. The session keeps a hash of the code, so changing the code
 * signs every earlier session out of live mode.
 */
class LiveAccess
{
    public const SESSION_KEY = 'triagedesk.live_access';

    public static function demo(): bool
    {
        return (bool) config('triagedesk.demo.enabled');
    }

    public static function unlocked(): bool
    {
        if (! static::demo()) {
            return true;
        }

        $code = (string) config('triagedesk.demo.access_code');

        return $code !== '' && hash_equals(static::fingerprint($code), (string) session(static::SESSION_KEY));
    }

    /**
     * Check a code, allowing 5 tries per visitor every 15 minutes. Returns an error, or null on success.
     */
    public static function unlock(string $attempt): ?string
    {
        $code = (string) config('triagedesk.demo.access_code');

        if ($code === '') {
            return 'Live runs are switched off on this demo.';
        }

        $key = 'live-code:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return 'Too many tries. Wait '.ceil(RateLimiter::availableIn($key) / 60).' minutes and try again.';
        }

        if (! hash_equals($code, trim($attempt))) {
            RateLimiter::hit($key, 900);

            return 'That code is not right.';
        }

        RateLimiter::clear($key);
        session()->regenerate();
        session([static::SESSION_KEY => static::fingerprint($code)]);

        return null;
    }

    public static function lock(): void
    {
        session()->forget(static::SESSION_KEY);
    }

    /**
     * Why this visitor cannot start a live run right now, or null when they can.
     */
    public static function denial(): ?string
    {
        if (! static::unlocked()) {
            return 'Live runs need an access code. Recorded runs are shown instead.';
        }

        if (! AiRouting::available()) {
            return 'Today\'s live AI budget is used up. Live runs return at '.AgentRunner::resetLabel().'; recorded runs are still here.';
        }

        if (AiBudget::runsExhausted()) {
            return 'The demo has reached today\'s number of live runs. They return at '.AgentRunner::resetLabel().'.';
        }

        return null;
    }

    protected static function fingerprint(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
