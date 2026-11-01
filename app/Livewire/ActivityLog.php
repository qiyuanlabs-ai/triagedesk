<?php

namespace App\Livewire;

use App\Models\AgentAction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Activity')]
class ActivityLog extends Component
{
    use WithPagination;

    public function render()
    {
        return view('livewire.activity-log', [
            'actions' => AgentAction::with('ticket', 'decider')->latest('id')->paginate(25),
        ]);
    }
}
