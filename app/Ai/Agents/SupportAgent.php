<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetTicket;
use App\Ai\Tools\IssueRefund;
use App\Ai\Tools\LookupCustomer;
use App\Ai\Tools\PostReply;
use App\Ai\Tools\SearchKnowledgeBase;
use App\Ai\Tools\SearchTickets;
use App\Ai\Tools\UpdateTicket;
use App\Models\TeamSkill;
use App\Models\Ticket;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Middleware\Stop;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Works one ticket: reads freely, and proposes changes that a human approves in the inbox.
 *
 * Each ticket has one conversation, with the ticket itself as the participant.
 */
#[MaxSteps(8)]
#[CacheInstructions]
#[CacheToolDefinitions]
class SupportAgent implements Agent, Conversational, HasMiddleware, HasSkills, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public Ticket $ticket) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<TEXT
        You are TriageDesk, the support agent for Flowly, a subscription project-management app.
        You are working on ticket #{$this->ticket->id} from customer id {$this->ticket->customer_id}.

        How to work:
        - Read before you act: use GetTicket, LookupCustomer and SearchKnowledgeBase to check facts. Never invent policy, prices or dates.
        - Load the matching skill before you write a reply or decide on a refund.
        - To change anything, call a write tool: UpdateTicket, PostReply or IssueRefund. A human approves changes and larger refunds,
          so propose the action you would take; do not ask the user for permission in text first.
        - Always fill the "reason" argument with one sentence the approver will read.
        - If a proposal is rejected, read the approver's note, adjust, and propose again only if it still makes sense.
        - Spam: set status to closed and priority to low; do not reply.
        - Keep your own chat answers short: what you found and what you proposed.
        TEXT;
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            new SearchTickets,
            new GetTicket,
            new LookupCustomer,
            new SearchKnowledgeBase,
            new UpdateTicket,
            new PostReply,
            new IssueRefund,
        ];
    }

    /**
     * Get the skills available to the agent: the files in resources/skills, plus the team's skills from the database.
     */
    public function skills(): iterable
    {
        return [
            resource_path('skills'),
            fn () => TeamSkill::enabledFor('support')->get()->map->toSkill(),
        ];
    }

    /**
     * Stop a run that has used more than its token allowance.
     */
    public function middleware(): array
    {
        $limit = (int) config('triagedesk.demo.run_token_limit');

        return [
            Stop::when(fn (PendingStep $step) => $limit > 0 && $step->usage->totalTokens() > $limit),
        ];
    }

    public function model(): ?string
    {
        return config('triagedesk.support_model');
    }
}
