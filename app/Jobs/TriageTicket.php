<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\AgentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TriageTicket implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Ticket $ticket) {}

    public function handle(AgentRunner $runner): void
    {
        $this->ticket->update(['agent_error' => null]);

        try {
            $runner->triage($this->ticket);
        } catch (Throwable $exception) {
            $this->ticket->update(['agent_error' => str($exception->getMessage())->limit(250)]);

            report($exception);
        }
    }
}
