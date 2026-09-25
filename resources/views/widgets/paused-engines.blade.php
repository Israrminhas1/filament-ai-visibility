<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger" compact>
        <x-slot name="heading">Tracking is interrupted</x-slot>

        <ul style="display: grid; gap: 0.25rem;">
            @foreach ($issues as $issue)
                <li>{{ $issue }}</li>
            @endforeach
        </ul>

        @if ($healthUrl)
            <x-slot name="afterHeader">
                <x-filament::button tag="a" :href="$healthUrl" size="sm" color="danger">Open Health</x-filament::button>
            </x-slot>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
