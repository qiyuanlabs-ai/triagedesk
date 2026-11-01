<?php

namespace App\Livewire;

use App\Services\AgentRunner;
use App\Services\AiBudget;
use App\Services\AiRouting;
use App\Services\LiveAccess;
use Livewire\Component;

/**
 * The demo banner: replay mode for visitors, a code box to switch on live runs, and today's spend.
 */
class LiveMode extends Component
{
    public string $code = '';

    public ?string $error = null;

    public function unlock()
    {
        $this->error = LiveAccess::unlock($this->code);
        $this->code = '';

        if ($this->error === null) {
            return $this->redirect(request()->header('Referer') ?: route('tickets.index'), navigate: true);
        }
    }

    public function lock()
    {
        LiveAccess::lock();

        return $this->redirect(request()->header('Referer') ?: route('tickets.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.live-mode', [
            'unlocked' => LiveAccess::unlocked(),
            'denial' => LiveAccess::denial(),
            'spend' => collect(AiRouting::configuredProviders())->map(fn (string $provider) => [
                'provider' => $provider,
                'spent' => AiBudget::spentTodayUsd($provider),
                'limit' => AiBudget::limitUsd($provider),
            ]),
            'resets' => AgentRunner::resetLabel(),
        ]);
    }
}
