<?php

namespace App\Ai\Agents;

use App\Models\Ticket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Classifies a new ticket. Its output is written straight onto the ticket, so it needs no approval.
 */
#[UseCheapestModel]
#[MaxTokens(1024)]
class TriageAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public Ticket $ticket) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You triage incoming support tickets for Flowly, a subscription project-management app.
        Classify the ticket from the customer's own words only. Do not guess facts that are not in the ticket.

        Categories: refund (wants money back), billing (charges, invoices, plan changes), bug (something broken),
        feature_request, account (login, access, data), spam (unsolicited or irrelevant).
        Priority: urgent = customer blocked or money taken in error; high = angry or business impact;
        normal = everything else that needs a reply; low = spam or nice-to-have requests.
        The summary is one plain sentence a teammate can read in two seconds.
        TEXT;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->enum(Ticket::CATEGORIES)->required(),
            'priority' => $schema->string()->enum(Ticket::PRIORITIES)->required(),
            'sentiment' => $schema->string()->enum(Ticket::SENTIMENTS)->required(),
            'summary' => $schema->string()->description('One sentence, under 160 characters.')->required(),
        ];
    }

    /**
     * Build the prompt for the agent's ticket.
     */
    public function promptText(): string
    {
        $plan = $this->ticket->customer->subscription?->plan ?? 'none';

        return "Subject: {$this->ticket->subject}\nCustomer plan: {$plan}\n\n{$this->ticket->body()}";
    }

    public function model(): ?string
    {
        return config('triagedesk.triage_model');
    }
}
