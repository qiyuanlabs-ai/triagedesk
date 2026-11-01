<div wire:poll.10s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold tracking-tight">Tickets</h1>
        <button wire:click="simulateTicket" class="rounded bg-stone-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-stone-700">Simulate a new ticket</button>
    </div>

    @if ($notice)
        <p class="mt-3 rounded bg-sky-50 px-3 py-2 text-sm text-sky-900">{{ $notice }}</p>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search subject or customer" class="min-w-0 flex-1 rounded border border-stone-300 bg-white px-3 py-1.5 text-sm">
        <select wire:model.live="status" class="rounded border border-stone-300 bg-white px-2 py-1.5 text-sm">
            <option value="">All statuses</option>
            @foreach (\App\Models\Ticket::STATUSES as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </select>
        <select wire:model.live="category" class="rounded border border-stone-300 bg-white px-2 py-1.5 text-sm">
            <option value="">All categories</option>
            @foreach (\App\Models\Ticket::CATEGORIES as $option)
                <option value="{{ $option }}">{{ str_replace('_', ' ', $option) }}</option>
            @endforeach
        </select>
    </div>

    <div class="mt-4 overflow-x-auto rounded-lg border border-stone-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-stone-200 text-xs uppercase text-stone-500">
                <tr>
                    <th class="px-3 py-2">#</th>
                    <th class="px-3 py-2">Subject</th>
                    <th class="px-3 py-2">Customer</th>
                    <th class="px-3 py-2">Triage</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Assignee</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @foreach ($tickets as $ticket)
                    <tr wire:key="ticket-{{ $ticket->id }}" class="hover:bg-stone-50">
                        <td class="px-3 py-2 text-stone-500">{{ $ticket->id }}</td>
                        <td class="px-3 py-2">
                            <a href="{{ route('tickets.show', $ticket) }}" wire:navigate class="font-medium hover:underline">{{ $ticket->subject }}</a>
                            @if ($ticket->pending_count)
                                <span class="ml-1 rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white">{{ $ticket->pending_count }} to approve</span>
                            @endif
                            @if ($ticket->summary)
                                <p class="text-xs text-stone-500">{{ $ticket->summary }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-2">{{ $ticket->customer->name }}</td>
                        <td class="px-3 py-2">
                            @if ($ticket->triaged_at)
                                <div class="flex flex-wrap gap-1">
                                    <x-badge :value="$ticket->category" />
                                    <x-badge :value="$ticket->priority" />
                                    <x-badge :value="$ticket->sentiment" />
                                </div>
                            @else
                                <button wire:click="triage({{ $ticket->id }})" class="text-xs text-sky-700 underline">Run triage</button>
                            @endif
                        </td>
                        <td class="px-3 py-2"><x-badge :value="$ticket->status" /></td>
                        <td class="px-3 py-2 text-stone-600">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $tickets->links() }}</div>
</div>
