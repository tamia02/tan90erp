<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Tan90 sign-in code</title>
    @include('emails.auth.partials.motion-styles')
</head>
<body style="margin:0;padding:0;background:#edf1f7;font-family:Arial,Helvetica,sans-serif;color:#111827">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#edf1f7;padding:34px 12px">
        <tr><td align="center">
            <table class="tan90-mail-card" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#fff;border:1px solid #dce3ed;border-radius:18px;overflow:hidden;box-shadow:0 16px 42px rgba(15,23,42,.11)">
                @include('emails.auth.partials.brand-header')
                <tr><td class="tan90-mail-pad" style="padding:36px 42px 18px">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                        <tr><td style="padding:7px 11px;border-radius:20px;background:#eef2ff;color:#4338ca;font-size:10px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase">Secure sign in</td></tr>
                    </table>
                    <h1 style="margin:17px 0 10px;color:#101a30;font-size:29px;line-height:1.2;letter-spacing:-.5px">Your sign-in code is ready.</h1>
                    <p style="margin:0;color:#526176;font-size:15px;line-height:1.75">Hello {{ $recipientName }}, enter this one-time code to securely access your Tan90 ERP workspace.</p>

                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:25px 0 17px;border:1px solid #dce5f4;border-radius:15px;background:#f7f9ff">
                        <tr><td align="center" style="padding:21px 14px 20px">
                            <p style="margin:0 0 10px;color:#64748b;font-size:10px;font-weight:bold;letter-spacing:1.7px;text-transform:uppercase">One-time verification code</p>
                            <div style="color:#252d72;font-size:37px;font-weight:bold;line-height:1.2;letter-spacing:11px;padding-left:11px">{{ $code }}</div>
                        </td></tr>
                    </table>

                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 18px">
                        <tr><td style="padding:8px 12px;border:1px solid #ccebd7;border-radius:20px;background:#effaf2;color:#166534;font-size:12px;font-weight:bold">Expires in {{ $expiresInMinutes }} minutes</td></tr>
                    </table>
                    <p style="margin:0;color:#526176;font-size:14px;line-height:1.75">This code works once. For your security, Tan90 staff will never ask you to share it.</p>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:23px;border-left:3px solid #818cf8;background:#f8f9fc">
                        <tr><td style="padding:12px 15px;color:#64748b;font-size:12px;line-height:1.65">Didn't request a code? You can ignore this message. Your account remains secure.</td></tr>
                    </table>
                </td></tr>
                @include('emails.auth.partials.footer')
            </table>
        </td></tr>
    </table>
</body>
</html>
