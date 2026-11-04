@props(['issuer', 'title', 'number' => null])
{{-- Logo + issuing company on the left, document title + number (and anything in the slot) on the right. --}}
<table>
    <tr>
        <td style="width:58%;">
            <img src="{{ public_path('images/tan90-logo.png') }}" style="height:80px;" alt="Tan90"><br>
            <div class="company" style="margin-top:4px;">{{ $issuer['name'] }}</div>
            @foreach ($issuer['lines'] as $line){{ $line }}<br>@endforeach
            @if ($issuer['gstin'])GSTIN {{ $issuer['gstin'] }}@endif
        </td>
        <td style="width:42%;">
            <div class="doc-title">{{ $title }}</div>
            @if ($number)<div class="doc-number">{{ $number }}</div>@endif
            {{ $slot }}
        </td>
    </tr>
</table>
<div class="footer"><span class="page"></span></div>
