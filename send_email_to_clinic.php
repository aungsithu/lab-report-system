<?php
require 'includes/db.php';
require 'includes/session_check.php';
include 'includes/header.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'mail.celtaclab.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'noreply@celtaclab.com';
        $mail->Password   = '.;2{5.2;kTLn';
        $mail->SMTPSecure = 'ssl';
        $mail->Port       = 465;
        $mail->setFrom('noreply@celtaclab.com', 'Celtac Lab');
        foreach ($to as $recipient) {
            $mail->addAddress($recipient);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

$message     = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    $clinic_emails = $_POST['clinic_emails'] ?? [];
    $subject       = trim($_POST['subject'] ?? '');
    $body          = $_POST['message'] ?? '';

    if (!empty($clinic_emails) && $subject && $body) {
        if (sendEmail($clinic_emails, $subject, $body)) {
            $message     = "Email sent successfully to " . count($clinic_emails) . " recipient(s).";
            $messageType = "success";
        } else {
            $message     = "Failed to send email. Please check the server logs.";
            $messageType = "danger";
        }
    } else {
        $message     = "Please fill in all fields and select at least one recipient.";
        $messageType = "warning";
    }
}

$emails = $conn->query("SELECT email FROM users WHERE email IS NOT NULL AND email != ''");
?>

<style>
/* ── Tokens ── */
.se-page {
  --se-blue:   #2563eb;
  --se-indigo: #4f46e5;
  --se-green:  #16a34a;
  --se-red:    #dc2626;
  --se-amber:  #d97706;
  --se-border: rgba(15,23,42,.08);
  --se-shadow: 0 4px 24px rgba(15,23,42,.07);
  --se-r:      14px;
}

