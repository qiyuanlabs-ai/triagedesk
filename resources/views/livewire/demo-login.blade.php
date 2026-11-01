<div class="mx-auto max-w-md px-4 py-16">
    <h1 class="text-2xl font-semibold tracking-tight">TriageDesk</h1>
    <p class="mt-2 text-sm text-stone-600">
        An AI agent triages Flowly's support tickets and proposes replies, ticket changes and refunds.
        Anything that changes data waits in an approval inbox until a person says yes.
    </p>
    @unless ($enabled)
        <p class="mt-6 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Sign-in without a password is only available in demo mode (DEMO_MODE=true).
        </p>
    @endunless
    <p class="mt-6 text-sm font-medium">Sign in as a teammate</p>
    <div class="mt-3 space-y-2">
        @foreach ($users as $user)
            <button wire:click="loginAs({{ $user->id }})" class="flex w-full items-center justify-between rounded-lg border border-stone-200 bg-white px-4 py-3 text-left hover:border-stone-400">
                <span>
                    <span class="font-medium">{{ $user->name }}</span>
                    <span class="block text-xs text-stone-500">{{ $user->email }}</span>
                </span>
                <span class="text-xs text-stone-500">{{ $user->role === 'admin' ? 'Admin: can approve anything' : 'Agent: own and unassigned tickets' }}</span>
            </button>
        @endforeach
    </div>
</div>
