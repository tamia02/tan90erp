<table border="1">
    <thead><tr>@foreach($columns as $column)<th>{{ $labels[$column] }}</th>@endforeach</tr></thead>
    <tbody>@foreach($rows as $row)<tr>@foreach($columns as $column)<td>{{ $column === 'last_updated' ? ($row[$column]?->format('Y-m-d H:i:s') ?? '') : ($column === 'available_value' ? number_format($row[$column], 2, '.', '') : ($column === 'unit_price' && $row[$column] !== null ? number_format($row[$column], 4, '.', '') : $row[$column])) }}</td>@endforeach</tr>@endforeach</tbody>
</table>
