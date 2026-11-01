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

        // The tool always acts on the action's own ticket; an edit naming another ticket is refused
        // rather than silently ignored, so the approver sees what will really happen.
        if ($editedArguments !== null && array_key_exists('ticket_id', $editedArguments)
            && (int) $editedArguments['ticket_id'] !== $action->ticket_id) {
            throw new InvalidArgumentException('An edit cannot move this action to another ticket.');
        }

        // A decision resumes the run, which calls the model; keep it pending rather than strand it.
        if (! AiRouting::available() || AiBudget::runsExhausted()) {
            throw new InvalidArgumentException('Today\'s live AI budget is used up, so this action stays pending until '.AgentRunner::resetLabel().'.');
        }

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
