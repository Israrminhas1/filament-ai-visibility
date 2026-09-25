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
