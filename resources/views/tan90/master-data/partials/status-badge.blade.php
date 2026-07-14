@php($clean = Str::slug($value ?? 'active'))
<span class="status status-{{ $clean }}">{{ Str::title(str_replace(['-', '_'], ' ', $value ?? 'active')) }}</span>
