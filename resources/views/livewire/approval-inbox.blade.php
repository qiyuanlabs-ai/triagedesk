<div wire:poll.10s>
    <h1 class="text-xl font-semibold tracking-tight">Approval inbox</h1>
    <p class="mt-1 text-sm text-stone-600">Everything the agent wants to change, across all tickets. Nothing below has happened yet.</p>

    @if ($decisionError)
        <p class="mt-3 rounded bg-red-50 px-3 py-2 text-sm text-red-800">{{ $decisionError }}</p>
    @endif

    <div class="mt-4 space-y-3">
        @forelse ($actions as $action)
            <x-action-card :action="$action" :edits="$edits" show-ticket />
        @empty
            <p class="rounded-lg border border-dashed border-stone-300 p-6 text-center text-sm text-stone-500">Nothing waiting. Simulate a ticket or ask the agent on a ticket to see proposals here.</p>
        @endforelse
    </div>
</div>
