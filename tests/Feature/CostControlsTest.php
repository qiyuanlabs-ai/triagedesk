<?php

namespace Tests\Feature;

use App\Livewire\ApprovalInbox;
use App\Livewire\LiveMode;
use App\Livewire\TicketDetail;
use App\Livewire\TicketList;
use App\Models\AgentAction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AiBudget;
use App\Services\AiRouting;
use App\Services\LiveAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Responses\Data\TextUsage;
use Livewire\Livewire;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class CostControlsTest extends TestCase
{
    use FakesAnthropic, RefreshDatabase;

    public function test_calls_are_priced_from_the_list_prices(): void
    {
        // Haiku 5.5: uncached input $0.10, cache reads $0.01, cache writes $0.125, output $0.50 per million tokens.
        $this->assertSame(1_000 + 100 + 1_250 + 5_000, AiBudget::costMicros('claude-haiku-5-5', new TextUsage(30_000, 10_000, 10_000, 10_000)));

        // Over 100K input tokens the whole call is billed at the long-context rate.
        $this->assertSame(60_000 + 5_000, AiBudget::costMicros('claude-haiku-5-5', new TextUsage(120_000, 2_000)));

        $this->assertSame(1_000 + 5_000, AiBudget::costMicros('gpt-6-luna', new TextUsage(10_000, 10_000)));
        $this->assertSame(20_000 + 100_000, AiBudget::costMicros('claude-sonnet-5-5', new TextUsage(10_000, 10_000)));

        // A model with no price is counted high on purpose.
        $this->assertSame(100_000 + 500_000, AiBudget::costMicros('some-new-model', new TextUsage(10_000, 10_000)));
    }

    public function test_each_provider_has_its_own_daily_limit_and_the_day_starts_at_midnight_in_shanghai(): void
    {
        $this->travelTo('2026-11-01 15:59:00 UTC'); // 23:59 in Shanghai

        AiBudget::recordCost(3_000_000, 'anthropic');
        AiBudget::recordCost(1_000_000, 'openai');

        $this->assertTrue(AiBudget::exhausted('anthropic'));
        $this->assertFalse(AiBudget::exhausted('openai'));

        $this->travelTo('2026-11-01 16:00:01 UTC'); // 00:00 the next day in Shanghai

        $this->assertSame(0, AiBudget::spentTodayMicros('anthropic'));
        $this->assertFalse(AiBudget::exhausted('anthropic'));
    }

    public function test_triage_prefers_openai_and_the_agent_prefers_claude_and_each_falls_back_to_the_other(): void
    {
        config(['ai.providers.openai.key' => 'test-openai-key']);

        $this->assertSame(['openai' => null, 'anthropic' => null], AiRouting::chain('triage'));
        $this->assertSame(['anthropic' => null, 'openai' => null], AiRouting::chain('support'));

        AiBudget::recordCost(3_000_000, 'anthropic');
        $this->assertSame(['openai' => null], AiRouting::chain('support'));
        $this->assertTrue(AiRouting::available());

        AiBudget::recordCost(6_000_000, 'openai');
        $this->assertSame([], AiRouting::chain('support'));
        $this->assertFalse(AiRouting::available());
    }

    public function test_a_provider_without_a_key_or_with_a_zero_limit_is_skipped(): void
    {
        $this->assertSame(['anthropic' => null], AiRouting::chain('support'));

        config(['ai.providers.openai.key' => 'test-openai-key', 'triagedesk.providers.openai.daily_limit_usd' => 0]);
        $this->assertSame(['anthropic' => null], AiRouting::chain('triage'));

        config(['triagedesk.providers.anthropic.model' => 'claude-sonnet-5-5']);
        $this->assertSame(['anthropic' => 'claude-sonnet-5-5'], AiRouting::chain('support'));
    }

    public function test_the_day_has_a_cap_on_live_runs(): void
    {
        config(['triagedesk.demo.live_runs_per_day' => 1]);
        $this->fakeAnthropic([[$this->text('One')], [$this->text('Two')]]);
        $ticket = Ticket::factory()->create();

        app(AgentRunner::class)->converse($ticket, 'One');

        $this->expectExceptionMessage('today\'s number of live runs');
        app(AgentRunner::class)->converse($ticket, 'Two');
    }

    public function test_in_demo_mode_live_runs_need_the_access_code(): void
    {
        config(['triagedesk.demo.enabled' => true, 'triagedesk.demo.access_code' => null]);
        $this->assertFalse(LiveAccess::unlocked());
        $this->assertSame('Live runs are switched off on this demo.', LiveAccess::unlock('anything'));

        config(['triagedesk.demo.access_code' => 'open-sesame']);
        $this->assertSame('That code is not right.', LiveAccess::unlock('wrong'));
        $this->assertNull(LiveAccess::unlock(' open-sesame '));
        $this->assertTrue(LiveAccess::unlocked());
        $this->assertNull(LiveAccess::denial());

        // A new code signs earlier sessions out of live mode.
        config(['triagedesk.demo.access_code' => 'new-code']);
        $this->assertFalse(LiveAccess::unlocked());
    }

    public function test_guessing_the_access_code_is_throttled(): void
    {
        config(['triagedesk.demo.enabled' => true, 'triagedesk.demo.access_code' => 'open-sesame']);

        foreach (range(1, 5) as $try) {
            LiveAccess::unlock('guess-'.$try);
        }

        $this->assertStringStartsWith('Too many tries.', LiveAccess::unlock('open-sesame'));
        $this->assertFalse(LiveAccess::unlocked());
    }

    public function test_visitors_without_the_code_cannot_start_any_model_call(): void
    {
        config(['triagedesk.demo.enabled' => true, 'triagedesk.demo.access_code' => 'open-sesame']);
        Http::fake();
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = Ticket::factory()->create();
        $action = AgentAction::create([
            'ticket_id' => $ticket->id, 'conversation_id' => 'conv', 'invocation_id' => 'inv', 'tool_call_id' => 'toolu_1',
            'tool' => 'PostReply', 'arguments' => ['body' => 'Hi'], 'required_approval' => true, 'status' => AgentAction::PENDING,
        ]);

        Livewire::actingAs($admin)->test(TicketDetail::class, ['ticket' => $ticket])
            ->set('message', 'Hello')->call('send')
            ->assertSet('chatError', 'Live runs need an access code. Recorded runs are shown instead.')
            ->call('triage')
            ->assertSet('chatError', 'Live runs need an access code. Recorded runs are shown instead.');

        Livewire::actingAs($admin)->test(TicketList::class)
            ->call('simulateTicket')
            ->assertSet('notice', 'Live runs need an access code. Recorded runs are shown instead.');

        Livewire::actingAs($admin)->test(ApprovalInbox::class)
            ->call('approve', $action->id)
            ->assertSet('decisionError', 'Live runs need an access code. Recorded runs are shown instead.');

        $this->assertTrue($action->fresh()->isPending());
        $this->assertSame(1, Ticket::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_approvals_stay_pending_while_the_budget_is_used_up(): void
    {
        config(['triagedesk.providers.anthropic.daily_limit_usd' => 0.01]);
        AiBudget::recordCost(10_000, 'anthropic');
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = Ticket::factory()->create();
        $action = AgentAction::create([
            'ticket_id' => $ticket->id, 'conversation_id' => 'conv', 'invocation_id' => 'inv', 'tool_call_id' => 'toolu_1',
            'tool' => 'PostReply', 'arguments' => ['body' => 'Hi'], 'required_approval' => true, 'status' => AgentAction::PENDING,
        ]);

        Livewire::actingAs($admin)->test(ApprovalInbox::class)
            ->call('approve', $action->id)
            ->assertSet('decisionError', fn ($error) => str_contains($error, 'live AI budget is used up'));

        $this->assertTrue($action->fresh()->isPending());
    }

    public function test_the_banner_switches_between_replay_and_live_mode(): void
    {
        config(['triagedesk.demo.enabled' => true, 'triagedesk.demo.access_code' => 'open-sesame']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/tickets')->assertOk()->assertSee('Replay mode')->assertDontSee('Live mode<');

        Livewire::actingAs($admin)->test(LiveMode::class)
            ->set('code', 'wrong')->call('unlock')->assertSet('error', 'That code is not right.')
            ->set('code', 'open-sesame')->call('unlock')->assertSet('error', null)->assertRedirect();

        $this->assertTrue(LiveAccess::unlocked());
        $this->actingAs($admin)->get('/tickets')->assertOk()->assertSee('Live mode')->assertSee('Anthropic $0.00 of $3.00');
    }
}
