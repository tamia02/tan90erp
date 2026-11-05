<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your Tan90 password</title>
    @include('emails.auth.partials.motion-styles')
</head>
<body style="margin:0;padding:0;background:#edf1f7;font-family:Arial,Helvetica,sans-serif;color:#111827">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#edf1f7;padding:34px 12px">
        <tr><td align="center">
            <table class="tan90-mail-card" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#fff;border:1px solid #dce3ed;border-radius:18px;overflow:hidden;box-shadow:0 16px 42px rgba(15,23,42,.11)">
                @include('emails.auth.partials.brand-header')
                <tr><td class="tan90-mail-pad" style="padding:36px 42px 18px">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                        <tr><td style="padding:7px 11px;border-radius:20px;background:#fff7ed;color:#c2410c;font-size:10px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase">Account recovery</td></tr>
                    </table>
                    <h1 style="margin:17px 0 10px;color:#101a30;font-size:29px;line-height:1.2;letter-spacing:-.5px">Let’s get you back in.</h1>
                    <p style="margin:0;color:#526176;font-size:15px;line-height:1.75">Hello {{ $recipientName }}, we received a request to reset your Tan90 ERP password. Use the secure button below to choose a new one.</p>

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:25px auto 22px">
                        <tr><td align="center" bgcolor="#4f46e5" style="border-radius:10px;background:#4f46e5;background-image:linear-gradient(110deg,#4338ca,#4f46e5 58%,#138c31)">
                            <a href="{{ $resetUrl }}" style="display:inline-block;padding:15px 27px;color:#fff;font-size:14px;font-weight:bold;letter-spacing:.2px;text-decoration:none">Reset my password&nbsp; →</a>
                        </td></tr>
                    </table>

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 19px">
                        <tr><td style="padding:8px 12px;border:1px solid #ccebd7;border-radius:20px;background:#effaf2;color:#166534;font-size:12px;font-weight:bold">Link expires in {{ $expiresInMinutes }} minutes · One use only</td></tr>
                    </table>

                    <p style="margin:0;color:#526176;font-size:14px;line-height:1.7">If the button doesn’t open, copy this link into your browser:</p>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:9px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc">
                        <tr><td style="padding:12px;color:#4338ca;font-size:12px;line-height:1.6;word-break:break-all"><a href="{{ $resetUrl }}" style="color:#4338ca;text-decoration:underline;word-break:break-all">{{ $resetUrl }}</a></td></tr>
                    </table>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:22px;border-left:3px solid #fb923c;background:#fffaf5">
                        <tr><td style="padding:12px 15px;color:#7c6a5d;font-size:12px;line-height:1.65">If you didn’t request a password reset, ignore this email. Your password won’t change unless you use this link.</td></tr>
                    </table>
                </td></tr>
                @include('emails.auth.partials.footer')
            </table>
        </td></tr>
    </table>
</body>
</html>
