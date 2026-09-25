<x-filament-widgets::widget>
    <div style="display: grid; gap: 1rem;">
        <x-filament::section icon="heroicon-o-light-bulb" heading="Prompts to win">
            <x-slot name="description">{{ $missed }} answers name a competitor but not you. Ranked by how many competitors appear, on how many engines.</x-slot>

            @forelse ($prompts as $row)
                <div style="padding: 0.5rem 0; border-bottom: 1px solid rgba(127,127,127,0.15);">
                    <div style="display: flex; justify-content: space-between; gap: 0.5rem;">
                        @if ($url = $promptUrl($row['prompt_id']))
                            <x-filament::link :href="$url" style="font-weight: 500;">{{ $row['text'] }}</x-filament::link>
                        @else
                            <span style="font-weight: 500;">{{ $row['text'] }}</span>
                        @endif
                        <x-filament::badge color="warning">{{ $row['answers'] }} answers</x-filament::badge>
                    </div>
                    <div style="font-size: 0.85rem; opacity: 0.8;">
                        Named instead: {{ implode(', ', $row['competitors']) }} · on {{ implode(', ', array_map($engineLabel, $row['engines'])) }}
                    </div>
                </div>
            @empty
                <div style="opacity: 0.75;">No gaps: wherever a competitor is named, you are too.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section icon="heroicon-o-megaphone" heading="Where to get featured">
            <x-slot name="description">Sites cited in the answers you are missing from. Being listed, reviewed or discussed on them is the most direct way into those answers.</x-slot>

            @forelse ($sources as $row)
                <div style="display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.4rem 0; border-bottom: 1px solid rgba(127,127,127,0.15);">
                    <div>
                        <div style="font-weight: 500;">{{ $row['domain'] }}</div>
                        <div style="font-size: 0.8rem; opacity: 0.75;"><span style="display: inline-block; width: 0.55rem; height: 0.55rem; border-radius: 9999px; background: {{ $row['color'] }}; margin-right: 0.25rem;"></span>{{ $row['category_label'] }}</div>
                    </div>
                    <span style="white-space: nowrap; opacity: 0.8;">{{ $row['answers'] }} answers</span>
                </div>
            @empty
                <div style="opacity: 0.75;">No sources yet.</div>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
