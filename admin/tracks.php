<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Strecken'; $adminPage = 'tracks';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin'); verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $imgPath = $_POST['image_path_current'] ?? '';
        $layoutPath = $_POST['layout_path_current'] ?? '';
        if (!empty($_FILES['image_file']['name'])) { $up=uploadFile($_FILES['image_file'],'tracks'); if($up)$imgPath=$up; }
        if (!empty($_FILES['layout_file']['name'])) { $up=uploadFile($_FILES['layout_file'],'tracks'); if($up)$layoutPath=$up; }
        $lat = strlen(trim($_POST['lat']??'')) ? (float)$_POST['lat'] : null;
        $lon = strlen(trim($_POST['lon']??'')) ? (float)$_POST['lon'] : null;
        $data = [trim($_POST['name']??''),trim($_POST['location']??''),trim($_POST['country']??''),(float)($_POST['length_km']??0)?:(null),(int)($_POST['corners']??0)?:(null),trim($_POST['lap_record']??''),trim($_POST['lap_record_driver']??''),(int)($_POST['lap_record_year']??0)?:(null),$imgPath,$layoutPath,trim($_POST['description']??''),$lat,$lon];
        if ($id) {
            $db->prepare("UPDATE tracks SET name=?,location=?,country=?,length_km=?,corners=?,lap_record=?,lap_record_driver=?,lap_record_year=?,image_path=?,layout_path=?,description=?,lat=?,lon=? WHERE id=?")->execute([...$data,$id]);
        } else {
            $db->prepare("INSERT INTO tracks (name,location,country,length_km,corners,lap_record,lap_record_driver,lap_record_year,image_path,layout_path,description,lat,lon) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($data);
        }
        auditLog('track_save','tracks',$id);
        $_SESSION['flash']=['type'=>'success','msg'=>'✅ Strecke gespeichert!'];
        header('Location: '.SITE_URL.'/admin/tracks.php'); exit;
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM tracks WHERE id=?")->execute([(int)$_POST['id']]);
        $_SESSION['flash']=['type'=>'success','msg'=>'🗑 Strecke gelöscht.'];
        header('Location: '.SITE_URL.'/admin/tracks.php'); exit;
    }
}
$editing = null;
if (isset($_GET['edit'])) { $stmt=$db->prepare("SELECT * FROM tracks WHERE id=?"); $stmt->execute([(int)$_GET['edit']]); $editing=$stmt->fetch(); }
$tracks = $db->query("SELECT t.*, COUNT(r.id) AS race_count FROM tracks t LEFT JOIN races r ON r.track_id=t.id OR r.track_name=t.name GROUP BY t.id ORDER BY t.name")->fetchAll();
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Strecken <span style="color:var(--primary)">verwalten</span></div>
<div class="admin-page-sub">Streckeninformationen, Layouts und Rekorde pflegen</div>
<div class="grid-2" style="gap:20px;align-items:start">
  <div class="card">
    <div class="card-header">
      <h3><?= $editing?'✏️ Strecke bearbeiten':'➕ Neue Strecke' ?></h3>
      <?php if($editing): ?><a href="<?= SITE_URL ?>/admin/tracks.php" class="btn btn-secondary btn-sm">Abbrechen</a><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save"/>
        <input type="hidden" name="id" value="<?= $editing?(int)$editing['id']:0 ?>"/>
        <input type="hidden" name="image_path_current" value="<?= h($editing['image_path']??'') ?>"/>
        <input type="hidden" name="layout_path_current" value="<?= h($editing['layout_path']??'') ?>"/>
        <div class="form-group"><label>Streckenname *</label><input type="text" name="name" class="form-control" value="<?= h($editing['name']??'') ?>" required placeholder="Monza, Spa-Francorchamps..."/></div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Ort</label><input type="text" name="location" class="form-control" value="<?= h($editing['location']??'') ?>" placeholder="Monza"/></div>
          <div class="form-group"><label>Land</label><input type="text" name="country" class="form-control" value="<?= h($editing['country']??'') ?>" placeholder="Italien"/></div>
        </div>
        <div class="form-row cols-2">
          <div class="form-group">
            <label>Breitengrad (Lat) <span class="text-muted" style="font-weight:400;font-size:.75rem">für Wettervorschau</span></label>
            <input type="text" name="lat" class="form-control" value="<?= h($editing['lat']??'') ?>" placeholder="z.B. 26.0325"/>
          </div>
          <div class="form-group">
            <label>Längengrad (Lon)</label>
            <input type="text" name="lon" class="form-control" value="<?= h($editing['lon']??'') ?>" placeholder="z.B. 50.5106"/>
          </div>
        </div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Länge (km)</label><input type="number" step="0.001" name="length_km" class="form-control" value="<?= h($editing['length_km']??'') ?>" placeholder="5.793"/></div>
          <div class="form-group"><label>Kurvenanzahl</label><input type="number" name="corners" class="form-control" value="<?= h($editing['corners']??'') ?>" placeholder="11"/></div>
        </div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Streckenrekord</label><input type="text" name="lap_record" class="form-control" value="<?= h($editing['lap_record']??'') ?>" placeholder="1:19.119"/></div>
          <div class="form-group"><label>Rekordhalter</label><input type="text" name="lap_record_driver" class="form-control" value="<?= h($editing['lap_record_driver']??'') ?>"/></div>
        </div>
        <div class="form-group"><label>Rekordjahr</label><input type="number" name="lap_record_year" class="form-control" value="<?= h($editing['lap_record_year']??'') ?>" placeholder="2024"/></div>
        <div class="form-group"><label>Streckenbild</label>
          <?php if(!empty($editing['image_path'])): ?><img src="<?= h($editing['image_path']) ?>" style="max-height:80px;margin-bottom:8px;border-radius:3px"/><?php endif; ?>
          <input type="file" name="image_file" class="form-control" accept="image/*"/>
        </div>
        <div class="form-group"><label>Streckenlayout (SVG/PNG)</label>
          <?php if(!empty($editing['layout_path'])): ?><img src="<?= h($editing['layout_path']) ?>" style="max-height:60px;margin-bottom:8px"/><?php endif; ?>
          <input type="file" name="layout_file" class="form-control" accept="image/*"/>
        </div>
        <div class="form-group"><label>Beschreibung</label><textarea name="description" class="form-control" style="min-height:80px"><?= h($editing['description']??'') ?></textarea></div>
        <button type="submit" class="btn btn-primary w-full"><?= $editing?'💾 Aktualisieren':'➕ Strecke anlegen' ?></button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Alle Strecken (<?= count($tracks) ?>)</h3></div>
    <div class="card-body" style="padding:0;max-height:700px;overflow-y:auto">
      <?php if($tracks): foreach($tracks as $t): ?>
      <div class="flex flex-center gap-2" style="padding:10px 16px;border-bottom:1px solid var(--border)">
        <?php if($t['image_path']): ?><img src="<?= h($t['image_path']) ?>" style="width:48px;height:32px;object-fit:cover;border-radius:2px"/><?php else: ?><div style="width:48px;height:32px;background:var(--bg3);border-radius:2px;display:flex;align-items:center;justify-content:center;font-size:1.2rem">🏁</div><?php endif; ?>
        <div class="flex-1">
          <div class="font-bold" style="font-size:.92rem"><?= h($t['name']) ?></div>
          <div class="text-muted" style="font-size:.75rem"><?= h($t['country']??'') ?><?= $t['length_km']?' · '.$t['length_km'].' km':'' ?><?= $t['race_count']>0?' · '.$t['race_count'].' Rennen':'' ?></div>
        </div>
        <div class="flex gap-1">
          <a href="<?= SITE_URL ?>/track.php?id=<?= $t['id'] ?>" class="btn btn-secondary btn-sm" target="_blank">👁</a>
          <a href="?edit=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">✏️</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Strecke löschen?')">
            <?= csrfField() ?><input type="hidden" name="action" value="delete"/><input type="hidden" name="id" value="<?= $t['id'] ?>"/>
            <button class="btn btn-danger btn-sm">🗑</button>
          </form>
        </div>
      </div>
      <?php endforeach; else: ?><div style="padding:18px" class="text-muted">Noch keine Strecken angelegt.</div><?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
