<x-filament-panels::page>
    @if ($killSwitch)
        <x-filament::section icon="heroicon-o-pause-circle" icon-color="danger">
            <x-slot name="heading">Everything is paused</x-slot>
            <x-slot name="description">
                "Pause everything" is switched on in Settings, so no scheduled or queued AI Visibility work runs.
            </x-slot>
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-server-stack">
        <x-slot name="heading">System</x-slot>
        <x-slot name="description">The queue worker and scheduler must be running for tracking to happen.</x-slot>

        <div style="display: grid; gap: 1rem;">
            @foreach ($checks as $check)
                <div style="display: flex; gap: 0.75rem; align-items: flex-start;">
                    <x-filament::icon :icon="$check->icon()" @class(['fi-color', 'fi-color-' . $check->color(), 'fi-text-color-600']) style="width: 1.5rem; height: 1.5rem; flex-shrink: 0;" />
                    <div>
                        <div style="font-weight: 600;">{{ $check->label }}</div>
                        <div>{{ $check->message }}</div>
                        @if ($check->fix && ! $check->ok())
                            <div style="margin-top: 0.25rem; opacity: 0.8;"><code style="white-space: pre-wrap; word-break: break-all;">{{ $check->fix }}</code></div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-cpu-chip">
        <x-slot name="heading">Engines</x-slot>
        <x-slot name="description">A paused engine is skipped until it is fixed, so runs never waste requests on it.</x-slot>

        <div style="display: grid; gap: 1.25rem;">
            @foreach ($engines as $engine)
                <div wire:key="engine-{{ $engine['key'] }}" style="display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: space-between; align-items: flex-start;">
                    <div style="min-width: 16rem; flex: 1;">
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <span style="font-weight: 600;">{{ $engine['label'] }}</span>
                            <x-filament::badge :color="$engine['status']->getColor()">
                                {{ $engine['status']->getLabel() }}@if ($engine['enabled'] && $engine['reason']): {{ $engine['reason']->getLabel() }}@endif
                            </x-filament::badge>
                        </div>
                        <div style="opacity: 0.8;">
                            Model: {{ $engine['model'] }} ·
                            Key: {{ match ($engine['key_source']) { 'panel' => 'saved in panel', 'ai-monitor' => 'from AI Monitor', 'env' => 'from .env', default => 'none' } }}
                        </div>
                        @if ($engine['enabled'] && ! $engine['usable'] && $engine['reason'])
                            <div style="margin-top: 0.25rem;">{{ $engine['reason']->fix() }}</div>
                            @if ($engine['paused_at'])
                                <div style="opacity: 0.7;">Paused {{ $engine['paused_at']->diffForHumans() }}</div>
                            @endif
                        @endif
                    </div>

                    @if ($canManage)
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                            @if ($engine['enabled'])
                                @if (! $engine['usable'])
                                    <x-filament::button size="sm" icon="heroicon-o-play" wire:click="testAndResume('{{ $engine['key'] }}')">
                                        Test &amp; resume
                                    </x-filament::button>
                                @else
                                    <x-filament::button size="sm" color="gray" icon="heroicon-o-pause" wire:click="pauseEngine('{{ $engine['key'] }}')">
                                        Pause
                                    </x-filament::button>
                                @endif
                            @endif
                            @if ($settingsUrl)
                                <x-filament::button tag="a" :href="$settingsUrl" size="sm" color="gray" icon="heroicon-o-key">
                                    Change key / settings
                                </x-filament::button>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
