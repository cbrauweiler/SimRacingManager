<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Ergebnis bearbeiten'; $adminPage = 'results';
$db = getDB();
requireRole('admin');

@ini_set('max_input_vars', 5000);

$resultId = (int)($_GET['id'] ?? 0);
if (!$resultId) { header('Location: '.SITE_URL.'/admin/results.php'); exit; }

$result = $db->prepare("SELECT r.*,rc.track_name,rc.round,rc.race_date,rc.season_id,s.name AS season_name FROM results r JOIN races rc ON rc.id=r.race_id JOIN seasons s ON s.id=rc.season_id WHERE r.id=?");
$result->execute([$resultId]); $result = $result->fetch();
if (!$result) { header('Location: '.SITE_URL.'/admin/results.php'); exit; }

// ----------------------------------------------------------------
// POST Handler – drei separate Aktionen über drei separate Formulare
// ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_entries') {
        $entries = $_POST['entry'] ?? [];
        foreach ($entries as $entryId => $e) {
            $entryId = (int)$entryId;
            if (!$entryId) continue;
            $dnf    = (int)($e['dnf']            ?? 0);
            $dsq    = (int)($e['dsq']            ?? 0);
            $fl     = (int)($e['is_fastest_lap'] ?? 0);
            $pos    = ($dnf || $dsq) ? null : ((int)($e['position'] ?? 0) ?: null);
            $laps   = (int)($e['laps'] ?? 0) ?: null;
            $points = $dsq ? 0 : (float)($e['points'] ?? 0);
            $db->prepare("UPDATE result_entries SET position=?,laps=?,total_time=?,gap=?,fastest_lap=?,is_fastest_lap=?,points=?,dnf=?,dsq=? WHERE id=? AND result_id=?")
               ->execute([$pos,$laps,trim($e['total_time']??''),trim($e['gap']??''),trim($e['fastest_lap']??''),$fl,$points,$dnf,$dsq,$entryId,$resultId]);
        }
        // Nur ein FL
        $db->prepare("UPDATE result_entries SET is_fastest_lap=0 WHERE result_id=? AND is_fastest_lap=1 AND id NOT IN (SELECT id FROM (SELECT id FROM result_entries WHERE result_id=? AND is_fastest_lap=1 ORDER BY position ASC LIMIT 1) t)")->execute([$resultId,$resultId]);
        auditLog('result_edit','results',$resultId,'Manual edit');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Ergebnis gespeichert!'];
        header('Location: '.SITE_URL.'/admin/result_edit.php?id='.$resultId); exit;
    }

    if ($action === 'add_entry') {
        $driverId = (int)($_POST['new_driver_id'] ?? 0) ?: null;
        $teamId   = (int)($_POST['new_team_id']   ?? 0) ?: null;
        $pos      = (int)($_POST['new_position']  ?? 0) ?: null;
        $db->prepare("INSERT INTO result_entries (result_id,driver_id,team_id,position,laps,points,dnf,dsq,is_fastest_lap) VALUES (?,?,?,?,0,0,0,0,0)")
           ->execute([$resultId,$driverId,$teamId,$pos]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Fahrer hinzugefügt.'];
        header('Location: '.SITE_URL.'/admin/result_edit.php?id='.$resultId); exit;
    }

    if ($action === 'recalc_points') {
        $pts = array_map('floatval', explode(',', getSetting('points_system','25,18,15,12,10,8,6,4,2,1')));
        $rows = $db->prepare("SELECT id,position FROM result_entries WHERE result_id=? AND dnf=0 AND dsq=0 ORDER BY position ASC");
        $rows->execute([$resultId]);
        foreach ($rows->fetchAll() as $e) {
            $p = $pts[((int)$e['position'])-1] ?? 0;
            $db->prepare("UPDATE result_entries SET points=? WHERE id=?")->execute([$p,$e['id']]);
        }
        $db->prepare("UPDATE result_entries SET points=0 WHERE result_id=? AND (dnf=1 OR dsq=1)")->execute([$resultId]);
        auditLog('result_recalc','results',$resultId);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Punkte neu berechnet!'];
        header('Location: '.SITE_URL.'/admin/result_edit.php?id='.$resultId); exit;
    }
}

// Daten laden
$entries = $db->prepare("SELECT re.*,d.name AS driver_name,t.name AS team_name,t.color FROM result_entries re LEFT JOIN drivers d ON d.id=re.driver_id LEFT JOIN teams t ON t.id=re.team_id WHERE re.result_id=? ORDER BY re.dnf ASC,re.dsq ASC,re.position ASC,re.id ASC");
$entries->execute([$resultId]); $entries=$entries->fetchAll();

