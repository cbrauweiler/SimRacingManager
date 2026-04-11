<?php
// teams.php (Admin)
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Teams'; $adminPage = 'teams';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $logoPath = $_POST['logo_path_current'] ?? '';
        if (!empty($_FILES['logo_file']['name'])) {
            $up = uploadFile($_FILES['logo_file'], 'logos'); if ($up) $logoPath = $up;
        }
        $data = [(int)$_POST['season_id'], trim($_POST['name']??''), strtoupper(trim($_POST['abbreviation']??'')), trim($_POST['color']??'#e8333a'), $logoPath, trim($_POST['car']??''), trim($_POST['nationality']??'')];
        if ($id) {
            $db->prepare("UPDATE teams SET season_id=?,name=?,abbreviation=?,color=?,logo_path=?,car=?,nationality=? WHERE id=?")->execute([...$data,$id]);
        } else {
            $db->prepare("INSERT INTO teams (season_id,name,abbreviation,color,logo_path,car,nationality) VALUES (?,?,?,?,?,?,?)")->execute($data);
        }
        $_SESSION['flash']=['type'=>'success','msg'=>'✅ Team gespeichert!'];
        header('Location: '.SITE_URL.'/admin/teams.php'); exit;
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM teams WHERE id=?")->execute([(int)$_POST['id']]);
        $_SESSION['flash']=['type'=>'success','msg'=>'🗑 Team gelöscht.'];
        header('Location: '.SITE_URL.'/admin/teams.php'); exit;
    }
}
$seasons = $db->query("SELECT * FROM seasons ORDER BY year DESC")->fetchAll();
$editing = null;
if (isset($_GET['edit'])) { $stmt=$db->prepare("SELECT * FROM teams WHERE id=?"); $stmt->execute([(int)$_GET['edit']]); $editing=$stmt->fetch(); }
$teams = $db->query("SELECT t.*, s.name AS season_name, (SELECT COUNT(*) FROM season_entries se WHERE se.team_id=t.id) dc FROM teams t LEFT JOIN seasons s ON s.id=t.season_id ORDER BY s.year DESC, t.name")->fetchAll();
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Teams <span style="color:var(--primary)">verwalten</span></div>
<div class="admin-page-sub">Teams anlegen und bearbeiten</div>
<div class="grid-2" style="gap:20px;align-items:start">
  <div class="card">
    <div class="card-header">
      <h3><?= $editing?'✏️ Team bearbeiten':'➕ Neues Team' ?></h3>
      <?php if ($editing): ?><a href="<?= SITE_URL ?>/admin/teams.php" class="btn btn-secondary btn-sm">Abbrechen</a><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save"/>
        <input type="hidden" name="id" value="<?= $editing?(int)$editing['id']:0 ?>"/>
        <input type="hidden" name="logo_path_current" value="<?= h($editing['logo_path']??'') ?>"/>
        <div class="form-group">
          <label>Saison *</label>
          <select name="season_id" class="form-control" required>
            <?php foreach ($seasons as $s): ?><option value="<?= $s['id'] ?>" <?= ($editing['season_id']??($seasons[0]['id']??0))==$s['id']?'selected':'' ?>><?= h($s['name']) ?> <?= h($s['year']??'') ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Teamname *</label><input type="text" name="name" class="form-control" value="<?= h($editing['name']??'') ?>" required/></div>
          <div class="form-group"><label>Kürzel</label><input type="text" name="abbreviation" class="form-control" value="<?= h($editing['abbreviation']??'') ?>" maxlength="5" placeholder="RBR, MER..."/></div>
        </div>
        <div class="form-group">
          <label>Teamfarbe</label>
          <div class="color-row">
            <div class="color-swatch"><input type="color" id="tc-pick" value="<?= h($editing['color']??'#e8333a') ?>" oninput="document.getElementById('tc-hex').value=this.value"/></div>
            <input type="text" id="tc-hex" name="color" class="form-control" value="<?= h($editing['color']??'#e8333a') ?>" style="max-width:130px" oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value))document.getElementById('tc-pick').value=this.value"/>
          </div>
        </div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Fahrzeug</label><input type="text" name="car" class="form-control" value="<?= h($editing['car']??'') ?>" placeholder="Ferrari 296 GT3..."/></div>
          <div class="form-group"><label>Nation</label><input type="text" name="nationality" class="form-control" value="<?= h($editing['nationality']??'') ?>" placeholder="Deutschland"/></div>
        </div>
        <div class="form-group">
          <label>Logo</label>
          <?php if (!empty($editing['logo_path'])): ?><img src="<?= h($editing['logo_path']) ?>" style="max-height:60px;margin-bottom:8px;border-radius:3px"/><?php endif; ?>
          <input type="file" name="logo_file" class="form-control" accept="image/*"/>
        </div>
        <button type="submit" class="btn btn-primary w-full"><?= $editing?'💾 Aktualisieren':'➕ Team anlegen' ?></button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Alle Teams (<?= count($teams) ?>)</h3></div>
    <div class="card-body" style="padding:0">
      <?php if ($teams): foreach ($teams as $t): ?>
      <div class="flex flex-center gap-2" style="padding:10px 16px;border-bottom:1px solid var(--border)">
        <div style="width:8px;height:36px;border-radius:2px;background:<?= h($t['color']) ?>;flex-shrink:0"></div>
        <?php if ($t['logo_path']): ?><img src="<?= h($t['logo_path']) ?>" style="height:32px;object-fit:contain"/><?php endif; ?>
        <div class="flex-1">
          <div class="font-bold"><?= h($t['name']) ?></div>
          <div class="text-muted" style="font-size:.78rem"><?= h($t['season_name']??'') ?> · <?= (int)$t['dc'] ?> Fahrer</div>
        </div>
        <div class="flex gap-1">
          <a href="<?= SITE_URL ?>/admin/drivers.php?team=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">👤 Fahrer</a>
          <a href="?edit=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">✏️</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Team löschen?')">
            <input type="hidden" name="action" value="delete"/><input type="hidden" name="id" value="<?= $t['id'] ?>"/>
            <button class="btn btn-danger btn-sm">🗑</button>
          </form>
        </div>
      </div>
      <?php endforeach; else: ?><div style="padding:18px" class="text-muted">Noch keine Teams.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
