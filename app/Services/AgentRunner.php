<?php

namespace App\Services;

use App\Ai\Agents\SupportAgent;
use App\Ai\Agents\TriageAgent;
use App\Exceptions\RunAlreadyClaimed;
use App\Models\AgentAction;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;

/**
 * Every call into the agents goes through here, so callers never touch conversation ids or decisions directly.
 *
 * Each entry point checks the day's budget and run cap before any model call; the agents' middleware
 * checks each provider's limit again before every single call.
 */
class AgentRunner
{
    /**
     * Classify a new ticket, then let the support agent propose next steps.
     */
    public function triage(Ticket $ticket): void
    {
        $this->startRun();

        $agent = new TriageAgent($ticket->loadMissing('customer.subscription', 'messages'));

        $result = $agent->prompt($agent->promptText());

        $ticket->update([
            'category' => $result['category'],
            'priority' => $result['priority'],
            'sentiment' => $result['sentiment'],
            'summary' => $result['summary'],
            'status' => $ticket->status === 'new' ? 'open' : $ticket->status,
            'triaged_at' => now(),
        ]);

        $this->ensureBudget();

        $this->prompt($ticket, sprintf(
            '[Automated triage] New ticket classified as %s / %s priority / %s sentiment. '
            .'Investigate it with your read tools and propose the next actions with your write tools.',
            $result['category'], $result['priority'], $result['sentiment'],
        ));
    }

    /**
     * Send a message to the ticket's agent conversation, starting one if needed.
     */
    public function converse(Ticket $ticket, string $message): AgentResponse
    {
        $this->startRun();

        return $this->prompt($ticket, $message);
    }

    /**
     * Resume the paused run once every action it is waiting on has a decision.
     *
     * Callers must authorize the decider first: continue() does not check who owns the conversation.
     */
    public function resume(Ticket $ticket, string $invocationId): ?AgentResponse
    {
        try {
            $this->ensureBudget();
        } catch (RuntimeException $exception) {
            // The budget ran out between the approval and this resume. Never leave the action
            // showing "approved" when it will not run: hand it back to the approvers instead.
            $reopened = $this->reopen($invocationId);

            throw new RuntimeException($reopened > 0
                ? 'Today\'s AI budget ran out before the approved actions could run, so they are back to pending. Approve them again after '.static::resetLabel().'.'
                : $exception->getMessage(), previous: $exception);
        }

        $actions = $this->claim($invocationId);

        if ($actions === null) {
            return null;
        }

        AiBudget::startRun();

        $decisions = Decisions::from($actions->mapWithKeys(fn (AgentAction $action) => [
            $action->tool_call_id => match (true) {
                $action->status === AgentAction::REJECTED => Decision::reject(
                    'A human rejected this action.'.($action->decision_note ? ' Their note: '.$action->decision_note : '')
                ),
                $action->edited_arguments !== null => Decision::edit($action->edited_arguments),
                default => Decision::approve(),
            },
        ])->all());

        return SupportAgent::make(ticket: $ticket)
            ->continue($ticket->agent_conversation_id, as: $ticket)
            ->prompt($decisions);
    }

    /**
     * Mark a fully decided turn as submitted, exactly once even if two workers resume it at the same moment.
     *
     * @return Collection<int, AgentAction>|null
     */
    protected function claim(string $invocationId)
    {
        try {
            return DB::transaction(function () use ($invocationId) {
                $actions = AgentAction::where('invocation_id', $invocationId)->whereNull('submitted_at')->get();

                if ($actions->isEmpty() || $actions->contains->isPending()) {
                    return null;
                }

                $claimed = AgentAction::whereKey($actions->modelKeys())
                    ->whereNull('submitted_at')
                    ->update(['submitted_at' => now()]);

                if ($claimed !== $actions->count()) {
                    throw new RunAlreadyClaimed; // rolls back this partial claim
                }

                return $actions;
            });
        } catch (RunAlreadyClaimed) {
            return null;
        }
    }

    /**
     * Return a run's approved, not yet submitted actions to the approval inbox.
     */
    protected function reopen(string $invocationId): int
    {
        return AgentAction::where('invocation_id', $invocationId)
            ->whereNull('submitted_at')
            ->where('status', AgentAction::APPROVED)
            ->update([
                'status' => AgentAction::PENDING,
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
                'edited_arguments' => null,
                'updated_at' => now(),
            ]);
    }

    protected function prompt(Ticket $ticket, string $message): AgentResponse
    {
        if ($ticket->actions()->pending()->exists()) {
            throw new RuntimeException('Decide the pending actions on this ticket before sending the agent a new message.');
        }

        $response = SupportAgent::make(ticket: $ticket)
            ->continueOrStart($ticket->agent_conversation_id, as: $ticket)
            ->prompt($message);

        if ($ticket->agent_conversation_id !== $response->conversationId) {
            $ticket->update(['agent_conversation_id' => $response->conversationId]);
        }

        return $response;
    }

    protected function startRun(): void
    {
        $this->ensureBudget();

        AiBudget::startRun();
    }

    protected function ensureBudget(): void
    {
        if (! AiRouting::available()) {
            throw new RuntimeException('Today\'s live AI budget is used up, or no provider is set up. Live runs return at '.static::resetLabel().'.');
        }

        if (AiBudget::runsExhausted()) {
            throw new RuntimeException('The demo has reached today\'s number of live runs. They return at '.static::resetLabel().'.');
        }
    }

    public static function resetLabel(): string
    {
        return 'midnight '.config('triagedesk.budget_timezone').' time';
    }
}
