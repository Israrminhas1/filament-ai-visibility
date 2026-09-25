@if ($history->isEmpty())
    <x-filament::empty-state icon="heroicon-o-clock" heading="No answers yet">
        <x-slot name="description">This prompt hasn't been answered yet. Run tracking on its brand to start its history.</x-slot>
    </x-filament::empty-state>
@else
    <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));">
        @foreach ($history as $engine => $entries)
            <x-filament::section :heading="$engineLabel($engine)" icon="heroicon-o-cpu-chip">
                <x-slot name="description">
                    Mentioned in {{ $entries->where('mentioned', true)->count() }} of the last {{ $entries->count() }} answers
                </x-slot>

                <div style="display: grid; gap: 0.9rem;">
                    @foreach ($entries as $entry)
                        <div style="border-left: 3px solid {{ $entry['mentioned'] ? '#10b981' : '#ef4444' }}; padding-left: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; gap: 0.5rem; align-items: center;">
                                <span style="font-weight: 600;">
                                    {{ $entry['mentioned'] ? 'Mentioned' . ($entry['position'] ? ' at #' . $entry['position'] : '') : 'Not mentioned' }}
                                </span>
                                @if ($url = $answerUrl($entry['result']))
                                    <x-filament::link :href="$url" size="sm">{{ $entry['result']->ran_at?->format('M j, Y H:i') }}</x-filament::link>
                                @else
                                    <span style="opacity: 0.75;">{{ $entry['result']->ran_at?->format('M j, Y H:i') }}</span>
                                @endif
                            </div>

                            @if ($entry['competitors'])
                                <div style="font-size: 0.85rem; opacity: 0.8;">Competitors: {{ implode(', ', $entry['competitors']) }}</div>
                            @endif

                            @foreach ($entry['changes'] as $change)
                                <div style="font-size: 0.85rem; color: {{ ['good' => '#059669', 'bad' => '#dc2626'][$change['type']] ?? 'inherit' }};">
                                    {{ ['good' => '▲', 'bad' => '▼'][$change['type']] ?? '•' }} {{ $change['text'] }}
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
@endif
