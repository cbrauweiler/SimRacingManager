<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
startSecureSession();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            if (!empty($user['mfa_enabled']) && !empty($user['mfa_secret'])) {
                // MFA aktiv: Daten in Session zwischenspeichern, zum MFA-Check weiterleiten
                $_SESSION['mfa_pending_id']   = $user['id'];
                $_SESSION['mfa_pending_user'] = $user['username'];
                $_SESSION['mfa_pending_role'] = $user['role'];
                header('Location: ' . SITE_URL . '/admin/mfa_check.php');
                exit;
            }
            // Kein MFA: direkt einloggen
            $_SESSION['admin_id']   = $user['id'];
            $_SESSION['admin_user'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'];
            $db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            header('Location: ' . SITE_URL . '/admin/');
            exit;
        }
        $error = 'Ungültige Zugangsdaten.';
    } else {
        $error = 'Bitte alle Felder ausfüllen.';
    }
}
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/admin/'); exit; }

$s = getAllSettings();
$primaryColor = $s['color_primary'] ?? '#e8333a';
$bgColor = $s['color_bg'] ?? '#0a0a0f';
$bg2 = adjustHex($bgColor ?? '#0a0a0f', 12);

function adjustHex(string $hex, int $amt): string {
    $hex = ltrim($hex,'#'); if(strlen($hex)===3) $hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return sprintf('#%02x%02x%02x',max(0,min(255,hexdec(substr($hex,0,2))+$amt)),max(0,min(255,hexdec(substr($hex,2,2))+$amt)),max(0,min(255,hexdec(substr($hex,4,2))+$amt)));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Admin Login – <?= h($s['league_name'] ?? 'SimRace Liga') ?></title>
<?php $sortableLocal = (file_exists(dirname(__DIR__).'/assets/fonts/BarlowCondensed-Black.woff2') || file_exists(dirname(__DIR__).'/assets/fonts/BarlowCondensed-Black.ttf')); ?>
<?php if($sortableLocal): ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/fonts/barlow-local.css"/>
<?php else: ?>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;900&family=Barlow:wght@400;500&display=swap" rel="stylesheet"/>
<?php endif; ?>
<style>
:root{--primary:<?= h($primaryColor)?>;--bg:<?= h($bgColor)?>;--bg2:<?= h($bg2)?>}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:#f0f0f5;font-family:'Barlow',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(45deg,transparent,transparent 40px,rgba(232,51,58,.025) 40px,rgba(232,51,58,.025) 80px);pointer-events:none}
.login-box{background:var(--bg2);border:1px solid #2a2a3a;border-radius:6px;padding:40px;width:100%;max-width:400px;position:relative;z-index:1}
.login-title{font-family:'Barlow Condensed',sans-serif;font-size:2rem;font-weight:900;text-transform:uppercase;margin-bottom:4px}
.login-sub{color:#8888a0;font-size:.85rem;margin-bottom:28px}
label{display:block;font-family:'Barlow Condensed',sans-serif;font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#8888a0;margin-bottom:6px}
input[type=text],input[type=password]{width:100%;background:#1c1c28;border:1px solid #2a2a3a;border-radius:4px;padding:10px 14px;color:#f0f0f5;font-size:.95rem;outline:none;transition:border-color .2s;margin-bottom:18px}
input:focus{border-color:var(--primary)}
.btn{display:block;width:100%;padding:12px;background:var(--primary);color:#fff;border:none;border-radius:4px;font-family:'Barlow Condensed',sans-serif;font-size:1rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;transition:filter .2s}
.btn:hover{filter:brightness(1.12)}
.error{background:rgba(200,50,50,.1);border:1px solid rgba(200,50,50,.3);color:#ff8080;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:.88rem}
.back{display:block;text-align:center;margin-top:16px;color:#8888a0;font-size:.82rem;text-decoration:none}
.back:hover{color:#f0f0f5}
</style>
</head>
<body>
<div class="login-box">
  <div class="login-title">Admin <span style="color:var(--primary)">Login</span></div>
  <div class="login-sub"><?= h($s['league_name'] ?? 'SimRace Liga') ?> – Verwaltung</div>
  <?php if ($error): ?><div class="error">⚠ <?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <label>Benutzername</label>
    <input type="text" name="username" autocomplete="username" required placeholder="admin"/>
    <label>Passwort</label>
    <input type="password" name="password" autocomplete="current-password" required placeholder="••••••••"/>
    <button type="submit" class="btn">Einloggen →</button>
  </form>
  <div style="display:flex;justify-content:space-between;margin-top:14px">
    <a href="<?= SITE_URL ?>/" class="back" style="margin:0">← Website</a>
    <a href="<?= SITE_URL ?>/admin/forgot_password.php" class="back" style="margin:0">Passwort vergessen?</a>
  </div>
</div>
</body>
</html>
