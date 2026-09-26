<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger" compact>
        <x-slot name="heading">Tracking is interrupted</x-slot>

        <ul style="display: grid; gap: 0.5rem;">
            @foreach ($issues as $issue)
                <li style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: baseline; justify-content: space-between;">
                    <span style="flex: 1; min-width: 14rem;">{{ $issue['text'] }}</span>
                    @if ($issue['url'])
                        <x-filament::link :href="$issue['url']" size="sm" color="danger">{{ $issue['label'] }}</x-filament::link>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($healthUrl)
            <x-slot name="afterHeader">
                <x-filament::button tag="a" :href="$healthUrl" size="sm" color="danger">Open Health</x-filament::button>
            </x-slot>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
