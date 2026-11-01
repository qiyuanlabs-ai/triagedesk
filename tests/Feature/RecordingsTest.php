<?php

namespace Tests\Feature;

use App\Models\AgentAction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AiBudget;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class RecordingsTest extends TestCase
{
    use DatabaseMigrations, FakesAnthropic;

    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/triagedesk-recording-'.getmypid().'.json';
        @unlink($this->path);
        config(['triagedesk.demo.recordings_path' => $this->path, 'cache.default' => 'database']);

        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_recorded_runs_come_back_after_the_nightly_reset_and_spend_is_kept(): void
    {
        $ticket = Ticket::where('subject', 'You charged me TWICE')->whereNull('triaged_at')->firstOrFail();
        $payment = $ticket->customer->payments()->latest('id')->firstOrFail();

        $this->fakeAnthropic([
            [$this->text(json_encode(['category' => 'refund', 'priority' => 'urgent', 'sentiment' => 'angry', 'summary' => 'Duplicate charge.']))],
            [$this->text('Proposing a refund.'), $this->toolUse('toolu_1', 'IssueRefund', ['payment_id' => $payment->id, 'amount' => $payment->amount_cents / 100, 'reason' => 'Duplicate charge.'])],
            [$this->text('Refunded the duplicate.')],
        ]);

        $this->artisan('triagedesk:record')->expectsOutputToContain('runs saved')->assertSuccessful();

        $this->assertFileExists($this->path);
        $this->assertTrue($ticket->fresh()->recorded);
        $this->assertSame(AgentAction::EXECUTED, AgentAction::where('ticket_id', $ticket->id)->sole()->status);
        $spent = AiBudget::spentTodayMicros('anthropic');
        $this->assertGreaterThan(0, $spent);

        $this->artisan('triagedesk:reset-demo')->assertSuccessful();

        $ticket = $ticket->fresh();
        $this->assertTrue($ticket->recorded);
        $this->assertSame('refund', $ticket->category);
        $this->assertNotNull($ticket->agent_conversation_id);
        $this->assertSame(AgentAction::EXECUTED, AgentAction::where('ticket_id', $ticket->id)->sole()->status);
        $this->assertSame($payment->amount_cents, $payment->fresh()->refunded_cents);
        $this->assertSame(1, Ticket::where('recorded', true)->count());
        $this->assertSame($spent, AiBudget::spentTodayMicros('anthropic'));

        $this->actingAs(User::firstWhere('role', 'admin'))
            ->get('/tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('Recorded run')
            ->assertSee('Refunded the duplicate.');
    }
}
