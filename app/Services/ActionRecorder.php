<?php

namespace App\Services;

use App\Models\AgentAction;
use Laravel\Ai\Tools\Request;

/**
 * Writes each executed write-tool call into the audit log.
 */
class ActionRecorder
{
    /**
     * Record that a write tool ran, updating the inbox row when the call went through approval.
     */
    public static function executed(Request $request, string $tool, ?int $ticketId, string $result, bool $failed = false): void
    {
        $id = $request->toolCallId() ?? 'local-'.str()->ulid();

        $action = AgentAction::firstOrNew(['tool_call_id' => $id]);

        $action->fill([
            'ticket_id' => $action->ticket_id ?? $ticketId,
            'tool' => $action->tool ?? $tool,
            'arguments' => $action->arguments ?? $request->all(),
            'status' => $failed ? AgentAction::FAILED : AgentAction::EXECUTED,
            'result' => $result,
        ])->save();
    }
}
