<?php
if (session_status() === PHP_SESSION_NONE) {
    // 💾 Always set session lifetime to 1 day (86400 seconds)
    $cookie_lifetime = 86400; // 1 day

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

// ✅ Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 👤 Optional: store commonly used session values
$role = $_SESSION['user_role'] ?? '';
$_SESSION['role'] = $role;
$clinic_id = $_SESSION['clinic_id'] ?? 0;
$username = $_SESSION['username'] ?? '';
?>
