<!doctype html>
<html lang="en">
<body style="margin:0;background:#f4f7fb;color:#1f2937;font-family:Arial,sans-serif;padding:28px">
    <main style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px">
        <p style="margin:0 0 12px;color:#4f46e5;font-size:12px;font-weight:700;letter-spacing:.08em">TAN90 ERP · {{ app()->environment('staging') ? 'STAGING · stage.tan90.xyz' : 'WORKFLOW UPDATE' }}</p>
        <h1 style="font-size:22px;line-height:1.3;margin:0 0 18px">A workflow item needs your attention</h1>
        <p style="font-size:15px;line-height:1.6;margin:0 0 24px">{{ $summary }}</p>
        <a href="{{ $actionUrl }}" style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:600">Open Tan90 ERP{{ app()->environment('staging') ? ' STAGING' : '' }}</a>
        <p style="color:#6b7280;font-size:12px;margin:24px 0 0">This message was sent to the active owner of the next workflow module.</p>
    </main>
</body>
</html>
