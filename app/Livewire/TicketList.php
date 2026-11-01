<?php

namespace App\Livewire;

use App\Jobs\TriageTicket;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\AiBudget;
use Database\Seeders\TicketSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Tickets')]
class TicketList extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $search = '';

    public ?string $notice = null;

    public function updating(): void
    {
        $this->resetPage();
    }

    /**
     * Create a ticket as if a customer had just emailed, and triage it in the background.
     */
    public function simulateTicket(): void
    {
        if (! $this->allowAgentRun()) {
            return;
        }

        $templates = TicketSeeder::TEMPLATES;
        [, , , $subject, $body] = $templates[array_rand($templates)];
        $customer = Customer::with(['subscription', 'payments'])->inRandomOrder()->first();
        $latest = $customer->payments->sortByDesc('paid_at')->first();

        $ticket = Ticket::create(['customer_id' => $customer->id, 'subject' => $subject]);
        $ticket->messages()->create([
            'author_type' => 'customer',
            'author_name' => $customer->name,
            'body' => strtr($body, [
                '{first}' => strtok($customer->name, ' '),
                '{plan}' => $customer->subscription->plan,
                '{amount}' => '$'.number_format($latest->amount_cents / 100, 2),
            ]),
        ]);

        TriageTicket::dispatch($ticket);

        $this->notice = "Ticket #{$ticket->id} arrived and is being triaged.";
    }

    public function triage(int $id): void
    {
        if ($this->allowAgentRun()) {
            TriageTicket::dispatch(Ticket::findOrFail($id));

            $this->notice = "Triaging ticket #{$id}.";
        }
    }

    protected function allowAgentRun(): bool
    {
        if (AiBudget::exhausted()) {
            $this->notice = 'The demo has used today\'s AI budget. Browsing still works; agent runs resume tomorrow.';

            return false;
        }

        if (config('triagedesk.demo.enabled')) {
            $allowed = RateLimiter::attempt('demo-triage:'.request()->ip(), config('triagedesk.demo.triages_per_ip_per_day'), fn () => true, 86400);

            if (! $allowed) {
                $this->notice = 'You have used today\'s demo triage runs. Try again tomorrow.';

                return false;
            }
        }

        return true;
    }

    public function render()
    {
        $tickets = Ticket::query()
            ->with(['customer', 'assignee'])
            ->withCount(['actions as pending_count' => fn ($query) => $query->pending()])
            ->when($this->status, fn ($query, $status) => $query->where('status', $status))
            ->when($this->category, fn ($query, $category) => $query->where('category', $category))
            ->when($this->search, fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('subject', 'like', "%{$search}%")
                ->orWhereRelation('customer', 'name', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate(15);

        return view('livewire.ticket-list', ['tickets' => $tickets]);
    }
}
