@props(['accent' => '#3c3c3c', 'titleColor' => '#111'])
{{-- Shared look of the Zoho Books documents: logo + company left, big title right, dark item header, light rules, page number footer. --}}
<style>
    @page { margin: 34px 40px 46px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .company { font-size: 10.5px; font-weight: bold; color: #222; }
    .doc-title { font-size: 26px; color: {{ $titleColor }}; text-align: right; line-height: 1.1; }
    .doc-number { font-size: 10px; font-weight: bold; text-align: right; color: #333; }
    .label { font-size: 10px; color: #444; }
    .strong { font-weight: bold; color: #222; }
    .meta td { padding: 4px 0; font-size: 9.5px; }
    .meta .k { text-align: right; color: #555; padding-right: 12px; }
    .meta .v { text-align: right; width: 120px; }
    .items th { background: {{ $accent }}; color: #fff; font-weight: normal; padding: 7px 8px; text-align: left; font-size: 9px; }
    .items td { padding: 9px 8px; border-bottom: 1px solid #ccc; }
    .items th.r, .r { text-align: right; }
    .items th.c, .c { text-align: center; }
    .muted { color: #777; font-size: 7.5px; }
    .totals td { padding: 6px 8px; }
    .grand { font-weight: bold; font-size: 10px; color: #222; }
    .band { background: #f2f2f2; }
    .section { font-size: 10px; color: #333; margin: 16px 0 4px; }
    .small { font-size: 8px; line-height: 1.45; }
    .footer { position: fixed; bottom: -28px; left: 0; right: 0; border-top: 1px solid #bbb; text-align: right; color: #8a7fc8; font-size: 7px; padding-top: 4px; }
    .footer .page:after { content: counter(page); }
</style>
