<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Ergebnis Upload'; $adminPage = 'upload';
$db = getDB();

// Save final result
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_result') {
    requireLogin();
    $raceId  = (int)$_POST['race_id'];
    $game    = trim($_POST['game'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');
    $count   = (int)$_POST['entry_count'];

    $db->prepare("INSERT INTO results (race_id, game, notes) VALUES (?,?,?)")->execute([$raceId, $game, $notes]);
    $resultId = (int)$db->lastInsertId();

    $fastestDriverId = null;

    for ($i = 0; $i < $count; $i++) {
        $pos       = (int)($_POST['position'][$i] ?? $i+1);
        $driverId  = (int)($_POST['driver_id'][$i] ?? 0) ?: null;
        $teamId    = (int)($_POST['team_id'][$i]  ?? 0) ?: null;
        $dnRaw     = $_POST['driver_name_raw'][$i] ?? '';
        $tnRaw     = $_POST['team_name_raw'][$i]   ?? '';
        $laps      = (int)($_POST['laps'][$i] ?? 0);
        $time      = trim($_POST['total_time'][$i] ?? '');
        $gap       = trim($_POST['gap'][$i] ?? '');
        $fl        = trim($_POST['fastest_lap'][$i] ?? '');
        $points    = (float)($_POST['points'][$i] ?? 0);
        $isDnf     = isset($_POST['dnf'][$i]) ? 1 : 0;
        $isFl      = isset($_POST['is_fl'][$i]) ? 1 : 0;
        // bonus_points = 0 beim Import; Pole+FL live in Wertung berechnet
        if ($isFl && $driverId) $fastestDriverId = $driverId;

        $db->prepare("INSERT INTO result_entries (result_id,position,driver_id,driver_name_raw,team_id,team_name_raw,laps,total_time,gap,fastest_lap,is_fastest_lap,dnf,points,bonus_points) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$resultId,$pos,$driverId,$dnRaw,$teamId,$tnRaw,$laps,$time,$gap,$fl,$isFl,$isDnf,$points,0]);
    }
    if ($fastestDriverId) {
        $db->prepare("UPDATE results SET fastest_lap_driver_id=? WHERE id=?")->execute([$fastestDriverId,$resultId]);
    }
    $_SESSION['flash']=['type'=>'success','msg'=>'✅ Ergebnis gespeichert!'];
    header('Location: '.SITE_URL.'/admin/results.php'); exit;
}

$races = $db->query("SELECT rc.*, s.name AS season_name FROM races rc JOIN seasons s ON s.id=rc.season_id ORDER BY rc.race_date DESC")->fetchAll();
$teams   = $db->query("SELECT * FROM teams ORDER BY name")->fetchAll();
// Fahrer MIT ihrem aktuellen Team aus season_entries der aktiven Saison
$drivers = $db->query("
    SELECT d.id, d.name, se.team_id, se.number, se.season_id
    FROM drivers d
    LEFT JOIN seasons s ON s.is_active = 1
    LEFT JOIN season_entries se ON se.driver_id = d.id AND se.season_id = s.id
    ORDER BY d.name
")->fetchAll();
$pointsSetting = getSetting('points_system','25,18,15,12,10,8,6,4,2,1');
$pointsArr = array_map('trim', explode(',', $pointsSetting));
$games = ['Assetto Corsa','Assetto Corsa Competizione','iRacing','rFactor 2','Le Mans Ultimate','Automobilista 2','Gran Turismo 7','F1 24','F1 25','RaceRoom','Rennsport','CSV (Universal)'];
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Ergebnis <span style="color:var(--primary)">Upload</span></div>
<div class="admin-page-sub">Rennergebnisse aus CSV/JSON Dateien importieren oder manuell eingeben</div>

<div class="card mb-4">
  <div class="card-header"><h3>Schritt 1: Rennen & Format wählen</h3></div>
  <div class="card-body">
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Rennen zuordnen *</label>
        <select id="sel-race" class="form-control">
          <option value="">– Rennen wählen –</option>
          <?php foreach ($races as $r): ?>
            <option value="<?= $r['id'] ?>"><?= h($r['track_name']) ?> – <?= $r['race_date']?date('d.m.Y',strtotime($r['race_date'])):'TBD' ?> (<?= h($r['season_name']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Spiel / Simulator</label>
        <select id="sel-game" class="form-control">
          <?php foreach ($games as $g): ?><option><?= h($g) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header"><h3>Schritt 2: Ergebnis eingeben</h3></div>
  <div class="card-body">
    <div class="tabs">
      <div class="tab active" data-tab="upload" onclick="adminTab('upload')">📁 Datei hochladen</div>
      <div class="tab" data-tab="manual" onclick="adminTab('manual')">✍️ Manuell eingeben</div>
    </div>

    <!-- File Upload -->
    <div class="tab-panel active" id="tab-upload">
      <div class="notice notice-info mb-3">
        📋 <strong>Unterstützte Formate:</strong><br/>
        <b>CSV Universal:</b> pos,fahrername,teamname,runden,gesamtzeit,abstand<br/>
        <b>iRacing JSON:</b> SessionResults.Results Array<br/>
        <b>ACC JSON:</b> Result Array (aus Ergebnis-Datei)<br/>
        <b>rFactor 2 / AMS2:</b> CSV Export
      </div>
      <div class="upload-zone" id="drop-zone">
        <div class="upload-icon">📂</div>
        <div class="upload-text"><strong>CSV oder JSON Datei hierher ziehen</strong></div>
        <div class="upload-text mt-1" style="font-size:.82rem">oder klicken zum Auswählen</div>
      </div>
      <input type="file" id="result-file-input" accept=".csv,.json,.txt" style="display:none"/>
    </div>

    <!-- Manual Input -->
    <div class="tab-panel" id="tab-manual">
      <div class="notice notice-info mb-3">
        ✍️ <strong>Format:</strong> Eine Zeile pro Fahrer:<br/>
        <code>Position,Fahrername,Teamname,Runden,Gesamtzeit,Abstand</code><br/>
        Beispiel: <code>1,Max Mustermann,Team Alpha,30,1:32:14.567,</code>
      </div>
      <div class="form-group">
        <label>Ergebnis (CSV Format)</label>
        <textarea id="manual-input" class="form-control" style="min-height:220px;font-family:monospace;font-size:.84rem" placeholder="1,Max Mustermann,Team Alpha,30,1:32:14.567,&#10;2,John Doe,Team Beta,30,1:32:18.123,+3.556&#10;3,Hans Schmidt,Team Gamma,30,1:32:25.890,+11.323"></textarea>
      </div>
      <button type="button" class="btn btn-secondary" onclick="parseManual()">📊 Vorschau generieren</button>
    </div>
  </div>
</div>

<!-- Preview & Save Form -->
<div id="result-preview-section" style="display:none">
<div class="card mb-3">
  <div class="card-header"><h3>Schritt 3: Zuordnung prüfen & speichern</h3></div>
  <div class="card-body">
    <div class="notice notice-warning mb-3">⚠️ Bitte prüfe alle Fahrer-Zuordnungen. Unbekannte Fahrer werden als Rohdaten gespeichert.</div>
    <form method="post" id="result-save-form">
      <input type="hidden" name="action" value="save_result"/>
      <input type="hidden" name="race_id" id="form-race-id"/>
      <input type="hidden" name="game" id="form-game"/>
      <div class="form-group mb-3">
        <label>Notizen (intern)</label>
        <input type="text" name="notes" class="form-control" placeholder="Optional: Streckenbedingungen, Besonderheiten..."/>
      </div>
      <div id="result-preview-table"></div>
      <div class="mt-3">
        <button type="submit" class="btn btn-primary btn-lg">✅ Ergebnis speichern</button>
        <button type="button" class="btn btn-secondary btn-lg" onclick="cancelPreview()">Abbrechen</button>
      </div>
    </form>
  </div>
</div>
</div>

<script>
const knownDrivers = <?= json_encode(array_map(fn($d)=>['id'=>$d['id'],'name'=>$d['name'],'team_id'=>$d['team_id']], $drivers)) ?>;
const knownTeams   = <?= json_encode(array_map(fn($t)=>['id'=>$t['id'],'name'=>$t['name'],'color'=>$t['color']], $teams)) ?>;
const pointsArr    = <?= json_encode($pointsArr) ?>;

initDropZone('drop-zone', 'result-file-input', function(file) {
    const reader = new FileReader();
    reader.onload = e => {
        let entries = [];
        if (file.name.endsWith('.json')) entries = parseJSONResult(e.target.result);
        else entries = parseCSVResult(e.target.result);
        showPreview(entries);
    };
    reader.readAsText(file);
});

function parseManual() {
    const text = document.getElementById('manual-input').value;
    const entries = parseCSVResult(text);
    showPreview(entries);
}

function showPreview(entries) {
    if (!entries.length) { alert('Keine Einträge gefunden. Format prüfen!'); return; }
    document.getElementById('form-race-id').value = document.getElementById('sel-race').value;
    document.getElementById('form-game').value = document.getElementById('sel-game').value;
    renderResultPreview(entries, knownDrivers, knownTeams, pointsArr.map(Number));
    document.getElementById('result-preview-section').style.display = 'block';
    document.getElementById('result-preview-section').scrollIntoView({behavior:'smooth'});
}

function cancelPreview() {
    document.getElementById('result-preview-section').style.display = 'none';
    document.getElementById('result-preview-table').innerHTML = '';
}
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
