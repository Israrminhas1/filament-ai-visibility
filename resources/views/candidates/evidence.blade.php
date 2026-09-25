@php
    $classification = $candidate->latestClassification;
    $website = $classification?->evidence['website'] ?? null;
    $mentions = $classification?->evidence['mentions'] ?? [];
@endphp

<div style="display: grid; gap: 1rem;">
    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
        @if ($candidate->label)
            <x-filament::badge :color="$candidate->label->getColor()">{{ $candidate->label->getLabel() }}</x-filament::badge>
        @endif
        @if ($candidate->confidence)
            <x-filament::badge :color="['high' => 'success', 'medium' => 'warning'][$candidate->confidence] ?? 'gray'">{{ ucfirst($candidate->confidence) }} confidence</x-filament::badge>
        @endif
        @if ($classification?->override_label)
            <x-filament::badge color="gray">Corrected by you</x-filament::badge>
        @endif
        <span style="opacity: 0.8;">Score {{ $candidate->score }}/100 · {{ $candidate->answers }} answers · {{ $candidate->prompts }} prompts · {{ count($candidate->engines ?? []) }} engines</span>
    </div>

    @if ($classification)
        <div>
            @if ($classification->company_name)<div><strong>{{ $classification->company_name }}</strong>@if ($candidate->domain) · <x-filament::link :href="'https://' . $candidate->domain" target="_blank">{{ $candidate->domain }}</x-filament::link>@endif</div>@endif
            @if ($classification->offering_summary)<div>{{ $classification->offering_summary }}</div>@endif
            @if ($classification->reason)<div style="margin-top: 0.25rem; opacity: 0.85;"><strong>Why:</strong> {{ $classification->reason }}</div>@endif
        </div>
    @else
        <div style="opacity: 0.8;">Not classified yet.</div>
    @endif

    @if ($mentions)
        <div>
            <div style="font-weight: 600; margin-bottom: 0.25rem;">How AI answers described it</div>
            <ul style="display: grid; gap: 0.25rem; list-style: disc; padding-left: 1.25rem;">
                @foreach ($mentions as $mention)
                    <li>{{ $mention }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($website)
        <div>
            <div style="font-weight: 600; margin-bottom: 0.25rem;">What its website says</div>
            @if (! empty($website['title']))<div>{{ $website['title'] }}</div>@endif
            @if (! empty($website['description']))<div style="opacity: 0.85;">{{ $website['description'] }}</div>@endif
            @if (! empty($website['note']))<div style="opacity: 0.85;">{{ $website['note'] }}</div>@endif
            @if (! empty($website['excerpt']))<div style="margin-top: 0.25rem; font-size: 0.85rem; opacity: 0.75;">{{ \Illuminate\Support\Str::limit($website['excerpt'], 600) }}</div>@endif
        </div>
    @endif
</div>
