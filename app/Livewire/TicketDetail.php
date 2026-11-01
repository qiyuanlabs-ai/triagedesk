<?php

namespace App\Livewire;

use App\Jobs\TriageTicket;
use App\Livewire\Concerns\DecidesActions;
use App\Models\Ticket;
use App\Services\AgentRunner;
use App\Services\LiveAccess;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Models\ConversationMessage;
use Livewire\Component;
use RuntimeException;
use Throwable;

class TicketDetail extends Component
{
    use DecidesActions;

    public Ticket $ticket;

    public string $message = '';

    public ?string $chatError = null;

    public function mount(Ticket $ticket): void
    {
        $this->ticket = $ticket;
    }

    /**
     * Ask the ticket's agent something. The run is synchronous: the demo favours simple code over streaming.
     */
    public function send(AgentRunner $runner): void
    {
        $this->validate(['message' => ['required', 'string', 'max:2000']]);

        $this->chatError = null;

        if (! auth()->user()->can('work', $this->ticket)) {
            $this->chatError = 'This ticket is assigned to someone else.';

            return;
        }

        if ($denial = LiveAccess::denial()) {
            $this->chatError = $denial;

            return;
        }

        if (config('triagedesk.demo.enabled')
            && ! RateLimiter::attempt('demo-chat:'.request()->ip(), config('triagedesk.demo.chats_per_ip_per_day'), fn () => true, 86400)) {
            $this->chatError = 'You have used today\'s demo messages. Try again tomorrow.';

            return;
        }

        // A run can take several model calls; PHP-FPM's default 30 seconds is too short.
        set_time_limit(180);

        try {
            $runner->converse($this->ticket, $this->message);
            $this->message = '';
        } catch (RuntimeException $exception) {
            $this->chatError = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);
            $this->chatError = 'The agent could not answer: '.str($exception->getMessage())->limit(200);
        }
    }

    public function triage(): void
    {
        $this->chatError = null;

        if ($this->ticket->triaged_at) {
            return;
        }

        if (! auth()->user()->can('work', $this->ticket)) {
            $this->chatError = 'This ticket is assigned to someone else.';

            return;
        }

        if ($denial = LiveAccess::denial()) {
            $this->chatError = $denial;

            return;
        }

        if (config('triagedesk.demo.enabled')
            && ! RateLimiter::attempt('demo-triage:'.request()->ip(), config('triagedesk.demo.triages_per_ip_per_day'), fn () => true, 86400)) {
            $this->chatError = 'You have used today\'s demo triage runs. Try again tomorrow.';

            return;
        }

        TriageTicket::dispatch($this->ticket);
    }

    public function render()
    {
        $this->ticket->refresh()->load(['customer.subscription', 'customer.payments', 'assignee', 'messages', 'actions.decider']);

        $conversation = $this->ticket->agent_conversation_id
            ? ConversationMessage::where('conversation_id', $this->ticket->agent_conversation_id)->orderBy('created_at')->orderBy('id')->get()
            : collect();

        return view('livewire.ticket-detail', [
            'conversation' => $conversation,
            'liveDenial' => LiveAccess::denial(),
            'pending' => $this->ticket->actions->where('status', 'pending')->values(),
            'history' => $this->ticket->actions->where('status', '!=', 'pending')->sortByDesc('id')->values(),
        ])->layout('layouts.app')->title("#{$this->ticket->id} {$this->ticket->subject}");
    }
}
