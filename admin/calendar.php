<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Rennkalender'; $adminPage = 'calendar';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin'); verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $trackId  = (int)($_POST['track_id'] ?? 0) ?: null;

        // Wenn Strecke aus DB gewählt → Daten von dort holen
        // Wenn manuell → aus den Feldern
        if ($trackId) {
            $t = $db->prepare("SELECT * FROM tracks WHERE id=?");
            $t->execute([$trackId]);
            $track = $t->fetch();
            $trackName = $track['name']       ?? trim($_POST['track_name'] ?? '');
            $location  = $track['location']   ?? trim($_POST['location']   ?? '');
            $country   = $track['country']    ?? trim($_POST['country']    ?? '');
        } else {
            $trackName = trim($_POST['track_name'] ?? '');
            $location  = trim($_POST['location']   ?? '');
            $country   = trim($_POST['country']    ?? '');
        }

        if (!$trackName) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Streckenname erforderlich!'];
            header('Location: '.SITE_URL.'/admin/calendar.php?season='.(int)$_POST['season_id']); exit;
        }

        $data = [
            (int)$_POST['season_id'],
            $trackId,
            (int)$_POST['round'],
            $trackName,
            $location,
            $country,
            $_POST['race_date'] ?: null,
            $_POST['race_time'] ?: null,
            (int)($_POST['laps'] ?? 0) ?: null,
            trim($_POST['notes'] ?? ''),
        ];

        if ($id) {
            $db->prepare("UPDATE races SET season_id=?,track_id=?,round=?,track_name=?,location=?,country=?,race_date=?,race_time=?,laps=?,notes=? WHERE id=?")
               ->execute([...$data, $id]);
        } else {
            $db->prepare("INSERT INTO races (season_id,track_id,round,track_name,location,country,race_date,race_time,laps,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute($data);
        }
        auditLog('race_save', 'races', $id, $trackName);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Rennen gespeichert!'];
        header('Location: '.SITE_URL.'/admin/calendar.php?season='.(int)$_POST['season_id']); exit;
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM races WHERE id=?")->execute([(int)$_POST['id']]);
        auditLog('race_delete', 'races', (int)$_POST['id']);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'🗑 Rennen gelöscht.'];
        header('Location: '.SITE_URL.'/admin/calendar.php?season='.(int)($_POST['season_id']??0)); exit;
    }
}

$seasons = $db->query("SELECT * FROM seasons ORDER BY year DESC")->fetchAll();
$activeSeason = array_values(array_filter($seasons, fn($s) => $s['is_active']))[0] ?? ($seasons[0] ?? null);
$seasonId = (int)($_GET['season'] ?? ($activeSeason['id'] ?? 0));

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM races WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
    if ($editing) $seasonId = (int)$editing['season_id'];
}

