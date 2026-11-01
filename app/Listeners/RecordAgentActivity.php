<?php

namespace App\Listeners;

use App\Models\AgentAction;
use App\Models\Ticket;
use App\Services\AiBudget;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Responses\Data\ToolResult;

class RecordAgentActivity
{
    /**
     * A paused run becomes inbox rows, so pending actions can be listed across every conversation.
     */
    public function pending(ToolApprovalRequested $event): void
    {
        $ticket = $event->conversationUser instanceof Ticket ? $event->conversationUser : null;

        $event->pendingApprovals->each(fn (PendingApproval $approval) => AgentAction::updateOrCreate(
            ['tool_call_id' => $approval->id],
            [
                'ticket_id' => $ticket?->id ?? ($approval->arguments['ticket_id'] ?? null),
                'conversation_id' => $event->conversationId,
                'invocation_id' => $event->invocationId,
                'tool' => $approval->tool,
                'arguments' => $approval->arguments,
                'approval_reason' => $approval->reason,
                'required_approval' => true,
                'status' => AgentAction::PENDING,
            ],
        ));
    }

    /**
     * Write each decided call's outcome. Rejected calls never reach the tool, and an approved
     * tool can return early (bad payment id, nothing to change) without recording itself.
     */
    public function resolved(ToolApprovalResolved $event): void
    {
        $event->toolResults->each(function (ToolResult $result) {
            $action = AgentAction::firstWhere('tool_call_id', $result->id);

            if (! $action) {
                return;
            }

            $action->result ??= $result->text();

            if ($result->failed) {
                $action->status = AgentAction::FAILED;
            } elseif (! $result->denied && $action->status === AgentAction::APPROVED) {
                $action->status = AgentAction::EXECUTED;
            }

            $action->save();
        });
    }

    public function usage(AgentPrompted $event): void
    {
        AiBudget::record($event->response->usage->totalTokens());
    }
}
