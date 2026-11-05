{{-- "Authorized Signature" like Zoho; the company stamp / signature image is printed above it when public/images/company-stamp.png exists. --}}
<div style="margin-top:40px; page-break-inside: avoid;">
    @if (is_file(public_path('images/company-stamp.png')))<img src="{{ public_path('images/company-stamp.png') }}" style="height:110px;"><br>@endif
    <span style="font-size:10px;">Authorized Signature</span> <span style="display:inline-block; width:160px; border-bottom:1px solid #222;">&nbsp;</span>
</div>
