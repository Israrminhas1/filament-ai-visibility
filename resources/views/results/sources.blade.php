@if ($cited === [] && $read === [])
    <div style="opacity: 0.75;">No sources.</div>
@else
    <div style="display: grid; gap: 1rem;">
        @foreach (['Cited in the answer' => $cited, 'Also read' => $read] as $heading => $sources)
            @continue($sources === [])

            <div style="display: grid; gap: 0.5rem;">
                <div style="font-size: 0.8125rem; font-weight: 600; opacity: 0.75;">{{ $heading }} ({{ count($sources) }})</div>

                <ol style="display: grid; gap: 0.6rem; margin: 0; padding: 0; list-style: none;">
                    @foreach ($sources as $source)
                        <li style="display: flex; gap: 0.5rem; align-items: baseline;">
                            <span style="font-variant-numeric: tabular-nums; opacity: 0.7; min-width: 1.5rem; flex-shrink: 0;">{{ $source['position'] }}.</span>
                            <div style="display: grid; gap: 0.15rem; min-width: 0;">
                                <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center;">
                                    @if ($source['url'])
                                        <x-filament::link :href="$source['url']" target="_blank" rel="noopener noreferrer nofollow" size="sm">{{ $source['domain'] }}</x-filament::link>
                                    @else
                                        <span style="font-size: 0.875rem; font-weight: 500;">{{ $source['domain'] }}</span>
                                    @endif
                                    @if ($source['badge'])
                                        <x-filament::badge size="sm" :color="$source['color']">{{ $source['badge'] }}</x-filament::badge>
                                    @endif
                                </div>
                                @if ($source['title'])
                                    <div style="font-size: 0.8125rem; line-height: 1.4; opacity: 0.8; overflow-wrap: anywhere;">{{ \Illuminate\Support\Str::limit($source['title'], 140) }}</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endforeach
    </div>
@endif
