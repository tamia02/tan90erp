<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your Tan90 password reset code</title></head>
<body style="margin:0;background:#f2f5fb;font-family:Arial,Helvetica,sans-serif;color:#132039">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:36px 12px;background:#f2f5fb"><tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff;border:1px solid #e3e9f3;border-radius:18px;overflow:hidden">
            <tr><td style="padding:26px 30px;background:linear-gradient(120deg,#151d39,#34358b);color:#fff;font-size:21px;font-weight:bold;letter-spacing:.4px">Tan90 <span style="font-weight:normal;color:#c7cbff">ERP</span>@if(app()->environment('staging')) <span style="font-size:12px;color:#fcd34d">STAGING · stage.tan90.xyz</span>@endif</td></tr>
            <tr><td style="padding:34px 30px 12px"><p style="margin:0 0 12px;color:#505d74;font-size:15px">Hello {{ $recipientName }},</p><h1 style="margin:0 0 12px;font-size:27px;line-height:1.25">Reset your password</h1><p style="margin:0;color:#526176;font-size:15px;line-height:1.7">Enter this one-time code on the Tan90 password reset page. It expires in {{ $expiresInMinutes }} minutes.</p></td></tr>
            <tr><td align="center" style="padding:22px 30px"><div style="display:inline-block;padding:17px 25px;border-radius:14px;background:#f0f1ff;color:#292e7a;font-size:36px;font-weight:bold;letter-spacing:11px;padding-left:36px">{{ $code }}</div></td></tr>
            <tr><td style="padding:0 30px 30px;color:#64748b;font-size:13px;line-height:1.7">This code works once. If you did not request a password reset, ignore this email; your password will remain unchanged. Tan90 staff will never ask you to share this code.</td></tr>
            <tr><td style="padding:14px 30px;background:#f8f9fc;border-top:1px solid #e9edf5;color:#7a8799;font-size:12px">Tan90 ERP · Secure account access</td></tr>
        </table>
    </td></tr></table>
</body>
</html>
