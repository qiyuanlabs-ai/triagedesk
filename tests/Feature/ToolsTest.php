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
        $approval = (new IssueRefund)->shouldRequestApproval(new Request(['amount' => $amount]));

        $this->assertSame($needsApproval, $approval !== null);
    }

    public function test_the_refund_limit_comes_from_config(): void
    {
        config(['triagedesk.refund_auto_approve_limit' => 200]);

        $this->assertNull((new IssueRefund)->shouldRequestApproval(new Request(['amount' => 149])));
    }

    public function test_write_tools_always_need_approval(): void
    {
        $this->assertNotNull((new UpdateTicket)->shouldRequestApproval(new Request(['ticket_id' => 1, 'status' => 'closed'])));
    }

    public function test_refunds_cannot_touch_another_customers_payment(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        $otherPayment = Customer::factory()->withPayments()->create()->payments()->first();

        $result = (new IssueRefund)->handle(new Request([
            'ticket_id' => $ticket->id, 'payment_id' => $otherPayment->id, 'amount' => 10, 'reason' => 'Test.',
        ], 'call_1'));

        $this->assertStringContainsString('does not belong', $result);
        $this->assertSame(0, $otherPayment->fresh()->refunded_cents);
    }

    public function test_refunds_cannot_exceed_what_is_left_on_the_payment(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        $payment = $ticket->customer->payments()->first();
        $payment->update(['refunded_cents' => 14000]);

        $result = (new IssueRefund)->handle(new Request([
            'ticket_id' => $ticket->id, 'payment_id' => $payment->id, 'amount' => 20, 'reason' => 'Test.',
        ], 'call_1'));

        $this->assertStringContainsString('Only $9.00', $result);
        $this->assertSame(14000, $payment->fresh()->refunded_cents);
    }

    public function test_a_full_refund_marks_the_payment_refunded_and_logs_it(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        $payment = $ticket->customer->payments()->first();

        (new IssueRefund)->handle(new Request([
            'ticket_id' => $ticket->id, 'payment_id' => $payment->id, 'amount' => 149, 'reason' => 'Duplicate.',
        ], 'call_9'));

        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(AgentAction::EXECUTED, AgentAction::firstWhere('tool_call_id', 'call_9')->status);
        $this->assertTrue($ticket->messages()->where('is_internal', true)->exists());
    }

    public function test_update_ticket_assigns_by_email(): void
    {
        $ticket = Ticket::factory()->create();
        $sam = User::factory()->create(['email' => 'sam@flowly.test']);

        (new UpdateTicket)->handle(new Request([
            'ticket_id' => $ticket->id, 'assignee_email' => 'sam@flowly.test', 'priority' => 'high', 'reason' => 'Escalation.',
        ], 'call_2'));

        $this->assertSame($sam->id, $ticket->fresh()->assignee_id);
        $this->assertSame('high', $ticket->fresh()->priority);
    }

    public function test_read_tools_return_what_the_model_needs(): void
    {
        $ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        KbArticle::create(['title' => 'Duplicate charges', 'body' => 'We refund the duplicate in full.']);

        $this->assertStringContainsString('Business plan', (new LookupCustomer)->handle(new Request(['customer_id' => $ticket->customer_id])));
        $this->assertStringContainsString('refundable $149.00', (new LookupCustomer)->handle(new Request(['email' => $ticket->customer->email])));
        $this->assertStringContainsString('charged my card twice', (new GetTicket)->handle(new Request(['ticket_id' => $ticket->id])));
        $this->assertStringContainsString('Duplicate charges', (new SearchKnowledgeBase)->handle(new Request(['query' => 'charged twice duplicate'])));
        $this->assertStringContainsString('No articles matched', (new SearchKnowledgeBase)->handle(new Request(['query' => 'kubernetes'])));
    }
}
