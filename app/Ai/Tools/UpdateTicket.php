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

class UpdateTicket implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Change a ticket\'s status, priority or assignee. A human approves every change before it runs.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'ticket_id' => ['required', 'integer', 'exists:tickets,id'],
            'status' => ['nullable', 'in:'.implode(',', Ticket::STATUSES)],
            'priority' => ['nullable', 'in:'.implode(',', Ticket::PRIORITIES)],
            'assignee_email' => ['nullable', 'email', 'exists:users,email'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $ticket = Ticket::findOrFail($data['ticket_id']);

        $changes = array_filter([
            'status' => $data['status'] ?? null,
            'priority' => $data['priority'] ?? null,
            'assignee_id' => isset($data['assignee_email']) ? User::where('email', $data['assignee_email'])->value('id') : null,
        ]);

        if ($changes === []) {
            return 'Nothing to change: pass a status, priority or assignee_email.';
        }

        $ticket->update($changes);

        $result = 'Updated ticket #'.$ticket->id.': '.collect($changes)->map(fn ($value, $key) => "{$key}={$value}")->implode(', ').'.';

        ActionRecorder::executed($request, 'UpdateTicket', $ticket->id, $result);

        return $result;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket_id' => $schema->integer()->required(),
            'status' => $schema->string()->enum(Ticket::STATUSES),
            'priority' => $schema->string()->enum(Ticket::PRIORITIES),
            'assignee_email' => $schema->string()->description('Email of the support teammate to assign.'),
            'reason' => $schema->string()->description('One sentence the approver will read.')->required(),
        ];
    }
}
