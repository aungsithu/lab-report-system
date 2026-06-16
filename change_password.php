<?php
require 'includes/db.php';
include 'includes/header.php';
require 'includes/session_check.php';

$user_id = $_SESSION['user_id'];
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res->fetch_assoc();

    if (!$user || !password_verify($current, $user['password'])) {
        $error = "Current password is incorrect.";
    } elseif ($new !== $confirm) {
        $error = "New passwords do not match.";
    } elseif (strlen($new) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $user_id);
        $stmt->execute();
        $success = "Password changed successfully!";
    }
}
?>

<style>
.cp-wrap {
  max-width: 520px;
  margin: 32px auto;
}

/* Hero */
.cp-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: 14px;
  padding: 22px 28px;
  color: #fff;
  display: flex; align-items: center; gap: 16px;
  position: relative; overflow: hidden;
  margin-bottom: 20px;
}
.cp-hero::before { content:''; position:absolute; right:-40px; top:-40px; width:180px; height:180px; border-radius:50%; background:rgba(255,255,255,.05); }
.cp-hero::after  { content:''; position:absolute; right:60px; bottom:-50px; width:130px; height:130px; border-radius:50%; background:rgba(255,255,255,.04); }
.cp-hero-icon {
  width: 48px; height: 48px; border-radius: 13px; flex-shrink: 0;
  background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.20);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; position: relative; z-index: 1;
}
.cp-hero-text { position: relative; z-index: 1; }
.cp-hero-text h4 { font-size: 18px; font-weight: 750; margin: 0 0 3px; letter-spacing: -.2px; }
.cp-hero-text p  { margin: 0; font-size: 13px; opacity: .75; }

/* Alert */
.cp-alert {
  border-radius: 11px; padding: 12px 16px;
  font-size: 14px; font-weight: 600;
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 18px;
}
.cp-alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; }
.cp-alert-danger  { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; animation: shake .3s ease; }
@keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-5px)} 75%{transform:translateX(5px)} }

