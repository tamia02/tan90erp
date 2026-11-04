<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tan90 ERP email preview</title>
    @include('emails.auth.partials.motion-styles')
</head>
<body style="margin:0;padding:0;background:#edf1f7;font-family:Arial,Helvetica,sans-serif;color:#111827">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#edf1f7;padding:34px 12px">
        <tr><td align="center">
            <table class="tan90-mail-card" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#fff;border:1px solid #dce3ed;border-radius:18px;overflow:hidden;box-shadow:0 16px 42px rgba(15,23,42,.11)">
                @include('emails.auth.partials.brand-header')
                <tr><td class="tan90-mail-pad" style="padding:36px 42px 18px">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                        <tr><td style="padding:7px 11px;border-radius:20px;background:#eef2ff;color:#4338ca;font-size:10px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase">Tan90 ERP · Email preview</td></tr>
                    </table>
                    <h1 style="margin:17px 0 10px;color:#101a30;font-size:29px;line-height:1.2;letter-spacing:-.5px">A more polished way to sign in.</h1>
                    <p style="margin:0;color:#526176;font-size:15px;line-height:1.75">This preview shows the updated Tan90 email design: a dark branded header, clearer content panels, a bright action area, and a subtle animated accent.</p>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:25px 0 18px;border:1px solid #dce5f4;border-radius:15px;background:#f7f9ff">
                        <tr><td align="center" style="padding:20px 14px">
                            <p style="margin:0 0 8px;color:#64748b;font-size:10px;font-weight:bold;letter-spacing:1.7px;text-transform:uppercase">Your workspace, in focus</p>
                            <strong style="color:#252d72;font-size:21px;line-height:1.4">Clear. Secure. Tan90.</strong>
                        </td></tr>
                    </table>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-left:3px solid #818cf8;background:#f8f9fc">
                        <tr><td style="padding:12px 15px;color:#64748b;font-size:12px;line-height:1.65">This is an email design and delivery preview only. It contains no sign-in code or password-reset link.</td></tr>
                    </table>
                </td></tr>
                @include('emails.auth.partials.footer')
            </table>
        </td></tr>
    </table>
</body>
</html>
