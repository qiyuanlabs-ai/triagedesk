<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\AgentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ResumeAgentRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Ticket $ticket, public string $invocationId) {}

    /**
     * Resume the paused run. Approved tool results are stored before the model continues,
     * so if the model fails afterwards the same decisions must not be sent again; the
     * teammate sends a normal chat message instead.
     */
    public function handle(AgentRunner $runner): void
    {
        $this->ticket->update(['agent_error' => null]);

        try {
            $runner->resume($this->ticket, $this->invocationId);
        } catch (Throwable $exception) {
            $this->ticket->update(['agent_error' => str($exception->getMessage())->limit(250)]);

            report($exception);
        }
    }
}
