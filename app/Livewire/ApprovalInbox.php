<?php

namespace App\Livewire;

use App\Livewire\Concerns\DecidesActions;
use App\Models\AgentAction;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Approval inbox')]
class ApprovalInbox extends Component
{
    use DecidesActions;

    public function render()
    {
        return view('livewire.approval-inbox', [
            'actions' => AgentAction::pending()->with('ticket.customer', 'ticket.assignee')->oldest('id')->get(),
        ]);
    }
}
