<?php

namespace Tests\Feature;

use App\Models\AgentAction;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class ApprovalFlowTest extends TestCase
{
    use FakesAnthropic, RefreshDatabase;

    protected Ticket $ticket;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create();
    }

    protected function duplicatePaymentId(): int
    {
        return $this->ticket->customer->payments()->latest('id')->value('id');
    }

    public function test_a_refund_over_the_limit_waits_for_approval(): void
    {
        $this->fakeAnthropic([
            [$this->text('Refunding the duplicate.'), $this->toolUse('toolu_1', 'IssueRefund', [
                'ticket_id' => $this->ticket->id, 'payment_id' => $this->duplicatePaymentId(), 'amount' => 149, 'reason' => 'Duplicate charge.',
            ])],
        ]);

        $response = app(AgentRunner::class)->converse($this->ticket, 'Refund the duplicate charge.');

        $this->assertTrue($response->hasPendingApprovals());
        $this->assertSame(0, $this->ticket->customer->payments()->sum('refunded_cents'));

        $action = AgentAction::sole();
        $this->assertSame(AgentAction::PENDING, $action->status);
        $this->assertSame('IssueRefund', $action->tool);
        $this->assertSame($this->ticket->id, $action->ticket_id);
        $this->assertSame('Refund of $149.00 is over the $50.00 auto-approve limit.', $action->approval_reason);
        $this->assertNotNull($this->ticket->fresh()->agent_conversation_id);
    }

    public function test_approving_resumes_the_run_and_executes_the_refund(): void
    {
        $this->fakeAnthropic([
            [$this->toolUse('toolu_1', 'IssueRefund', [
                'ticket_id' => $this->ticket->id, 'payment_id' => $this->duplicatePaymentId(), 'amount' => 149, 'reason' => 'Duplicate charge.',
            ])],
            [$this->text('Done: the duplicate $149 was refunded.')],
        ]);

        app(AgentRunner::class)->converse($this->ticket, 'Refund the duplicate charge.');

        app(ApprovalService::class)->approve(AgentAction::sole(), $this->admin);

        $action = AgentAction::sole();
        $this->assertSame(AgentAction::EXECUTED, $action->status);
        $this->assertSame($this->admin->id, $action->decided_by);
        $this->assertNotNull($action->submitted_at);
        $this->assertStringContainsString('Refunded $149.00', $action->result);
        $this->assertSame(14900, $this->ticket->customer->payments()->sum('refunded_cents'));
        $this->assertStringContainsString('Refunded $149.00', $this->toolResultSent('toolu_1'));
    }

    public function test_rejecting_with_a_note_skips_the_tool_and_tells_the_model_why(): void
    {
        $this->fakeAnthropic([
            [$this->toolUse('toolu_1', 'IssueRefund', [
                'ticket_id' => $this->ticket->id, 'payment_id' => $this->duplicatePaymentId(), 'amount' => 149, 'reason' => 'Duplicate charge.',
            ])],
            [$this->text('Understood, no refund. I will ask billing to check first.')],
        ]);

        app(AgentRunner::class)->converse($this->ticket, 'Refund the duplicate charge.');

        app(ApprovalService::class)->reject(AgentAction::sole(), $this->admin, 'Bank shows only one charge.');

        $action = AgentAction::sole();
        $this->assertSame(AgentAction::REJECTED, $action->status);
        $this->assertSame('Bank shows only one charge.', $action->decision_note);
        $this->assertSame(0, $this->ticket->customer->payments()->sum('refunded_cents'));
        $this->assertStringContainsString('Bank shows only one charge.', $this->toolResultSent('toolu_1'));
        Http::assertSentCount(2);
    }

    public function test_an_approver_can_edit_the_arguments(): void
    {
        $this->fakeAnthropic([
            [$this->toolUse('toolu_1', 'IssueRefund', [
                'ticket_id' => $this->ticket->id, 'payment_id' => $this->duplicatePaymentId(), 'amount' => 149, 'reason' => 'Duplicate charge.',
            ])],
            [$this->text('Refunded the edited amount.')],
        ]);

        app(AgentRunner::class)->converse($this->ticket, 'Refund the duplicate charge.');

        $action = AgentAction::sole();
        app(ApprovalService::class)->approve($action, $this->admin, [...$action->arguments, 'amount' => 100]);

        $this->assertSame(10000, $this->ticket->customer->payments()->sum('refunded_cents'));
        $this->assertSame(100, AgentAction::sole()->edited_arguments['amount']);
    }

    public function test_a_small_refund_runs_without_approval_and_is_still_logged(): void
    {
        $this->fakeAnthropic([
            [$this->toolUse('toolu_1', 'IssueRefund', [
                'ticket_id' => $this->ticket->id, 'payment_id' => $this->duplicatePaymentId(), 'amount' => 14.90, 'reason' => 'Outage credit, 10% of the month.',
            ])],
            [$this->text('Refunded $14.90 as an outage credit.')],
        ]);

        $response = app(AgentRunner::class)->converse($this->ticket, 'Give them the outage credit.');

        $this->assertFalse($response->hasPendingApprovals());
        $this->assertSame(1490, $this->ticket->customer->payments()->sum('refunded_cents'));

        $action = AgentAction::sole();
        $this->assertSame(AgentAction::EXECUTED, $action->status);
        $this->assertFalse($action->required_approval);
        $this->assertNull($action->decided_by);
    }

    public function test_the_run_resumes_only_after_every_pending_call_in_the_turn_is_decided(): void
    {
        $this->fakeAnthropic([
            [
                $this->toolUse('toolu_1', 'UpdateTicket', ['ticket_id' => $this->ticket->id, 'priority' => 'urgent', 'reason' => 'Money taken in error.']),
                $this->toolUse('toolu_2', 'PostReply', ['ticket_id' => $this->ticket->id, 'body' => 'Hi, we are refunding the duplicate today.', 'reason' => 'Tell the customer.']),
            ],
            [$this->text('Priority raised and reply sent.')],
        ]);

        app(AgentRunner::class)->converse($this->ticket, 'Handle it.');
        $this->assertSame(2, AgentAction::pending()->count());

        app(ApprovalService::class)->approve(AgentAction::firstWhere('tool_call_id', 'toolu_1'), $this->admin);

        Http::assertSentCount(1);
        $this->assertNull($this->ticket->fresh()->priority);

        app(ApprovalService::class)->approve(AgentAction::firstWhere('tool_call_id', 'toolu_2'), $this->admin);

        Http::assertSentCount(2);
        $this->assertSame('urgent', $this->ticket->fresh()->priority);
        $this->assertSame('pending', $this->ticket->fresh()->status);
        $this->assertTrue($this->ticket->messages()->where('author_type', 'agent')->exists());
        $this->assertSame(2, AgentAction::where('status', AgentAction::EXECUTED)->count());
    }

    public function test_new_chat_messages_wait_until_pending_actions_are_decided(): void
    {
        $this->fakeAnthropic([
            [$this->toolUse('toolu_1', 'PostReply', ['ticket_id' => $this->ticket->id, 'body' => 'Hello', 'reason' => 'Reply.'])],
        ]);

        app(AgentRunner::class)->converse($this->ticket, 'Reply to them.');

        $this->expectExceptionMessage('Decide the pending actions');

        app(AgentRunner::class)->converse($this->ticket->fresh(), 'Actually, wait.');
    }
}
