@php($tone = match($value) {
    'approved', 'active', 'released', 'mrp_ready', 'passed', 'completed' => 'success',
    'rejected', 'failed' => 'danger',
    'review', 'pending', 'technical_review', 'qa_review', 'cost_review', 'plant_trial' => 'warning',
    default => 'muted',
})
<span class="badge badge-{{ $tone }}">{{ Str::title(str_replace('_', ' ', $value ?? 'draft')) }}</span>