$seasonDrivers = $db->prepare("SELECT d.id,d.name,se.number,t.name AS team_name FROM season_entries se JOIN drivers d ON d.id=se.driver_id LEFT JOIN teams t ON t.id=se.team_id WHERE se.season_id=? ORDER BY se.number,d.name");
$seasonDrivers->execute([$result['season_id']]); $seasonDrivers=$seasonDrivers->fetchAll();

$seasonTeams = $db->prepare("SELECT id,name FROM teams WHERE season_id=? ORDER BY name");
$seasonTeams->execute([$result['season_id']]); $seasonTeams=$seasonTeams->fetchAll();

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Ergebnis <span style="color:var(--primary)">bearbeiten</span></div>
<div class="admin-page-sub">R<?= (int)$result['round'] ?> · <?= h($result['track_name']) ?> · <?= h($result['season_name']) ?><?= $result['race_date'] ? ' · '.date('d.m.Y',strtotime($result['race_date'])) : '' ?></div>

<div class="flex gap-2 mb-3" style="flex-wrap:wrap">
  <a href="<?= SITE_URL ?>/admin/results.php" class="btn btn-secondary">← Zurück</a>
  <a href="<?= SITE_URL ?>/results.php?id=<?= $resultId ?>" target="_blank" class="btn btn-secondary">👁 Vorschau</a>
</div>

<!-- ================================================================
  FORMULAR 1: Einträge speichern
  Enthält NUR input-Felder und einen submit-Button. Sonst nichts.
================================================================ -->
<form method="post" action="<?= SITE_URL ?>/admin/result_edit.php?id=<?= $resultId ?>">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save_entries"/>
  <div class="card mb-3">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <h3>🏁 Ergebniseinträge (<?= count($entries) ?> Fahrer)</h3>
      <button type="submit" class="btn btn-primary">💾 Änderungen speichern</button>
    </div>
    <div class="card-body" style="padding:0">
      <div class="overflow-x">
      <table class="admin-table" style="font-size:.85rem">
        <thead>
          <tr>
            <th style="width:52px">POS</th>
            <th style="min-width:160px">Fahrer</th>
            <th style="min-width:130px">Team</th>
            <th style="width:62px">Runden</th>
            <th style="width:112px">Gesamtzeit</th>
            <th style="width:100px">Abstand</th>
            <th style="width:88px">Bestzeit</th>
            <th style="width:36px" title="Fastest Lap">FL</th>
            <th style="width:42px">DNF</th>
            <th style="width:42px">DSQ</th>
            <th style="width:68px">Punkte</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $e): $eid = $e['id']; ?>
          <tr id="row-<?= $eid ?>" style="opacity:<?= ($e['dnf']||$e['dsq']) ? '.55' : '1' ?>">

            <td>
              <?php if ($e['dnf'] || $e['dsq']): ?>
                <input type="hidden" name="entry[<?= $eid ?>][position]" value=""/>
                <span class="text-muted" style="font-size:.8rem"><?= $e['dnf']?'DNF':'DSQ' ?></span>
              <?php else: ?>
                <input type="number" name="entry[<?= $eid ?>][position]"
                       value="<?= (int)$e['position'] ?>" min="1" max="99"
                       class="form-control" style="width:50px;padding:4px 6px;text-align:center"/>
              <?php endif; ?>
            </td>

            <td><strong><?= h($e['driver_name']??$e['driver_name_raw']??'?') ?></strong></td>

            <td>
              <?php if($e['color']): ?><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= h($e['color']) ?>;margin-right:4px;vertical-align:middle"></span><?php endif; ?>
              <?= h($e['team_name']??$e['team_name_raw']??'–') ?>
            </td>

            <td>
              <input type="number" name="entry[<?= $eid ?>][laps]"
                     value="<?= (int)$e['laps'] ?>" min="0"
                     class="form-control" style="width:56px;padding:4px 6px;text-align:center"/>
            </td>

            <td>
              <input type="text" name="entry[<?= $eid ?>][total_time]"
                     value="<?= h($e['total_time']??'') ?>"
                     class="form-control" style="width:106px;padding:4px 6px;font-family:monospace;font-size:.8rem"
                     placeholder="1:20:37.377"/>
            </td>

            <td>
              <input type="text" name="entry[<?= $eid ?>][gap]"
                     value="<?= h($e['gap']??'') ?>"
                     class="form-control" style="width:94px;padding:4px 6px;font-family:monospace;font-size:.8rem"
                     placeholder="+1.060s"/>
            </td>

            <td>
              <input type="text" name="entry[<?= $eid ?>][fastest_lap]"
                     value="<?= h($e['fastest_lap']??'') ?>"
                     class="form-control" style="width:84px;padding:4px 6px;font-family:monospace;font-size:.8rem"
                     placeholder="2:21.209"/>
            </td>

            <td style="text-align:center">
              <input type="hidden" name="entry[<?= $eid ?>][is_fastest_lap]" value="0"/>
              <input type="checkbox" name="entry[<?= $eid ?>][is_fastest_lap]" value="1"
                     <?= $e['is_fastest_lap'] ? 'checked' : '' ?>
                     onchange="onlyOneFL(this)"/>
            </td>

            <td style="text-align:center">
              <input type="hidden" name="entry[<?= $eid ?>][dnf]" value="0"/>
              <input type="checkbox" name="entry[<?= $eid ?>][dnf]" value="1"
                     <?= $e['dnf'] ? 'checked' : '' ?>
                     onchange="dimRow(<?= $eid ?>)"/>
            </td>

            <td style="text-align:center">
              <input type="hidden" name="entry[<?= $eid ?>][dsq]" value="0"/>
              <input type="checkbox" name="entry[<?= $eid ?>][dsq]" value="1"
                     <?= $e['dsq'] ? 'checked' : '' ?>
                     onchange="dimRow(<?= $eid ?>)"/>
            </td>

            <td>
              <input type="number" name="entry[<?= $eid ?>][points]"
                     value="<?= $e['points'] ?>" min="0" step="0.5"
                     class="form-control" style="width:62px;padding:4px 6px;text-align:center;font-weight:700;color:var(--primary)"/>
            </td>

          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
    <div class="card-body" style="border-top:1px solid var(--border);padding:10px 16px;text-align:right">
      <button type="submit" class="btn btn-primary">💾 Änderungen speichern</button>
    </div>
  </div>
