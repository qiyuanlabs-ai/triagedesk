<?php

namespace Tests\Feature;

use App\Jobs\TriageTicket;
use App\Livewire\DemoLogin;
use App\Livewire\TicketDetail;
use App\Livewire\TicketList;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guests_are_sent_to_the_teammate_picker(): void
    {
        $this->get('/tickets')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Alex Rivera')->assertSee('Sam Chen');
    }

    public function test_picking_a_teammate_signs_in(): void
    {
        $sam = User::firstWhere('email', 'sam@flowly.test');

        Livewire::test(DemoLogin::class)->call('loginAs', $sam->id)->assertRedirect(route('tickets.index'));

        $this->assertAuthenticatedAs($sam);
    }

    public function test_every_page_renders_with_seed_data(): void
    {
        $this->actingAs(User::firstWhere('role', 'admin'));

        $this->get('/tickets')->assertOk()->assertSee('Simulate a new ticket');
        $this->get('/tickets/'.Ticket::first()->id)->assertOk()->assertSee(Ticket::first()->subject);
        $this->get('/inbox')->assertOk()->assertSee('Nothing waiting');
        $this->get('/activity')->assertOk();
        $this->get('/skills')->assertOk()->assertSee('refund-policy')->assertSee('escalation');
    }

    public function test_simulating_a_ticket_queues_triage(): void
    {
        Queue::fake();

        Livewire::actingAs(User::first())->test(TicketList::class)->call('simulateTicket');

        $this->assertSame(61, Ticket::count());
        Queue::assertPushed(TriageTicket::class, fn ($job) => $job->ticket->is(Ticket::latest('id')->first()));
    }

    public function test_ticket_list_filters_by_category(): void
    {
        Livewire::actingAs(User::first())
            ->test(TicketList::class)
            ->set('category', 'spam')
            ->assertSee('Boost your SEO ranking')
            ->assertDontSee('Gantt view please');
    }

    public function test_teammates_cannot_chat_about_someone_elses_ticket(): void
    {
        $ticket = Ticket::whereNotNull('assignee_id')->first();
        $other = User::where('role', 'agent')->whereKeyNot($ticket->assignee_id)->first();

        Livewire::actingAs($other)
            ->test(TicketDetail::class, ['ticket' => $ticket])
            ->set('message', 'Refund them')
            ->call('send')
            ->assertSet('chatError', 'This ticket is assigned to someone else.');
    }
}
