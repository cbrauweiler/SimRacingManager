<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
startSecureSession();
if (isLoggedIn()) { header('Location: '.SITE_URL.'/admin/'); exit; }

$db    = getDB();
$token = trim($_GET['token'] ?? '');
$msg   = ''; $msgType = ''; $valid = false; $user = null;

if ($token) {
    try {
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE reset_token=? AND reset_token_expires > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        $valid = (bool)$user;
        if (!$valid) $msg = 'Dieser Link ist ungültig oder abgelaufen.'; $msgType = 'error';
    } catch (\Throwable $e) {
        $msg = 'Fehler: reset_token Spalten fehlen – SQL-Migration ausführen.'; $msgType = 'error';
    }
} else {
    $msg = 'Kein Token angegeben.'; $msgType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid && $user) {
    $pass  = $_POST['password']  ?? '';
    $pass2 = $_POST['password2'] ?? '';
    if (strlen($pass) < 6) {
        $msg = 'Passwort muss mind. 6 Zeichen haben.'; $msgType = 'error';
    } elseif ($pass !== $pass2) {
        $msg = 'Passwörter stimmen nicht überein.'; $msgType = 'error';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
        $db->prepare("UPDATE admin_users SET password_hash=?, reset_token=NULL, reset_token_expires=NULL WHERE id=?")
           ->execute([$hash, $user['id']]);
        auditLog('password_reset','admin_users',$user['id'],$user['username']);
        $msg = 'Passwort erfolgreich geändert! Du kannst dich jetzt einloggen.';
        $msgType = 'success';
        $valid = false; // Formular nicht mehr zeigen
    }
}

$s = getAllSettings();
$primaryColor = $s['color_primary'] ?? '#e8333a';
$bgColor = $s['color_bg'] ?? '#0a0a0f';
$bg2 = adjustHex($bgColor, 12);
function adjustHex(string $hex, int $amt): string {
    $hex=ltrim($hex,'#');if(strlen($hex)===3)$hex=$hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return sprintf('#%02x%02x%02x',max(0,min(255,hexdec(substr($hex,0,2))+$amt)),max(0,min(255,hexdec(substr($hex,2,2))+$amt)),max(0,min(255,hexdec(substr($hex,4,2))+$amt)));
}
?>
<!DOCTYPE html><html lang="de"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Passwort zurücksetzen – <?= h($s['league_name']??'SimRace Liga') ?></title>
<?php $sortableLocal = file_exists(dirname(__DIR__).'/assets/fonts/BarlowCondensed-Black.woff2'); ?>
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
.box{background:var(--bg2);border:1px solid #2a2a3a;border-radius:6px;padding:40px;width:100%;max-width:400px;position:relative;z-index:1}
.title{font-family:'Barlow Condensed',sans-serif;font-size:1.8rem;font-weight:900;text-transform:uppercase;margin-bottom:4px}
.sub{color:#8888a0;font-size:.85rem;margin-bottom:24px}
label{display:block;font-family:'Barlow Condensed',sans-serif;font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#8888a0;margin-bottom:6px}
input[type=password]{width:100%;background:#1c1c28;border:1px solid #2a2a3a;border-radius:4px;padding:10px 14px;color:#f0f0f5;font-size:.95rem;outline:none;transition:border-color .2s;margin-bottom:18px}
input:focus{border-color:var(--primary)}
.btn{display:block;width:100%;padding:12px;background:var(--primary);color:#fff;border:none;border-radius:4px;font-family:'Barlow Condensed',sans-serif;font-size:1rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;transition:filter .2s}
.btn:hover{filter:brightness(1.12)}
.msg-success{background:rgba(39,174,96,.1);border:1px solid rgba(39,174,96,.3);color:#4cffb0;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:.88rem}
.msg-error{background:rgba(200,50,50,.1);border:1px solid rgba(200,50,50,.3);color:#ff8080;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:.88rem}
.links{margin-top:16px;text-align:center}
.links a{color:#8888a0;font-size:.82rem;text-decoration:none}
.links a:hover{color:#f0f0f5}
</style>
</head><body>
<div class="box">
  <div class="title">Neues <span style="color:var(--primary)">Passwort</span></div>
  <div class="sub"><?= h($user['username'] ?? '') ?> – Passwort zurücksetzen</div>
  <?php if($msg): ?>
    <div class="msg-<?= $msgType ?>"><?= h($msg) ?></div>
  <?php endif; ?>
  <?php if($valid): ?>
  <form method="post">
    <label>Neues Passwort (mind. 6 Zeichen)</label>
    <input type="password" name="password" minlength="6" required placeholder="••••••••"/>
    <label>Passwort wiederholen</label>
    <input type="password" name="password2" minlength="6" required placeholder="••••••••"/>
    <button type="submit" class="btn">Passwort speichern →</button>
  </form>
  <?php endif; ?>
  <div class="links">
    <a href="<?= SITE_URL ?>/admin/login.php">← Zurück zum Login</a>
  </div>
</div>
</body></html>
