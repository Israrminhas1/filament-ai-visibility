<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-folder" heading="Visibility by topic">
        <x-slot name="description">Weakest topics first. Change is in points vs the previous period.</x-slot>

        @if ($rows->isEmpty())
            <div style="opacity: 0.75;">No topics yet. Use "Organise into topics" on a brand, or add topics to prompts.</div>
        @else
            <div style="display: grid; gap: 0.6rem;">
                @foreach ($rows as $row)
                    <div style="display: grid; grid-template-columns: minmax(8rem, 14rem) 1fr 7rem; gap: 0.75rem; align-items: center;">
                        <div style="min-width: 0;">
                            <div style="font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row['name'] }}</div>
                            <div style="font-size: 0.8rem; opacity: 0.7;">{{ $row['prompts'] }} prompts · {{ $row['answers'] }} answers</div>
                        </div>
                        <div style="height: 0.6rem; border-radius: 9999px; background: rgba(127,127,127,0.15);">
                            <div style="height: 100%; width: {{ $row['visibility'] ?? 0 }}%; border-radius: 9999px; background: {{ ($row['visibility'] ?? 0) >= 50 ? '#10b981' : (($row['visibility'] ?? 0) >= 20 ? '#f59e0b' : '#ef4444') }};"></div>
                        </div>
                        <div style="text-align: right; white-space: nowrap;">
                            {{ $row['visibility'] !== null ? $row['visibility'] . '%' : '—' }}
                            @if ($row['change'] !== null && $row['change'] != 0)
                                <span style="font-size: 0.8rem; color: {{ $row['change'] > 0 ? '#059669' : '#dc2626' }};">{{ $row['change'] > 0 ? '▲' : '▼' }}{{ abs($row['change']) }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
