<?php

namespace Tests\Feature;

use App\Ai\Tools\GetTicket;
use App\Ai\Tools\IssueRefund;
use App\Ai\Tools\LookupCustomer;
use App\Ai\Tools\SearchKnowledgeBase;
use App\Ai\Tools\UpdateTicket;
use App\Models\AgentAction;
use App\Models\Customer;
use App\Models\KbArticle;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ToolsTest extends TestCase
{
    use RefreshDatabase;

    public static function refundAmounts(): array
    {
        return [
            'well under the limit' => [10, false],
            'exactly the limit' => [50, false],
            'one cent over' => [50.01, true],
            'a Business month' => [149, true],
        ];
    }

    #[DataProvider('refundAmounts')]
    public function test_refunds_need_approval_only_above_the_limit(float $amount, bool $needsApproval): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        $payment = $ticket->customer->payments()->first();

        $approval = (new IssueRefund($ticket))->shouldRequestApproval(new Request(['payment_id' => $payment->id, 'amount' => $amount]));

        $this->assertSame($needsApproval, $approval !== null);
    }

    public function test_the_refund_limit_comes_from_config(): void
    {
        config(['triagedesk.refund_auto_approve_limit' => 200]);

        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();

        $this->assertNull((new IssueRefund($ticket))->shouldRequestApproval(new Request(['payment_id' => $ticket->customer->payments()->value('id'), 'amount' => 149])));
    }

    public function test_write_tools_always_need_approval(): void
    {
        $this->assertNotNull((new UpdateTicket(Ticket::factory()->create()))->shouldRequestApproval(new Request(['status' => 'closed'])));
    }

    public function test_refunds_cannot_touch_another_customers_payment(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        $otherPayment = Customer::factory()->withPayments()->create()->payments()->first();

        $result = (new IssueRefund($ticket))->handle(new Request([
            'payment_id' => $otherPayment->id, 'amount' => 10, 'reason' => 'Test.',
        ], 'call_1'));

        $this->assertStringContainsString('does not belong', $result);
        $this->assertSame(0, $otherPayment->fresh()->refunded_cents);
    }

    public function test_refunds_cannot_exceed_what_is_left_on_the_payment(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        $payment = $ticket->customer->payments()->first();
        $payment->update(['refunded_cents' => 14000]);
        $this->approvedAction($ticket, 'call_1');

        $result = (new IssueRefund($ticket))->handle(new Request([
            'payment_id' => $payment->id, 'amount' => 20, 'reason' => 'Test.',
        ], 'call_1'));

        $this->assertStringContainsString('Only $9.00', $result);
        $this->assertSame(14000, $payment->fresh()->refunded_cents);
    }

    public function test_a_full_refund_marks_the_payment_refunded_and_logs_it(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        $payment = $ticket->customer->payments()->first();

        $this->approvedAction($ticket, 'call_9');

        (new IssueRefund($ticket))->handle(new Request([
            'payment_id' => $payment->id, 'amount' => 149, 'reason' => 'Duplicate.',
        ], 'call_9'));

        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(AgentAction::EXECUTED, AgentAction::firstWhere('tool_call_id', 'call_9')->status);
        $this->assertTrue($ticket->messages()->where('is_internal', true)->exists());
    }

    public function test_update_ticket_assigns_by_email(): void
    {
        $ticket = Ticket::factory()->create();
        $sam = User::factory()->create(['email' => 'sam@flowly.test']);

        (new UpdateTicket($ticket))->handle(new Request([
            'assignee_email' => 'sam@flowly.test', 'priority' => 'high', 'reason' => 'Escalation.',
        ], 'call_2'));

        $this->assertSame($sam->id, $ticket->fresh()->assignee_id);
        $this->assertSame('high', $ticket->fresh()->priority);
    }

    public function test_a_second_auto_refund_on_another_payment_counts_toward_the_limit(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        [$first, $second] = $ticket->customer->payments()->orderBy('id')->get()->all();

        (new IssueRefund($ticket))->handle(new Request(['payment_id' => $first->id, 'amount' => 30, 'reason' => 'Credit.'], 'call_1'));

        $approval = (new IssueRefund($ticket))->shouldRequestApproval(new Request(['payment_id' => $second->id, 'amount' => 30]));

        $this->assertSame('Refund of $30.00 brings refunds on this ticket and payment to $60.00, over the $50.00 auto-approve limit.', $approval?->reason);
    }

    public function test_a_refund_a_human_approved_runs_even_above_the_limit(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        $payment = $ticket->customer->payments()->first();

        $refused = (new IssueRefund($ticket))->handle(new Request(['payment_id' => $payment->id, 'amount' => 80, 'reason' => 'Test.'], 'call_1'));
        $this->assertStringContainsString('needs a human', $refused);
        $this->assertSame(0, $payment->fresh()->refunded_cents);

        $this->approvedAction($ticket, 'call_2');
        (new IssueRefund($ticket))->handle(new Request(['payment_id' => $payment->id, 'amount' => 80, 'reason' => 'Test.'], 'call_2'));
        $this->assertSame(8000, $payment->fresh()->refunded_cents);
    }

    protected function approvedAction(Ticket $ticket, string $toolCallId): AgentAction
    {
        return AgentAction::create([
            'ticket_id' => $ticket->id, 'conversation_id' => 'conv', 'invocation_id' => 'inv', 'tool_call_id' => $toolCallId,
            'tool' => 'IssueRefund', 'arguments' => [], 'required_approval' => true, 'status' => AgentAction::APPROVED,
        ]);
    }

    public function test_read_tools_return_what_the_model_needs(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        KbArticle::create(['title' => 'Duplicate charges', 'body' => 'We refund the duplicate in full.']);

        $this->assertStringContainsString('Business plan', (new LookupCustomer($ticket))->handle(new Request(['customer_id' => $ticket->customer_id])));
        $this->assertStringContainsString('refundable $149.00', (new LookupCustomer($ticket))->handle(new Request(['email' => $ticket->customer->email])));
        $this->assertStringContainsString('refundable $149.00', (new LookupCustomer($ticket))->handle(new Request([])));
        $this->assertStringContainsString('charged my card twice', (new GetTicket($ticket))->handle(new Request(['ticket_id' => $ticket->id])));
        $this->assertStringContainsString('charged my card twice', (new GetTicket($ticket))->handle(new Request([])));
        $this->assertStringContainsString('Duplicate charges', (new SearchKnowledgeBase)->handle(new Request(['query' => 'charged twice duplicate'])));
        $this->assertStringContainsString('No articles matched', (new SearchKnowledgeBase)->handle(new Request(['query' => 'kubernetes'])));
    }
}
