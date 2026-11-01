@props(['value'])
@php
    $colors = [
        'urgent' => 'bg-red-100 text-red-800', 'high' => 'bg-orange-100 text-orange-800',
        'normal' => 'bg-stone-100 text-stone-700', 'low' => 'bg-stone-100 text-stone-500',
        'angry' => 'bg-red-100 text-red-800', 'negative' => 'bg-orange-100 text-orange-800',
        'positive' => 'bg-green-100 text-green-800', 'neutral' => 'bg-stone-100 text-stone-600',
        'new' => 'bg-blue-100 text-blue-800', 'open' => 'bg-sky-100 text-sky-800', 'pending' => 'bg-amber-100 text-amber-800',
        'solved' => 'bg-green-100 text-green-800', 'closed' => 'bg-stone-200 text-stone-600',
        'approved' => 'bg-sky-100 text-sky-800', 'executed' => 'bg-green-100 text-green-800',
        'rejected' => 'bg-stone-200 text-stone-700', 'failed' => 'bg-red-100 text-red-800',
    ];
@endphp
@if ($value)
    <span {{ $attributes->class(['inline-block rounded px-1.5 py-0.5 text-xs font-medium', $colors[$value] ?? 'bg-violet-100 text-violet-800']) }}>{{ str_replace('_', ' ', $value) }}</span>
@endif
