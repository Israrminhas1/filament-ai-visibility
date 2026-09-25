<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-squares-2x2" heading="Visibility by engine">
        <x-slot name="description">% of each engine's answers that mention each brand. Spot where competitors beat you.</x-slot>

        @if (empty($engines))
            <div style="opacity: 0.75;">No answers in this period.</div>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: separate; border-spacing: 3px; font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach ($engines as $engine)
                                <th style="padding: 0.4rem; font-weight: 500; opacity: 0.8; white-space: nowrap;">{{ $engineLabels[$engine] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="padding: 0.4rem; white-space: nowrap; {{ $row['type'] === 'brand' ? 'font-weight: 600;' : '' }}">
                                    <span style="display: inline-block; width: 0.6rem; height: 0.6rem; border-radius: 9999px; background: {{ $row['color'] }}; margin-right: 0.4rem;"></span>{{ $row['name'] }}
                                </td>
                                @foreach ($engines as $engine)
                                    <td style="padding: 0.4rem; text-align: center; border-radius: 0.3rem; {{ \IsrarMinhas\FilamentAiVisibility\Filament\Widgets\EngineHeatmap::cellStyle($row['cells'][$engine]) }}">
                                        {{ $row['cells'][$engine] !== null ? $row['cells'][$engine] . '%' : '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
