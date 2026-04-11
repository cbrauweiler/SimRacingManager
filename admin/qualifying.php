<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Qualifying'; $adminPage = 'qualifying';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin(); verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_entry') {
        $raceId    = (int)$_POST['race_id'];
        $driverId  = (int)$_POST['driver_id'] ?: null;
        $teamId    = (int)$_POST['team_id'] ?: null;
        $pos       = (int)$_POST['position'];
        $lapTime   = trim($_POST['lap_time'] ?? '');
        $gap       = trim($_POST['gap'] ?? '');
        $dnRaw     = trim($_POST['driver_name_raw'] ?? '');
        $laps      = (int)($_POST['laps'] ?? 0);
        $db->prepare("INSERT INTO qualifying_results (race_id,driver_id,driver_name_raw,team_id,position,lap_time,gap,laps) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$raceId,$driverId,$dnRaw,$teamId,$pos,$lapTime,$gap,$laps]);
        auditLog('qualifying_add','qualifying_results',0,"Race $raceId P$pos");
        $_SESSION['flash']=['type'=>'success','msg'=>'✅ Qualifying-Eintrag gespeichert!'];
        header('Location: '.SITE_URL.'/admin/qualifying.php?race='.$raceId); exit;
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM qualifying_results WHERE id=?")->execute([(int)$_POST['id']]);
        $_SESSION['flash']=['type'=>'success','msg'=>'🗑 Gelöscht.'];
        header('Location: '.SITE_URL.'/admin/qualifying.php'); exit;
    }
    if ($action === 'bulk_save') {
        $raceId = (int)$_POST['race_id'];
        $db->prepare("DELETE FROM qualifying_results WHERE race_id=?")->execute([$raceId]);
        $lines = explode("\n", trim($_POST['bulk_data']??''));
        foreach ($lines as $i => $line) {
            $line = trim($line); if (!$line) continue;
            $cols = array_map('trim', explode(',', $line));
            $pos = (int)($cols[0] ?? $i+1);
            $dnRaw = $cols[1] ?? '';
            $lapTime = $cols[2] ?? '';
            $gap = $i===0 ? '' : ($cols[3] ?? '');
            $driver = $db->prepare("SELECT id,team_id FROM drivers WHERE name LIKE ?"); $driver->execute(['%'.trim($dnRaw).'%']);
            $dr = $driver->fetch();
            $db->prepare("INSERT INTO qualifying_results (race_id,driver_id,driver_name_raw,team_id,position,lap_time,gap) VALUES (?,?,?,?,?,?,?)")
               ->execute([$raceId,$dr['id']??null,$dnRaw,$dr['team_id']??null,$pos,$lapTime,$gap]);
        }
        auditLog('qualifying_bulk','qualifying_results',$raceId,'Bulk import');
        $_SESSION['flash']=['type'=>'success','msg'=>'✅ Qualifying importiert!'];
        header('Location: '.SITE_URL.'/admin/qualifying.php?race='.$raceId); exit;
    }
}

$races = $db->query("SELECT rc.*,s.name AS season_name FROM races rc JOIN seasons s ON s.id=rc.season_id ORDER BY rc.race_date DESC LIMIT 50")->fetchAll();
$selectedRaceId = (int)($_GET['race'] ?? ($races[0]['id'] ?? 0));
$selectedRace = null; foreach($races as $r) if($r['id']==$selectedRaceId){$selectedRace=$r;break;}

$existing = [];
if ($selectedRaceId) {
    $stmt = $db->prepare("SELECT qr.*,d.name AS driver_name,t.name AS team_name,t.color FROM qualifying_results qr LEFT JOIN drivers d ON d.id=qr.driver_id LEFT JOIN teams t ON t.id=qr.team_id WHERE qr.race_id=? ORDER BY qr.position ASC");
    $stmt->execute([$selectedRaceId]); $existing=$stmt->fetchAll();
}
$allDrivers = $db->query("SELECT d.*, t.name AS team_name, se.number FROM drivers d LEFT JOIN seasons s ON s.is_active=1 LEFT JOIN season_entries se ON se.driver_id=d.id AND se.season_id=s.id LEFT JOIN teams t ON t.id=se.team_id ORDER BY d.name")->fetchAll();
$allTeams   = $db->query("SELECT * FROM teams ORDER BY name")->fetchAll();
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Qualifying <span style="color:var(--primary)">verwalten</span></div>
<div class="admin-page-sub">Qualifying-Ergebnisse eingeben und verwalten</div>

