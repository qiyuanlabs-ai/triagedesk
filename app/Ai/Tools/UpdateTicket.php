<?php

namespace App\Ai\Tools;

use App\Models\Ticket;
use App\Models\User;
use App\Services\ActionRecorder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Changes the ticket the agent is working on. The ticket is fixed when the agent is built, never taken from the model.
 */
class UpdateTicket implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(protected Ticket $ticket) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Change this ticket\'s status, priority or assignee. A human approves every change before it runs.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Ticket::STATUSES)],
            'priority' => ['nullable', 'in:'.implode(',', Ticket::PRIORITIES)],
            'assignee_email' => ['nullable', 'email', 'exists:users,email'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $changes = array_filter([
            'status' => $data['status'] ?? null,
            'priority' => $data['priority'] ?? null,
            'assignee_id' => isset($data['assignee_email']) ? User::where('email', $data['assignee_email'])->value('id') : null,
        ]);

        if ($changes === []) {
            return 'Nothing to change: pass a status, priority or assignee_email.';
        }

        $this->ticket->update($changes);

        $result = 'Updated ticket #'.$this->ticket->id.': '.collect($changes)->map(fn ($value, $key) => "{$key}={$value}")->implode(', ').'.';

        ActionRecorder::executed($request, 'UpdateTicket', $this->ticket->id, $result);

        return $result;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(Ticket::STATUSES),
            'priority' => $schema->string()->enum(Ticket::PRIORITIES),
            'assignee_email' => $schema->string()->description('Email of the support teammate to assign.'),
            'reason' => $schema->string()->description('One sentence the approver will read.')->required(),
        ];
    }
}
