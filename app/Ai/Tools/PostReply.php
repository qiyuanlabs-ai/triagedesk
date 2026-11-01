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

/**
 * Replies on the ticket the agent is working on. The ticket is fixed when the agent is built, never taken from the model.
 */
class PostReply implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(protected Ticket $ticket) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Send a reply to the customer on this ticket. A human approves (and may edit) every reply before it is sent.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->ticket->messages()->create([
            'author_type' => 'agent',
            'author_name' => 'Support team',
            'body' => $data['body'],
        ]);

        if (in_array($this->ticket->status, ['new', 'open'], true)) {
            $this->ticket->update(['status' => 'pending']);
        }

        $result = "Reply posted on ticket #{$this->ticket->id}; status is now {$this->ticket->status}.";

        ActionRecorder::executed($request, 'PostReply', $this->ticket->id, $result);

        return $result;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()->description('The full reply, written to the customer.')->required(),
            'reason' => $schema->string()->description('One sentence the approver will read.'),
        ];
    }
}
