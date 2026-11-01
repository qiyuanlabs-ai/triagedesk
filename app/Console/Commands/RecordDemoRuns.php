<?php

namespace App\Console\Commands;

use App\Models\AgentAction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentRunner;
use App\Services\AiBudget;
use App\Services\AiRouting;
use App\Services\ApprovalService;
use App\Support\Recordings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the agent for real on a handful of seed tickets, approves what it proposes as the admin,
 * and saves the result so the public demo can show these runs every day without calling a model.
 */
class RecordDemoRuns extends Command
{
    /**
     * One untriaged seed ticket per subject: refunds above and below the limit, billing, account, spam.
     */
    public const SUBJECTS = [
        'You charged me TWICE',
        'Refund for this month?',
        'Outage yesterday cost us a day',
        'Need VAT number on invoice',
        'Locked out after losing phone',
        'Boost your SEO ranking 300%',
    ];

    protected $signature = 'triagedesk:record {--fresh : Reset the demo data first, so the recording starts from the seed}';

    protected $description = 'Record real agent runs on a few seed tickets for the public demo to replay';

    public function handle(AgentRunner $runner, ApprovalService $approvals): int
    {
        if (! AiRouting::available()) {
            $this->error('No AI provider has an API key and budget left today, so nothing can be recorded.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            // Leave the old recording out of the reset, so this run starts from untouched seed tickets.
            $path = Recordings::path();
            config(['triagedesk.demo.recordings_path' => $path.'.skip']);
            $this->call('triagedesk:reset-demo');
            config(['triagedesk.demo.recordings_path' => $path]);
        }

        // Resume approved runs right here instead of on the queue worker.
        config(['queue.default' => 'sync']);

        $admin = User::where('role', 'admin')->orderBy('id')->firstOrFail();
        $recorded = [];

        foreach (self::SUBJECTS as $subject) {
            $ticket = Ticket::where('subject', $subject)->whereNull('triaged_at')->orderBy('id')->first();

            if (! $ticket) {
                $this->warn("No untriaged ticket \"{$subject}\" left. Run with --fresh to start from the seed.");

                continue;
            }

            $this->line("Recording #{$ticket->id} {$subject}");

            try {
                $runner->triage($ticket);

                // Approve what the agent proposes, as the admin, until it stops asking (at most 3 rounds).
                for ($round = 0; $round < 3 && $ticket->actions()->pending()->exists(); $round++) {
                    $ticket->actions()->pending()->get()->each(fn (AgentAction $action) => $approvals->approve($action, $admin));
                }
            } catch (Throwable $exception) {
                $this->error("  Failed: {$exception->getMessage()}");

                continue;
            }

            $ticket->refresh()->forceFill(['recorded' => true])->save();
            $recorded[] = $ticket->id;
            $this->line('  '.$ticket->category.' / '.$ticket->priority.', '.$ticket->actions()->count().' actions');
        }

        if ($recorded === []) {
            $this->error('Nothing was recorded.');

            return self::FAILURE;
        }

        Recordings::save(Recordings::export($recorded));

        $spent = collect(config('triagedesk.providers'))->keys()
            ->map(fn (string $provider) => sprintf('%s $%.4f', $provider, AiBudget::spentTodayUsd($provider)))
            ->implode(', ');

        $this->info(count($recorded).' runs saved to '.Recordings::path().". Spent today so far: {$spent}.");

        return self::SUCCESS;
    }
}
