<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-document-text" heading="Your pages cited">
        <x-slot name="description">Pages on {{ $brand?->name ?? 'your site' }} that AI answers cite most. These are the pages AI engines trust.</x-slot>

        @forelse ($rows as $row)
            <div style="display: flex; justify-content: space-between; gap: 0.75rem; padding: 0.35rem 0; border-bottom: 1px solid rgba(127,127,127,0.15);">
                <x-filament::link :href="$row->url" target="_blank" style="min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row->url }}</x-filament::link>
                <span style="white-space: nowrap; opacity: 0.8;">{{ $row->answers }} answers</span>
            </div>
        @empty
            <div style="opacity: 0.75;">No answers cite your site yet.</div>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
