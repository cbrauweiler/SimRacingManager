<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
startSecureSession();

// Abbruch-Link: Session leeren und zum Login
if (isset($_GET['cancel'])) {
    unset($_SESSION['mfa_pending_id'], $_SESSION['mfa_pending_user'], $_SESSION['mfa_pending_role']);
    header('Location: ' . SITE_URL . '/admin/login.php'); exit;
}

// Nur aufrufbar wenn ein MFA-Pending in der Session ist
if (empty($_SESSION['mfa_pending_id'])) {
    header('Location: ' . SITE_URL . '/admin/login.php'); exit;
}
if (isLoggedIn()) {
    header('Location: ' . SITE_URL . '/admin/'); exit;
}

$db    = getDB();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\D/', '', $_POST['code'] ?? '');

    if (strlen($code) !== 6) {
        $error = 'Bitte einen 6-stelligen Code eingeben.';
    } else {
        $stmt = $db->prepare("SELECT mfa_secret FROM admin_users WHERE id=?");
        $stmt->execute([$_SESSION['mfa_pending_id']]);
        $row = $stmt->fetch();

        if ($row && mfaVerify($row['mfa_secret'], $code)) {
            // Code korrekt – einloggen
            $uid = (int)$_SESSION['mfa_pending_id'];
            $_SESSION['admin_id']   = $uid;
            $_SESSION['admin_user'] = $_SESSION['mfa_pending_user'];
            $_SESSION['admin_role'] = $_SESSION['mfa_pending_role'];
            unset($_SESSION['mfa_pending_id'], $_SESSION['mfa_pending_user'], $_SESSION['mfa_pending_role']);
            $db->prepare("UPDATE admin_users SET last_login=NOW() WHERE id=?")->execute([$uid]);
            header('Location: ' . SITE_URL . '/admin/'); exit;
        }

        $error = 'Ungültiger Code. Bitte erneut versuchen.';
    }
}

$s = getAllSettings();
$primaryColor = $s['color_primary'] ?? '#e8333a';
$bgColor      = $s['color_bg']      ?? '#0a0a0f';
function _mfaAdjHex(string $hex, int $amt): string {
    $hex=ltrim($hex,'#');if(strlen($hex)===3)$hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return sprintf('#%02x%02x%02x',max(0,min(255,hexdec(substr($hex,0,2))+$amt)),max(0,min(255,hexdec(substr($hex,2,2))+$amt)),max(0,min(255,hexdec(substr($hex,4,2))+$amt)));
}
$bg2 = _mfaAdjHex($bgColor, 12);
$pendingUser = h($_SESSION['mfa_pending_user'] ?? '');
?>
<!DOCTYPE html><html lang="de"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Zwei-Faktor-Code – <?= h($s['league_name']??'') ?></title>
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
.box{background:var(--bg2);border:1px solid #2a2a3a;border-radius:6px;padding:40px;width:100%;max-width:380px;position:relative;z-index:1;text-align:center}
.icon{font-size:3rem;margin-bottom:12px}
.title{font-family:'Barlow Condensed',sans-serif;font-size:1.6rem;font-weight:900;text-transform:uppercase;margin-bottom:4px}
.sub{color:#8888a0;font-size:.85rem;margin-bottom:24px;line-height:1.5}
.code-input{width:100%;background:#1c1c28;border:1px solid #2a2a3a;border-radius:4px;padding:14px;color:#f0f0f5;font-size:2rem;font-family:'Barlow Condensed',sans-serif;font-weight:700;letter-spacing:.3em;text-align:center;outline:none;transition:border-color .2s;margin-bottom:16px}
.code-input:focus{border-color:var(--primary)}
.btn{display:block;width:100%;padding:12px;background:var(--primary);color:#fff;border:none;border-radius:4px;font-family:'Barlow Condensed',sans-serif;font-size:1rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;transition:filter .2s}
.btn:hover{filter:brightness(1.12)}
.error{background:rgba(200,50,50,.12);border:1px solid rgba(200,50,50,.35);color:#ff8080;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:.88rem}
.hint{color:#8888a0;font-size:.78rem;margin-top:14px;line-height:1.6}
.back{display:block;text-align:center;margin-top:14px;color:#8888a0;font-size:.82rem;text-decoration:none}
.back:hover{color:#f0f0f5}
</style>
</head><body>
<div class="box">
  <div class="icon">🔐</div>
  <div class="title">Zwei-Faktor <span style="color:var(--primary)">Code</span></div>
  <div class="sub">
    Einloggen als <strong style="color:var(--primary)"><?= $pendingUser ?></strong><br/>
    Gib den 6-stelligen Code aus deiner Authenticator-App ein
  </div>

  <?php if ($error): ?>
  <div class="error">⚠ <?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="text" name="code" id="code-input" class="code-input"
           maxlength="6" pattern="\d{6}" placeholder="000000"
           autocomplete="one-time-code" inputmode="numeric"
           autofocus required/>
    <button type="submit" class="btn">Bestätigen →</button>
  </form>

  <div class="hint">Code ist 30 Sekunden gültig.<br/>
    Kein Zugang mehr? Wende dich an einen Super-Admin.</div>

  <!-- Zurück: GET-Parameter löst Session-Cleanup aus, KEIN PHP im onclick -->
  <a href="<?= SITE_URL ?>/admin/mfa_check.php?cancel=1" class="back">← Zurück zum Login</a>
</div>

<script>
var codeInput = document.getElementById('code-input');
codeInput.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
    if (this.value.length === 6) {
        this.closest('form').submit();
    }
});
// Fokus sicherstellen
codeInput.focus();
</script>
</body></html>
