<?php

namespace App\Ai\Tools;

use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class LookupCustomer implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Look up a customer by id or email: plan, subscription status, renewal date and the last 5 payments with their refundable amounts.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'required_without:email'],
            'email' => ['nullable', 'string', 'required_without:customer_id'],
        ]);

        $customer = Customer::with(['subscription', 'payments' => fn ($query) => $query->latest('paid_at')->limit(5)])
            ->when($data['customer_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($data['email'] ?? null, fn ($query, $email) => $query->where('email', $email))
            ->first();

        if (! $customer) {
            return 'No customer found.';
        }

        $subscription = $customer->subscription
            ? sprintf('%s plan, $%s/month, %s, renews %s',
                $customer->subscription->plan,
                number_format($customer->subscription->mrr_cents / 100, 2),
                $customer->subscription->status,
                $customer->subscription->renews_at?->toDateString() ?? 'never')
            : 'no subscription';

        $payments = $customer->payments->map(fn (Payment $payment) => sprintf(
            '- payment id %d: $%s on %s for "%s" (%s), refundable $%s',
            $payment->id,
            number_format($payment->amount_cents / 100, 2),
            $payment->paid_at->toDateString(),
            $payment->description,
            $payment->status,
            number_format($payment->refundableCents() / 100, 2),
        ))->implode("\n") ?: '- none';

        return <<<TEXT
        Customer {$customer->id}: {$customer->name} <{$customer->email}>, {$customer->company}
        Subscription: {$subscription}
        Recent payments:
        {$payments}
        TEXT;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_id' => $schema->integer(),
            'email' => $schema->string(),
        ];
    }
}
