<tr>
    <td bgcolor="#101a30" style="background:#101a30;background-image:linear-gradient(125deg,#101a30 0%,#192b4a 62%,#183b42 100%);padding:26px 30px 20px">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
            <tr>
                <td align="left" valign="middle" style="width:56%">
                    <div style="display:inline-block;background:#fff;border-radius:11px;padding:7px 10px;line-height:0">
                        <img src="{{ $message->embed(public_path('images/tan90-logo.png')) }}" alt="Tan90" width="112" style="display:block;width:112px;max-width:100%;height:auto">
                    </div>
                </td>
                <td align="right" valign="middle" style="color:#fff;font-family:Arial,Helvetica,sans-serif">
                    <span style="display:block;color:#bbf7d0;font-size:10px;font-weight:bold;letter-spacing:2px">TAN90 ERP</span>
                    <span style="display:block;margin-top:5px;color:#dbeafe;font-size:11px;letter-spacing:.7px">{{ app()->environment('staging') ? 'STAGING · stage.tan90.xyz' : 'SECURE WORKSPACE' }}</span>
                </td>
            </tr>
            <tr><td colspan="2" style="padding-top:22px">
                <div style="height:4px;background:#2a3c58;border-radius:4px;overflow:hidden">
                    <div class="tan90-shimmer" style="width:48px;height:4px;background:linear-gradient(90deg,#35c46a,#82e4aa,#8b83ff);border-radius:4px"></div>
                </div>
            </td></tr>
        </table>
    </td>
</tr>
