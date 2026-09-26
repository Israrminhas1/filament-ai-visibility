<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI visibility report: {{ $brand->name }}</title>
</head>
<body style="margin: 0; padding: 0; background: #f4f5f7; font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color: #111827; font-size: 14px; line-height: 1.5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #f4f5f7;">
    <tr>
        <td align="center" style="padding: 24px 12px;">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width: 640px; width: 100%; background: #ffffff; border-radius: 8px;">
                <tr>
                    <td style="padding: 24px 28px 8px;">
                        <div style="font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em;">AI visibility report</div>
                        <div style="font-size: 22px; font-weight: 700;">{{ $brand->name }}</div>
                        <div style="color: #6b7280;">{{ $from->format('M j, Y') }} – {{ $until->format('M j, Y') }} ({{ $days }} days)</div>
                    </td>
                </tr>

                @isset($summary)
                    @php
                        $delta = fn ($now, $before) => $now !== null && $before !== null ? round($now - $before, 1) : null;
                        $tiles = [
                            ['Visibility', $summary['visibility'], $previous['visibility'], '%'],
                            ['Share of voice', $summary['share_of_voice'], $previous['share_of_voice'], '%'],
                            ['Cited as a source', $summary['citation_rate'], $previous['citation_rate'], '%'],
                        ];
                    @endphp
                    <tr>
                        <td style="padding: 12px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    @foreach ($tiles as [$label, $now, $before, $unit])
                                        @php $change = $delta($now, $before); @endphp
                                        <td width="33%" style="padding: 12px; background: #f9fafb; border-radius: 6px; vertical-align: top;">
                                            <div style="font-size: 12px; color: #6b7280;">{{ $label }}</div>
                                            <div style="font-size: 24px; font-weight: 700;">{{ $now !== null ? $now . $unit : '—' }}</div>
                                            @if ($change !== null)
                                                <div style="font-size: 12px; color: {{ $change >= 0 ? '#059669' : '#dc2626' }};">{{ $change >= 0 ? '▲ +' : '▼ ' }}{{ $change }} pts</div>
                                            @endif
                                        </td>
                                        @if (! $loop->last)<td width="8"></td>@endif
                                    @endforeach
                                </tr>
                            </table>
                            <div style="margin-top: 8px; color: #6b7280; font-size: 12px;">
                                {{ number_format($summary['answers']) }} answers
                                @if ($summary['avg_position'] !== null) · average position #{{ $summary['avg_position'] }} @endif
                                @if (! empty($reach)) · search-weighted reach {{ $reach['reach'] }}% @endif
                                · ${{ number_format($summary['spend'], 2) }} spent
                            </div>
                        </td>
                    </tr>
                @endisset

                @if (! empty($leaderboard) && count($leaderboard))
                    <tr><td style="padding: 16px 28px 4px; font-size: 16px; font-weight: 700;">Competitors</td></tr>
                    <tr>
                        <td style="padding: 0 28px 8px;">
                            <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 13px;">
                                <tr style="color: #6b7280; text-align: left;"><th align="left">Brand</th><th align="right">Visibility</th><th align="right">Share of voice</th><th align="right">Named first</th></tr>
                                @foreach ($leaderboard as $row)
                                    <tr style="border-top: 1px solid #e5e7eb; {{ $row['type'] === 'brand' ? 'font-weight: 700;' : '' }}">
                                        <td>{{ $row['name'] }}</td>
                                        <td align="right">{{ $row['visibility'] }}%</td>
                                        <td align="right">{{ $row['share_of_voice'] }}%</td>
                                        <td align="right">{{ $row['win_rate'] !== null ? $row['win_rate'] . '%' : '—' }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif

                @isset($opportunities)
                    <tr><td style="padding: 16px 28px 4px; font-size: 16px; font-weight: 700;">Opportunities</td></tr>
                    <tr>
                        <td style="padding: 0 28px 8px;">
                            @forelse ($opportunities as $row)
                                <div style="padding: 6px 0; border-top: 1px solid #e5e7eb;">
                                    <div style="font-weight: 600;">{{ $row['text'] }}</div>
                                    <div style="font-size: 12px; color: #6b7280;">Named instead: {{ implode(', ', $row['competitors']) }}</div>
                                </div>
                            @empty
                                <div style="color: #6b7280;">No gaps: wherever a competitor is named, you are too.</div>
                            @endforelse

                            @if (! empty($featureSources) && count($featureSources))
                                <div style="margin-top: 8px; font-weight: 600;">Where to get featured</div>
                                <div style="font-size: 13px; color: #374151;">
                                    @foreach ($featureSources as $source){{ $source['domain'] }} ({{ $source['category_label'] }})@if (! $loop->last), @endif @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @endisset

                @if (isset($gained) || isset($lost))
                    <tr><td style="padding: 16px 28px 4px; font-size: 16px; font-weight: 700;">Prompt movers</td></tr>
                    <tr>
                        <td style="padding: 0 28px 8px; font-size: 13px;">
                            @foreach ($gained ?? [] as $row)
                                <div style="padding: 4px 0; border-top: 1px solid #e5e7eb;"><span style="color: #059669;">▲ +{{ $row['change'] }}</span> {{ $row['text'] }}</div>
                            @endforeach
                            @foreach ($lost ?? [] as $row)
                                <div style="padding: 4px 0; border-top: 1px solid #e5e7eb;"><span style="color: #dc2626;">▼ {{ $row['change'] }}</span> {{ $row['text'] }}</div>
                            @endforeach
                            @if (empty($gained) && empty($lost))
                                <div style="color: #6b7280;">No prompt changed visibility vs the previous period.</div>
                            @endif
                        </td>
                    </tr>
                @endif

                @if (! empty($sources) && count($sources))
                    <tr><td style="padding: 16px 28px 4px; font-size: 16px; font-weight: 700;">Top sources</td></tr>
                    <tr>
                        <td style="padding: 0 28px 8px; font-size: 13px;">
                            @foreach ($sources as $source)
                                <div style="padding: 4px 0; border-top: 1px solid #e5e7eb;">{{ $source['domain'] }} <span style="color: #6b7280;">· {{ $source['category'] }} · {{ $source['answers'] }} answers</span></div>
                            @endforeach
                        </td>
                    </tr>
                @endif

                @if (! empty($perception) && $perception['analysed'] > 0)
                    @php $total = max(1, array_sum($perception['sentiment'])); @endphp
                    <tr><td style="padding: 16px 28px 4px; font-size: 16px; font-weight: 700;">How AI talks about {{ $brand->name }}</td></tr>
                    <tr>
                        <td style="padding: 0 28px 8px; font-size: 13px;">
                            Positive {{ round($perception['sentiment']['positive'] / $total * 100) }}% ·
                            Neutral {{ round($perception['sentiment']['neutral'] / $total * 100) }}% ·
                            Negative {{ round($perception['sentiment']['negative'] / $total * 100) }}%
                            @if ($perception['descriptors'])
                                <div style="color: #6b7280;">Described as: {{ implode(', ', array_keys(array_slice($perception['descriptors'], 0, 8, true))) }}</div>
                            @endif
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding: 20px 28px 24px; color: #9ca3af; font-size: 12px;">
                        Sent by AI Visibility. Answers skipped because an engine was paused are not counted.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
