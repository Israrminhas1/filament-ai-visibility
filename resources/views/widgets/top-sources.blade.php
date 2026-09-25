<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-link" heading="Top sources">
        <x-slot name="description">Sites the AI engines cite most, by share of answers.</x-slot>

        @forelse ($rows as $row)
            <div style="display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.4rem 0; border-bottom: 1px solid rgba(127,127,127,0.15);">
                <div style="min-width: 0;">
                    <div style="font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row['domain'] }}</div>
                    <div style="font-size: 0.8rem; opacity: 0.75;">
                        <span style="display: inline-block; width: 0.55rem; height: 0.55rem; border-radius: 9999px; background: {{ $row['color'] }}; margin-right: 0.25rem;"></span>{{ $row['category'] }}
                    </div>
                </div>
                <div style="text-align: right; white-space: nowrap;">
                    <div style="font-weight: 600;">{{ $row['share'] }}%</div>
                    <div style="font-size: 0.8rem; opacity: 0.75;">{{ $row['answers'] }} answers</div>
                </div>
            </div>
        @empty
            <div style="opacity: 0.75;">No sources yet.</div>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
