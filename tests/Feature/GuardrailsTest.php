<?php

namespace Tests\Feature;

use App\Ai\Agents\SupportAgent;
use App\Livewire\TicketDetail;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AiBudget;
use App\Services\LiveAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\TextUsage;
use Livewire\Livewire;
use Tests\Concerns\FakesAnthropic;
use Tests\TestCase;

class GuardrailsTest extends TestCase
{
    use FakesAnthropic, RefreshDatabase;

    public function test_agent_runs_stop_once_every_provider_has_spent_its_daily_limit(): void
    {
        config(['triagedesk.providers.anthropic.daily_limit_usd' => 0.01]);
        AiBudget::recordCost(10_000, 'anthropic');

        $this->expectExceptionMessage('live AI budget is used up');

        app(AgentRunner::class)->converse(Ticket::factory()->create(), 'Hello');
    }

    public function test_every_model_call_is_priced_and_counted_against_its_provider(): void
    {
        $this->fakeAnthropic([[$this->text('Hi')]]);

        app(AgentRunner::class)->converse(Ticket::factory()->create(), 'Hello');

        // 100 input tokens at $0.10/MTok plus 20 output tokens at $0.50/MTok, on Claude Haiku 5.5.
        $this->assertSame('claude-haiku-5-5', $this->anthropicRequests[0]['model']);
        $this->assertSame(20, AiBudget::spentTodayMicros('anthropic'));
        $this->assertSame(1, AiBudget::runsToday());
    }

    public function test_a_run_stops_before_the_next_model_call_once_the_limit_is_reached(): void
    {
        config(['triagedesk.providers.anthropic.daily_limit_usd' => 0.000_015]); // 15 micro-dollars: one call costs 20

        $ticket = Ticket::factory()->create();
        $this->fakeAnthropic([
            [$this->toolUse('toolu_1', 'GetTicket', [])],
            [$this->text('Never sent.')],
        ]);

        app(AgentRunner::class)->converse($ticket, 'Look at the ticket.');

        $this->assertCount(1, $this->anthropicRequests);
    }

    public function test_demo_chat_is_rate_limited_per_ip(): void
    {
        config(['triagedesk.demo.enabled' => true, 'triagedesk.demo.access_code' => 'open-sesame', 'triagedesk.demo.chats_per_ip_per_day' => 1]);
        SupportAgent::fake(['First answer', 'Second answer']);
        $ticket = Ticket::factory()->create();
        LiveAccess::unlock('open-sesame');

        $component = Livewire::actingAs(User::factory()->create(['role' => 'admin']))
            ->test(TicketDetail::class, ['ticket' => $ticket]);

        $component->set('message', 'One')->call('send')->assertSet('chatError', null);
        $component->set('message', 'Two')->call('send')->assertSet('chatError', 'You have used today\'s demo messages. Try again tomorrow.');

        SupportAgent::assertPromptedTimes(1);
    }

    public function test_a_run_stops_when_it_passes_its_token_limit(): void
    {
        config(['triagedesk.demo.run_token_limit' => 500]);

        [, $stop] = (new SupportAgent(Ticket::factory()->create()))->middleware();

        $step = fn (int $tokens) => new PendingStep(2, false, 'anthropic', 'claude-test', null, [], [], null, null, usage: new TextUsage($tokens, 0));

        $this->assertInstanceOf(StepResult::class, $stop->handle($step(600), fn () => 'next'));
        $this->assertSame('next', $stop->handle($step(100), fn () => 'next'));
    }
}
