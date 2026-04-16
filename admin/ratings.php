<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Fahrer Ratings'; $adminPage = 'ratings';
requireRole('admin');
$db = getDB();

$activeSeason = $db->query("SELECT * FROM seasons WHERE is_active=1 LIMIT 1")->fetch();
$sid = $activeSeason['id'] ?? 0;

// POST: Ratings berechnen oder Einstellungen speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'calculate') {
        $targetSid = (int)($_POST['season_id'] ?? $sid);
        if ($targetSid) {
            $count = calculateRatings($db, $targetSid);
            auditLog('ratings_calculate', 'driver_ratings', $targetSid, "{$count} Fahrer");
            $_SESSION['flash'] = ['type'=>'success', 'msg'=>"✅ Ratings berechnet: {$count} Fahrer aktualisiert."];
        }
        header('Location: '.SITE_URL.'/admin/ratings.php'); exit;
    }

    if ($action === 'settings') {
        setSetting('rating_min_starts',  (int)($_POST['rating_min_starts']  ?? 2));
        setSetting('rating_full_starts', (int)($_POST['rating_full_starts'] ?? 4));
        setSetting('rating_show_public', isset($_POST['rating_show_public']) ? '1' : '0');
        $_SESSION['flash'] = ['type'=>'success', 'msg'=>'✅ Einstellungen gespeichert.'];
        header('Location: '.SITE_URL.'/admin/ratings.php'); exit;
    }
}

// Alle Saisons
$seasons = $db->query("SELECT * FROM seasons ORDER BY year DESC")->fetchAll();

// Aktuelle Ratings laden
$ratings = [];
if ($sid) {
    $ratings = $db->query("
        SELECT dr.*, d.name AS driver_name, d.photo_path, d.nationality,
               t.name AS team_name, t.color AS team_color
        FROM driver_ratings dr
        JOIN drivers d ON d.id = dr.driver_id
        LEFT JOIN season_entries se ON se.driver_id = d.id AND se.season_id = {$sid}
        LEFT JOIN teams t ON t.id = se.team_id
        WHERE dr.season_id = {$sid}
        ORDER BY dr.overall DESC
    ")->fetchAll();
}

$fullStarts = (int)getSetting('rating_full_starts', '4');

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Fahrer <span style="color:var(--primary)">Ratings</span></div>
<div class="admin-page-sub">RPCE – Racecraft · Pace · Consistency · Experience</div>

<div class="grid-2" style="gap:20px;align-items:start">

  <!-- Einstellungen + Berechnen -->
  <div>
    <div class="card mb-3">
      <div class="card-header"><h3>⚙️ Einstellungen</h3></div>
      <div class="card-body">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="settings"/>
          <div class="form-row cols-2">
            <div class="form-group">
              <label>Mindest-Starts (Rating sichtbar)</label>
              <input type="number" name="rating_min_starts" class="form-control"
                     value="<?= getSetting('rating_min_starts','2') ?>" min="1" max="10" style="max-width:80px"/>
            </div>
            <div class="form-group">
              <label>Starts für vollwertiges Rating</label>
              <input type="number" name="rating_full_starts" class="form-control"
                     value="<?= getSetting('rating_full_starts','4') ?>" min="1" max="20" style="max-width:80px"/>
              <div class="form-hint">Darunter: vorläufig (*)</div>
            </div>
          </div>
          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" name="rating_show_public" <?= getSetting('rating_show_public','1')==='1'?'checked':'' ?>/>
              Ratings öffentlich anzeigen
            </label>
          </div>
          <button type="submit" class="btn btn-secondary btn-sm">💾 Speichern</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>🔄 Ratings berechnen</h3></div>
      <div class="card-body">
        <p class="text-muted mb-3" style="font-size:.85rem">
          Berechnet die Ratings aller Fahrer der gewählten Saison neu anhand der eingetragenen Ergebnisse.
        </p>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="calculate"/>
          <div class="form-group">
            <label>Saison</label>
            <select name="season_id" class="form-control">
              <?php foreach ($seasons as $s): ?>
              <option value="<?= $s['id'] ?>" <?= $s['id']==$sid?'selected':'' ?>>
                <?= h($s['name']) ?> <?= h($s['year']??'') ?> <?= $s['is_active']?'(aktiv)':'' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">⚡ Jetzt berechnen</button>
        </form>
        <div class="notice notice-info mt-3" style="font-size:.8rem">
          💡 Ratings werden nicht automatisch aktualisiert. Nach jedem Rennen neu berechnen.
        </div>
      </div>
    </div>
  </div>

  <!-- Aktuelle Ratings Übersicht -->
  <div class="card">
    <div class="card-header">
      <h3>📊 Aktuelle Ratings<?= $activeSeason ? ' – '.h($activeSeason['name']) : '' ?></h3>
    </div>
    <div class="card-body" style="padding:0">
      <?php if ($ratings): ?>
      <table class="admin-table" style="font-size:.83rem">
        <thead>
          <tr>
            <th>Fahrer</th>
            <th style="width:50px;text-align:center" title="Racecraft">R</th>
            <th style="width:50px;text-align:center" title="Pace">P</th>
            <th style="width:50px;text-align:center" title="Consistency">C</th>
            <th style="width:50px;text-align:center" title="Experience">E</th>
            <th style="width:65px;text-align:center">Ges.</th>
            <th style="width:50px;text-align:center">Starts</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ratings as $i => $r):
            $provisional = $r['starts'] < $fullStarts;
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <?php if ($r['team_color']): ?>
                <span style="width:3px;height:24px;border-radius:2px;background:<?= h($r['team_color']) ?>;flex-shrink:0"></span>
                <?php endif; ?>
                <div>
                  <div style="font-weight:600"><?= h($r['driver_name']) ?></div>
                  <?php if ($r['team_name']): ?><div class="text-muted" style="font-size:.72rem"><?= h($r['team_name']) ?></div><?php endif; ?>
                </div>
              </div>
            </td>
            <?php foreach (['racecraft','pace','consistency','experience'] as $attr): ?>
            <td style="text-align:center">
              <span style="font-family:var(--font-display);font-weight:700;font-size:.9rem;color:<?= ratingColor((float)$r[$attr]) ?>">
                <?= number_format((float)$r[$attr], 1) ?>
              </span>
            </td>
            <?php endforeach; ?>
            <td style="text-align:center">
              <span style="font-family:var(--font-display);font-weight:900;font-size:1.05rem;color:var(--primary)">
                <?= number_format((float)$r['overall'], 1) ?>
              </span>
              <?= $provisional ? '<sup style="color:var(--secondary);font-size:.65rem">*</sup>' : '' ?>
            </td>
            <td style="text-align:center;color:var(--text2)"><?= (int)$r['starts'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="text-muted" style="font-size:.75rem;padding:8px 16px">* Vorläufig (weniger als <?= $fullStarts ?> Starts)</div>
      <?php else: ?>
      <div style="padding:18px" class="text-muted">
        Noch keine Ratings berechnet.
        <?= $sid ? 'Klicke auf „Jetzt berechnen".' : 'Keine aktive Saison.' ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>
<?php
// Hilfsfunktion: Farbe nach Rating-Wert
function ratingColor(float $v): string {
    if ($v >= 8.5) return '#4cffb0';
    if ($v >= 7.0) return '#a0f080';
    if ($v >= 5.5) return '#f5a623';
    if ($v >= 4.0) return '#ff9040';
    return '#ff6060';
}
?>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
