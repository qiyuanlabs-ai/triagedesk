<?php

namespace App\Ai\Tools;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetTicket implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get one ticket with its triage fields and full message thread.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate(['ticket_id' => ['required', 'integer']]);

        $ticket = Ticket::with(['customer', 'assignee', 'messages'])->find($data['ticket_id']);

        if (! $ticket) {
            return "Ticket #{$data['ticket_id']} does not exist.";
        }

        $thread = $ticket->messages->map(fn (TicketMessage $message) => sprintf(
            '- %s%s (%s): %s',
            $message->author_name, $message->is_internal ? ' [internal note]' : '', $message->author_type, $message->body,
        ))->implode("\n");

        return <<<TEXT
        Ticket #{$ticket->id}: {$ticket->subject}
        Customer: {$ticket->customer->name} <{$ticket->customer->email}> (customer id {$ticket->customer_id})
        Status: {$ticket->status}; priority: {$this->orNone($ticket->priority)}; category: {$this->orNone($ticket->category)}; sentiment: {$this->orNone($ticket->sentiment)}
        Assignee: {$this->orNone($ticket->assignee?->email)}
        Summary: {$this->orNone($ticket->summary)}
        Messages:
        {$thread}
        TEXT;
    }

    protected function orNone(?string $value): string
    {
        return $value ?? 'none';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket_id' => $schema->integer()->required(),
        ];
    }
}
