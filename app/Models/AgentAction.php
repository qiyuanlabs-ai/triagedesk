<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One write-tool call by the agent: the approval inbox and the audit log share this table.
 */
#[Fillable([
    'ticket_id', 'conversation_id', 'invocation_id', 'tool_call_id', 'tool', 'arguments',
    'edited_arguments', 'approval_reason', 'status', 'required_approval', 'decided_by',
    'decision_note', 'decided_at', 'submitted_at', 'result',
])]
class AgentAction extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXECUTED = 'executed';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'edited_arguments' => 'array',
            'required_approval' => 'boolean',
            'decided_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Get the arguments the tool ran (or will run) with.
     */
    public function effectiveArguments(): array
    {
        return $this->edited_arguments ?? $this->arguments;
    }
}
