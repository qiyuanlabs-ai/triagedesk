<?php

namespace App\Services;

use App\Ai\Agents\SupportAgent;
use App\Ai\Agents\TriageAgent;
use App\Models\AgentAction;
use App\Models\Ticket;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;

/**
 * Every call into the agents goes through here, so callers never touch conversation ids or decisions directly.
 */
class AgentRunner
{
    /**
     * Classify a new ticket, then let the support agent propose next steps.
     */
    public function triage(Ticket $ticket): void
    {
        $this->ensureBudget();

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

        $this->converse($ticket, sprintf(
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
        $this->ensureBudget();

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

    /**
     * Resume the paused run once every action it is waiting on has a decision.
     *
     * Callers must authorize the decider first: continue() does not check who owns the conversation.
     */
    public function resume(Ticket $ticket, string $invocationId): ?AgentResponse
    {
        $actions = AgentAction::where('invocation_id', $invocationId)
            ->whereNull('submitted_at')
            ->get();

        if ($actions->isEmpty() || $actions->contains->isPending()) {
            return null;
        }

        $decisions = Decisions::from($actions->mapWithKeys(fn (AgentAction $action) => [
            $action->tool_call_id => match (true) {
                $action->status === AgentAction::REJECTED => Decision::reject(
                    'A human rejected this action.'.($action->decision_note ? ' Their note: '.$action->decision_note : '')
                ),
                $action->edited_arguments !== null => Decision::edit($action->edited_arguments),
                default => Decision::approve(),
            },
        ])->all());

        AgentAction::whereKey($actions->modelKeys())->update(['submitted_at' => now()]);

        return SupportAgent::make(ticket: $ticket)
            ->continue($ticket->agent_conversation_id, as: $ticket)
            ->prompt($decisions);
    }

    protected function ensureBudget(): void
    {
        if (AiBudget::exhausted()) {
            throw new RuntimeException('The demo has used today\'s AI budget. It resets at midnight UTC.');
        }
    }
}
