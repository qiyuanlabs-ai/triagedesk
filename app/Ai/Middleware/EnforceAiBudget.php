<?php

namespace App\Ai\Middleware;

use App\Services\AiBudget;
use Closure;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

/**
 * Checks the provider's daily limit before every model call and records what the call cost after it.
 *
 * A run can only overshoot a limit by the one call already in flight when the limit was reached.
 */
class EnforceAiBudget
{
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        if (AiBudget::exhausted($step->provider)) {
            return StepResult::stop();
        }

        return $next($step)->then(
            fn (StepResponse $response) => AiBudget::recordUsage($step->provider, $step->model, $response->usage),
        );
    }
}
