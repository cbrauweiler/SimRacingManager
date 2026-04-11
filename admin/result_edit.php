<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Ergebnis bearbeiten'; $adminPage = 'results';
$db = getDB();
requireLogin();

$resultId = (int)($_GET['id'] ?? 0);
if (!$resultId) { header('Location: '.SITE_URL.'/admin/results.php'); exit; }

// Ergebnis laden
$result = $db->prepare("SELECT r.*,rc.track_name,rc.round,rc.race_date,rc.season_id,s.name AS season_name FROM results r JOIN races rc ON rc.id=r.race_id JOIN seasons s ON s.id=rc.season_id WHERE r.id=?");
$result->execute([$resultId]); $result = $result->fetch();
if (!$result) { header('Location: '.SITE_URL.'/admin/results.php'); exit; }

// POST: Speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_entries') {
        $entries = $_POST['entry'] ?? [];
        foreach ($entries as $entryId => $e) {
            $entryId = (int)$entryId;
            $pos     = $e['dnf'] || $e['dsq'] ? null : ((int)($e['position'] ?: 0) ?: null);
            $laps    = (int)($e['laps'] ?? 0) ?: null;
            $points  = (float)($e['points'] ?? 0);
            $dnf     = isset($e['dnf']) ? 1 : 0;
            $dsq     = isset($e['dsq']) ? 1 : 0;
            $fl      = isset($e['is_fastest_lap']) ? 1 : 0;

            // Nur ein Fahrer kann FL haben – wird unten validiert
            $db->prepare("UPDATE result_entries SET
                position=?, laps=?, total_time=?, gap=?,
                fastest_lap=?, is_fastest_lap=?, points=?,
                dnf=?, dsq=?
                WHERE id=? AND result_id=?
            ")->execute([
                $pos,
                $laps,
                trim($e['total_time'] ?? ''),
                trim($e['gap'] ?? ''),
                trim($e['fastest_lap'] ?? ''),
                $fl,
                $points,
                $dnf,
                $dsq,
                $entryId,
                $resultId
            ]);
        }
        // Sicherstellen dass nur ein Fahrer FL hat
        $flCount = $db->prepare("SELECT COUNT(*) FROM result_entries WHERE result_id=? AND is_fastest_lap=1");
        $flCount->execute([$resultId]);
        if ($flCount->fetchColumn() > 1) {
            // Alle FL außer dem ersten zurücksetzen
            $db->prepare("UPDATE result_entries SET is_fastest_lap=0 WHERE result_id=? AND is_fastest_lap=1 ORDER BY position ASC LIMIT 99 OFFSET 1")->execute([$resultId]);
        }
        auditLog('result_edit','results',$resultId,'Manual edit');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Ergebnis gespeichert!'];
        header('Location: '.SITE_URL.'/admin/result_edit.php?id='.$resultId); exit;
    }

    if ($action === 'add_entry') {
        $driverId = (int)($_POST['new_driver_id'] ?? 0) ?: null;
        $teamId   = (int)($_POST['new_team_id']   ?? 0) ?: null;
        $pos      = (int)($_POST['new_position']  ?? 0) ?: null;
        $db->prepare("INSERT INTO result_entries (result_id,driver_id,team_id,position,laps,points,dnf,dsq) VALUES (?,?,?,?,0,0,0,0)")
           ->execute([$resultId,$driverId,$teamId,$pos]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Fahrer hinzugefügt.'];
        header('Location: '.SITE_URL.'/admin/result_edit.php?id='.$resultId); exit;
    }

    if ($action === 'delete_entry') {
        $db->prepare("DELETE FROM result_entries WHERE id=? AND result_id=?")->execute([(int)$_POST['entry_id'],$resultId]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'🗑 Eintrag gelöscht.'];
        header('Location: '.SITE_URL.'/admin/result_edit.php?id='.$resultId); exit;
    }

    if ($action === 'recalc_points') {
        // Punkte neu berechnen anhand der Punktetabelle und der aktuellen Positionen
        $pointsSystem = getSetting('points_system','25,18,15,12,10,8,6,4,2,1');
        $pts = array_map('floatval', explode(',', $pointsSystem));
        $entries = $db->prepare("SELECT id,position,dnf,dsq FROM result_entries WHERE result_id=? AND dnf=0 AND dsq=0 ORDER BY position ASC");
        $entries->execute([$resultId]); $entries=$entries->fetchAll();
        foreach ($entries as $e) {
            $p = isset($pts[$e['position']-1]) ? $pts[$e['position']-1] : 0;
            $db->prepare("UPDATE result_entries SET points=? WHERE id=?")->execute([$p,$e['id']]);
        }
        // DNF/DSQ = 0 Punkte
        $db->prepare("UPDATE result_entries SET points=0 WHERE result_id=? AND (dnf=1 OR dsq=1)")->execute([$resultId]);
        auditLog('result_recalc','results',$resultId);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Punkte neu berechnet!'];
        header('Location: '.SITE_URL.'/admin/result_edit.php?id='.$resultId); exit;
    }
}

