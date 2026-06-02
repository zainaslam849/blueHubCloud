<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>New user registered</title>
<style>
  body { margin:0; padding:0; background:#f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color:#1a1a2e; }
  .wrap { max-width:520px; margin:40px auto; background:#fff; border-radius:12px; box-shadow:0 2px 16px rgba(0,0,0,.08); overflow:hidden; }
  .header { background:linear-gradient(135deg,#0f172a,#1e40af); padding:32px 40px; text-align:center; }
  .header h1 { color:#fff; margin:0; font-size:22px; font-weight:700; }
  .header p { color:rgba(255,255,255,.7); margin:6px 0 0; font-size:14px; }
  .body { padding:36px 40px; }
  .greeting { font-size:16px; margin-bottom:16px; }
  .intro { color:#555; font-size:14px; line-height:1.6; margin-bottom:24px; }
  .info-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:20px 24px; margin-bottom:28px; }
  .info-row { display:flex; justify-content:space-between; font-size:14px; padding:6px 0; border-bottom:1px solid #f1f5f9; }
  .info-row:last-child { border-bottom:none; }
  .info-label { color:#64748b; font-weight:500; }
  .info-value { color:#1e293b; font-weight:600; }
  .cta { text-align:center; margin-bottom:24px; }
  .btn { display:inline-block; background:#2563eb; color:#fff; text-decoration:none; padding:13px 28px; border-radius:8px; font-weight:600; font-size:14px; }
  .footer { padding:20px 40px; background:#f9fafb; border-top:1px solid #e5e7eb; font-size:12px; color:#9ca3af; text-align:center; line-height:1.6; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>{{ $appName }}</h1>
    <p>Admin Notification</p>
  </div>
  <div class="body">
    <p class="greeting">Hello Admin,</p>
    <p class="intro">A new user has registered and verified their email address on <strong>{{ $appName }}</strong>.</p>
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">Name</span>
        <span class="info-value">{{ $userName }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Email</span>
        <span class="info-value">{{ $userEmail }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">Registered</span>
        <span class="info-value">{{ now()->format('M d, Y H:i') }}</span>
      </div>
    </div>
    <div class="cta">
      <a class="btn" href="{{ $adminUrl }}">View in Admin Panel</a>
    </div>
  </div>
  <div class="footer">
    &copy; {{ date('Y') }} {{ $appName }}. This is an automated notification.
  </div>
</div>
</body>
</html>
