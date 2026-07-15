@php
    $tone = match ($value) {
        'approved', 'active', 'released', 'mrp_ready', 'passed', 'completed' => 'good',
        'rejected', 'failed' => 'critical',
        'review', 'pending', 'technical_review', 'qa_review', 'cost_review', 'plant_trial' => 'warning',
        default => 'muted',
    };
    $styles = [
        'good' => 'background: var(--status-good-bg); color: var(--status-good);',
        'critical' => 'background: var(--status-critical-bg); color: var(--status-critical);',
        'warning' => 'background: var(--status-warning-bg); color: var(--status-warning);',
        'muted' => 'background: var(--surface-2); color: var(--text-muted);',
    ];
@endphp
<span class="inline-flex px-2 py-0.5 rounded text-xs font-medium" style="{{ $styles[$tone] }}">{{ Str::title(str_replace('_', ' ', $value ?? 'draft')) }}</span>