/* ── Hero ── */
.se-hero {
  background: linear-gradient(120deg, #0f2444 0%, #1d4ed8 55%, #3b82f6 100%);
  border-radius: var(--se-r);
  padding: 22px 28px;
  color: #fff;
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; flex-wrap: wrap;
  position: relative; overflow: hidden;
  margin-bottom: 20px;
}
.se-hero::before { content:''; position:absolute; right:-50px; top:-50px; width:220px; height:220px; border-radius:50%; background:rgba(255,255,255,.05); }
.se-hero::after  { content:''; position:absolute; right:80px; bottom:-70px; width:160px; height:160px; border-radius:50%; background:rgba(255,255,255,.04); }
.se-hero h4 { font-size:20px; font-weight:750; margin:0 0 3px; letter-spacing:-.2px; position:relative; z-index:1; }
.se-hero p  { margin:0; font-size:13px; opacity:.75; position:relative; z-index:1; }
.se-hero-left { position:relative; z-index:1; }

/* From badge in hero */
.se-from-badge {
  background: rgba(255,255,255,.13); border: 1px solid rgba(255,255,255,.20);
  border-radius: 10px; padding: 8px 16px;
  font-size: 12.5px; font-weight: 600;
  display: flex; align-items: center; gap: 8px;
  backdrop-filter: blur(4px); position: relative; z-index: 1;
}
.se-from-badge i { opacity: .75; }

/* ── Alert ── */
.se-alert {
  border-radius: 10px; padding: 13px 18px;
  font-size: 14px; font-weight: 600;
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 18px;
}
.se-alert-success { background:#f0fdf4; border:1px solid #86efac; color:#15803d; }
.se-alert-danger  { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }
.se-alert-warning { background:#fffbeb; border:1px solid #fcd34d; color:#92400e; }

/* ── Card ── */
.se-card {
  background: #fff;
  border: 1px solid var(--se-border);
  border-radius: var(--se-r);
  box-shadow: var(--se-shadow);
  overflow: hidden;
  margin-bottom: 0;
}
.se-card-head {
  background: #f8fafc;
  border-bottom: 1px solid var(--se-border);
  padding: 14px 22px;
  display: flex; align-items: center; gap: 9px;
}
.se-card-head .title { font-size: 14.5px; font-weight: 700; color: #111827; }
.se-card-body { padding: 24px 26px 28px; }

/* ── Field ── */
.se-field { margin-bottom: 22px; }
.se-field label {
  display: block;
  font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .07em;
  color: #6b7280; margin-bottom: 7px;
}
.se-field label .req { color: #dc2626; }

.se-field .form-control,
.se-field .form-select {
  border-radius: 10px;
  border: 1.5px solid rgba(15,23,42,.11);
  font-size: 14px; padding: 10px 13px;
  background: #f9fafb; color: #111827;
  transition: border-color .15s, box-shadow .15s;
}
.se-field .form-control:focus,
.se-field .form-select:focus {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,.13);
  background: #fff;
}
.se-field .form-control::placeholder { color: #9ca3af; }
.se-field .form-hint {
  font-size: 11.5px; color: #9ca3af; margin-top: 5px;
  display: flex; align-items: center; gap: 5px;
}

/* ── Recipient pills ── */
.recipient-wrap {
  border: 1.5px solid rgba(15,23,42,.11);
  border-radius: 10px; background: #f9fafb;
  padding: 10px 12px; min-height: 48px;
  display: flex; flex-wrap: wrap; gap: 6px; align-items: flex-start;
  cursor: text;
  transition: border-color .15s, box-shadow .15s;
}
.recipient-wrap:focus-within {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,.13);
  background: #fff;
}
.r-pill {
  display: inline-flex; align-items: center; gap: 6px;
  background: #eff6ff; border: 1px solid #bfdbfe;
  color: #1d4ed8; border-radius: 999px;
  padding: 4px 10px 4px 10px;
  font-size: 12.5px; font-weight: 600;
  cursor: pointer; user-select: none;
  transition: background .12s;
}
.r-pill:hover { background: #dbeafe; }
.r-pill.selected { background: #2563eb; border-color: #2563eb; color: #fff; }
.r-pill .r-remove {
  font-size: 13px; line-height: 1; opacity: .6;
  display: none;
}
.r-pill.selected .r-remove { display: inline; }

/* Select all row */
.select-all-row {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 8px;
}
.btn-sel-all, .btn-sel-none {
  font-size: 12px; font-weight: 600; cursor: pointer;
  background: none; border: 1px solid rgba(15,23,42,.12);
  border-radius: 6px; padding: 3px 10px; color: #374151;
  transition: background .12s;
}
.btn-sel-all:hover  { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
.btn-sel-none:hover { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
.r-count { font-size: 12px; color: #9ca3af; margin-left: auto; }

/* hidden real select */
#clinic_emails { display: none; }

/* ── TinyMCE wrapper styling ── */
.se-editor-wrap {
  border-radius: 10px; overflow: hidden;
  border: 1.5px solid rgba(15,23,42,.11);
  transition: border-color .15s, box-shadow .15s;
}
.se-editor-wrap:focus-within {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,.13);
}
.se-editor-wrap .tox-tinymce {
  border: none !important;
  border-radius: 0 !important;
}

/* ── Submit ── */
.btn-se-send {
  background: linear-gradient(135deg, #2563eb, #4f46e5);
  color: #fff; border: none;
  border-radius: 10px; padding: 12px 32px;
  font-size: 15px; font-weight: 700;
  display: inline-flex; align-items: center; gap: 9px;
  cursor: pointer; transition: opacity .15s, transform .1s;
  box-shadow: 0 4px 16px rgba(37,99,235,.30);
}
.btn-se-send:hover { opacity: .88; transform: translateY(-1px); }
.btn-se-send:active { transform: translateY(0); }

/* ── Subject input with icon ── */
.se-input-icon { position: relative; }
.se-input-icon i {
  position: absolute; left: 12px; top: 50%;
  transform: translateY(-50%); color: #9ca3af; font-size: 14px; pointer-events: none;
}
.se-input-icon .form-control { padding-left: 35px; }
</style>

<div class="se-page">

  <!-- Hero -->
  <div class="se-hero">
    <div class="se-hero-left">
      <h4><i class="bi bi-envelope-paper-fill me-2" style="opacity:.85;"></i>Send Email</h4>
      <p>Compose and send emails to clinic users directly from the system</p>
    </div>
    <div class="se-from-badge">
      <i class="bi bi-shield-check-fill"></i>
      From: noreply@celtaclab.com
    </div>
  </div>

  <!-- Alert -->
  <?php if (!empty($message)): ?>
    <?php
      $alertClass = 'se-alert-warning';
      $alertIcon  = 'bi-exclamation-triangle-fill';
      if ($messageType === 'success') { $alertClass = 'se-alert-success'; $alertIcon = 'bi-check-circle-fill'; }
      if ($messageType === 'danger')  { $alertClass = 'se-alert-danger';  $alertIcon = 'bi-x-circle-fill'; }
    ?>
    <div class="se-alert <?= $alertClass ?>">
      <i class="bi <?= $alertIcon ?>"></i>
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <!-- Form card -->
  <div class="se-card">
    <div class="se-card-head">
      <i class="bi bi-pencil-square text-primary" style="font-size:15px;"></i>
      <span class="title">Compose Email</span>
    </div>
    <div class="se-card-body">
      <form method="POST" autocomplete="off" id="emailForm">

        <!-- Recipients -->
        <div class="se-field">
          <label>Recipients <span class="req">*</span></label>

          <!-- Select all / none row -->
          <div class="select-all-row">
            <button type="button" class="btn-sel-all" id="btnSelAll">
              <i class="bi bi-check2-all me-1"></i>Select All
            </button>
            <button type="button" class="btn-sel-none" id="btnSelNone">
              <i class="bi bi-x-lg me-1"></i>Clear
            </button>
            <span class="r-count" id="rCount">0 selected</span>
          </div>

          <!-- Pill selector -->
          <div class="recipient-wrap" id="recipientWrap">
            <?php
              // Reset pointer and re-query
              $emails2 = $conn->query("SELECT email FROM users WHERE email IS NOT NULL AND email != '' ORDER BY email");
              while ($e = $emails2->fetch_assoc()):
                $em = htmlspecialchars($e['email']);
            ?>
              <span class="r-pill" data-email="<?= $em ?>" onclick="toggleRecipient(this)">
                <i class="bi bi-envelope" style="font-size:11px; opacity:.6;"></i>
                <?= $em ?>
                <span class="r-remove">×</span>
              </span>
            <?php endwhile; ?>
          </div>

          <!-- Hidden real select (submitted with form) -->
          <select name="clinic_emails[]" id="clinic_emails" multiple required>
            <?php
              $emails3 = $conn->query("SELECT email FROM users WHERE email IS NOT NULL AND email != ''");
              while ($e = $emails3->fetch_assoc()):
            ?>
              <option value="<?= htmlspecialchars($e['email']) ?>"><?= htmlspecialchars($e['email']) ?></option>
            <?php endwhile; ?>
          </select>

          <div class="se-field-hint form-hint">
            <i class="bi bi-info-circle"></i>
            Click on email addresses above to select recipients
          </div>
        </div>

        <!-- Subject -->
        <div class="se-field">
          <label>Subject <span class="req">*</span></label>
          <div class="se-input-icon">
            <i class="bi bi-fonts"></i>
            <input type="text" name="subject" id="subject" class="form-control"
                   placeholder="Enter email subject..." required>
          </div>
        </div>

        <!-- Message -->
        <div class="se-field">
          <label>Message <span class="req">*</span></label>
          <div class="se-editor-wrap">
            <textarea name="message" id="message" rows="8" class="form-control"
                      style="border:none; border-radius:0;"></textarea>
          </div>
        </div>

        <!-- Actions -->
        <div style="display:flex; justify-content:flex-end; align-items:center; gap:14px; padding-top:6px; border-top:1px solid rgba(15,23,42,.06); margin-top:8px;">
          <span id="recipientSummary" style="font-size:13px; color:#9ca3af;"></span>
          <button type="submit" name="send_email" class="btn-se-send">
            <i class="bi bi-send-fill"></i> Send Email
          </button>
        </div>

      </form>
    </div>
  </div>

</div><!-- /.se-page -->

<!-- TinyMCE -->
<script src="../assets/tinymce/js/tinymce/tinymce.min.js"></script>
<script>
  tinymce.init({
    selector: '#message',
    height: 300,
    menubar: false,
    plugins: 'lists link',
    toolbar: 'undo redo | bold italic underline | bullist numlist | link',
    branding: false,
    skin: 'oxide',
    content_css: 'default',
  });

  // Sync TinyMCE before submit
  document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('emailForm').addEventListener('submit', function () {
      tinymce.triggerSave();
    });
  });

  // ── Recipient pill logic ──
  function updateHiddenSelect() {
    const sel     = document.getElementById('clinic_emails');
    const pills   = document.querySelectorAll('.r-pill.selected');
    const count   = document.getElementById('rCount');
    const summary = document.getElementById('recipientSummary');

    // Sync hidden select
    Array.from(sel.options).forEach(opt => {
      opt.selected = [...pills].some(p => p.dataset.email === opt.value);
    });

    const n = pills.length;
    count.textContent   = n + ' selected';
    summary.textContent = n > 0 ? 'Sending to ' + n + ' recipient' + (n > 1 ? 's' : '') : '';
  }

  function toggleRecipient(pill) {
    pill.classList.toggle('selected');
    updateHiddenSelect();
  }

  document.getElementById('btnSelAll').addEventListener('click', function () {
    document.querySelectorAll('.r-pill').forEach(p => p.classList.add('selected'));
    updateHiddenSelect();
  });

  document.getElementById('btnSelNone').addEventListener('click', function () {
    document.querySelectorAll('.r-pill').forEach(p => p.classList.remove('selected'));
    updateHiddenSelect();
  });

  updateHiddenSelect();
</script>

<?php include 'includes/footer.php'; ?>