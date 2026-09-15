<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $entry->gate_no }}</title>
    <style>
        /* dompdf has limited CSS support (no custom properties, no flex/grid) --
           deliberately plain, table-based layout rather than reusing the app's
           var(--token) design system, which dompdf can't render at all. */
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .eyebrow { font-size: 10px; color: #4f46e5; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 6px; }
        .meta { color: #6b7280; font-size: 10px; margin: 0 0 18px; }
        .section-title { font-size: 13px; font-weight: bold; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #d1d5db; }
        table.fields { width: 100%; border-collapse: collapse; }
        table.fields td { width: 25%; padding: 5px 8px; vertical-align: top; border: 1px solid #e5e7eb; }
        table.fields td .label { display: block; font-size: 9px; color: #6b7280; text-transform: uppercase; }
        table.fields td .value { display: block; margin-top: 2px; font-size: 11px; word-wrap: break-word; }
        .issue { border: 1px solid #e5e7eb; padding: 6px 8px; margin-bottom: 6px; }
        .issue.open { background: #fef2f2; border-color: #fecaca; }
        .issue-title { font-weight: bold; }
        .issue-meta { color: #6b7280; font-size: 9px; text-transform: capitalize; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #d1d5db; color: #9ca3af; font-size: 9px; }
    </style>
</head>
<body>
    <div class="eyebrow">Tan90 ERP &middot; Gate Entry Record</div>
    <h1>{{ $entry->gate_no }}</h1>
    <div class="meta">Generated {{ now()->format('d M Y, H:i') }} by {{ auth()->user()->name }}</div>

    <div class="section-title">Gate entry — submitted details</div>
    <table class="fields">
        <tr>
            @foreach ($fields as $i => $f)
                <td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td>
                @if (($i + 1) % 4 === 0 && ! $loop->last)
                    </tr><tr>
                @endif
            @endforeach
        </tr>
    </table>

    @if ($issues->isNotEmpty())
        <div class="section-title">Validation issues</div>
        @foreach ($issues as $issue)
            <div class="issue {{ $issue->status === 'open' ? 'open' : '' }}">
                <span class="issue-title">{{ $issue->title }}</span>
                <span class="issue-meta"> &middot; {{ $issue->severity }} &middot; {{ $issue->status }}</span>
                <div>{{ $issue->description }}</div>
            </div>
        @endforeach
    @endif

    @if (! empty($qcFields))
        <div class="section-title">QC result</div>
        <table class="fields">
            <tr>
                @foreach ($qcFields as $i => $f)
                    <td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td>
                    @if (($i + 1) % 4 === 0 && ! $loop->last)
                        </tr><tr>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif

    @if (! empty($grnFields))
        <div class="section-title">GRN record</div>
        <table class="fields">
            <tr>
                @foreach ($grnFields as $i => $f)
                    <td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td>
                    @if (($i + 1) % 4 === 0 && ! $loop->last)
                        </tr><tr>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif

    @if (! empty($financeFields))
        <div class="section-title">Finance record</div>
        <table class="fields">
            <tr>
                @foreach ($financeFields as $i => $f)
                    <td><span class="label">{{ $f['label'] }}</span><span class="value">{{ $f['value'] }}</span></td>
                    @if (($i + 1) % 4 === 0 && ! $loop->last)
                        </tr><tr>
                    @endif
                @endforeach
            </tr>
        </table>
    @endif

    <div class="footer">Tan90 ERP &middot; {{ $entry->gate_no }} &middot; Confidential internal record</div>
</body>
</html>
