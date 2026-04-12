<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
requireLogin();
$db  = getDB();
$cu  = currentUser();
$user = $db->prepare("SELECT * FROM admin_users WHERE id=?")->execute([$cu['id']]) ? $db->prepare("SELECT * FROM admin_users WHERE id=?")->execute([$cu['id']]) : null;
$stmt = $db->prepare("SELECT * FROM admin_users WHERE id=?");
$stmt->execute([$cu['id']]);
$user = $stmt->fetch();

$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $secret = mfaGenerateSecret();
        $_SESSION['mfa_pending_secret'] = $secret;
        header('Location: /admin/mfa_setup.php?step=verify'); exit;
    }

    if ($action === 'verify_enable') {
        $secret = $_SESSION['mfa_pending_secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');
        if (!$secret) { $msg = 'Kein Secret. Bitte neu starten.'; $msgType = 'error'; }
        elseif (!mfaVerify($secret, $code)) { $msg = '❌ Code ungültig. Bitte prüfen ob die Uhr synchron ist.'; $msgType = 'error'; }
        else {
            $db->prepare("UPDATE admin_users SET mfa_secret=?, mfa_enabled=1 WHERE id=?")->execute([$secret, $cu['id']]);
            unset($_SESSION['mfa_pending_secret']);
            auditLog('mfa_enable','admin_users',$cu['id']);
            $msg = '✅ MFA wurde aktiviert!'; $msgType = 'success';
            $stmt->execute([$cu['id']]); $user = $stmt->fetch();
        }
    }

    if ($action === 'disable') {
        $code = trim($_POST['code'] ?? '');
        if (!mfaVerify($user['mfa_secret'], $code)) { $msg = '❌ Code ungültig.'; $msgType = 'error'; }
        else {
            $db->prepare("UPDATE admin_users SET mfa_secret=NULL, mfa_enabled=0 WHERE id=?")->execute([$cu['id']]);
            auditLog('mfa_disable','admin_users',$cu['id']);
            $msg = '✅ MFA wurde deaktiviert.'; $msgType = 'success';
            $stmt->execute([$cu['id']]); $user = $stmt->fetch();
        }
    }
}

$step = $_GET['step'] ?? 'overview';
$pendingSecret = $_SESSION['mfa_pending_secret'] ?? '';

$adminTitle = 'MFA Einrichten'; $adminPage = '';
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Zwei-Faktor <span style="color:var(--primary)">Authentifizierung</span></div>
<div class="admin-page-sub">TOTP – kompatibel mit Google Authenticator, Authy, 1Password u.a.</div>

<?php if ($msg): ?>
<div class="notice notice-<?= $msgType ?> mb-3"><?= h($msg) ?></div>
<?php endif; ?>

<?php if ($user['mfa_enabled']): ?>
<!-- MFA ist aktiv -->
<div class="card mb-3">
  <div class="card-header">
    <h3>🔐 MFA Status</h3>
    <span class="badge badge-success">Aktiv</span>
  </div>
  <div class="card-body">
    <div class="notice notice-info mb-3">MFA ist für deinen Account aktiviert. Du wirst bei jedem Login nach einem Code gefragt.</div>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="disable"/>
      <div class="form-group" style="max-width:280px">
        <label>Aktuellen Code eingeben um MFA zu deaktivieren</label>
        <input type="text" name="code" class="form-control" maxlength="6" pattern="\d{6}" placeholder="123456" autocomplete="one-time-code" required/>
      </div>
      <button type="submit" class="btn btn-danger" onclick="return confirm('MFA wirklich deaktivieren?')">🔓 MFA deaktivieren</button>
    </form>
  </div>
</div>

<?php elseif ($step === 'verify' && $pendingSecret): ?>
<!-- Schritt 2: QR scannen + Code verifizieren -->
<div class="card mb-3">
  <div class="card-header"><h3>📱 Schritt 2: App einrichten & verifizieren</h3></div>
  <div class="card-body">
    <div class="grid-2" style="gap:24px;align-items:start">
      <div>
        <p class="mb-3">Scanne diesen QR-Code mit deiner Authenticator-App (Google Authenticator, Authy, 1Password, etc.):</p>
        <div style="background:#fff;padding:12px;border-radius:8px;display:inline-block;margin-bottom:16px">
          <img src="<?= mfaQrUrl($pendingSecret, $cu['user'], getSetting('league_name','SimRace Liga')) ?>"
               width="200" height="200" alt="QR Code"/>
        </div>
        <div class="notice notice-info" style="font-size:.82rem">
          Kein QR-Scanner? Trage diesen Code manuell ein:<br/>
          <code style="font-size:.9rem;letter-spacing:.15em;font-weight:700"><?= h(chunk_split($pendingSecret,4,' ')) ?></code>
        </div>
      </div>
      <div>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="verify_enable"/>
          <div class="form-group">
            <label>6-stelligen Code aus der App eingeben</label>
            <input type="text" name="code" class="form-control"
                   maxlength="6" pattern="\d{6}" placeholder="123456"
                   autocomplete="one-time-code" required autofocus
                   style="font-size:1.4rem;letter-spacing:.2em;text-align:center;max-width:180px"/>
          </div>
          <button type="submit" class="btn btn-primary">✅ Verifizieren & MFA aktivieren</button>
          <a href="/admin/mfa_setup.php" class="btn btn-secondary mt-2">← Abbrechen</a>
        </form>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Schritt 1: MFA einrichten -->
<div class="card mb-3">
  <div class="card-header">
    <h3>🔐 MFA Status</h3>
    <span class="badge badge-muted">Nicht aktiv</span>
  </div>
  <div class="card-body">
    <p class="mb-3">Schütze deinen Account mit einem zweiten Faktor. Du benötigst eine Authenticator-App auf deinem Smartphone.</p>
    <div class="flex gap-2 mb-3" style="flex-wrap:wrap">
      <span class="badge badge-info">Google Authenticator</span>
      <span class="badge badge-info">Authy</span>
      <span class="badge badge-info">1Password</span>
      <span class="badge badge-info">Bitwarden</span>
      <span class="badge badge-info">Microsoft Authenticator</span>
    </div>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="generate"/>
      <button type="submit" class="btn btn-primary">🔑 MFA einrichten</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
