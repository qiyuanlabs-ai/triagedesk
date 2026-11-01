<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id', 'assignee_id', 'subject', 'status', 'priority', 'category',
    'sentiment', 'summary', 'triaged_at', 'agent_conversation_id', 'agent_error',
])]
class Ticket extends Model
{
    use HasFactory;

    public const STATUSES = ['new', 'open', 'pending', 'solved', 'closed'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const CATEGORIES = ['refund', 'billing', 'bug', 'feature_request', 'account', 'spam'];

    public const SENTIMENTS = ['positive', 'neutral', 'negative', 'angry'];

    protected function casts(): array
    {
        return ['triaged_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->oldest('id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AgentAction::class);
    }

    /**
     * Get the customer's opening message.
     */
    public function body(): string
    {
        return (string) $this->messages->firstWhere('author_type', 'customer')?->body;
    }
}