/* Card */
.cp-card {
  background: #fff;
  border: 1px solid rgba(15,23,42,.08);
  border-radius: 14px;
  box-shadow: 0 4px 24px rgba(15,23,42,.07);
  overflow: hidden;
}
.cp-card-head {
  background: #f8fafc;
  border-bottom: 1px solid rgba(15,23,42,.07);
  padding: 14px 22px;
  display: flex; align-items: center; gap: 9px;
}
.cp-card-head span { font-size: 14px; font-weight: 700; color: #111827; }
.cp-card-body { padding: 24px 24px 28px; }

/* Fields */
.cp-field { margin-bottom: 20px; }
.cp-field label {
  display: block;
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  color: #6b7280; margin-bottom: 7px;
}
.cp-field label .req { color: #dc2626; }
.pw-wrap { position: relative; }
.pw-wrap input {
  width: 100%;
  background: #f9fafb;
  border: 1.5px solid rgba(15,23,42,.11);
  border-radius: 11px;
  padding: 11px 44px 11px 13px;
  font-size: 14px; color: #111827;
  outline: none;
  transition: border-color .15s, box-shadow .15s, background .15s;
}
.pw-wrap input::placeholder { color: #cbd5e1; }
.pw-wrap input:focus {
  border-color: #2563eb;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}
.pw-toggle {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer;
  color: #9ca3af; padding: 4px; line-height: 0;
  transition: color .15s;
}
.pw-toggle:hover { color: #475569; }

/* Strength bar */
.strength-wrap { margin-top: 8px; display: none; }
.strength-bar-track {
  height: 4px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-bottom: 5px;
}
.strength-bar-fill {
  height: 100%; border-radius: 999px;
  transition: width .3s, background .3s;
  width: 0%;
}
.strength-label { font-size: 11.5px; font-weight: 600; }

/* Divider */
.cp-divider { border: none; border-top: 1px solid rgba(15,23,42,.07); margin: 22px 0 20px; }

/* Submit */
.btn-cp-submit {
  width: 100%;
  background: linear-gradient(135deg, #2563eb, #4f46e5);
  border: none; border-radius: 11px; padding: 13px;
  font-size: 15px; font-weight: 700; color: #fff; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: transform .15s, box-shadow .15s, opacity .15s;
  box-shadow: 0 6px 20px rgba(37,99,235,.32);
}
.btn-cp-submit:hover { transform: translateY(-1px); box-shadow: 0 10px 28px rgba(37,99,235,.42); }
.btn-cp-submit:active { transform: translateY(0); }

/* Tips */
.cp-tips {
  margin-top: 16px;
  background: #f8fafc;
  border: 1px solid rgba(15,23,42,.07);
  border-radius: 11px; padding: 14px 16px;
  font-size: 12.5px; color: #64748b; line-height: 1.8;
}
.cp-tips strong { color: #374151; display: block; margin-bottom: 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
.cp-tips ul { margin: 0; padding-left: 16px; }
</style>

<div class="cp-wrap">

  <!-- Hero -->
  <div class="cp-hero">
    <div class="cp-hero-icon">
      <i class="bi bi-shield-lock-fill"></i>
    </div>
    <div class="cp-hero-text">
      <h4>Change Password</h4>
      <p>Keep your account secure with a strong password</p>
    </div>
  </div>

  <!-- Alert -->
  <?php if ($success): ?>
    <div class="cp-alert cp-alert-success">
      <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
    </div>
  <?php elseif ($error): ?>
    <div class="cp-alert cp-alert-danger">
      <i class="bi bi-x-circle-fill"></i> <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <!-- Card -->
  <div class="cp-card">
    <div class="cp-card-head">
      <i class="bi bi-key-fill text-primary" style="font-size:14px;"></i>
      <span>Update Your Password</span>
    </div>
    <div class="cp-card-body">
      <form method="POST" autocomplete="off">

        <!-- Current password -->
        <div class="cp-field">
          <label>Current Password <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" name="current_password" id="pw_current" placeholder="Enter current password" required>
            <button type="button" class="pw-toggle" onclick="togglePw('pw_current', this)">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <hr class="cp-divider">

        <!-- New password -->
        <div class="cp-field">
          <label>New Password <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" name="new_password" id="pw_new" placeholder="Enter new password" required oninput="checkStrength(this.value)">
            <button type="button" class="pw-toggle" onclick="togglePw('pw_new', this)">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          <!-- Strength indicator -->
          <div class="strength-wrap" id="strengthWrap">
            <div class="strength-bar-track">
              <div class="strength-bar-fill" id="strengthBar"></div>
            </div>
            <span class="strength-label" id="strengthLabel"></span>
          </div>
        </div>

        <!-- Confirm password -->
        <div class="cp-field" style="margin-bottom:8px;">
          <label>Confirm New Password <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" name="confirm_password" id="pw_confirm" placeholder="Re-enter new password" required>
            <button type="button" class="pw-toggle" onclick="togglePw('pw_confirm', this)">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div style="margin-bottom:22px;">
          <button type="submit" class="btn-cp-submit">
            <i class="bi bi-shield-check"></i> Update Password
          </button>
        </div>

      </form>

      <!-- Tips -->
      <div class="cp-tips">
        <strong><i class="bi bi-lightbulb me-1"></i> Password Tips</strong>
        <ul>
          <li>At least 6 characters long</li>
          <li>Mix uppercase, lowercase, numbers & symbols</li>
          <li>Avoid using your name or common words</li>
        </ul>
      </div>

    </div>
  </div>

</div>

<script>
// Toggle show/hide password
const eyeOpen = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>`;
const eyeOff  = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>`;

function togglePw(id, btn) {
  const input = document.getElementById(id);
  const svg   = btn.querySelector('svg');
  const hidden = input.type === 'password';
  input.type = hidden ? 'text' : 'password';
  svg.innerHTML = hidden ? eyeOff : eyeOpen;
}

// Password strength meter
function checkStrength(val) {
  const wrap  = document.getElementById('strengthWrap');
  const bar   = document.getElementById('strengthBar');
  const label = document.getElementById('strengthLabel');

  if (!val) { wrap.style.display = 'none'; return; }
  wrap.style.display = 'block';

  let score = 0;
  if (val.length >= 6)  score++;
  if (val.length >= 10) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;

  const levels = [
    { pct: '20%', color: '#ef4444', text: 'Very Weak',  textColor: '#dc2626' },
    { pct: '40%', color: '#f97316', text: 'Weak',       textColor: '#ea580c' },
    { pct: '60%', color: '#eab308', text: 'Fair',       textColor: '#ca8a04' },
    { pct: '80%', color: '#22c55e', text: 'Strong',     textColor: '#16a34a' },
    { pct: '100%',color: '#10b981', text: 'Very Strong',textColor: '#059669' },
  ];

  const lvl = levels[Math.min(score, 4)];
  bar.style.width      = lvl.pct;
  bar.style.background = lvl.color;
  label.textContent    = lvl.text;
  label.style.color    = lvl.textColor;
}
</script>

<?php include 'includes/footer.php'; ?>