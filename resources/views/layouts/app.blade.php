<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'TriageDesk' }} · TriageDesk</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-900 antialiased">
    @php($pendingCount = \App\Models\AgentAction::pending()->count())
    <header class="border-b border-stone-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <a href="{{ route('tickets.index') }}" wire:navigate class="font-semibold tracking-tight">TriageDesk</a>
            <nav class="flex flex-wrap gap-4 text-sm text-stone-600">
                <a href="{{ route('tickets.index') }}" wire:navigate wire:current="font-semibold text-stone-900">Tickets</a>
                <a href="{{ route('inbox') }}" wire:navigate wire:current="font-semibold text-stone-900">
                    Approval inbox
                    @if ($pendingCount)
                        <span class="ml-1 rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white">{{ $pendingCount }}</span>
                    @endif
                </a>
                <a href="{{ route('activity') }}" wire:navigate wire:current="font-semibold text-stone-900">Activity</a>
                <a href="{{ route('skills') }}" wire:navigate wire:current="font-semibold text-stone-900">Skills</a>
            </nav>
            <div class="ml-auto flex items-center gap-3 text-sm">
                <span class="text-stone-600">{{ auth()->user()->name }} <span class="text-stone-400">({{ auth()->user()->role }})</span></span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="text-stone-500 underline hover:text-stone-900">Switch teammate</button>
                </form>
            </div>
        </div>
    </header>

    @if (config('triagedesk.demo.enabled'))
        <div class="bg-stone-900 px-4 py-2 text-center text-xs text-stone-200">
            Public demo of an open-source Laravel AI agent. Data resets every night; refunds and replies are simulated.
        </div>
    @endif

    <main class="mx-auto max-w-7xl px-4 py-6">
        {{ $slot }}
    </main>
</body>
</html>