$races = [];
if ($seasonId) {
    $stmt = $db->prepare("
        SELECT rc.*, res.id AS result_id,
               tr.image_path AS track_image
        FROM races rc
        LEFT JOIN results res ON res.race_id = rc.id
        LEFT JOIN tracks tr ON tr.id = rc.track_id
        WHERE rc.season_id = ?
        ORDER BY rc.round ASC
    ");
    $stmt->execute([$seasonId]);
    $races = $stmt->fetchAll();
}

// Alle angelegten Strecken für Dropdown
$tracks = $db->query("SELECT * FROM tracks ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/includes/layout.php';
?>

<div class="admin-page-title">Renn<span style="color:var(--primary)">kalender</span></div>
<div class="admin-page-sub">Rennen planen, bearbeiten und verwalten</div>

<!-- Season selector -->
<?php if (count($seasons) > 1): ?>
<div class="flex gap-1 mb-3 flex-wrap">
  <?php foreach ($seasons as $s): ?>
  <a href="?season=<?= $s['id'] ?>"
     class="btn btn-sm <?= $s['id']==$seasonId ? 'btn-primary' : 'btn-secondary' ?>">
    <?= h($s['name']) ?> <?= h($s['year']??'') ?><?= $s['is_active'] ? ' ★' : '' ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid-2" style="gap:20px;align-items:start">

  <!-- Form -->
  <div class="card">
    <div class="card-header">
      <h3><?= $editing ? '✏️ Rennen bearbeiten' : '➕ Rennen hinzufügen' ?></h3>
      <?php if ($editing): ?>
        <a href="<?= SITE_URL ?>/admin/calendar.php?season=<?= $seasonId ?>" class="btn btn-secondary btn-sm">Abbrechen</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" id="race-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save"/>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>"/>

        <div class="form-group">
          <label>Saison *</label>
          <select name="season_id" class="form-control" required>
            <?php foreach ($seasons as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $s['id']==$seasonId ? 'selected' : '' ?>>
                <?= h($s['name']) ?> <?= h($s['year']??'') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row cols-2">
          <div class="form-group">
            <label>Runde #</label>
            <input type="number" name="round" class="form-control"
                   value="<?= $editing ? (int)$editing['round'] : count($races)+1 ?>" min="1"/>
          </div>
          <div class="form-group">
            <label>Runden (Rennen)</label>
            <input type="number" name="laps" class="form-control"
                   value="<?= $editing ? (int)$editing['laps'] : '' ?>" placeholder="z.B. 30"/>
          </div>
        </div>

        <!-- Strecken-Auswahl -->
        <div class="form-group">
          <label>Strecke auswählen</label>
          <?php if ($tracks): ?>
          <select name="track_id" id="track-select" class="form-control" onchange="fillTrackData(this)">
            <option value="">── Strecke wählen oder manuell eingeben ──</option>
            <?php foreach ($tracks as $tr): ?>
              <option value="<?= $tr['id'] ?>"
                      data-name="<?= h($tr['name']) ?>"
                      data-location="<?= h($tr['location'] ?? '') ?>"
                      data-country="<?= h($tr['country'] ?? '') ?>"
                      data-laps="<?= (int)($tr['corners'] ?? 0) ?>"
                      data-img="<?= h($tr['image_path'] ?? '') ?>"
                      <?= ($editing['track_id'] ?? 0) == $tr['id'] ? 'selected' : '' ?>>
                <?= h($tr['name']) ?>
                <?= $tr['location'] ? ' – '.h($tr['location']) : '' ?>
                <?= $tr['country'] ? ' ('.h($tr['country']).')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
          <div class="notice notice-info" style="margin:0">
            Noch keine Strecken angelegt.
            <a href="<?= SITE_URL ?>/admin/tracks.php" style="color:var(--primary)">→ Strecken verwalten</a>
          </div>
          <?php endif; ?>
        </div>

        <!-- Track Preview -->
        <div id="track-preview" style="display:<?= (!empty($editing['track_id']) && $editing['track_image'] ?? '') ? 'flex' : 'none' ?>;align-items:center;gap:12px;padding:10px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:4px;margin-bottom:14px">
          <img id="track-preview-img"
               src="<?= h($editing['track_image'] ?? '') ?>"
               style="width:80px;height:52px;object-fit:cover;border-radius:3px;flex-shrink:0"/>
          <div>
            <div id="track-preview-name" class="font-display font-bold" style="font-size:1rem"></div>
            <div id="track-preview-loc" class="text-muted" style="font-size:.8rem"></div>
          </div>
          <a href="<?= SITE_URL ?>/admin/tracks.php" class="btn btn-secondary btn-sm" style="margin-left:auto">✏️ Bearbeiten</a>
        </div>

        <!-- Manuelle Felder (werden bei Streckenauswahl automatisch gefüllt, bleiben editierbar) -->
        <div style="padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border);margin-bottom:16px">
          <div class="text-muted" style="font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px">
            Streckendetails <span style="opacity:.6">(wird bei Auswahl automatisch gefüllt, manuell überschreibbar)</span>
          </div>
          <div class="form-group" style="margin-bottom:12px">
            <label>Streckenname *</label>
            <input type="text" name="track_name" id="field-track-name" class="form-control"
                   value="<?= h($editing['track_name'] ?? '') ?>"
                   required placeholder="z.B. Monza"/>
          </div>
          <div class="form-row cols-2">
            <div class="form-group" style="margin-bottom:0">
              <label>Ort</label>
              <input type="text" name="location" id="field-location" class="form-control"
                     value="<?= h($editing['location'] ?? '') ?>" placeholder="Monza"/>
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label>Land</label>
              <input type="text" name="country" id="field-country" class="form-control"
                     value="<?= h($editing['country'] ?? '') ?>" placeholder="Italien"/>
            </div>
          </div>
        </div>

        <div class="form-row cols-2">
          <div class="form-group">
            <label>Datum</label>
            <input type="date" name="race_date" class="form-control"
                   value="<?= h($editing['race_date'] ?? '') ?>"/>
          </div>
          <div class="form-group">
            <label>Uhrzeit</label>
            <input type="time" name="race_time" class="form-control"
                   value="<?= h(substr($editing['race_time'] ?? '', 0, 5)) ?>"/>
          </div>
        </div>

        <div class="form-group">
          <label>Notizen (intern)</label>
          <textarea name="notes" class="form-control" style="min-height:55px"><?= h($editing['notes'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary w-full">
          <?= $editing ? '💾 Aktualisieren' : '➕ Rennen hinzufügen' ?>
        </button>
      </form>

      <?php if(!$tracks): ?>
      <div class="mt-2 text-center">
        <a href="<?= SITE_URL ?>/admin/tracks.php" class="btn btn-secondary btn-sm">
          🗺️ Strecken anlegen →
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Race List -->
  <div class="card">
    <div class="card-header">
      <h3>
        Kalender:
        <?php
        $idx = array_search($seasonId, array_column($seasons, 'id'));
        echo $idx !== false ? h($seasons[$idx]['name'].' '.($seasons[$idx]['year']??'')) : 'Alle';
        ?>
      </h3>
      <span class="badge badge-muted"><?= count($races) ?> Rennen</span>
    </div>
    <div class="card-body" style="padding:0">
      <?php if ($races):
        $today = date('Y-m-d');
        foreach ($races as $r):
          $isPast = $r['race_date'] && $r['race_date'] < $today;
      ?>
      <div class="flex flex-center gap-2" style="padding:10px 16px;border-bottom:1px solid var(--border);<?= $isPast ? 'opacity:.6' : '' ?>">

        <!-- Strecken-Thumbnail wenn vorhanden -->
        <?php if ($r['track_image']): ?>
          <img src="<?= h($r['track_image']) ?>"
               style="width:44px;height:30px;object-fit:cover;border-radius:2px;flex-shrink:0"/>
        <?php else: ?>
          <div style="width:44px;height:30px;background:var(--bg3);border-radius:2px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">🏁</div>
        <?php endif; ?>

        <div style="font-family:var(--font-display);font-weight:700;color:var(--text2);min-width:32px;font-size:.88rem">
          R<?= (int)$r['round'] ?>
        </div>

        <div class="flex-1" style="min-width:0">
          <div class="font-bold" style="font-size:.92rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= h($r['track_name']) ?>
            <?php if ($r['track_id']): ?>
              <span class="badge badge-info" style="font-size:.6rem;vertical-align:middle">DB</span>
            <?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:.76rem">
            <?= $r['race_date'] ? date('d.m.Y', strtotime($r['race_date'])) : 'TBD' ?>
            <?= $r['race_time'] ? ' · '.substr($r['race_time'], 0, 5).' Uhr' : '' ?>
            <?= $r['location'] ? ' · '.h($r['location']) : '' ?>
          </div>
        </div>

        <div class="flex gap-1">
          <?php if ($r['result_id']): ?>
            <a href="<?= SITE_URL ?>/results.php?id=<?= $r['result_id'] ?>" class="btn btn-secondary btn-sm" target="_blank" title="Ergebnis ansehen">🏁</a>
          <?php endif; ?>
          <?php if ($r['track_id']): ?>
            <a href="<?= SITE_URL ?>/admin/tracks.php?edit=<?= $r['track_id'] ?>" class="btn btn-secondary btn-sm" title="Strecke bearbeiten">🗺️</a>
          <?php endif; ?>
          <a href="?edit=<?= $r['id'] ?>&season=<?= $seasonId ?>" class="btn btn-secondary btn-sm">✏️</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Rennen löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete"/>
            <input type="hidden" name="id" value="<?= $r['id'] ?>"/>
            <input type="hidden" name="season_id" value="<?= $seasonId ?>"/>
            <button class="btn btn-danger btn-sm">🗑</button>
          </form>
        </div>
      </div>
      <?php endforeach;
      else: ?>
        <div style="padding:20px;text-align:center" class="text-muted">
          Noch keine Rennen in dieser Saison.<br/>
          <?php if (!$seasons): ?>
            <a href="<?= SITE_URL ?>/admin/seasons.php" class="btn btn-primary btn-sm mt-2">Saison anlegen →</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// Strecken-Daten als JSON für JS
const tracksData = <?= json_encode(array_combine(
    array_column($tracks, 'id'),
    $tracks
)) ?>;

function fillTrackData(select) {
    const id = parseInt(select.value);
    const preview = document.getElementById('track-preview');
    const previewImg = document.getElementById('track-preview-img');
    const previewName = document.getElementById('track-preview-name');
    const previewLoc = document.getElementById('track-preview-loc');

    if (!id || !tracksData[id]) {
        preview.style.display = 'none';
        return;
    }

    const t = tracksData[id];

    // Felder automatisch befüllen
    document.getElementById('field-track-name').value = t.name  || '';
    document.getElementById('field-location').value   = t.location || '';
    document.getElementById('field-country').value    = t.country  || '';

    // Preview anzeigen
    if (t.image_path) {
        previewImg.src = t.image_path;
        previewImg.style.display = 'block';
    } else {
        previewImg.style.display = 'none';
    }
    previewName.textContent = t.name || '';
    previewLoc.textContent  = [t.location, t.country].filter(Boolean).join(', ');
    preview.style.display   = 'flex';
}

// Beim Laden: wenn eine Strecke vorausgewählt ist (Edit-Modus), Preview zeigen
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('track-select');
    if (sel && sel.value) {
        // Preview nur wenn track_id gesetzt, aber Felder NICHT überschreiben (kommen aus DB)
        const id = parseInt(sel.value);
        if (id && tracksData[id]) {
            const t = tracksData[id];
            const preview = document.getElementById('track-preview');
            const previewImg = document.getElementById('track-preview-img');
            document.getElementById('track-preview-name').textContent = t.name || '';
            document.getElementById('track-preview-loc').textContent  = [t.location, t.country].filter(Boolean).join(', ');
            if (t.image_path) { previewImg.src = t.image_path; previewImg.style.display = 'block'; }
            preview.style.display = 'flex';
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
