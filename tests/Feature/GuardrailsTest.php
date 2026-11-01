<?php

namespace Tests\Feature;

use App\Ai\Agents\SupportAgent;
use App\Livewire\TicketDetail;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AiBudget;
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

    public function test_agent_runs_stop_once_the_daily_budget_is_spent(): void
    {
        config(['triagedesk.demo.daily_token_budget' => 1000]);
        AiBudget::record(1000);

        $this->expectExceptionMessage('today\'s AI budget');

        app(AgentRunner::class)->converse(Ticket::factory()->create(), 'Hello');
    }

    public function test_every_prompt_counts_toward_the_budget(): void
    {
        $this->fakeAnthropic([[$this->text('Hi')]]);

        app(AgentRunner::class)->converse(Ticket::factory()->create(), 'Hello');

        $this->assertSame(120, AiBudget::spentToday());
    }

    public function test_demo_chat_is_rate_limited_per_ip(): void
    {
        config(['triagedesk.demo.enabled' => true, 'triagedesk.demo.chats_per_ip_per_day' => 1]);
        SupportAgent::fake(['First answer', 'Second answer']);
        $ticket = Ticket::factory()->create();

        $component = Livewire::actingAs(User::factory()->create(['role' => 'admin']))
            ->test(TicketDetail::class, ['ticket' => $ticket]);

        $component->set('message', 'One')->call('send')->assertSet('chatError', null);
        $component->set('message', 'Two')->call('send')->assertSet('chatError', 'You have used today\'s demo messages. Try again tomorrow.');

        SupportAgent::assertPromptedTimes(1);
    }

    public function test_a_run_stops_when_it_passes_its_token_limit(): void
    {
        config(['triagedesk.demo.run_token_limit' => 500]);

        [$stop] = (new SupportAgent(Ticket::factory()->create()))->middleware();

        $step = fn (int $tokens) => new PendingStep(2, false, 'anthropic', 'claude-test', null, [], [], null, null, usage: new TextUsage($tokens, 0));

        $this->assertInstanceOf(StepResult::class, $stop->handle($step(600), fn () => 'next'));
        $this->assertSame('next', $stop->handle($step(100), fn () => 'next'));
    }
}
