<div style="display: grid; gap: 0.75rem;">
    <div><strong>Brand:</strong> {{ $brand?->name ?? '—' }} @if ($brand?->primaryDomain()) ({{ $brand->primaryDomain() }}) @endif</div>
    <div><strong>Engines:</strong> {{ $engines ? implode(', ', $engines) : 'none' }}</div>
    <div><strong>Active prompts:</strong> {{ $prompts }}</div>
    <div><strong>Competitors:</strong> {{ $competitors }}</div>
    <div><strong>Keywords:</strong> {{ $keywords }}</div>
    <div><strong>Runs:</strong> {{ $frequency }}</div>
    <div><strong>Estimated cost:</strong> {{ $monthly }} per month @if ($budget) (budget ${{ number_format((float) $budget, 2) }}) @endif</div>

    <div style="margin-top: 0.5rem; opacity: 0.8;">
        Click "Finish setup" to start. You can change everything later in Settings and on each brand.
    </div>
</div>