</form>
<!-- /FORMULAR 1 -->

<!-- ================================================================
  FORMULAR 2: Punkte neu berechnen (komplett separat)
================================================================ -->
<form method="post" action="<?= SITE_URL ?>/admin/result_edit.php?id=<?= $resultId ?>" style="display:inline">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="recalc_points"/>
  <button type="submit" class="btn btn-secondary mb-3"
          onclick="return confirm('Punkte neu berechnen? Aktuelle Punkte werden überschrieben!')">
    🔄 Punkte neu berechnen
  </button>
</form>

<!-- ================================================================
  FORMULAR 3: Fahrer hinzufügen (komplett separat)
================================================================ -->
<div class="card mb-3">
  <div class="card-header"><h3>➕ Fahrer hinzufügen</h3></div>
  <div class="card-body">
    <form method="post" action="<?= SITE_URL ?>/admin/result_edit.php?id=<?= $resultId ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_entry"/>
      <div class="form-row cols-4" style="align-items:flex-end;gap:12px">
        <div class="form-group" style="margin:0">
          <label>Position</label>
          <input type="number" name="new_position" min="1" max="99" class="form-control" placeholder="z.B. 11"/>
        </div>
        <div class="form-group" style="margin:0;flex:2">
          <label>Fahrer</label>
          <select name="new_driver_id" class="form-control">
            <option value="">– wählen –</option>
            <?php foreach($seasonDrivers as $d): ?>
            <option value="<?= $d['id'] ?>"><?= $d['number']?'#'.(int)$d['number'].' ':'' ?><?= h($d['name']) ?><?= $d['team_name']?' ('.$d['team_name'].')':'' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;flex:2">
          <label>Team</label>
          <select name="new_team_id" class="form-control">
            <option value="">– wählen –</option>
            <?php foreach($seasonTeams as $t): ?>
            <option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex-shrink:0">
          <button type="submit" class="btn btn-primary">➕ Hinzufügen</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="notice notice-info" style="font-size:.82rem">
  💡 Punkteabzüge durch Strafen werden separat im Strafensystem verwaltet und automatisch in der Wertung berücksichtigt.
</div>

<script>
function onlyOneFL(cb) {
    if (cb.checked) {
        document.querySelectorAll('input[type=checkbox][value="1"][name*="[is_fastest_lap]"]').forEach(function(el) {
            if (el !== cb) el.checked = false;
        });
    }
}
function dimRow(id) {
    var row  = document.getElementById('row-' + id);
    var dnf  = row.querySelector('input[name*="[dnf]"][value="1"]').checked;
    var dsq  = row.querySelector('input[name*="[dsq]"][value="1"]').checked;
    var dead = dnf || dsq;
    row.style.opacity = dead ? '0.55' : '1';
    var pts  = row.querySelector('input[name*="[points]"]');
    if (dead && pts) pts.value = 0;
}
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
