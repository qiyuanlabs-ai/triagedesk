<?php

namespace App\Ai\Tools;

use App\Models\Payment;
use App\Models\Ticket;
use App\Services\ActionRecorder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class IssueRefund implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        $limit = number_format($this->limit(), 2);

        return "Refund part or all of a customer's payment. Refunds up to \${$limit} run at once; larger refunds wait for a human to approve them.";
    }

    /**
     * Only refunds above the configured limit need a human.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $amount = (float) ($request['amount'] ?? 0);

        if ($amount <= $this->limit()) {
            return false;
        }

        return Approval::required(sprintf(
            'Refund of $%s is over the $%s auto-approve limit.',
            number_format($amount, 2),
            number_format($this->limit(), 2),
        ));
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $data = $request->validate([
            'ticket_id' => ['required', 'integer', 'exists:tickets,id'],
            'payment_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $ticket = Ticket::findOrFail($data['ticket_id']);
        $payment = Payment::where('customer_id', $ticket->customer_id)->find($data['payment_id']);

        if (! $payment) {
            return "Payment {$data['payment_id']} does not belong to this ticket's customer. No refund was made.";
        }

        $cents = (int) round($data['amount'] * 100);

        if ($cents > $payment->refundableCents()) {
            return sprintf('Only $%s of payment %d can still be refunded. No refund was made.',
                number_format($payment->refundableCents() / 100, 2), $payment->id);
        }

        $payment->refunded_cents += $cents;
        $payment->status = $payment->refundableCents() === 0 ? 'refunded' : 'partially_refunded';
        $payment->save();

        $ticket->messages()->create([
            'author_type' => 'system',
            'author_name' => 'TriageDesk',
            'body' => sprintf('Refunded $%s of payment %d. Reason: %s', number_format($cents / 100, 2), $payment->id, $data['reason']),
            'is_internal' => true,
        ]);

        $result = sprintf('Refunded $%s of payment %d (demo: no real money moved).', number_format($cents / 100, 2), $payment->id);

        ActionRecorder::executed($request, 'IssueRefund', $ticket->id, $result);

        return $result;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket_id' => $schema->integer()->required(),
            'payment_id' => $schema->integer()->description('From LookupCustomer.')->required(),
            'amount' => $schema->number()->description('US dollars.')->required(),
            'reason' => $schema->string()->description('One sentence the approver will read.')->required(),
        ];
    }

    protected function limit(): float
    {
        return (float) config('triagedesk.refund_auto_approve_limit');
    }
}
