<?php

use App\Livewire\ActivityLog;
use App\Livewire\ApprovalInbox;
use App\Livewire\DemoLogin;
use App\Livewire\SkillList;
use App\Livewire\TicketDetail;
use App\Livewire\TicketList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tickets');

Route::livewire('/login', DemoLogin::class)->middleware('guest')->name('login');

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::livewire('/tickets', TicketList::class)->name('tickets.index');
    Route::livewire('/tickets/{ticket}', TicketDetail::class)->name('tickets.show');
    Route::livewire('/inbox', ApprovalInbox::class)->name('inbox');
    Route::livewire('/activity', ActivityLog::class)->name('activity');
    Route::livewire('/skills', SkillList::class)->name('skills');
});
