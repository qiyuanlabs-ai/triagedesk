<?php

namespace App\Ai\Tools;

use App\Models\AgentAction;
use App\Models\Payment;
use App\Models\Ticket;
use App\Services\ActionRecorder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Refunds a payment of the ticket's customer. The ticket is fixed when the agent is built, never taken from the model.
 *
 * Small refunds run at once, but only while the running total stays within the limit: the amount, plus what was
 * already refunded on that payment, plus what earlier calls auto-refunded on this ticket. Splitting a large refund
 * into small ones therefore still reaches a human.
 */
class IssueRefund implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(protected Ticket $ticket) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        $limit = number_format($this->limitCents() / 100, 2);

        return "Refund part or all of one of this ticket's customer's payments. While the refunds on this ticket and payment total \${$limit} or less "
            .'they run at once; beyond that a human approves. Never split a refund into smaller ones: propose the full amount in one call.';
    }

    /**
     * Ask a human once this refund would take the running total over the limit.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $cents = $this->cents($request);
        $before = $this->refundedBeforeCents($this->payment($request));

        if ($before + $cents <= $this->limitCents()) {
            return false;
        }

        $limit = number_format($this->limitCents() / 100, 2);

        return Approval::required($before === 0
            ? sprintf('Refund of $%s is over the $%s auto-approve limit.', number_format($cents / 100, 2), $limit)
            : sprintf('Refund of $%s brings refunds on this ticket and payment to $%s, over the $%s auto-approve limit.',
                number_format($cents / 100, 2), number_format(($before + $cents) / 100, 2), $limit));
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'payment_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $cents = $this->cents($request);
        $approvedByHuman = $request->toolCallId() !== null && AgentAction::where('tool_call_id', $request->toolCallId())
            ->where('status', AgentAction::APPROVED)
            ->exists();

        return DB::transaction(function () use ($request, $data, $cents, $approvedByHuman) {
            $payment = $this->payment($request);

            if (! $payment) {
                return "Payment {$data['payment_id']} does not belong to this ticket's customer. No refund was made.";
            }

            // Several refunds requested in one model turn are all checked before any of them runs,
            // so the running total is checked again here, inside the transaction.
            if (! $approvedByHuman && $this->refundedBeforeCents($payment) + $cents > $this->limitCents()) {
                $result = sprintf('This refund needs a human: with earlier refunds it is over the $%s auto-approve limit. '
                    .'Propose the whole remaining refund as one IssueRefund call so it goes to approval. No refund was made.',
                    number_format($this->limitCents() / 100, 2));

                ActionRecorder::executed($request, 'IssueRefund', $this->ticket->id, $result, failed: true);

                return $result;
            }

            // One conditional update, so two refunds racing each other can never both spend the same balance.
            $updated = Payment::whereKey($payment->id)
                ->whereRaw('amount_cents - refunded_cents >= ?', [$cents])
                ->update(['refunded_cents' => DB::raw('refunded_cents + '.$cents), 'updated_at' => now()]);

            $payment->refresh();

            if ($updated === 0) {
                return sprintf('Only $%s of payment %d can still be refunded. No refund was made.',
                    number_format($payment->refundableCents() / 100, 2), $payment->id);
            }

            $payment->update(['status' => $payment->refundableCents() === 0 ? 'refunded' : 'partially_refunded']);

            $this->ticket->messages()->create([
                'author_type' => 'system',
                'author_name' => 'TriageDesk',
                'body' => sprintf('Refunded $%s of payment %d. Reason: %s', number_format($cents / 100, 2), $payment->id, $data['reason']),
                'is_internal' => true,
            ]);

            $result = sprintf('Refunded $%s of payment %d (demo: no real money moved).', number_format($cents / 100, 2), $payment->id);

            ActionRecorder::executed($request, 'IssueRefund', $this->ticket->id, $result);

            return $result;
        });
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'payment_id' => $schema->integer()->description('From LookupCustomer.')->required(),
            'amount' => $schema->number()->description('US dollars.')->required(),
            'reason' => $schema->string()->description('One sentence the approver will read.')->required(),
        ];
    }

    protected function payment(Request $request): ?Payment
    {
        return Payment::where('customer_id', $this->ticket->customer_id)->find((int) ($request['payment_id'] ?? 0));
    }

    protected function cents(Request $request): int
    {
        return (int) round(((float) ($request['amount'] ?? 0)) * 100);
    }

    /**
     * What counts toward the limit before this refund: everything refunded on the payment, plus refunds
     * on this ticket's other payments that ran without a human.
     */
    protected function refundedBeforeCents(?Payment $payment): int
    {
        $autoRefundedElsewhere = AgentAction::where('ticket_id', $this->ticket->id)
            ->where('tool', 'IssueRefund')
            ->where('status', AgentAction::EXECUTED)
            ->where('required_approval', false)
            ->get()
            ->map->effectiveArguments()
            ->reject(fn (array $arguments) => $payment && (int) ($arguments['payment_id'] ?? 0) === $payment->id)
            ->sum(fn (array $arguments) => (int) round(((float) ($arguments['amount'] ?? 0)) * 100));

        return ($payment?->refunded_cents ?? 0) + $autoRefundedElsewhere;
    }

    protected function limitCents(): int
    {
        return (int) round(config('triagedesk.refund_auto_approve_limit') * 100);
    }
}
