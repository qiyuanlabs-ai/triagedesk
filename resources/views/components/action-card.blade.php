@props(['action', 'edits' => [], 'showTicket' => false])
@php($args = $action->arguments)
<div class="rounded-lg border border-amber-300 bg-amber-50 p-4" wire:key="action-{{ $action->id }}">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="font-medium">
            {{ match ($action->tool) {
                'IssueRefund' => 'Refund $'.number_format((float) ($args['amount'] ?? 0), 2).' of payment '.($args['payment_id'] ?? '?'),
                'PostReply' => 'Reply to the customer',
                'UpdateTicket' => 'Change ticket: '.collect($args)->only(['status', 'priority', 'assignee_email'])->map(fn ($v, $k) => "$k → $v")->implode(', '),
                default => $action->tool,
            } }}
        </p>
        @if ($showTicket && $action->ticket)
            <a href="{{ route('tickets.show', $action->ticket) }}" wire:navigate class="text-sm text-stone-600 underline">#{{ $action->ticket->id }} {{ $action->ticket->subject }}</a>
        @endif
    </div>

    @if ($action->approval_reason)
        <p class="mt-1 text-xs font-medium text-amber-800">Why it needs you: {{ $action->approval_reason }}</p>
    @endif
    @if (! empty($args['reason']))
        <p class="mt-1 text-sm text-stone-700">Agent's reason: {{ $args['reason'] }}</p>
    @endif
    @if ($action->tool === 'PostReply' && ! isset($edits[$action->id]))
        <div class="mt-2 whitespace-pre-line rounded border border-stone-200 bg-white p-3 text-sm">{{ $args['body'] ?? '' }}</div>
    @endif

    @isset($edits[$action->id])
        <label class="mt-3 block text-xs font-medium text-stone-600">Edit the arguments, then approve</label>
        <textarea wire:model="edits.{{ $action->id }}" rows="8" class="mt-1 w-full rounded border border-stone-300 bg-white p-2 font-mono text-xs"></textarea>
    @endisset

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <button wire:click="approve({{ $action->id }})" wire:loading.attr="disabled" class="rounded bg-stone-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-stone-700">
            {{ isset($edits[$action->id]) ? 'Approve with edits' : 'Approve' }}
        </button>
        @isset($edits[$action->id])
            <button wire:click="cancelEdit({{ $action->id }})" class="rounded border border-stone-300 bg-white px-3 py-1.5 text-sm">Cancel edit</button>
        @else
            <button wire:click="startEdit({{ $action->id }})" class="rounded border border-stone-300 bg-white px-3 py-1.5 text-sm">Edit</button>
        @endisset
        <input wire:model="notes.{{ $action->id }}" type="text" placeholder="Rejection note for the agent (optional)" class="min-w-0 flex-1 rounded border border-stone-300 bg-white px-2 py-1.5 text-sm">
        <button wire:click="reject({{ $action->id }})" class="rounded border border-red-300 bg-white px-3 py-1.5 text-sm text-red-700 hover:bg-red-50">Reject</button>
    </div>
</div>
