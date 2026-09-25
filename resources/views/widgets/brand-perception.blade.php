<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-face-smile" :heading="'How AI talks about ' . ($brand?->name ?? 'you')">
        <x-slot name="description">From the analysis of {{ $perception['analysed'] ?? 0 }} mentions in the period.</x-slot>

        @if (! $perception || $perception['analysed'] === 0)
            <div style="opacity: 0.75;">No analysed mentions yet. Answers are analysed after each run when "Answer analysis" is on in Settings.</div>
        @else
            @php
                $total = max(1, array_sum($perception['sentiment']));
                $recTotal = max(1, array_sum($perception['recommendation']));
            @endphp

            <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));">
                <div>
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">Sentiment</div>
                    <div style="display: flex; height: 0.75rem; border-radius: 9999px; overflow: hidden; background: rgba(127,127,127,0.15);">
                        @foreach ($sentiments as $sentiment)
                            <div style="width: {{ $perception['sentiment'][$sentiment->value] / $total * 100 }}%; background: {{ $sentiment->hex() }};"></div>
                        @endforeach
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 0.5rem; font-size: 0.85rem;">
                        @foreach ($sentiments as $sentiment)
                            <span><span style="display: inline-block; width: 0.55rem; height: 0.55rem; border-radius: 9999px; background: {{ $sentiment->hex() }};"></span> {{ $sentiment->getLabel() }} {{ round($perception['sentiment'][$sentiment->value] / $total * 100) }}%</span>
                        @endforeach
                    </div>
                </div>

                <div>
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">How strongly it is recommended</div>
                    @foreach ($recommendations as $recommendation)
                        @php $share = round($perception['recommendation'][$recommendation->value] / $recTotal * 100); @endphp
                        <div style="display: grid; grid-template-columns: 8.5rem 1fr 2.5rem; gap: 0.5rem; align-items: center; font-size: 0.85rem; margin-bottom: 0.25rem;" title="{{ $recommendation->getDescription() }}">
                            <span>{{ $recommendation->getLabel() }}</span>
                            <div style="height: 0.5rem; border-radius: 9999px; background: rgba(127,127,127,0.15);"><div style="height: 100%; width: {{ $share }}%; border-radius: 9999px; background: {{ $recommendation->hex() }};"></div></div>
                            <span style="text-align: right;">{{ $share }}%</span>
                        </div>
                    @endforeach
                </div>

                <div>
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">Words used to describe it</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                        @forelse ($perception['descriptors'] as $descriptor => $count)
                            <x-filament::badge color="gray">{{ $descriptor }} · {{ $count }}</x-filament::badge>
                        @empty
                            <span style="opacity: 0.75;">None yet.</span>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
