<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width" />
<title></title>
</head>
<!--
  rscd-community.org transactional mail, dressed like the site: black plate,
  #382418 brown panel border, parchment #e0d8c0 headings, #90c040 green
  links, Arial 13px white text, and the grey outset button the site's .btn
  wears. Tables and inline styles because mail clients ignore stylesheets;
  no images because mail clients block them by default and the mail should
  read fine without them.
-->
<body bgcolor="#000000" style="margin:0;padding:0;background:#000000;">
<div style="display:none;max-height:0px;overflow:hidden;">
    A password reset was requested for your account. The link expires in 15 minutes.
</div>
<table cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#000000" style="background:#000000;">
<tr>
    <td align="center" style="padding:24px 10px;">
        <table cellpadding="0" cellspacing="0" border="0" width="600" style="width:600px;max-width:600px;">
        <!-- MASTHEAD -->
        <tr>
            <td align="center" style="padding:0 0 14px 0;">
                <a href="%%app.base_url%%" target="_blank" style="font-family:Arial,Helvetica,sans-serif;font-size:22px;font-weight:bold;color:#e0d8c0;text-decoration:none;letter-spacing:1px;">%%app.name%%</a>
            </td>
        </tr>
        <!-- PANEL -->
        <tr>
            <td style="border:2px solid #382418;background:#000000;padding:18px 22px;">
                <h1 style="margin:0 0 12px 0;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:bold;color:#e0d8c0;">
                    Password reset requested
                </h1>
                <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;color:#ffffff;">
                    A password reset has been requested for your account. To choose a new password, press the button below. Reset requests expire automatically after 15 minutes.
                </p>
                <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;color:#ffffff;">
                    If you didn't ask for this, it is recommended that you reset your password anyway as a precaution.
                </p>
                <!-- BUTTON -->
                <table cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 8px 0;">
                <tr>
                    <td style="border:2px outset #f0f0f0;background:#d4d0c8;">
                        <a href="%%reset_url%%" target="_blank" style="display:block;padding:5px 18px;font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:bold;color:#000000;text-decoration:none;">Reset password</a>
                    </td>
                </tr>
                </table>
                <p style="margin:0 0 10px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:17px;color:#b0a890;">
                    Or copy this link into your browser:<br />
                    <a href="%%reset_url%%" target="_blank" style="color:#90c040;text-decoration:none;">%%reset_url%%</a>
                </p>
                <p style="margin:14px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:19px;color:#ffffff;">
                    Reply to this email or write to <a href="mailto:%%app.supportEmailAddress%%" style="color:#90c040;text-decoration:none;">%%app.supportEmailAddress%%</a> for assistance.
                </p>
            </td>
        </tr>
        <!-- FOOTER -->
        <tr>
            <td align="center" style="padding:14px 0 0 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:18px;color:#b0a890;">
                <a href="%%app.base_url%%" target="_blank" style="color:#90c040;text-decoration:none;">%%app.root%%</a>
                &nbsp;&bull;&nbsp;
                <a href="mailto:%%app.supportEmailAddress%%" style="color:#90c040;text-decoration:none;">%%app.supportEmailAddress%%</a>
                <br />
                &copy; %%app.name%%
            </td>
        </tr>
        </table>
    </td>
</tr>
</table>
</body>
</html>
