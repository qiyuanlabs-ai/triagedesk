<?php

namespace Tests\Feature;

use App\Jobs\TriageTicket;
use App\Models\AgentAction;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AiBudget;
use App\Services\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAnthropic;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class ProviderMixTest extends TestCase
{
    use FakesAnthropic, FakesOpenAi, RefreshDatabase;

    protected Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openai.key' => 'test-openai-key']);
        $this->ticket = Ticket::factory()->for(Customer::factory()->withPayments())->create(['status' => 'new']);
    }

    protected function paymentId(): int
    {
        return $this->ticket->customer->payments()->latest('id')->value('id');
    }

    public function test_triage_runs_on_openai_and_the_agent_on_claude_and_each_is_billed_to_its_provider(): void
    {
        $openAi = $this->fakeOpenAiTurns([
            [$this->openAiText(json_encode(['category' => 'refund', 'priority' => 'urgent', 'sentiment' => 'angry', 'summary' => 'Duplicate charge.']))],
        ]);
        $anthropic = Http::sequence()->push([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => 'I will propose a refund.']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ]);
        Http::fake(['api.openai.com/*' => $openAi, 'api.anthropic.com/*' => $anthropic]);

        TriageTicket::dispatch($this->ticket);

        $this->ticket->refresh();
        $this->assertNull($this->ticket->agent_error);
        $this->assertSame('refund', $this->ticket->category);
        $this->assertSame('gpt-6-luna', $this->openAiRequests[0]['model']);
        $this->assertSame(1000 * 0.10 + 200 * 0.50, (float) AiBudget::spentTodayMicros('openai'));
        $this->assertSame(20, AiBudget::spentTodayMicros('anthropic'));
    }

    public function test_the_agent_moves_to_openai_once_claude_has_spent_its_limit_including_resuming_a_paused_run(): void
    {
        $this->fakeAnthropic([
            [$this->toolUse('toolu_1', 'IssueRefund', ['payment_id' => $this->paymentId(), 'amount' => 149, 'reason' => 'Duplicate charge.'])],
        ]);
        app(AgentRunner::class)->converse($this->ticket, 'Refund the duplicate.');
        $this->assertSame(1, AgentAction::pending()->count());

        // Claude reaches its limit while the refund waits for approval.
        AiBudget::recordCost(3_000_000, 'anthropic');

        $openAi = $this->fakeOpenAiTurns([[$this->openAiText('Refunded the duplicate $149.')]]);
        Http::fake(['api.openai.com/*' => $openAi, 'api.anthropic.com/*' => fn () => $this->fail('Claude is over its limit.')]);

        app(ApprovalService::class)->approve(AgentAction::sole(), User::factory()->create(['role' => 'admin']));

        $this->assertNull($this->ticket->fresh()->agent_error);
        $this->assertSame(AgentAction::EXECUTED, AgentAction::sole()->status);
        $this->assertSame(14900, $this->ticket->customer->payments()->sum('refunded_cents'));
        $this->assertCount(1, $this->openAiRequests);
        $this->assertGreaterThan(0, AiBudget::spentTodayMicros('openai'));
    }

    public function test_the_agent_fails_over_to_openai_when_claude_is_overloaded(): void
    {
        $openAi = $this->fakeOpenAiTurns([
            [$this->openAiCall('call_1', 'IssueRefund', ['payment_id' => $this->paymentId(), 'amount' => 20, 'reason' => 'Goodwill credit.'])],
            [$this->openAiText('Refunded $20.')],
        ]);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529),
            'api.openai.com/*' => $openAi,
        ]);

        $response = app(AgentRunner::class)->converse($this->ticket, 'Give them a $20 credit.');

        $this->assertSame('Refunded $20.', $response->text);
        $this->assertSame(2000, $this->ticket->customer->payments()->sum('refunded_cents'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com'));
    }
}
