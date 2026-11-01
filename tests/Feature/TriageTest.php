<?php

namespace Tests\Feature;

use App\Ai\Agents\SupportAgent;
use App\Ai\Agents\TriageAgent;
use App\Jobs\TriageTicket;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TriageTest extends TestCase
{
    use RefreshDatabase;

    public function test_triage_writes_the_classification_and_asks_the_agent_for_next_steps(): void
    {
        TriageAgent::fake([[
            'category' => 'refund',
            'priority' => 'urgent',
            'sentiment' => 'angry',
            'summary' => 'Duplicate charge; customer wants it refunded.',
        ]]);
        SupportAgent::fake(['I proposed a refund of the duplicate charge.']);

        $ticket = Ticket::factory()->create(['status' => 'new']);

        TriageTicket::dispatch($ticket);

        $ticket->refresh();
        $this->assertSame('refund', $ticket->category);
        $this->assertSame('urgent', $ticket->priority);
        $this->assertSame('angry', $ticket->sentiment);
        $this->assertSame('open', $ticket->status);
        $this->assertNotNull($ticket->triaged_at);
        $this->assertNotNull($ticket->agent_conversation_id);
        $this->assertNull($ticket->agent_error);

        TriageAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'charged my card twice'));
        SupportAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '[Automated triage]'));
    }

    public function test_a_failed_run_is_shown_on_the_ticket_instead_of_crashing_the_queue(): void
    {
        TriageAgent::fake(fn () => throw new \RuntimeException('Provider unavailable'));

        $ticket = Ticket::factory()->create(['status' => 'new']);

        TriageTicket::dispatch($ticket);

        $this->assertSame('Provider unavailable', $ticket->fresh()->agent_error);
        $this->assertNull($ticket->fresh()->triaged_at);
    }
}
