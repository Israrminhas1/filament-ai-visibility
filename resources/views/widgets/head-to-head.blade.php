<x-filament-widgets::widget>
    @if (! $competitor)
        <x-filament::empty-state icon="heroicon-o-scale" heading="No competitors yet">
            <x-slot name="description">Add competitors to the brand, or track some from the Discovered screen.</x-slot>
        </x-filament::empty-state>
    @elseif (! $data || $data['total'] === 0)
        <x-filament::empty-state icon="heroicon-o-scale" heading="No answers in this period" />
    @else
        <div style="display: grid; gap: 1rem;">
            <x-filament::section>
                <div style="display: grid; grid-template-columns: 1fr auto 1fr; gap: 1rem; align-items: center; text-align: center;">
                    <div>
                        <div style="font-size: 1.1rem; font-weight: 600;">{{ $data['brand']['name'] }}</div>
                        <div style="font-size: 2rem; font-weight: 700;">{{ $data['brand']['visibility'] }}%</div>
                        <div style="opacity: 0.8;">visibility · named ahead {{ $data['brand']['wins'] }} times</div>
                    </div>
                    <div style="font-size: 0.85rem; opacity: 0.75;">vs<br>{{ $data['co_mention_rate'] }}% named together</div>
                    <div>
                        <div style="font-size: 1.1rem; font-weight: 600;">{{ $data['rival']['name'] }}</div>
                        <div style="font-size: 2rem; font-weight: 700;">{{ $data['rival']['visibility'] }}%</div>
                        <div style="opacity: 0.8;">visibility · named ahead {{ $data['rival']['wins'] }} times</div>
                    </div>
                </div>
            </x-filament::section>

            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));">
                @foreach ([['brand_prompts', $data['brand']['name'] . ' wins', 'success', 'brand', 'rival'], ['rival_prompts', $data['rival']['name'] . ' wins', 'danger', 'rival', 'brand']] as [$key, $title, $color, $us, $them])
                    <x-filament::section :heading="$title">
                        <x-slot name="description">Prompts where it is named ahead more often, or the other is not named.</x-slot>
                        @forelse ($data[$key]->take(10) as $row)
                            <div style="display: flex; justify-content: space-between; gap: 0.5rem; padding: 0.35rem 0; border-bottom: 1px solid rgba(127,127,127,0.15);">
                                @if ($url = $promptUrl($row['prompt_id']))
                                    <x-filament::link :href="$url" style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row['text'] }}</x-filament::link>
                                @else
                                    <span>{{ $row['text'] }}</span>
                                @endif
                                <x-filament::badge :color="$color">{{ $row[$us] }}–{{ $row[$them] }}</x-filament::badge>
                            </div>
                        @empty
                            <div style="opacity: 0.75;">None.</div>
                        @endforelse
                    </x-filament::section>
                @endforeach

                @foreach (['brand', 'rival'] as $side)
                    <x-filament::section :heading="'How AI describes ' . $data[$side]['name']">
                        <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                            @forelse ($data[$side]['perception']['descriptors'] as $descriptor => $count)
                                <x-filament::badge color="gray">{{ $descriptor }} · {{ $count }}</x-filament::badge>
                            @empty
                                <span style="opacity: 0.75;">No analysed mentions yet.</span>
                            @endforelse
                        </div>
                    </x-filament::section>
                @endforeach

                <x-filament::section :heading="'Sites citing ' . $data['rival']['name'] . ' but not you'">
                    <x-slot name="description">Getting featured here puts you in the same answers.</x-slot>
                    @forelse ($data['rival_only_sources'] as $domain => $answers)
                        <div style="display: flex; justify-content: space-between; padding: 0.3rem 0; border-bottom: 1px solid rgba(127,127,127,0.15);"><span>{{ $domain }}</span><span style="opacity: 0.8;">{{ $answers }} answers</span></div>
                    @empty
                        <div style="opacity: 0.75;">None.</div>
                    @endforelse
                </x-filament::section>

                <x-filament::section :heading="'Sites citing you but not ' . $data['rival']['name']">
                    @forelse ($data['brand_only_sources'] as $domain => $answers)
                        <div style="display: flex; justify-content: space-between; padding: 0.3rem 0; border-bottom: 1px solid rgba(127,127,127,0.15);"><span>{{ $domain }}</span><span style="opacity: 0.8;">{{ $answers }} answers</span></div>
                    @empty
                        <div style="opacity: 0.75;">None.</div>
                    @endforelse
                </x-filament::section>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>
