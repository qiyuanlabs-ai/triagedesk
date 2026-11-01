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
        abort_unless(static::enabled(), 403, 'Sign-in without a password is only available in demo mode.');

        Auth::login(User::findOrFail($userId));

        session()->regenerate();

        return $this->redirectRoute('tickets.index', navigate: true);
    }

    /**
     * Password-free sign-in exists for the public demo and local development only.
     */
    public static function enabled(): bool
    {
        return (bool) config('triagedesk.demo.enabled') || app()->environment('local', 'testing');
    }

    public function render()
    {
        return view('livewire.demo-login', [
            'users' => static::enabled() ? User::orderBy('id')->get() : collect(),
            'enabled' => static::enabled(),
        ]);
    }
}
