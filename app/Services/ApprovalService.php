<?php

namespace App\Services;

use App\Jobs\ResumeAgentRun;
use App\Models\AgentAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Records a human's decision on a pending action and resumes the agent when its turn is fully decided.
 */
class ApprovalService
{
    public function approve(AgentAction $action, User $user, ?array $editedArguments = null): void
    {
        $this->decide($action, $user, AgentAction::APPROVED, null, $editedArguments);
    }

    public function reject(AgentAction $action, User $user, ?string $note = null): void
    {
        $this->decide($action, $user, AgentAction::REJECTED, $note);
    }

    protected function decide(AgentAction $action, User $user, string $status, ?string $note, ?array $editedArguments = null): void
    {
        Gate::forUser($user)->authorize('work', $action->ticket);

        DB::transaction(function () use ($action, $user, $status, $note, $editedArguments) {
            $action = AgentAction::whereKey($action->getKey())->lockForUpdate()->firstOrFail();

            if (! $action->isPending()) {
                throw new InvalidArgumentException('This action has already been decided.');
            }

            $action->update([
                'status' => $status,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'edited_arguments' => $editedArguments !== null && $editedArguments != $action->arguments ? $editedArguments : null,
            ]);
        });

        $siblingsPending = AgentAction::where('invocation_id', $action->invocation_id)->pending()->exists();

        if (! $siblingsPending) {
            ResumeAgentRun::dispatch($action->ticket, $action->invocation_id);
        }
    }
}
