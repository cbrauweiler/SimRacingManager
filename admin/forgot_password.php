<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
startSecureSession();
if (isLoggedIn()) { header('Location: '.SITE_URL.'/admin/'); exit; }

$db = getDB();
$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    if (!$identifier) {
        $msg = 'Bitte Benutzername oder E-Mail eingeben.'; $msgType = 'error';
    } else {
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE username=? OR email=? LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        // Immer die gleiche Meldung (kein User-Enumeration)
        $msg = 'Falls ein Account mit diesen Daten existiert, wurde eine E-Mail versendet.';
        $msgType = 'success';

        if ($user && $user['email']) {
            // Token generieren (1h gültig)
            $token  = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 3600);
            // Token in DB speichern (reset_token Feld, ggf. anlegen)
            try {
                $db->prepare("UPDATE admin_users SET reset_token=?, reset_token_expires=? WHERE id=?")
                   ->execute([$token, $expiry, $user['id']]);
                sendPasswordResetMail($user['email'], $user['username'], $token);
            } catch (\Throwable $e) {
                // reset_token Spalten noch nicht vorhanden
                $msg = 'Fehler: reset_token Spalten fehlen – SQL-Migration ausführen.';
                $msgType = 'error';
            }
        }
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
<title>Passwort vergessen – <?= h($s['league_name']??'SimRace Liga') ?></title>
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
input[type=text],input[type=email]{width:100%;background:#1c1c28;border:1px solid #2a2a3a;border-radius:4px;padding:10px 14px;color:#f0f0f5;font-size:.95rem;outline:none;transition:border-color .2s;margin-bottom:18px}
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
  <div class="title">Passwort <span style="color:var(--primary)">vergessen</span></div>
  <div class="sub"><?= h($s['league_name']??'SimRace Liga') ?> – Zugangsdaten zurücksetzen</div>
  <?php if($msg): ?>
    <div class="msg-<?= $msgType ?>"><?= h($msg) ?></div>
  <?php endif; ?>
  <?php if(!$msg || $msgType==='error'): ?>
  <form method="post">
    <label>Benutzername oder E-Mail</label>
    <input type="text" name="identifier" autocomplete="username" required placeholder="admin oder mail@example.de"/>
    <button type="submit" class="btn">Zurücksetzen →</button>
  </form>
  <?php endif; ?>
  <div class="links">
    <a href="<?= SITE_URL ?>/admin/login.php">← Zurück zum Login</a>
  </div>
</div>
</body></html>
