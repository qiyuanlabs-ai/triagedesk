<div wire:poll.5s class="grid gap-6 lg:grid-cols-5">
    <section class="lg:col-span-3">
        <a href="{{ route('tickets.index') }}" wire:navigate class="text-sm text-stone-500 hover:underline">← Tickets</a>
        <h1 class="mt-2 text-xl font-semibold tracking-tight">#{{ $ticket->id }} {{ $ticket->subject }}</h1>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <x-badge :value="$ticket->status" />
            <x-badge :value="$ticket->category" />
            <x-badge :value="$ticket->priority" />
            <x-badge :value="$ticket->sentiment" />
            <span class="text-stone-500">Assignee: {{ $ticket->assignee?->name ?? 'Unassigned' }}</span>
            @unless ($ticket->triaged_at)
                <button wire:click="triage" class="text-sky-700 underline">Run triage</button>
            @endunless
        </div>
        @if ($ticket->summary)
            <p class="mt-2 text-sm text-stone-600">AI summary: {{ $ticket->summary }}</p>
        @endif
        @if ($ticket->agent_error)
            <p class="mt-3 rounded bg-red-50 px-3 py-2 text-sm text-red-800">Last agent run failed: {{ $ticket->agent_error }}</p>
        @endif

        <div class="mt-4 rounded-lg border border-stone-200 bg-white p-4 text-sm">
            <p class="font-medium">{{ $ticket->customer->name }} <span class="font-normal text-stone-500">&lt;{{ $ticket->customer->email }}&gt; · {{ $ticket->customer->company }}</span></p>
            @if ($ticket->customer->subscription)
                <p class="mt-1 text-stone-600">
                    {{ $ticket->customer->subscription->plan }} · {{ $ticket->customer->subscription->status }}
                    · ${{ number_format($ticket->customer->subscription->mrr_cents / 100, 2) }}/mo
                </p>
            @endif
            <ul class="mt-2 space-y-0.5 text-xs text-stone-500">
                @foreach ($ticket->customer->payments->sortByDesc('paid_at') as $payment)
                    <li>Payment {{ $payment->id }}: ${{ number_format($payment->amount_cents / 100, 2) }} on {{ $payment->paid_at->toFormattedDateString() }} · {{ $payment->description }} · {{ str_replace('_', ' ', $payment->status) }}@if ($payment->refunded_cents) (refunded ${{ number_format($payment->refunded_cents / 100, 2) }})@endif</li>
                @endforeach
            </ul>
        </div>

        <div class="mt-4 space-y-3">
            @foreach ($ticket->messages as $message)
                <div wire:key="message-{{ $message->id }}" @class([
                    'rounded-lg border p-4 text-sm',
                    'border-stone-200 bg-white' => $message->author_type === 'customer',
                    'border-sky-200 bg-sky-50' => $message->author_type === 'agent',
                    'border-dashed border-stone-300 bg-stone-50 text-stone-600' => $message->is_internal,
                ])>
                    <p class="text-xs text-stone-500">{{ $message->author_name }}{{ $message->is_internal ? ' · internal note' : '' }} · {{ $message->created_at->diffForHumans() }}</p>
                    <p class="mt-1 whitespace-pre-line">{{ $message->body }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <aside class="space-y-4 lg:col-span-2">
        <h2 class="font-semibold">Agent</h2>

        @if ($decisionError)
            <p class="rounded bg-red-50 px-3 py-2 text-sm text-red-800">{{ $decisionError }}</p>
        @endif

        @foreach ($pending as $action)
            <x-action-card :action="$action" :edits="$edits" />
        @endforeach

        <div class="rounded-lg border border-stone-200 bg-white p-4">
            <div class="max-h-96 space-y-3 overflow-y-auto text-sm">
                @forelse ($conversation as $turn)
                    @if (filled($turn->content))
                        <div wire:key="turn-{{ $turn->id }}">
                            <p class="text-xs font-medium text-stone-500">{{ $turn->role === 'user' ? (str_starts_with($turn->content, '[Automated') ? 'TriageDesk' : 'Teammate') : 'Agent' }}</p>
                            @if ($turn->role === 'user')
                                <p class="whitespace-pre-line">{{ $turn->content }}</p>
                            @else
                                {{-- The model writes Markdown. Raw HTML and unsafe links are stripped. --}}
                                <div class="agent-md">{!! Str::markdown($turn->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                            @endif
                        </div>
                    @endif
                    @foreach ($turn->tool_calls ?? [] as $call)
                        <p class="text-xs text-stone-400">↳ {{ is_array($call) ? ($call['name'] ?? 'tool') : $call->name }}</p>
                    @endforeach
                @empty
                    <p class="text-stone-500">No agent activity yet. Run triage, or ask the agent about this ticket.</p>
                @endforelse
            </div>

            <form wire:submit="send" class="mt-3 flex gap-2">
                <input wire:model="message" type="text" placeholder="Ask the agent, e.g. “Draft a reply and refund the duplicate”" class="min-w-0 flex-1 rounded border border-stone-300 px-3 py-1.5 text-sm">
                <button class="rounded bg-stone-900 px-3 py-1.5 text-sm font-medium text-white" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="send">Send</span>
                    <span wire:loading wire:target="send">Thinking…</span>
                </button>
            </form>
            @error('message') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror
            @if ($chatError)
                <p class="mt-2 text-xs text-red-700">{{ $chatError }}</p>
            @endif
        </div>

        @if ($history->isNotEmpty())
            <div class="rounded-lg border border-stone-200 bg-white p-4 text-sm">
                <h3 class="font-medium">Action history</h3>
                <ul class="mt-2 space-y-2">
                    @foreach ($history as $action)
                        <li wire:key="history-{{ $action->id }}">
                            <x-badge :value="$action->status" /> {{ $action->tool }}
                            <span class="text-xs text-stone-500">
                                {{ $action->required_approval ? ($action->decider ? 'decided by '.$action->decider->name : 'awaiting run') : 'ran without approval' }}
                            </span>
                            @if ($action->result)
                                <p class="text-xs text-stone-600">{{ $action->result }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </aside>
</div>
