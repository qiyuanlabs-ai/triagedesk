<?php

namespace App\Ai\Tools;

use App\Models\Ticket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Searches the tickets of this ticket's customer only.
 */
class SearchTickets implements Tool
{
    public function __construct(protected Ticket $ticket) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search this customer\'s tickets by keyword or status, to see their history. Returns up to 10 tickets, newest first.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', Ticket::STATUSES)],
        ]);

        $tickets = Ticket::query()
            ->where('customer_id', $this->ticket->customer_id)
            ->when($data['query'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('subject', 'like', "%{$search}%")
                ->orWhere('summary', 'like', "%{$search}%")))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->limit(10)
            ->get();

        if ($tickets->isEmpty()) {
            return 'No tickets matched.';
        }

        return $tickets->map(fn (Ticket $ticket) => sprintf(
            '#%d [%s/%s] %s',
            $ticket->id, $ticket->status, $ticket->priority ?? 'untriaged', $ticket->subject,
        ))->implode("\n");
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Words to look for in the subject or summary.'),
            'status' => $schema->string()->enum(Ticket::STATUSES),
        ];
    }
}