<!-- Race selector -->
<div class="form-group mb-3" style="max-width:400px">
  <label>Rennen wählen</label>
  <select class="form-control" onchange="location.href='?race='+this.value">
    <?php foreach($races as $r): ?>
      <option value="<?= $r['id'] ?>" <?= $r['id']==$selectedRaceId?'selected':'' ?>><?= h($r['track_name']) ?> (<?= $r['race_date']?date('d.m.Y',strtotime($r['race_date'])):'TBD' ?>) – <?= h($r['season_name']) ?></option>
    <?php endforeach; ?>
  </select>
</div>

<div class="grid-2" style="gap:20px;align-items:start">
  <!-- Bulk Import -->
  <div class="card">
    <div class="card-header"><h3>📋 Bulk-Import (CSV)</h3></div>
    <div class="card-body">
      <div class="notice notice-info mb-3">Format: <code>Position,Fahrername,Rundenzeit,Abstand</code><br/>Erste Zeile = Pole (kein Abstand nötig)</div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bulk_save"/>
        <input type="hidden" name="race_id" value="<?= $selectedRaceId ?>"/>
        <div class="form-group">
          <textarea name="bulk_data" class="form-control" style="min-height:200px;font-family:monospace;font-size:.84rem" placeholder="1,Max Mustermann,1:23.456,&#10;2,John Doe,1:23.789,+0.333&#10;3,Hans Schmidt,1:24.001,+0.545"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">⬆️ Importieren</button>
      </form>
    </div>
  </div>

  <!-- Einzeleintrag -->
  <div class="card">
    <div class="card-header"><h3>➕ Einzeln hinzufügen</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_entry"/>
        <input type="hidden" name="race_id" value="<?= $selectedRaceId ?>"/>
        <div class="form-row cols-2">
          <div class="form-group"><label>Position</label><input type="number" name="position" class="form-control" value="<?= count($existing)+1 ?>" min="1"/></div>
          <div class="form-group"><label>Runden</label><input type="number" name="laps" class="form-control" value="1"/></div>
        </div>
        <div class="form-group"><label>Fahrer</label>
          <select name="driver_id" class="form-control">
            <option value="">– Manuell eingeben –</option>
            <?php foreach($allDrivers as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name']) ?><?= $d['team_name']?' ('.$d['team_name'].')':'' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Oder Name (wenn nicht registriert)</label><input type="text" name="driver_name_raw" class="form-control" placeholder="Fahrername..."/></div>
        <div class="form-row cols-2">
          <div class="form-group"><label>Rundenzeit</label><input type="text" name="lap_time" class="form-control" placeholder="1:23.456"/></div>
          <div class="form-group"><label>Abstand (P2+)</label><input type="text" name="gap" class="form-control" placeholder="+0.333"/></div>
        </div>
        <button type="submit" class="btn btn-primary w-full">➕ Hinzufügen</button>
      </form>
    </div>
  </div>
</div>

<!-- Existing Results -->
<?php if ($existing): ?>
<div class="card mt-3">
  <div class="card-header"><h3>📋 Qualifying: <?= h($selectedRace['track_name']??'') ?> (<?= count($existing) ?> Fahrer)</h3></div>
  <div class="card-body" style="padding:0">
    <div class="overflow-x">
    <table class="admin-table">
      <thead><tr><th>Pos</th><th>Fahrer</th><th>Team</th><th>Rundenzeit</th><th>Abstand</th><th style="text-align:right">Aktion</th></tr></thead>
      <tbody>
        <?php foreach($existing as $e): ?>
        <tr>
          <td class="pos-col <?= $e['position']==1?'pos-1':($e['position']==2?'pos-2':($e['position']==3?'pos-3':'')) ?>"><?= $e['position']==1?'🏆':'P'.(int)$e['position'] ?></td>
          <td><?= h($e['driver_name']??$e['driver_name_raw']) ?></td>
          <td><?php if($e['team_name']): ?><span class="team-dot" style="background:<?= h($e['color']??'#666') ?>"></span><?= h($e['team_name']) ?><?php else: ?>–<?php endif; ?></td>
          <td style="font-family:monospace"><?= h($e['lap_time']??'–') ?></td>
          <td class="gap-col"><?= h($e['gap']??'–') ?></td>
          <td>
            <form method="post" style="display:flex;justify-content:flex-end" onsubmit="return confirm('Löschen?')">
              <?= csrfField() ?><input type="hidden" name="action" value="delete"/><input type="hidden" name="id" value="<?= $e['id'] ?>"/>
              <button class="btn btn-danger btn-sm">🗑</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
