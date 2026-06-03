<?php
// teams.php (Admin)
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Teams'; $adminPage = 'teams';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin');
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $logoPath = $_POST['logo_path_current'] ?? '';
        if (!empty($_FILES['logo_file']['name'])) {
            $up = uploadFile($_FILES['logo_file'], 'logos'); if ($up) $logoPath = $up;
        }
        $seasonIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['season_ids'] ?? [])))));
        $data = [trim($_POST['name']??''), strtoupper(trim($_POST['abbreviation']??'')), trim($_POST['color']??'#e8333a'), $logoPath, trim($_POST['car']??''), trim($_POST['nationality']??'')];
        if ($id) {
            $db->prepare("UPDATE teams SET name=?,abbreviation=?,color=?,logo_path=?,car=?,nationality=? WHERE id=?")->execute([...$data,$id]);
        } else {
            $db->prepare("INSERT INTO teams (name,abbreviation,color,logo_path,car,nationality) VALUES (?,?,?,?,?,?)")->execute($data);
            $id = (int)$db->lastInsertId();
        }
        // Saison-Verknüpfungen synchronisieren (n:m)
        $db->prepare("DELETE FROM team_seasons WHERE team_id=?")->execute([$id]);
        if ($seasonIds) {
            $ins = $db->prepare("INSERT IGNORE INTO team_seasons (team_id, season_id) VALUES (?,?)");
            foreach ($seasonIds as $sLink) { $ins->execute([$id, $sLink]); }
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
$seasons = $db->query("SELECT * FROM seasons ORDER BY id DESC")->fetchAll();
$editing = null;
$editingSeasonIds = [];
if (isset($_GET['edit'])) {
    $stmt=$db->prepare("SELECT * FROM teams WHERE id=?"); $stmt->execute([(int)$_GET['edit']]); $editing=$stmt->fetch();
    if ($editing) {
        $es=$db->prepare("SELECT season_id FROM team_seasons WHERE team_id=?"); $es->execute([(int)$editing['id']]);
        $editingSeasonIds = array_map('intval', $es->fetchAll(PDO::FETCH_COLUMN));
    }
}
$teams = $db->query("
    SELECT t.*,
           (SELECT GROUP_CONCAT(s.name ORDER BY s.id DESC SEPARATOR ', ')
              FROM team_seasons ts JOIN seasons s ON s.id=ts.season_id WHERE ts.team_id=t.id) AS season_name,
           (SELECT MAX(ts.season_id) FROM team_seasons ts WHERE ts.team_id=t.id) AS max_sid,
           (SELECT COUNT(*) FROM season_entries se WHERE se.team_id=t.id) dc
    FROM teams t
    ORDER BY max_sid DESC, t.name
")->fetchAll();
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
      <form method="post" enctype="multipart/form-data" onsubmit="return teamSeasonsValid(this)">
        <input type="hidden" name="action" value="save"/>
        <input type="hidden" name="id" value="<?= $editing?(int)$editing['id']:0 ?>"/>
        <input type="hidden" name="logo_path_current" value="<?= h($editing['logo_path']??'') ?>"/>
        <div class="form-group">
          <label>Saisons *</label>
          <?php $teamSel = $editing ? $editingSeasonIds : ($seasons ? [(int)$seasons[0]['id']] : []); ?>
          <div style="display:flex;flex-direction:column;gap:6px;max-height:200px;overflow:auto;padding:8px;background:var(--bg3);border:1px solid var(--border);border-radius:4px">
            <?php if (!$seasons): ?>
              <span class="text-muted">Noch keine Saisons angelegt.</span>
            <?php else: foreach ($seasons as $s): ?>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" name="season_ids[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $teamSel, true)?'checked':'' ?>/>
              <span><?= h($s['name']) ?><?= $s['is_active']?' ★':'' ?></span>
            </label>
            <?php endforeach; endif; ?>
          </div>
          <div class="input-hint">Ein Team kann in mehreren Saisons gemeldet sein.</div>
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
<script>
function teamSeasonsValid(form){
  if (form.querySelectorAll('input[name="season_ids[]"]:checked').length === 0){
    alert('Bitte mindestens eine Saison auswählen.');
    return false;
  }
  return true;
}
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
