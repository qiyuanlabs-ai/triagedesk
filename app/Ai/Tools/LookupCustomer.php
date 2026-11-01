<?php

namespace App\Ai\Tools;

use App\Models\Payment;
use App\Models\Ticket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Reads the customer on the ticket the agent is working on, and no one else, so text planted in a ticket
 * cannot make the agent fetch other customers' records.
 */
class LookupCustomer implements Tool
{
    public function __construct(protected Ticket $ticket) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Look up the customer on this ticket: plan, subscription status, renewal date and the last 5 payments with their refundable amounts. '
            .'Other customers are not available.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $customer = $this->ticket->customer()
            ->with(['subscription', 'payments' => fn ($query) => $query->latest('paid_at')->limit(5)])
            ->first();

        $asked = array_filter([
            'customer_id' => $request['customer_id'] ?? null,
            'email' => $request['email'] ?? null,
        ]);

        if (($asked['customer_id'] ?? $customer->id) != $customer->id
            || strcasecmp($asked['email'] ?? $customer->email, $customer->email) !== 0) {
            return "Only this ticket's customer (customer id {$customer->id}) can be looked up.";
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
            'customer_id' => $schema->integer()->description('Optional: only this ticket\'s customer is available.'),
        ];
    }
}
