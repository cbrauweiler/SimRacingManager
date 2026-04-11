<?php
// seasons.php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Saisons'; $adminPage = 'seasons';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $data = [trim($_POST['name']??''), (int)($_POST['year']??0) ?: null, trim($_POST['game']??''), trim($_POST['car_class']??''), trim($_POST['description']??''), isset($_POST['is_active'])?1:0];
        if ($id) {
            $db->prepare("UPDATE seasons SET name=?,year=?,game=?,car_class=?,description=?,is_active=? WHERE id=?")->execute([...$data,$id]);
        } else {
            $db->prepare("INSERT INTO seasons (name,year,game,car_class,description,is_active) VALUES (?,?,?,?,?,?)")->execute($data);
        }
        $_SESSION['flash']=['type'=>'success','msg'=>'✅ Saison gespeichert!'];
        header('Location: '.SITE_URL.'/admin/seasons.php'); exit;
    }
    if ($action === 'activate') {
        $db->prepare("UPDATE seasons SET is_active=0")->execute();
        $db->prepare("UPDATE seasons SET is_active=1 WHERE id=?")->execute([(int)$_POST['id']]);
        header('Location: '.SITE_URL.'/admin/seasons.php'); exit;
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM seasons WHERE id=?")->execute([(int)$_POST['id']]);
        $_SESSION['flash']=['type'=>'success','msg'=>'🗑 Saison gelöscht.'];
        header('Location: '.SITE_URL.'/admin/seasons.php'); exit;
    }
}
$editing = null;
if (isset($_GET['edit'])) { $stmt=$db->prepare("SELECT * FROM seasons WHERE id=?"); $stmt->execute([(int)$_GET['edit']]); $editing=$stmt->fetch(); }
$seasons = $db->query("SELECT s.*,(SELECT COUNT(*) FROM teams t WHERE t.season_id=s.id) tc,(SELECT COUNT(*) FROM races r WHERE r.season_id=s.id) rc FROM seasons s ORDER BY year DESC, id DESC")->fetchAll();
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Saisons <span style="color:var(--primary)">verwalten</span></div>
<div class="admin-page-sub">Saisons anlegen, bearbeiten und aktivieren</div>

<div class="card mb-4">
  <div class="card-header">
    <h3><?= $editing ? '✏️ Saison bearbeiten' : '➕ Neue Saison' ?></h3>
    <?php if ($editing): ?><a href="<?= SITE_URL ?>/admin/seasons.php" class="btn btn-secondary btn-sm">Abbrechen</a><?php endif; ?>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="action" value="save"/>
      <input type="hidden" name="id" value="<?= $editing?(int)$editing['id']:0 ?>"/>
      <div class="form-row cols-2">
        <div class="form-group"><label>Name *</label><input type="text" name="name" class="form-control" value="<?= h($editing['name']??'') ?>" required placeholder="Saison 1, GT3 Cup 2025..."/></div>
        <div class="form-group"><label>Jahr</label><input type="number" name="year" class="form-control" value="<?= h($editing['year']??date('Y')) ?>" placeholder="<?= date('Y') ?>"/></div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label>Spiel / Simulator</label><input type="text" name="game" class="form-control" value="<?= h($editing['game']??'') ?>" placeholder="Assetto Corsa, iRacing, rFactor 2..."/></div>
        <div class="form-group"><label>Fahrzeugklasse</label><input type="text" name="car_class" class="form-control" value="<?= h($editing['car_class']??'') ?>" placeholder="GT3, GT4, Formula..."/></div>
      </div>
      <div class="form-group"><label>Beschreibung</label><textarea name="description" class="form-control" style="min-height:80px"><?= h($editing['description']??'') ?></textarea></div>
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:16px;font-size:.9rem">
        <input type="checkbox" name="is_active" <?= ($editing['is_active']??0)?'checked':'' ?>/> <span>Als aktive Saison setzen</span>
      </label>
      <button type="submit" class="btn btn-primary"><?= $editing?'💾 Aktualisieren':'➕ Saison anlegen' ?></button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h3>Alle Saisons</h3></div>
  <div class="card-body" style="padding:0">
    <?php if ($seasons): ?>
    <table class="admin-table">
      <thead><tr><th>Name</th><th>Jahr</th><th>Spiel</th><th>Teams</th><th>Rennen</th><th>Status</th><th style="text-align:right">Aktionen</th></tr></thead>
      <tbody>
        <?php foreach ($seasons as $s): ?>
        <tr>
          <td><strong><?= h($s['name']) ?></strong></td>
          <td><?= $s['year'] ?: '–' ?></td>
          <td class="text-muted"><?= h($s['game']??'–') ?></td>
          <td><?= $s['tc'] ?></td>
          <td><?= $s['rc'] ?></td>
          <td><span class="badge <?= $s['is_active']?'badge-primary':'badge-muted' ?>"><?= $s['is_active']?'Aktiv':'Inaktiv' ?></span></td>
          <td>
            <div class="actions">
              <?php if (!$s['is_active']): ?>
              <form method="post" style="display:inline"><input type="hidden" name="action" value="activate"/><input type="hidden" name="id" value="<?= $s['id'] ?>"/><button class="btn btn-success btn-sm">✅ Aktivieren</button></form>
              <?php endif; ?>
              <a href="?edit=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">✏️</a>
              <a href="<?= SITE_URL ?>/admin/calendar.php?season=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">📅</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Saison und alle zugehörigen Rennen löschen?')">
                <input type="hidden" name="action" value="delete"/><input type="hidden" name="id" value="<?= $s['id'] ?>"/>
                <button class="btn btn-danger btn-sm">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div style="padding:18px" class="text-muted">Noch keine Saisons angelegt.</div><?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
