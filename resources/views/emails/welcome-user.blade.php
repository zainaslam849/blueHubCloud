<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Welcome to {{ $appName }}</title>
<style>
  * { box-sizing: border-box; }
  body { margin:0; padding:0; background:#f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color:#1a1a2e; }
  .wrap { max-width:560px; margin:40px auto; background:#fff; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,.09); overflow:hidden; }
  .header { background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%); padding:36px 40px; text-align:center; }
  .header__logo { font-size:24px; font-weight:800; color:#fff; letter-spacing:-.5px; margin-bottom:6px; }
  .header__sub { color:rgba(255,255,255,.6); font-size:13px; }
  .body { padding:36px 40px; }
  .greeting { font-size:18px; font-weight:700; color:#0f172a; margin:0 0 10px; }
  .intro { color:#475569; font-size:14px; line-height:1.65; margin:0 0 28px; }
  .cred-card {
    background:#f8fafc; border:1.5px solid #e2e8f0;
    border-radius:12px; padding:6px 0; margin-bottom:28px;
    overflow:hidden;
  }
  .cred-head {
    padding:12px 20px 10px; font-size:11px; font-weight:700;
    text-transform:uppercase; letter-spacing:.08em; color:#64748b;
    border-bottom:1px solid #e2e8f0; background:#f1f5f9;
  }
  .cred-row { display:flex; align-items:center; padding:14px 20px; border-bottom:1px solid #f1f5f9; gap:12px; }
  .cred-row:last-child { border-bottom:none; }
  .cred-icon { width:32px; height:32px; border-radius:8px; background:#e0e7ff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .cred-icon svg { width:16px; height:16px; }
  .cred-label { font-size:12px; color:#64748b; margin-bottom:2px; font-weight:500; }
  .cred-value { font-size:14px; color:#0f172a; font-weight:700; font-family: 'Courier New', monospace; word-break:break-all; }
  .cred-value--pass { letter-spacing:.05em; }
  .warn-box {
    background:#fffbeb; border:1px solid #fde68a; border-radius:10px;
    padding:14px 18px; margin-bottom:28px;
    display:flex; gap:12px; align-items:flex-start;
  }
  .warn-box__icon { font-size:18px; flex-shrink:0; margin-top:1px; }
  .warn-box__text { font-size:13px; color:#92400e; line-height:1.55; }
  .warn-box__text strong { color:#78350f; }
  .cta { text-align:center; margin-bottom:28px; }
  .btn {
    display:inline-block; background:linear-gradient(135deg,#3b82f6,#2563eb);
    color:#fff; text-decoration:none; padding:14px 34px;
    border-radius:10px; font-weight:700; font-size:15px;
    box-shadow:0 4px 14px rgba(37,99,235,.35); letter-spacing:.01em;
  }
  .login-url { text-align:center; font-size:12px; color:#94a3b8; margin-bottom:24px; }
  .login-url a { color:#3b82f6; text-decoration:none; word-break:break-all; }
  .footer { padding:20px 40px; background:#f9fafb; border-top:1px solid #e5e7eb; font-size:12px; color:#9ca3af; text-align:center; line-height:1.6; }
</style>
</head>
<body>
<div class="wrap">

  <div class="header">
    <div class="header__logo">{{ $appName }}</div>
    <div class="header__sub">Your account is ready to use</div>
  </div>

  <div class="body">
    <p class="greeting">Hi {{ $userName }},</p>
    <p class="intro">
      An account has been created for you on <strong>{{ $appName }}</strong>. You can now sign in and access your dashboard using the credentials below.
    </p>

    <div class="cred-card">
      <div class="cred-head">Your Login Credentials</div>
      <div class="cred-row">
        <div class="cred-icon">
          <svg viewBox="0 0 20 20" fill="none" style="color:#4f46e5">
            <path d="M3 8a5 5 0 1 1 10 0A5 5 0 0 1 3 8Z" stroke="currentColor" stroke-width="1.5"/>
            <path d="M10.5 13.5 13 16m0 0 2.5 2.5M13 16l2.5-2.5M13 16l-2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
        </div>
        <div>
          <div class="cred-label">Email address</div>
          <div class="cred-value">{{ $userEmail }}</div>
        </div>
      </div>
      <div class="cred-row">
        <div class="cred-icon">
          <svg viewBox="0 0 20 20" fill="none" style="color:#4f46e5">
            <rect x="4" y="9" width="12" height="9" rx="2" stroke="currentColor" stroke-width="1.5"/>
            <path d="M7 9V6a3 3 0 1 1 6 0v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            <circle cx="10" cy="13.5" r="1.2" fill="currentColor"/>
          </svg>
        </div>
        <div>
          <div class="cred-label">Password</div>
          <div class="cred-value cred-value--pass">{{ $plainPassword }}</div>
        </div>
      </div>
      <div class="cred-row">
        <div class="cred-icon">
          <svg viewBox="0 0 20 20" fill="none" style="color:#4f46e5">
            <path d="M10 2a8 8 0 1 0 0 16A8 8 0 0 0 10 2Z" stroke="currentColor" stroke-width="1.5"/>
            <path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
        </div>
        <div>
          <div class="cred-label">Login URL</div>
          <div class="cred-value"><a href="{{ $loginUrl }}" style="color:#2563eb;text-decoration:none;">{{ $loginUrl }}</a></div>
        </div>
      </div>
    </div>

    <div class="warn-box">
      <div class="warn-box__icon">⚠️</div>
      <div class="warn-box__text">
        <strong>Keep these credentials safe.</strong>
        We recommend changing your password after your first login. Do not share this email with anyone.
      </div>
    </div>

    <div class="cta">
      <a class="btn" href="{{ $loginUrl }}">Sign in to your account</a>
    </div>

    <div class="login-url">
      Or copy this link: <a href="{{ $loginUrl }}">{{ $loginUrl }}</a>
    </div>
  </div>

  <div class="footer">
    &copy; {{ date('Y') }} {{ $appName }}. You received this because an admin created an account for you.<br>
    If you did not expect this email, please contact support.
  </div>

</div>
</body>
</html>
