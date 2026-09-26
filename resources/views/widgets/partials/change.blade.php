{{-- A change in points, marked with an arrow and sign so it reads without colour too. --}}
@if ($change !== null && $change != 0)
    <span class="{{ $change > 0 ? 'aiv-up' : 'aiv-down' }}" style="font-size: 0.8rem;">{{ $change > 0 ? '▲ +' : '▼ −' }}{{ abs($change) }}</span>
@endif
