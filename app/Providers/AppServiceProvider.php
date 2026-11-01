<?php

namespace App\Providers;

use App\Listeners\RecordAgentActivity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ToolApprovalRequested::class, [RecordAgentActivity::class, 'pending']);
        Event::listen(ToolApprovalResolved::class, [RecordAgentActivity::class, 'resolved']);
    }
}
