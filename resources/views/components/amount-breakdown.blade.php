@props(['b', 'compact' => false])
{{-- Taxable value + GST shown separately, then the total. $b from App\Services\AmountBreakdown. --}}
@php $inr = fn ($v) => '₹'.number_format((float) $v, 2); @endphp
@if ($compact)
    <div {{ $attributes->merge(['class' => 'text-xs']) }} style="color: var(--text-muted);">
        Taxable {{ $inr($b['taxable']) }} · GST {{ $inr($b['gst']) }}@if ($b['reverse_charge']) (reverse charge)@endif
    </div>
@else
    <dl {{ $attributes->merge(['class' => 'grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1 text-xs']) }}>
        <div><dt style="color: var(--text-muted);">Taxable value</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $inr($b['taxable']) }}</dd></div>
        @if ($b['igst'] > 0)
            <div><dt style="color: var(--text-muted);">IGST</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $inr($b['igst']) }}</dd></div>
        @else
            <div><dt style="color: var(--text-muted);">CGST</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $inr($b['cgst']) }}</dd></div>
            <div><dt style="color: var(--text-muted);">SGST</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $inr($b['sgst']) }}</dd></div>
        @endif
        <div><dt style="color: var(--text-muted);">Total (incl. GST)</dt><dd class="font-semibold" style="color: var(--text-primary);">{{ $inr($b['total']) }}@if ($b['reverse_charge']) <span class="font-normal" style="color: var(--text-muted);">· reverse charge</span>@endif</dd></div>
    </dl>
@endif
