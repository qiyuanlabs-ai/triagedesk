<div>
    <h1 class="text-xl font-semibold tracking-tight">Skills</h1>
    <p class="mt-1 text-sm text-stone-600">The agent sees only each skill's name and description, and loads the full instructions when a task needs them.</p>

    <h2 class="mt-6 font-semibold">From files <span class="text-sm font-normal text-stone-500">resources/skills/*/SKILL.md</span></h2>
    <div class="mt-2 grid gap-3 md:grid-cols-2">
        @foreach ($fileSkills as $skill)
            <div class="rounded-lg border border-stone-200 bg-white p-4 text-sm">
                <p class="font-medium">{{ $skill->name }}</p>
                <p class="text-stone-600">{{ $skill->description }}</p>
                <pre class="mt-2 whitespace-pre-wrap text-xs text-stone-500">{{ $skill->instructions }}</pre>
            </div>
        @endforeach
    </div>

    <h2 class="mt-6 font-semibold">From the database <span class="text-sm font-normal text-stone-500">loaded per team by a closure</span></h2>
    <div class="mt-2 grid gap-3 md:grid-cols-2">
        @foreach ($teamSkills as $skill)
            <div @class(['rounded-lg border bg-white p-4 text-sm', 'border-stone-200' => $skill->enabled, 'border-dashed border-stone-300 opacity-60' => ! $skill->enabled])>
                <p class="font-medium">{{ $skill->name }} <span class="text-xs font-normal text-stone-500">team: {{ $skill->team }}{{ $skill->enabled ? '' : ' · disabled' }}</span></p>
                <p class="text-stone-600">{{ $skill->description }}</p>
                <pre class="mt-2 whitespace-pre-wrap text-xs text-stone-500">{{ $skill->instructions }}</pre>
            </div>
        @endforeach
    </div>
</div>
