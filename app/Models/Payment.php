<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'amount_cents', 'refunded_cents', 'status', 'description', 'paid_at'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the amount that can still be refunded, in cents.
     */
    public function refundableCents(): int
    {
        return max(0, $this->amount_cents - $this->refunded_cents);
    }
}
