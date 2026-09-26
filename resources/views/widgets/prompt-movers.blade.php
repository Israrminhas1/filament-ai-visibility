<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-arrows-up-down" heading="Prompts">
        <x-slot name="description">Biggest changes vs the previous period, and the prompts where the brand is least visible.</x-slot>

        <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));">
            @foreach (['gained' => ['Gained', 'success'], 'lost' => ['Lost', 'danger'], 'weakest' => ['Least visible', 'warning']] as $key => [$title, $color])
                <div>
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">{{ $title }}</div>

                    @forelse (${$key} as $row)
                        <div style="display: flex; justify-content: space-between; gap: 0.5rem; padding: 0.35rem 0; border-bottom: 1px solid rgba(127,127,127,0.15);">
                            <div style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $row['text'] }}">
                                @if ($row['url'])
                                    <x-filament::link :href="$row['url']">{{ $row['text'] }}</x-filament::link>
                                @else
                                    {{ $row['text'] }}
                                @endif
                            </div>
                            <x-filament::badge :color="$color">
                                @if ($key === 'weakest')
                                    {{ $row['visibility'] }}%
                                @else
                                    {{ $row['change'] > 0 ? '▲ +' . $row['change'] : '▼ −' . abs($row['change']) }} pts
                                @endif
                            </x-filament::badge>
                        </div>
                    @empty
                        <div style="opacity: 0.7;">Nothing yet.</div>
                    @endforelse
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
