<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.guest')]
#[Title('Sign in')]
class DemoLogin extends Component
{
    /**
     * The demo has no passwords: pick a teammate and go.
     */
    public function loginAs(int $userId)
    {
        Auth::login(User::findOrFail($userId));

        session()->regenerate();

        return $this->redirectRoute('tickets.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.demo-login', ['users' => User::orderBy('id')->get()]);
    }
}
