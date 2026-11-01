<?php

namespace App\Ai\Tools;

use App\Models\Ticket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchTickets implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search support tickets by keyword, customer email or status. Returns up to 10 tickets, newest first.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'customer_email' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:'.implode(',', Ticket::STATUSES)],
        ]);

        $tickets = Ticket::query()
            ->with('customer')
            ->when($data['query'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('subject', 'like', "%{$search}%")
                ->orWhere('summary', 'like', "%{$search}%")))
            ->when($data['customer_email'] ?? null, fn ($query, $email) => $query->whereRelation('customer', 'email', $email))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->limit(10)
            ->get();

        if ($tickets->isEmpty()) {
            return 'No tickets matched.';
        }

        return $tickets->map(fn (Ticket $ticket) => sprintf(
            '#%d [%s/%s] %s (customer: %s)',
            $ticket->id, $ticket->status, $ticket->priority ?? 'untriaged', $ticket->subject, $ticket->customer->email,
        ))->implode("\n");
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Words to look for in the subject or summary.'),
            'customer_email' => $schema->string()->description('Only tickets from this customer.'),
            'status' => $schema->string()->enum(Ticket::STATUSES),
        ];
    }
}
