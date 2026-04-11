<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Benutzer'; $adminPage = 'users';
$db = getDB();
$me = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin(); verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $uname = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $role  = in_array($_POST['role']??'', ['superadmin','admin','editor']) ? $_POST['role'] : 'editor';
        if (!$uname || !$pass) { $_SESSION['flash']=['type'=>'error','msg'=>'❌ Benutzername und Passwort erforderlich!']; }
        else {
            $check = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE username=?"); $check->execute([$uname]);
            if ($check->fetchColumn()) { $_SESSION['flash']=['type'=>'error','msg'=>'❌ Benutzername bereits vergeben!']; }
            else {
                $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
                $db->prepare("INSERT INTO admin_users (username,email,password_hash,role) VALUES (?,?,?,?)")->execute([$uname,$email,$hash,$role]);
                auditLog('user_create','admin_users',0,$uname);
                // Willkommens-Mail
                if ($email) sendWelcomeMail($email, $uname, $pass);
                $mailHint = $email ? ' E-Mail wurde versendet.' : ' Keine E-Mail-Adresse angegeben.';
                $_SESSION['flash']=['type'=>'success','msg'=>'✅ Benutzer angelegt!' . $mailHint];
            }
        }
        header('Location: '.SITE_URL.'/admin/users.php'); exit;
    }

    if ($action === 'change_password') {
        $uid    = (int)$_POST['uid'];
        $pass   = $_POST['new_password'] ?? '';
        $pass2  = $_POST['new_password2'] ?? '';
        if (strlen($pass) < 6) { $_SESSION['flash']=['type'=>'error','msg'=>'❌ Passwort mind. 6 Zeichen!']; }
        elseif ($pass !== $pass2) { $_SESSION['flash']=['type'=>'error','msg'=>'❌ Passwörter stimmen nicht überein!']; }
        else {
            $db->prepare("UPDATE admin_users SET password_hash=? WHERE id=?")->execute([password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]), $uid]);
            $_SESSION['flash']=['type'=>'success','msg'=>'✅ Passwort geändert!'];
        }
        header('Location: '.SITE_URL.'/admin/users.php'); exit;
    }

    if ($action === 'delete' && (int)$_POST['uid'] !== (int)$me['id']) {
        $db->prepare("DELETE FROM admin_users WHERE id=?")->execute([(int)$_POST['uid']]);
        $_SESSION['flash']=['type'=>'success','msg'=>'🗑 Benutzer gelöscht.'];
        header('Location: '.SITE_URL.'/admin/users.php'); exit;
    }
}

$users = $db->query("SELECT id, username, email, role, created_at, last_login FROM admin_users ORDER BY created_at")->fetchAll();
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Benutzer <span style="color:var(--primary)">verwalten</span></div>
<div class="admin-page-sub">Admin-Benutzer anlegen und Passwörter ändern</div>

<div class="grid-2" style="gap:20px;align-items:start">
  <!-- Add User -->
  <div class="card">
    <div class="card-header"><h3>➕ Benutzer hinzufügen</h3></div>
    <div class="card-body">
      <form method="post"><?= csrfField() ?>
        <input type="hidden" name="action" value="add"/>
        <div class="form-group"><label>Benutzername *</label><input type="text" name="username" class="form-control" required/></div>
        <div class="form-group"><label>E-Mail <?php if(getSetting('mail_from','')): ?><span class="badge badge-success" style="font-size:.65rem">Willkommensmail wird gesendet</span><?php else: ?><span class="badge badge-muted" style="font-size:.65rem">Mail nicht konfiguriert</span><?php endif; ?></label><input type="email" name="email" class="form-control"/></div>
        <div class="form-group"><label>Passwort * (mind. 6 Zeichen)</label><input type="password" name="password" class="form-control" minlength="6" required/></div>
        <div class="form-group">
          <label>Rolle</label>
          <select name="role" class="form-control">
            <option value="editor">Editor (News, Kalender)</option>
            <option value="admin">Admin (alles außer Benutzer)</option>
            <option value="superadmin">Super Admin (alles)</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary w-full">Benutzer anlegen</button>
      </form>
    </div>
  </div>

  <!-- Change Password -->
  <div class="card">
    <div class="card-header"><h3>🔑 Passwort ändern</h3></div>
    <div class="card-body">
      <form method="post"><?= csrfField() ?>
        <input type="hidden" name="action" value="change_password"/>
        <div class="form-group">
          <label>Benutzer</label>
          <select name="uid" class="form-control">
            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= $u['id']==$me['id']?'selected':'' ?>><?= h($u['username']) ?> <?= $u['id']==$me['id']?'(ich)':'' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Neues Passwort *</label><input type="password" name="new_password" class="form-control" minlength="6" required/></div>
        <div class="form-group"><label>Passwort wiederholen *</label><input type="password" name="new_password2" class="form-control" minlength="6" required/></div>
        <button type="submit" class="btn btn-primary w-full">Passwort ändern</button>
      </form>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header"><h3>Alle Benutzer</h3></div>
  <div class="card-body" style="padding:0">
    <table class="admin-table">
      <thead><tr><th>Benutzername</th><th>E-Mail</th><th>Rolle</th><th>Erstellt</th><th>Letzter Login</th><th style="text-align:right">Aktionen</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><strong><?= h($u['username']) ?></strong><?= $u['id']==$me['id']?' <span class="badge badge-primary" style="font-size:.65rem">Ich</span>':'' ?></td>
          <td class="text-muted"><?= h($u['email']??'–') ?></td>
          <td><span class="badge <?= $u['role']==='superadmin'?'badge-primary':($u['role']==='admin'?'badge-secondary':'badge-info') ?>"><?= h($u['role']) ?></span></td>
          <td class="text-muted" style="font-size:.82rem"><?= date('d.m.Y', strtotime($u['created_at'])) ?></td>
          <td class="text-muted" style="font-size:.82rem"><?= $u['last_login']?date('d.m.Y H:i',strtotime($u['last_login'])):'Nie' ?></td>
          <td>
            <div class="actions">
              <?php if ($u['id'] != $me['id']): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Benutzer löschen?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete"/><input type="hidden" name="uid" value="<?= $u['id'] ?>"/>
                <button class="btn btn-danger btn-sm">🗑</button>
              </form>
              <?php else: ?><span class="text-muted" style="font-size:.8rem">Eigener Account</span><?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
