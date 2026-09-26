@php
    $typeLabels = ['brand' => ['You', 'success'], 'competitor' => ['Competitor', 'warning'], 'entity' => ['Other', 'gray']];
@endphp

@if ($mentions === [])
    <div style="opacity: 0.75;">No brands or companies were named in this answer.</div>
@else
    <ol style="display: grid; gap: 0.875rem; margin: 0; padding: 0; list-style: none;">
        @foreach ($mentions as $mention)
            @php
                [$typeLabel, $typeColor] = $typeLabels[$mention['type']] ?? ['Other', 'gray'];
            @endphp
            <li style="display: grid; gap: 0.3rem;">
                <div style="display: flex; flex-wrap: wrap; gap: 0.4rem; align-items: center;">
                    <span style="font-variant-numeric: tabular-nums; opacity: 0.7; min-width: 1.75rem;">#{{ $mention['position'] }}</span>
                    <span style="font-weight: {{ $mention['type'] === 'brand' ? 600 : 500 }};">{{ $mention['name'] }}</span>
                    <x-filament::badge size="sm" :color="$typeColor">{{ $typeLabel }}</x-filament::badge>
                    @if ($mention['count'] > 1)
                        <span style="font-size: 0.8125rem; opacity: 0.7;">× {{ $mention['count'] }}</span>
                    @endif
                </div>

                @if ($mention['sentiment'] || $mention['recommendation'] || $mention['descriptors'] !== [])
                    <div style="display: flex; flex-wrap: wrap; gap: 0.25rem; padding-inline-start: 2.15rem;">
                        @if ($mention['sentiment'])
                            <x-filament::badge size="sm" :color="$mention['sentiment']->getColor()">{{ $mention['sentiment']->getLabel() }}</x-filament::badge>
                        @endif
                        @if ($mention['recommendation'])
                            <x-filament::badge size="sm" color="info">{{ $mention['recommendation'] }}</x-filament::badge>
                        @endif
                        @foreach ($mention['descriptors'] as $descriptor)
                            <x-filament::badge size="sm" color="gray">{{ $descriptor }}</x-filament::badge>
                        @endforeach
                    </div>
                @endif

                @if ($mention['snippet'])
                    <div style="padding-inline-start: 2.15rem; font-size: 0.8125rem; line-height: 1.5; opacity: 0.8;">“{{ \Illuminate\Support\Str::limit($mention['snippet'], 220) }}”</div>
                @endif
            </li>
        @endforeach
    </ol>
@endif
