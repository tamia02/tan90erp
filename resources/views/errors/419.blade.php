<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Session expired · Tan90 ERP</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:#15243b;background:#f5f8fc}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px}
        main{width:min(100%,560px);background:#fff;border:1px solid #dce5ef;border-radius:20px;padding:32px;box-shadow:0 18px 48px #17315212}
        .brand{font-size:12px;font-weight:800;letter-spacing:.12em;color:#6158d8;text-transform:uppercase}
        h1{font-size:28px;margin:12px 0 8px}p{color:#516078;line-height:1.6;margin:0 0 20px}
        .actions{display:flex;flex-wrap:wrap;gap:12px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;border:1px solid #d5deea;border-radius:10px;color:#24344e;text-decoration:none;font-weight:700}
        .primary{background:#147b55;border-color:#147b55;color:#fff}.hint{font-size:13px;margin-top:18px;margin-bottom:0}
        @media(max-width:480px){main{padding:24px}h1{font-size:24px}.actions>*{width:100%}}
    </style>
</head>
<body>
    <main role="main" aria-labelledby="expired-title">
        <div class="brand">Tan90 ERP · Secure access</div>
        <h1 id="expired-title">Your session has expired</h1>
        <p>Your request was not submitted. Sign in again, then reopen the page and retry. This protects your account when a form has been left open for too long.</p>
        <div class="actions">
            <a class="button primary" href="{{ route('login') }}">Sign in again</a>
            <a class="button" href="{{ url('/') }}">Go to home</a>
        </div>
        <p class="hint">If this keeps happening, refresh the page before starting a long form and contact your administrator.</p>
    </main>
</body>
</html>
