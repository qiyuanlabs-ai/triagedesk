<div>
    <h1 class="text-xl font-semibold tracking-tight">Activity</h1>
    <p class="mt-1 text-sm text-stone-600">Every write the agent proposed or ran, and who decided.</p>

    <div class="mt-4 overflow-x-auto rounded-lg border border-stone-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-stone-200 text-xs uppercase text-stone-500">
                <tr>
                    <th class="px-3 py-2">When</th>
                    <th class="px-3 py-2">Ticket</th>
                    <th class="px-3 py-2">Action</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Decided by</th>
                    <th class="px-3 py-2">Result</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($actions as $action)
                    <tr wire:key="log-{{ $action->id }}">
                        <td class="px-3 py-2 whitespace-nowrap text-stone-500">{{ $action->created_at->diffForHumans() }}</td>
                        <td class="px-3 py-2">@if ($action->ticket)<a href="{{ route('tickets.show', $action->ticket) }}" wire:navigate class="hover:underline">#{{ $action->ticket->id }}</a>@endif</td>
                        <td class="px-3 py-2">{{ $action->tool }}</td>
                        <td class="px-3 py-2"><x-badge :value="$action->status" /></td>
                        <td class="px-3 py-2 text-stone-600">
                            {{ $action->required_approval ? ($action->decider?->name ?? '—') : 'auto (under limit)' }}
                            @if ($action->decision_note)<span class="block text-xs text-stone-500">“{{ $action->decision_note }}”</span>@endif
                        </td>
                        <td class="px-3 py-2 text-xs text-stone-600">{{ $action->result }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-stone-500">No agent actions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $actions->links() }}</div>
</div>
