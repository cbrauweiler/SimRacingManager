<?php
// admin/drivers.php – Globale Fahrerverwaltung (saisonunabhängig)
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Fahrer'; $adminPage = 'drivers';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin'); verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $photoPath = $_POST['photo_path_current'] ?? '';
        if (!empty($_FILES['photo_file']['name'])) {
            $up = uploadFile($_FILES['photo_file'], 'photos', ['image/jpeg','image/png','image/webp']);
            if ($up) $photoPath = $up;
        }
        $name        = trim($_POST['name'] ?? '');
        $nationality = strtoupper(trim($_POST['nationality'] ?? ''));
        $bio         = trim($_POST['bio'] ?? '');

        if (!$name) { $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Name erforderlich!']; header('Location: '.SITE_URL.'/admin/drivers.php'); exit; }

        if ($id) {
            $db->prepare("UPDATE drivers SET name=?, nationality=?, photo_path=?, bio=? WHERE id=?")
               ->execute([$name, $nationality, $photoPath, $bio, $id]);
            auditLog('driver_update', 'drivers', $id, $name);
        } else {
            $db->prepare("INSERT INTO drivers (name, nationality, photo_path, bio) VALUES (?,?,?,?)")
               ->execute([$name, $nationality, $photoPath, $bio]);
            auditLog('driver_create', 'drivers', (int)$db->lastInsertId(), $name);
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Fahrer gespeichert!'];
        header('Location: '.SITE_URL.'/admin/drivers.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        // Prüfen ob Fahrer in Ergebnissen vorkommt
        $used = $db->prepare("SELECT COUNT(*) FROM result_entries WHERE driver_id=?");
        $used->execute([$id]);
        if ($used->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Fahrer kann nicht gelöscht werden – er hat bereits Rennergebnisse!'];
        } else {
            $db->prepare("DELETE FROM drivers WHERE id=?")->execute([$id]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'🗑 Fahrer gelöscht.'];
            auditLog('driver_delete', 'drivers', $id);
        }
        header('Location: '.SITE_URL.'/admin/drivers.php'); exit;
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM drivers WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}

// Alle globalen Fahrer mit Saison-Info
$drivers = $db->query("
    SELECT d.*,
           COUNT(DISTINCT se.season_id) AS season_count,
           GROUP_CONCAT(DISTINCT s.name ORDER BY s.year DESC SEPARATOR ', ') AS seasons_list
    FROM drivers d
    LEFT JOIN season_entries se ON se.driver_id = d.id
    LEFT JOIN seasons s ON s.id = se.season_id
    GROUP BY d.id
    ORDER BY d.name
")->fetchAll();

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Fahrer <span style="color:var(--primary)">Stammdaten</span></div>
<div class="admin-page-sub">Globale Fahrerverwaltung – saisonunabhängig. Saison-Zuordnung erfolgt im <a href="<?= SITE_URL ?>/admin/lineup.php" style="color:var(--primary)">Saison-Lineup</a>.</div>

<div class="grid-2" style="gap:20px;align-items:start">
  <!-- Form -->
  <div class="card">
    <div class="card-header">
      <h3><?= $editing ? '✏️ Fahrer bearbeiten' : '➕ Neuer Fahrer' ?></h3>
      <?php if($editing): ?><a href="<?= SITE_URL ?>/admin/drivers.php" class="btn btn-secondary btn-sm">Abbrechen</a><?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save"/>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>"/>
        <input type="hidden" name="photo_path_current" value="<?= h($editing['photo_path'] ?? '') ?>"/>

        <div class="form-group">
          <label>Vollständiger Name *</label>
          <input type="text" name="name" class="form-control" value="<?= h($editing['name'] ?? '') ?>" required placeholder="Vorname Nachname"/>
        </div>
        <div class="form-group">
          <label>Nationalität</label>
          <input type="text" name="nationality" class="form-control" value="<?= h($editing['nationality'] ?? '') ?>" maxlength="5" placeholder="DE, AT, CH, GB..."/>
          <div class="input-hint">2–3 Buchstaben ISO-Kürzel</div>
        </div>
        <div class="form-group">
          <label>Foto</label>
          <?php if(!empty($editing['photo_path'])): ?>
            <img src="<?= h($editing['photo_path']) ?>" style="height:60px;width:60px;border-radius:50%;object-fit:cover;margin-bottom:8px;display:block"/>
          <?php endif; ?>
          <input type="file" name="photo_file" class="form-control" accept="image/*"/>
          <div class="input-hint">JPG, PNG, WebP – quadratisch empfohlen</div>
        </div>
        <div class="form-group">
          <label>Bio / Beschreibung</label>
          <textarea name="bio" class="form-control" style="min-height:80px" placeholder="Kurze Beschreibung des Fahrers..."><?= h($editing['bio'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-full"><?= $editing ? '💾 Aktualisieren' : '➕ Fahrer anlegen' ?></button>
      </form>
    </div>
    <?php if($editing): ?>
    <div class="card-footer">
      <div class="text-muted" style="font-size:.82rem">Startnummer, Team und Reserve-Status werden im <a href="<?= SITE_URL ?>/admin/lineup.php" style="color:var(--primary)">Saison-Lineup</a> verwaltet.</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Driver List -->
  <div class="card">
    <div class="card-header">
      <h3>Alle Fahrer (<?= count($drivers) ?>)</h3>
      <a href="<?= SITE_URL ?>/admin/lineup.php" class="btn btn-primary btn-sm">⚡ Saison-Lineup</a>
    </div>
    <div class="card-body" style="padding:0;max-height:650px;overflow-y:auto">
      <?php if($drivers): foreach($drivers as $d): ?>
      <div class="flex flex-center gap-2" style="padding:10px 16px;border-bottom:1px solid var(--border)">
        <?php if($d['photo_path']): ?>
          <img src="<?= h($d['photo_path']) ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0"/>
        <?php else: ?>
          <div class="driver-avatar" style="width:36px;height:36px;flex-shrink:0"><?= h(mb_substr($d['name'],0,2)) ?></div>
        <?php endif; ?>
        <div class="flex-1" style="min-width:0">
          <div class="font-bold" style="font-size:.92rem"><?= h($d['name']) ?></div>
          <div class="text-muted" style="font-size:.75rem">
            <?= $d['nationality'] ? h($d['nationality']).' · ' : '' ?>
            <?= $d['season_count'] ?> Saison<?= $d['season_count'] != 1 ? 'en' : '' ?>
            <?php if($d['seasons_list']): ?> (<?= h($d['seasons_list']) ?>)<?php endif; ?>
          </div>
        </div>
        <div class="flex gap-1">
          <a href="<?= SITE_URL ?>/driver.php?id=<?= $d['id'] ?>" class="btn btn-secondary btn-sm" target="_blank">👁</a>
          <a href="?edit=<?= $d['id'] ?>" class="btn btn-secondary btn-sm">✏️</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Fahrer wirklich löschen?\nAcht: Fahrer mit Rennergebnissen können nicht gelöscht werden.')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete"/>
            <input type="hidden" name="id" value="<?= $d['id'] ?>"/>
            <button class="btn btn-danger btn-sm">🗑</button>
          </form>
        </div>
      </div>
      <?php endforeach; else: ?>
        <div style="padding:18px" class="text-muted">Noch keine Fahrer angelegt.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