// Einträge laden
$entries = $db->prepare("
    SELECT re.*, d.name AS driver_name, t.name AS team_name, t.color
    FROM result_entries re
    LEFT JOIN drivers d ON d.id=re.driver_id
    LEFT JOIN teams t ON t.id=re.team_id
    WHERE re.result_id=?
    ORDER BY re.dnf ASC, re.dsq ASC, re.position ASC, re.id ASC
");
$entries->execute([$resultId]); $entries=$entries->fetchAll();

// Fahrer + Teams der Saison für Dropdowns
$seasonDrivers = $db->prepare("SELECT d.id,d.name,se.number,t.name AS team_name FROM season_entries se JOIN drivers d ON d.id=se.driver_id LEFT JOIN teams t ON t.id=se.team_id WHERE se.season_id=? ORDER BY se.number,d.name");
$seasonDrivers->execute([$result['season_id']]); $seasonDrivers=$seasonDrivers->fetchAll();

$seasonTeams = $db->prepare("SELECT id,name,color FROM teams WHERE season_id=? ORDER BY name");
$seasonTeams->execute([$result['season_id']]); $seasonTeams=$seasonTeams->fetchAll();

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Ergebnis <span style="color:var(--primary)">bearbeiten</span></div>
<div class="admin-page-sub">
  R<?= (int)$result['round'] ?> · <?= h($result['track_name']) ?> · <?= h($result['season_name']) ?>
  <?= $result['race_date'] ? ' · '.date('d.m.Y',strtotime($result['race_date'])) : '' ?>
</div>

<div class="flex gap-2 mb-3" style="flex-wrap:wrap">
  <a href="<?= SITE_URL ?>/admin/results.php" class="btn btn-secondary">← Zurück</a>
  <a href="<?= SITE_URL ?>/results.php?id=<?= $resultId ?>" target="_blank" class="btn btn-secondary">👁 Vorschau</a>
  <form method="post" style="display:inline" onsubmit="return confirm('Punkte neu berechnen? Aktuelle Punkte werden überschrieben!')">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="recalc_points"/>
    <button class="btn btn-secondary" title="Punkte anhand der Punktetabelle neu berechnen">🔄 Punkte neu berechnen</button>
  </form>
</div>

<!-- Einträge bearbeiten -->
<form method="post" id="edit-form">
<?= csrfField() ?>
<input type="hidden" name="action" value="save_entries"/>
<div class="card mb-3">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <h3>🏁 Ergebniseinträge (<?= count($entries) ?> Fahrer)</h3>
    <button type="submit" class="btn btn-primary btn-sm">💾 Alles speichern</button>
  </div>
  <div class="card-body" style="padding:0">
    <div class="overflow-x">
    <table class="admin-table" style="font-size:.85rem">
      <thead>
        <tr>
          <th style="width:50px">POS</th>
          <th style="min-width:160px">Fahrer</th>
          <th style="min-width:140px">Team</th>
          <th style="width:60px">Runden</th>
          <th style="width:110px">Gesamtzeit</th>
          <th style="width:100px">Abstand</th>
          <th style="width:90px">Bestzeit</th>
          <th style="width:44px" title="Fastest Lap">FL</th>
          <th style="width:44px">DNF</th>
          <th style="width:44px">DSQ</th>
          <th style="width:70px">Punkte</th>
          <th style="width:40px"></th>
        </tr>
      </thead>
      <tbody id="entries-tbody">
        <?php foreach ($entries as $e): ?>
        <tr class="<?= $e['dnf']||$e['dsq']?'opacity-50':'' ?>" id="row-<?= $e['id'] ?>">
          <td>
            <input type="number" name="entry[<?= $e['id'] ?>][position]"
                   value="<?= $e['position'] ?>" min="1" max="99"
                   class="form-control" style="width:52px;padding:4px 6px;text-align:center"
                   <?= $e['dnf']||$e['dsq']?'disabled':'' ?>/>
          </td>
          <td>
            <span style="font-weight:700"><?= h($e['driver_name']??$e['driver_name_raw']??'?') ?></span>
          </td>
          <td>
            <?php if($e['color']): ?>
              <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= h($e['color']) ?>;margin-right:5px;vertical-align:middle"></span>
            <?php endif; ?>
            <?= h($e['team_name']??$e['team_name_raw']??'–') ?>
          </td>
          <td>
            <input type="number" name="entry[<?= $e['id'] ?>][laps]"
                   value="<?= $e['laps'] ?>" min="0"
                   class="form-control" style="width:58px;padding:4px 6px;text-align:center"/>
          </td>
          <td>
            <input type="text" name="entry[<?= $e['id'] ?>][total_time]"
                   value="<?= h($e['total_time']??'') ?>"
                   class="form-control" style="width:108px;padding:4px 6px;font-family:monospace;font-size:.8rem"
                   placeholder="1:20:37.377"/>
          </td>
          <td>
            <input type="text" name="entry[<?= $e['id'] ?>][gap]"
                   value="<?= h($e['gap']??'') ?>"
                   class="form-control" style="width:96px;padding:4px 6px;font-family:monospace;font-size:.8rem"
                   placeholder="+1.060s"/>
          </td>
          <td>
            <input type="text" name="entry[<?= $e['id'] ?>][fastest_lap]"
                   value="<?= h($e['fastest_lap']??'') ?>"
                   class="form-control" style="width:88px;padding:4px 6px;font-family:monospace;font-size:.8rem"
                   placeholder="2:21.209"/>
          </td>
          <td style="text-align:center">
            <input type="checkbox" name="entry[<?= $e['id'] ?>][is_fastest_lap]"
                   <?= $e['is_fastest_lap']?'checked':'' ?>
                   title="Schnellste Runde"
                   onchange="handleFL(this, <?= $e['id'] ?>)"/>
          </td>
          <td style="text-align:center">
            <input type="checkbox" name="entry[<?= $e['id'] ?>][dnf]"
                   <?= $e['dnf']?'checked':'' ?>
                   onchange="handleDNF(this, <?= $e['id'] ?>)"/>
          </td>
          <td style="text-align:center">
            <input type="checkbox" name="entry[<?= $e['id'] ?>][dsq]"
                   <?= $e['dsq']?'checked':'' ?>
                   onchange="handleDNF(this, <?= $e['id'] ?>)"/>
          </td>
          <td>
            <input type="number" name="entry[<?= $e['id'] ?>][points]"
                   value="<?= $e['points'] ?>" min="0" step="0.5"
                   class="form-control" style="width:66px;padding:4px 6px;text-align:center;font-weight:700;color:var(--primary)"/>
          </td>
          <td>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Eintrag löschen?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_entry"/>
              <input type="hidden" name="entry_id" value="<?= $e['id'] ?>"/>
              <button class="btn btn-danger btn-sm btn-icon" type="submit">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="card-body" style="border-top:1px solid var(--border);padding:10px 16px;text-align:right">
    <button type="submit" class="btn btn-primary">💾 Alle Änderungen speichern</button>
  </div>
</div>
</form>

<!-- Fahrer hinzufügen -->
<div class="card mb-3">
  <div class="card-header"><h3>➕ Fahrer hinzufügen</h3></div>
  <div class="card-body">
    <form method="post" class="form-row cols-4" style="align-items:flex-end;gap:12px">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_entry"/>
      <div class="form-group" style="margin:0">
        <label>Position</label>
        <input type="number" name="new_position" min="1" max="99" class="form-control" placeholder="z.B. 11"/>
      </div>
      <div class="form-group" style="margin:0;flex:2">
        <label>Fahrer (aus Saison-Lineup)</label>
        <select name="new_driver_id" class="form-control">
          <option value="">– Fahrer wählen –</option>
          <?php foreach($seasonDrivers as $d): ?>
          <option value="<?= $d['id'] ?>"><?= $d['number']?'#'.(int)$d['number'].' ':''; ?><?= h($d['name']) ?><?= $d['team_name']?' ('.$d['team_name'].')':'' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:2">
        <label>Team</label>
        <select name="new_team_id" class="form-control">
          <option value="">– Team wählen –</option>
          <?php foreach($seasonTeams as $t): ?>
          <option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex-shrink:0">
        <button type="submit" class="btn btn-primary">➕ Hinzufügen</button>
      </div>
    </form>
  </div>
</div>

<div class="notice notice-info" style="font-size:.82rem">
  💡 <strong>Punkte neu berechnen</strong> überschreibt alle aktuellen Punkte anhand der Punktetabelle aus den Einstellungen.
  Individuelle Punkteabzüge durch Strafen werden separat im Strafensystem verwaltet und automatisch abgezogen.
</div>

<script>
function handleFL(cb, id) {
    // Nur ein FL gleichzeitig erlaubt
    if (cb.checked) {
        document.querySelectorAll('[name*="[is_fastest_lap]"]').forEach(function(el) {
            if (el !== cb) el.checked = false;
        });
    }
}
function handleDNF(cb, id) {
    const row = document.getElementById('row-' + id);
    const isDNForDSQ = row.querySelector('[name*="[dnf]"]').checked ||
                       row.querySelector('[name*="[dsq]"]').checked;
    row.style.opacity = isDNForDSQ ? '0.5' : '1';
    const posInput = row.querySelector('[name*="[position]"]');
    const ptsInput = row.querySelector('[name*="[points]"]');
    if (isDNForDSQ) {
        posInput.disabled = true;
        ptsInput.value = 0;
    } else {
        posInput.disabled = false;
    }
}
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
