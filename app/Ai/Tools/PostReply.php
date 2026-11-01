<?php

namespace App\Ai\Tools;

use App\Models\Ticket;
use App\Services\ActionRecorder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PostReply implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Send a reply to the customer on a ticket. A human approves (and may edit) every reply before it is sent.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'ticket_id' => ['required', 'integer', 'exists:tickets,id'],
            'body' => ['required', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $ticket = Ticket::findOrFail($data['ticket_id']);

        $ticket->messages()->create([
            'author_type' => 'agent',
            'author_name' => 'Support team',
            'body' => $data['body'],
        ]);

        if (in_array($ticket->status, ['new', 'open'], true)) {
            $ticket->update(['status' => 'pending']);
        }

        $result = "Reply posted on ticket #{$ticket->id}; status is now {$ticket->status}.";

        ActionRecorder::executed($request, 'PostReply', $ticket->id, $result);

        return $result;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket_id' => $schema->integer()->required(),
            'body' => $schema->string()->description('The full reply, written to the customer.')->required(),
            'reason' => $schema->string()->description('One sentence the approver will read.'),
        ];
    }
}
