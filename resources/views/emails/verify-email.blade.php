<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Verify your email</title>
<style>
  body { margin:0; padding:0; background:#f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color:#1a1a2e; }
  .wrap { max-width:520px; margin:40px auto; background:#fff; border-radius:12px; box-shadow:0 2px 16px rgba(0,0,0,.08); overflow:hidden; }
  .header { background:linear-gradient(135deg,#2563eb,#1d4ed8); padding:32px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:22px; font-weight:700; }
  .header p { color:rgba(255,255,255,.8); margin:6px 0 0; font-size:14px; }
  .body { padding:36px 40px; }
  .greeting { font-size:16px; margin-bottom:16px; }
  .intro { color:#555; font-size:14px; line-height:1.6; margin-bottom:28px; }
  .code-box { background:#f0f4ff; border:2px dashed #2563eb; border-radius:10px; padding:22px; text-align:center; margin-bottom:28px; }
  .code { font-size:38px; font-weight:900; letter-spacing:10px; color:#2563eb; font-family:monospace; }
  .code-label { font-size:12px; color:#888; margin-top:8px; }
  .note { background:#fefce8; border:1px solid #fde047; border-radius:8px; padding:14px 18px; font-size:13px; color:#713f12; line-height:1.5; margin-bottom:24px; }
  .footer { padding:20px 40px; background:#f9fafb; border-top:1px solid #e5e7eb; font-size:12px; color:#9ca3af; text-align:center; line-height:1.6; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>{{ $appName }}</h1>
    <p>Email Verification</p>
  </div>
  <div class="body">
    <p class="greeting">Hi {{ $userName }},</p>
    <p class="intro">
      Thanks for creating an account. To complete your registration, please enter the verification code below in the app.
    </p>
    <div class="code-box">
      <div class="code">{{ $verificationCode }}</div>
      <div class="code-label">This code expires in 15 minutes</div>
    </div>
    <div class="note">
      If you did not create an account, please ignore this email. No action is required.
    </div>
  </div>
  <div class="footer">
    &copy; {{ date('Y') }} {{ $appName }}. All rights reserved.
  </div>
</div>
</body>
</html>
