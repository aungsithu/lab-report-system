<?php
if (session_status() === PHP_SESSION_NONE) {
    $remember = isset($_POST['remember_me']) || isset($_COOKIE['remember_me']);
    $cookie_lifetime = $remember ? (86400 * 7) : 28800;
    ini_set('session.gc_maxlifetime', $cookie_lifetime);
    ini_set('session.cookie_lifetime', $cookie_lifetime);
    session_set_cookie_params([
        'lifetime' => $cookie_lifetime,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit; }
require 'includes/db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $stmt = $conn->prepare("SELECT u.*, c.name AS clinic_name FROM users u LEFT JOIN clinics c ON u.clinic_id = c.id WHERE u.username = ? OR u.email = ?");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['role']      = $user['role'];
        if ($user['role'] === 'clinic_user') {
            $_SESSION['clinic_id']   = $user['clinic_id'];
            $_SESSION['clinic_name'] = $user['clinic_name'] ?? '';
        }
        if (isset($_POST['remember_me'])) {
            setcookie('remember_me', '1', time() + (86400 * 7), '/', '', isset($_SERVER['HTTPS']), true);
        }
        $now = date('Y-m-d H:i:s');
        $update = $conn->prepare("UPDATE users SET last_login = ? WHERE id = ?");
        $update->bind_param("si", $now, $user['id']);
        $update->execute();
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Celtac Lab · Login</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      min-height: 100vh;
      font-family: 'Plus Jakarta Sans', sans-serif;
      display: flex; align-items: center; justify-content: center;
      padding: 24px;
      background: #eef2ff;
      background-image:
        radial-gradient(ellipse 70% 50% at 10% 15%, rgba(99,102,241,.18), transparent 55%),
        radial-gradient(ellipse 60% 50% at 90% 80%, rgba(20,184,166,.13), transparent 55%),
        radial-gradient(ellipse 40% 40% at 50% 50%, rgba(59,130,246,.08), transparent 60%);
    }
    body::before {
      content: '';
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background-image: radial-gradient(circle, rgba(99,102,241,.12) 1px, transparent 1px);
      background-size: 30px 30px;
    }
    .wrap { position: relative; z-index: 1; width: 100%; max-width: 420px; }
    .card {
      background: #fff;
      border-radius: 24px;
      box-shadow:
        0 0 0 1px rgba(99,102,241,.09),
        0 2px 4px rgba(0,0,0,.03),
        0 20px 40px rgba(99,102,241,.13),
        0 40px 70px rgba(0,0,0,.07);
      overflow: hidden;
    }
    .card-accent { height: 4px; background: linear-gradient(90deg, #4f46e5, #818cf8, #0ea5e9, #14b8a6); }
    .card-body { padding: 34px 34px 28px; }
    .brand { display: flex; align-items: center; gap: 13px; margin-bottom: 30px; }
    .brand-icon {
      width: 48px; height: 48px; border-radius: 13px; flex-shrink: 0;
      background: linear-gradient(135deg, #eff6ff, #e0e7ff);
      border: 1.5px solid rgba(99,102,241,.15);
      display: flex; align-items: center; justify-content: center; padding: 7px;
    }
    .brand-icon img { width: 100%; height: 100%; object-fit: contain; }
    .brand-name { font-size: 16.5px; font-weight: 800; color: #0f172a; letter-spacing: -.2px; }
    .brand-sub  { font-size: 12px; color: #94a3b8; margin-top: 2px; }
    .login-title { font-size: 21px; font-weight: 800; color: #0f172a; letter-spacing: -.3px; margin-bottom: 4px; }
    .login-sub   { font-size: 13px; color: #94a3b8; margin-bottom: 26px; }
    .login-error {
      background: #fef2f2; border: 1px solid #fecaca; border-radius: 11px;
      padding: 11px 14px; display: flex; align-items: center; gap: 9px;
      color: #dc2626; font-size: 13.5px; font-weight: 500; margin-bottom: 20px;
      animation: shake .3s ease;
    }
    @keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-5px)} 75%{transform:translateX(5px)} }
    .field { margin-bottom: 17px; }
    .field label {
      display: block; font-size: 11.5px; font-weight: 700;
      text-transform: uppercase; letter-spacing: .07em; color: #64748b; margin-bottom: 7px;
    }
    .field input {
      width: 100%; background: #f8fafc; border: 1.5px solid #e2e8f0;
      border-radius: 11px; padding: 12px 14px;
      font-size: 14px; color: #0f172a;
      font-family: 'Plus Jakarta Sans', sans-serif;
      outline: none; transition: border-color .18s, box-shadow .18s, background .18s;
    }
    .field input::placeholder { color: #cbd5e1; }
    .field input:focus {
      border-color: #6366f1; background: #fff;
      box-shadow: 0 0 0 4px rgba(99,102,241,.10);
    }
    .pw-wrap { position: relative; }
    .pw-wrap input { padding-right: 46px; }
    .pw-toggle {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: #94a3b8; padding: 4px; line-height: 0; transition: color .15s;
    }
    .pw-toggle:hover { color: #475569; }
    .bottom-row {
      display: flex; align-items: center; justify-content: space-between;
      margin: 8px 0 22px;
    }
    .remember { display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .remember input[type="checkbox"] { width: 16px; height: 16px; accent-color: #6366f1; cursor: pointer; }
    .remember span { font-size: 13px; color: #64748b; }
    .secure { font-size: 12px; color: #14b8a6; display: flex; align-items: center; gap: 5px; font-weight: 600; }
    .btn-login {
      width: 100%;
      background: linear-gradient(135deg, #4f46e5, #6366f1 50%, #0ea5e9);
      border: none; border-radius: 11px; padding: 13px;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 15px; font-weight: 700; color: #fff; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      transition: transform .15s, box-shadow .15s;
      box-shadow: 0 6px 20px rgba(99,102,241,.38);
    }
    .btn-login:hover { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(99,102,241,.48); }
    .btn-login:active { transform: translateY(0); }
    .card-footer {
      border-top: 1px solid #f1f5f9; padding: 13px 34px;
      text-align: center; font-size: 12px; color: #cbd5e1;
    }
    .copy { text-align: center; margin-top: 18px; font-size: 12px; color: #94a3b8; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="card-accent"></div>
    <div class="card-body">
      <div class="brand">
        <div class="brand-icon"><img src="assets/celtaclogo.png" alt="Celtac Lab"></div>
        <div>
          <div class="brand-name">Celtac Lab System</div>
          <div class="brand-sub">Lab Information System</div>
        </div>
      </div>
      <div class="login-title">Welcome back</div>
      <div class="login-sub">Sign in to your account to continue</div>
      <?php if ($error): ?>
      <div class="login-error">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>
      <form method="POST" autocomplete="off">
        <div class="field">
          <label>Username or Email</label>
          <input type="text" name="username" placeholder="Enter your username or email" required>
        </div>
        <div class="field">
          <label>Password</label>
          <div class="pw-wrap">
            <input type="password" name="password" id="password" placeholder="Enter your password" required>
            <button type="button" class="pw-toggle" id="togglePw">
              <svg id="eyeIcon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>
        <div class="bottom-row">
          <label class="remember">
            <input type="checkbox" name="remember_me" id="remember_me">
            <span>Remember me (7 days)</span>
          </label>
          <div class="secure">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Secure
          </div>
        </div>
        <button type="submit" class="btn-login">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
            <polyline points="10 17 15 12 10 7"/>
            <line x1="15" y1="12" x2="3" y2="12"/>
          </svg>
          Sign In
        </button>
      </form>
    </div>
    <div class="card-footer">By signing in you agree to internal system policies.</div>
  </div>
  <div class="copy">&copy; <?= date('Y') ?> Celtac Lab. All rights reserved.</div>
</div>
<script>
(function(){
  const btn=document.getElementById('togglePw'),pw=document.getElementById('password'),icon=document.getElementById('eyeIcon');
  if(!btn||!pw)return;
  const open=`<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
  const off=`<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`;
  btn.addEventListener('click',()=>{const h=pw.type==='password';pw.type=h?'text':'password';icon.innerHTML=h?off:open;});
})();
</script>
</body>
</html>