<div class="bg-stone-900 px-4 py-2 text-xs text-stone-200">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-center gap-x-4 gap-y-2 text-center">
        <span>Public demo of an open-source Laravel AI agent. Data resets every night; refunds and replies are simulated.</span>

        @if ($unlocked)
            <span class="rounded bg-emerald-700 px-2 py-0.5 font-medium text-white">Live mode</span>
            <span class="text-stone-300">
                Today:
                @foreach ($spend as $row)
                    {{ ucfirst($row['provider']) }} ${{ number_format($row['spent'], 2) }} of ${{ number_format($row['limit'], 2) }}@if (! $loop->last) · @endif
                @endforeach
                (resets at {{ $resets }})
            </span>
            @if ($denial)
                <span class="text-amber-300">{{ $denial }}</span>
            @endif
            <button wire:click="lock" class="underline hover:text-white">Leave live mode</button>
        @else
            <span class="rounded bg-violet-700 px-2 py-0.5 font-medium text-white">Replay mode</span>
            <span class="text-stone-300">You are browsing recorded real runs; nothing here calls a model.</span>
            <form wire:submit="unlock" class="flex items-center gap-1">
                <label for="live-code" class="sr-only">Access code</label>
                <input id="live-code" wire:model="code" type="password" autocomplete="off" placeholder="Access code for live runs" class="w-48 rounded border border-stone-600 bg-stone-800 px-2 py-0.5 text-xs text-white placeholder:text-stone-400">
                <button class="rounded bg-stone-700 px-2 py-0.5 hover:bg-stone-600">Go live</button>
            </form>
            @if ($error)
                <span class="text-amber-300">{{ $error }}</span>
            @endif
        @endif
    </div>
</div>
