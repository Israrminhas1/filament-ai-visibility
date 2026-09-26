<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-trophy" heading="Leaderboard">
        <x-slot name="description">Your brand and active competitors across all answers in the period. Change is in points vs the previous period.</x-slot>

        @include('ai-visibility::widgets.partials.styles')

        @if ($rows->isEmpty())
            <div style="opacity: 0.75;">No answers in this period.</div>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="text-align: left; opacity: 0.75;">
                            <th style="padding: 0.5rem;">Brand</th>
                            <th style="padding: 0.5rem; text-align: right;">Visibility</th>
                            <th style="padding: 0.5rem; text-align: right;">Share of voice</th>
                            <th style="padding: 0.5rem; text-align: right;">Avg. position</th>
                            <th style="padding: 0.5rem; text-align: right;" title="Named first, of the answers mentioning it">Named first</th>
                            <th style="padding: 0.5rem; text-align: right;">Site cited</th>
                            <th style="padding: 0.5rem; text-align: right;" title="Positive minus negative mentions">Net sentiment</th>
                            <th style="padding: 0.5rem; text-align: right;">Top pick</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-top: 1px solid rgba(127,127,127,0.15); {{ $row['type'] === 'brand' ? 'font-weight: 600;' : '' }}">
                                <td style="padding: 0.5rem; white-space: nowrap;">
                                    <span style="display: inline-block; width: 0.6rem; height: 0.6rem; border-radius: 9999px; background: {{ $row['color'] }}; margin-right: 0.4rem;"></span>{{ $row['name'] }}@if ($row['type'] === 'brand') <span style="opacity: 0.6; font-weight: 400;">(you)</span>@endif
                                </td>
                                <td style="padding: 0.5rem; text-align: right; white-space: nowrap;">
                                    {{ $row['visibility'] }}%
                                    @include('ai-visibility::widgets.partials.change', ['change' => $row['change']])
                                </td>
                                <td style="padding: 0.5rem; text-align: right;">{{ $row['share_of_voice'] }}%</td>
                                <td style="padding: 0.5rem; text-align: right;">{{ $row['avg_position'] !== null ? '#' . $row['avg_position'] : '—' }}</td>
                                <td style="padding: 0.5rem; text-align: right;">{{ $row['win_rate'] !== null ? $row['win_rate'] . '%' : '—' }}</td>
                                <td style="padding: 0.5rem; text-align: right;">{{ $row['citation_rate'] }}%</td>
                                <td @class(['aiv-up' => ($row['net_sentiment'] ?? 0) > 0, 'aiv-down' => ($row['net_sentiment'] ?? 0) < 0]) style="padding: 0.5rem; text-align: right;">{{ $row['net_sentiment'] === null ? '—' : ($row['net_sentiment'] > 0 ? '+' . $row['net_sentiment'] : ($row['net_sentiment'] < 0 ? '−' . abs($row['net_sentiment']) : '0')) }}</td>
                                <td style="padding: 0.5rem; text-align: right;">{{ $row['top_pick_rate'] !== null ? $row['top_pick_rate'] . '%' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
