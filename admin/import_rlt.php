<?php
// admin/import_rlt.php – Racing League Tools JSON Import v1
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'RLT JSON Import'; $adminPage = 'import_rlt';
requireRole('admin');
$db = getDB();

// ============================================================
// Hilfsfunktionen
// ============================================================
function rltFormatLapTime(int $ms): string {
    if ($ms <= 0) return '–';
    $min = floor($ms / 60000);
    $sec = floor(($ms % 60000) / 1000);
    $msc = $ms % 1000;
    return sprintf('%d:%02d.%03d', $min, $sec, $msc);
}
function rltFormatRaceTime(int $ms): string {
    if ($ms <= 0) return '–';
    $h   = floor($ms / 3600000);
    $min = floor(($ms % 3600000) / 60000);
    $sec = floor(($ms % 60000) / 1000);
    $msc = $ms % 1000;
    return $h > 0
        ? sprintf('%d:%02d:%02d.%03d', $h, $min, $sec, $msc)
        : sprintf('%d:%02d.%03d', $min, $sec, $msc);
}

// ============================================================
// Fahrer-Matching: exakt + fuzzy (≥2 Namensteile übereinstimmend)
// ============================================================
function rltMatchDriver(string $name, array $lineup): ?array {
    $name = trim($name);
    $nameLow = strtolower($name);
    // Exakter Match (case-insensitive)
    foreach ($lineup as $l) {
        if (strtolower($l['driver_name']) === $nameLow) return $l;
        if (!empty($l['ingame_name']) && strtolower($l['ingame_name']) === $nameLow) return $l;
    }
    // Fuzzy: Namensteile
    $parts = array_filter(preg_split('/[\s_\-\.]+/', $nameLow));
    $best = null; $bestScore = 1;
    foreach ($lineup as $l) {
        $lparts = array_filter(preg_split('/[\s_\-\.]+/', strtolower($l['driver_name'])));
        $common = count(array_intersect($parts, $lparts));
        if ($common > $bestScore) { $bestScore = $common; $best = $l; }
    }
    return $best;
}

// ============================================================
// JSON parsen
// ============================================================
function parseRltJson(string $json): array|false {
    $data = json_decode($json, true);
    if (!$data || !isset($data['Drivers'])) return false;

    $sessionType = strtolower($data['SessionType'] ?? 'race');
    $isQualify   = str_contains($sessionType, 'qualif');
    $trackName   = $data['TrackName']       ?? 'Unbekannt';
    $date        = $data['Date']            ?? null;
    $dateStr     = $date ? date('Y-m-d', strtotime($date)) : null;
    $flDriver    = $data['FastestLapDriver']['Name'] ?? null;

    $entries = [];
    foreach ($data['Drivers'] as $d) {
        $name    = trim($d['Driver']['Name'] ?? $d['DriverNameIngame'] ?? '');
        $status  = $d['Status'] ?? 'Ok';
        $dnf     = strtolower($status) === 'dnf';
        $dsq     = strtolower($status) === 'dsq' || strtolower($status) === 'disqualified';
        $laps    = (int)($d['LapsCount'] ?? 0);
        $flMs    = (int)($d['FastestLapTimeInt'] ?? 0);
        $timeMs  = (int)($d['TimeInt'] ?? 0);
        $gapMs   = (int)($d['GapInt']  ?? 0);
        $pos     = (int)($d['Position'] ?? 0);
        $isReserve = strtolower($d['SeatType'] ?? '') === 'reserve';

        $entries[] = [
            'name'           => $name,
            'ingame_name'    => trim($d['DriverNameIngame'] ?? ''),
            'position'       => $pos,
            'race_number'    => (string)($d['RaceNumber'] ?? ''),
            'team_name_xml'  => $d['Team']['Name'] ?? '',
            'laps'           => $laps,
            'grid_pos'       => (int)($d['GridPosition'] ?? 0),
            'pits'           => (int)($d['PitsCount'] ?? 0),
            'time_ms'        => $timeMs,
            'gap_ms'         => $gapMs,
            'fl_ms'          => $flMs,
            'fl_formatted'   => $flMs > 0 ? rltFormatLapTime($flMs) : '–',
            'is_fastest_lap' => ($flDriver && $flDriver === $name) ? 1 : 0,
            'dnf'            => $dnf ? 1 : 0,
            'dsq'            => $dsq ? 1 : 0,
            'is_reserve'     => $isReserve ? 1 : 0,
            'nationality'    => $d['NationalityIngame'] ?? '',
            'penalty_secs'   => (int)($d['PenaltySecsIngame'] ?? 0) + (int)($d['PenaltySecsStewards'] ?? 0),
            'penalty_pos'    => (int)($d['PenaltyPosIngame'] ?? 0) + (int)($d['PenaltyPosStewards'] ?? 0),
            'total_time'     => '',
            'gap'            => '',
        ];
    }

    // Sortierung: Klassierte nach Position, DNF/DSQ ans Ende
    usort($entries, function($a, $b) {
        $aDead = ($a['dnf'] || $a['dsq']) ? 1 : 0;
        $bDead = ($b['dnf'] || $b['dsq']) ? 1 : 0;
        if ($aDead !== $bDead) return $aDead - $bDead;
        return $a['position'] <=> $b['position'];
    });

    // Zeit-/Gap-Formatierung
    $leader = null;
    foreach ($entries as $i => &$e) {
        if ($isQualify) {
            if ($i === 0) {
                $e['total_time'] = $e['fl_formatted'];
                $leader = $e;
            } elseif ($e['fl_ms'] > 0 && $leader && $leader['fl_ms'] > 0) {
                $diffMs = $e['fl_ms'] - $leader['fl_ms'];
                $e['gap'] = '+' . rltFormatLapTime($diffMs);
            }
        } else {
            if ($i === 0 && $e['time_ms'] > 0) {
                $e['total_time'] = rltFormatRaceTime($e['time_ms']);
                $leader = $e;
            } elseif (!$e['dnf'] && !$e['dsq'] && $e['time_ms'] > 0 && $leader && $leader['time_ms'] > 0) {
                $diffMs = $e['time_ms'] - $leader['time_ms'];
                $e['gap'] = '+' . rltFormatLapTime($diffMs);
            } elseif ($e['dnf']) {
                $e['gap'] = 'DNF';
            } elseif ($e['dsq']) {
                $e['gap'] = 'DSQ';
            }
        }
    }
    unset($e);

    return [
        'type'       => $isQualify ? 'qualify' : 'race',
        'track'      => $trackName,
        'date'       => $dateStr,
        'game'       => 'Racing League Tools',
        'weather'    => $data['WeatherType'] ?? '',
        'total_laps' => (int)($data['TotalLaps'] ?? 0),
        'entries'    => $entries,
    ];
}

// ============================================================
// Aktive Saison + Lineup laden
// ============================================================
$activeSeason = $db->query("SELECT * FROM seasons WHERE is_active=1 LIMIT 1")->fetch();
$sid = $activeSeason['id'] ?? 0;

$lineupDrivers = [];
$lineupTeams   = [];
if ($sid) {
    $lstmt = $db->prepare("
        SELECT se.driver_id, se.team_id, se.number, se.is_reserve,
               d.name AS driver_name, t.name AS team_name, t.color AS team_color, t.id AS team_id
        FROM season_entries se
        JOIN drivers d ON d.id=se.driver_id
        LEFT JOIN teams t ON t.id=se.team_id
        WHERE se.season_id=?
        ORDER BY se.number, d.name
    ");
    $lstmt->execute([$sid]);
    $lineupDrivers = $lstmt->fetchAll();

    $tstmt = $db->prepare("SELECT id,name,color FROM teams WHERE season_id=? ORDER BY name");
    $tstmt->execute([$sid]);
    $lineupTeams = $tstmt->fetchAll();
}

// Rennen der aktiven Saison für Zuordnung
$races = [];
if ($sid) {
    $rstmt = $db->prepare("SELECT r.*, (SELECT COUNT(*) FROM results WHERE race_id=r.id) AS has_result FROM races r WHERE r.season_id=? ORDER BY r.round ASC");
    $rstmt->execute([$sid]);
    $races = $rstmt->fetchAll();
}

$pointsSystem = getSetting('points_system', '25,18,15,12,10,8,6,4,2,1');
$pointsArr    = array_map('floatval', explode(',', $pointsSystem));

// ============================================================
// POST: Schritt 1 – JSON hochladen & parsen
// ============================================================
$parsedData = null;
$matches    = [];
$parseError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['rlt_json'])) {
    requireRole('admin'); verifyCsrf();
    $file = $_FILES['rlt_json'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $parseError = 'Upload-Fehler: ' . $file['error'];
    } else {
        $json = file_get_contents($file['tmp_name']);
        $parsedData = parseRltJson($json);
        if (!$parsedData) {
            $parseError = 'Ungültiges JSON oder unbekanntes Format.';
        } else {
            foreach ($parsedData['entries'] as $e) {
                $matches[] = rltMatchDriver($e['name'], $lineupDrivers)
                          ?? rltMatchDriver($e['ingame_name'], $lineupDrivers);
            }
        }
    }
}

// ============================================================
// POST: Schritt 2 – Importieren
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    requireRole('admin'); verifyCsrf();

    $raceId    = (int)$_POST['race_id'];
    $isQualify = ($_POST['session_type'] ?? 'race') === 'qualify';
    $count     = (int)$_POST['count'];

    if (!$raceId) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Kein Rennen ausgewählt.'];
        header('Location: '.SITE_URL.'/admin/import_rlt.php'); exit;
    }

    if ($isQualify) {
        // Qualifying-Ergebnisse
        $db->prepare("DELETE FROM qualifying_results WHERE race_id=?")->execute([$raceId]);
        for ($i = 0; $i < $count; $i++) {
            $driverId  = (int)($_POST["driver_id"][$i] ?? 0) ?: null;
            $teamId    = (int)($_POST["team_id"][$i]   ?? 0) ?: null;
            $pos       = (int)($_POST["position"][$i]  ?? 0);
            $lapTime   = trim($_POST["lap_time"][$i]   ?? '');
            $gap       = trim($_POST["gap"][$i]        ?? '');
            $dNameRaw  = trim($_POST["driver_name_raw"][$i] ?? '');
            $tNameRaw  = trim($_POST["team_name_raw"][$i]   ?? '');
            $db->prepare("INSERT INTO qualifying_results (race_id,driver_id,driver_name_raw,team_id,team_name_raw,position,lap_time,gap) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$raceId,$driverId,$dNameRaw,$teamId,$tNameRaw,$pos,$lapTime,$gap]);
        }
        auditLog('import_rlt_quali','races',$raceId,"RLT Qualifying Import: {$count} Fahrer");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Qualifying importiert: {$count} Fahrer."];
        header('Location: '.SITE_URL.'/admin/import_rlt.php'); exit;
    } else {
        // Rennergebnis
        $db->prepare("DELETE FROM results WHERE race_id=?")->execute([$raceId]);
        $stmt = $db->prepare("INSERT INTO results (race_id,game,imported_at) VALUES (?,?,NOW())");
        $stmt->execute([$raceId, 'Racing League Tools']);
        $resultId = (int)$db->lastInsertId();

        for ($i = 0; $i < $count; $i++) {
            $driverId  = (int)($_POST["driver_id"][$i]  ?? 0) ?: null;
            $teamId    = (int)($_POST["team_id"][$i]    ?? 0) ?: null;
            $pos       = (int)($_POST["position"][$i]   ?? 0) ?: null;
            $laps      = (int)($_POST["laps"][$i]       ?? 0);
            $points    = (float)($_POST["points"][$i]   ?? 0);
            $dnf       = (int)($_POST["dnf"][$i]        ?? 0);
            $dsq       = (int)($_POST["dsq"][$i]        ?? 0);
            $fl        = isset($_POST["is_fl"][$i])     ? 1 : 0;
            $totalTime = trim($_POST["total_time"][$i]  ?? '');
            $gap       = trim($_POST["gap"][$i]         ?? '');
            $lapTime   = trim($_POST["lap_time"][$i]    ?? '');
            $dNameRaw  = trim($_POST["driver_name_raw"][$i] ?? '');
            $tNameRaw  = trim($_POST["team_name_raw"][$i]   ?? '');
            if ($dnf || $dsq) { $pos = null; $points = 0; }
            $db->prepare("INSERT INTO result_entries (result_id,position,driver_id,driver_name_raw,team_id,team_name_raw,laps,total_time,gap,fastest_lap,is_fastest_lap,dnf,dsq,points,bonus_points) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)")
               ->execute([$resultId,$pos,$driverId,$dNameRaw,$teamId,$tNameRaw,$laps,$totalTime,$gap,$lapTime,$fl,$dnf,$dsq,$points]);
        }
        auditLog('import_rlt_race','results',$resultId,"RLT Race Import: {$count} Fahrer");

        // Discord Webhook
        if (getSetting('discord_notify_results','0')==='1' && getSetting('discord_webhook_url','')) {
            $rData = $db->prepare("SELECT r.*,rc.track_name,rc.race_date,rc.round,rc.location,s.name AS season_name,s.id AS season_id FROM results r JOIN races rc ON rc.id=r.race_id JOIN seasons s ON s.id=rc.season_id WHERE r.id=?")->execute([$resultId]);
            // simplified – discord notify happens on results page manually if needed
        }

        $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Rennergebnis importiert! {$count} Fahrer. <a href='".SITE_URL."/results.php?id={$resultId}' target='_blank'>Ergebnis ansehen →</a>"];
        header('Location: '.SITE_URL.'/admin/import_rlt.php'); exit;
    }
}

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">RLT <span style="color:var(--primary)">JSON Import</span></div>
<div class="admin-page-sub">Racing League Tools – Rennen und Qualifying importieren</div>

<?php if (!$activeSeason): ?>
<div class="notice notice-warning">⚠️ Keine aktive Saison gesetzt. Bitte zuerst eine Saison aktivieren.</div>
<?php elseif (!$races): ?>
<div class="notice notice-warning">⚠️ Keine Rennen im Kalender. Bitte zuerst Rennen anlegen.</div>
<?php endif; ?>

<!-- ============================================================
  SCHRITT 1: Upload
============================================================ -->
<?php if (!$parsedData): ?>
<div class="card mb-3">
  <div class="card-header"><h3>📂 RLT JSON hochladen</h3></div>
  <div class="card-body">
    <?php if ($parseError): ?>
    <div class="notice notice-error mb-3">❌ <?= h($parseError) ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <div class="form-group">
        <label>JSON-Datei (Race oder Qualification Export aus Racing League Tools)</label>
        <div class="upload-zone" id="drop-zone" style="cursor:pointer">
          <div style="font-size:2rem;margin-bottom:8px">📄</div>
          <div>JSON-Datei hier ablegen oder klicken</div>
          <div class="text-muted" style="font-size:.8rem;margin-top:4px">results_*.json</div>
          <input type="file" name="rlt_json" id="file-input" accept=".json,application/json"
                 required style="display:none"/>
        </div>
        <div id="file-name" class="text-muted mt-1" style="font-size:.82rem"></div>
      </div>
      <button type="submit" class="btn btn-primary">📤 Datei analysieren</button>
    </form>
  </div>
</div>

<div class="notice notice-info" style="font-size:.82rem">
  💡 <strong>Unterstützte Formate:</strong> Race und Qualification JSON-Exporte aus Racing League Tools.
  Die Fahrer werden automatisch mit dem Saison-Lineup abgeglichen (exakt + Fuzzy-Matching).
</div>

<script>
var zone = document.getElementById('drop-zone');
var inp  = document.getElementById('file-input');
zone.addEventListener('click', () => inp.click());
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('drag');
    if (e.dataTransfer.files[0]) {
        inp.files = e.dataTransfer.files;
        document.getElementById('file-name').textContent = '📄 ' + e.dataTransfer.files[0].name;
    }
});
inp.addEventListener('change', () => {
    if (inp.files[0]) document.getElementById('file-name').textContent = '📄 ' + inp.files[0].name;
});
</script>

<?php else: // SCHRITT 2: Vorschau + Import ?>

<?php
$isQualify = $parsedData['type'] === 'qualify';
$entries   = $parsedData['entries'];
$entryCount = count($entries);
?>

<div class="notice notice-info mb-3" style="font-size:.85rem">
  <strong><?= $isQualify ? '⏱ Qualifying' : '🏁 Rennen' ?></strong> erkannt ·
  Strecke: <strong><?= h($parsedData['track']) ?></strong> ·
  Datum: <strong><?= h($parsedData['date'] ?? '–') ?></strong> ·
  <?= $entryCount ?> Fahrer ·
  Wetter: <?= h($parsedData['weather']) ?>
</div>

<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="action"       value="import"/>
  <input type="hidden" name="session_type" value="<?= $parsedData['type'] ?>"/>
  <input type="hidden" name="count"        value="<?= $entryCount ?>"/>

  <div class="card mb-3">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
      <h3><?= $isQualify ? '⏱ Qualifying-Vorschau' : '🏁 Rennergebnis-Vorschau' ?> (<?= $entryCount ?> Fahrer)</h3>
      <div class="flex gap-2">
        <a href="<?= SITE_URL ?>/admin/import_rlt.php" class="btn btn-secondary btn-sm">← Neu laden</a>
        <button type="submit" class="btn btn-primary btn-sm">💾 Importieren</button>
      </div>
    </div>

    <!-- Rennen zuordnen -->
    <div class="card-body" style="border-bottom:1px solid var(--border)">
      <div class="form-group" style="max-width:480px;margin:0">
        <label>Rennen zuordnen *</label>
        <select name="race_id" class="form-control" required>
          <option value="">– Rennen wählen –</option>
          <?php foreach ($races as $r): ?>
          <option value="<?= $r['id'] ?>"
            <?php
            // Auto-Select: Streckenname-Match
            $rTrack = strtolower($r['track_name']);
            $pTrack = strtolower($parsedData['track']);
            if (str_contains($rTrack, $pTrack) || str_contains($pTrack, $rTrack)) echo 'selected';
            ?>>
            R<?= (int)$r['round'] ?> – <?= h($r['track_name']) ?>
            <?= $r['race_date'] ? ' ('.date('d.m.Y', strtotime($r['race_date'])).')' : '' ?>
            <?= $r['has_result'] ? ' ⚠ bereits Ergebnis vorhanden' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="card-body" style="padding:0">
      <div class="overflow-x">
      <table class="admin-table" style="font-size:.84rem">
        <thead>
          <tr>
            <th style="width:44px">POS</th>
            <?php if (!$isQualify): ?><th style="width:44px">Grid</th><?php endif; ?>
            <th>RLT-Name</th>
            <th>RLT-Team</th>
            <th style="min-width:170px">Liga-Fahrer</th>
            <th style="min-width:130px">Liga-Team</th>
            <?php if (!$isQualify): ?>
            <th style="width:55px">Rdn.</th>
            <th style="width:110px">Zeit</th>
            <?php else: ?>
            <th style="width:110px">Bestzeit</th>
            <th style="width:90px">Abstand</th>
            <?php endif; ?>
            <th style="width:90px">Schnellste</th>
            <?php if (!$isQualify): ?>
            <th style="width:55px">Punkte</th>
            <th style="width:36px">FL</th>
            <th style="width:36px">DNF</th>
            <th style="width:36px">DSQ</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $i => $e):
            $matched = $matches[$i] ?? null;
            $pts = ($e['dnf'] || $e['dsq']) ? 0 : ($pointsArr[$e['position']-1] ?? 0);
          ?>
          <tr id="row-<?= $i ?>" class="<?= ($e['dnf']||$e['dsq']) ? 'dnf-row' : '' ?>">

            <!-- Position (hidden) -->
            <td>
              <span class="pos-col <?= $i===0?'pos-1':($i===1?'pos-2':($i===2?'pos-3':'')) ?>"
                    style="font-family:var(--font-display);font-weight:900">
                <?= ($e['dnf']||$e['dsq']) ? ($e['dnf']?'DNF':'DSQ') : $e['position'] ?>
              </span>
              <input type="hidden" name="position[<?= $i ?>]"        value="<?= $e['position'] ?>"/>
              <input type="hidden" name="driver_name_raw[<?= $i ?>]"  value="<?= h($e['name']) ?>"/>
              <input type="hidden" name="team_name_raw[<?= $i ?>]"    value="<?= h($e['team_name_xml']) ?>"/>
              <input type="hidden" name="laps[<?= $i ?>]"             value="<?= $e['laps'] ?>"/>
              <input type="hidden" name="total_time[<?= $i ?>]"       value="<?= h($i===0 ? $e['total_time'] : '') ?>"/>
              <input type="hidden" name="gap[<?= $i ?>]"              value="<?= h($i>0 ? $e['gap'] : '') ?>"/>
              <input type="hidden" name="lap_time[<?= $i ?>]"         value="<?= h($e['fl_formatted']) ?>"/>
              <input type="hidden" name="dnf[<?= $i ?>]"              value="<?= $e['dnf'] ?>"/>
              <input type="hidden" name="dsq[<?= $i ?>]"              value="<?= $e['dsq'] ?>"/>
            </td>

            <?php if (!$isQualify): ?>
            <td class="text-muted" style="font-size:.8rem"><?= $e['grid_pos'] ?: '–' ?></td>
            <?php endif; ?>

            <!-- RLT-Name -->
            <td>
              <div style="font-weight:600"><?= h($e['name']) ?></div>
              <?php if ($e['ingame_name'] && $e['ingame_name'] !== $e['name']): ?>
              <div class="text-muted" style="font-size:.72rem"><?= h($e['ingame_name']) ?></div>
              <?php endif; ?>
              <div class="text-muted" style="font-size:.7rem">#<?= h($e['race_number']) ?></div>
            </td>

            <!-- RLT-Team -->
            <td class="text-muted" style="font-size:.78rem;font-style:italic"><?= h($e['team_name_xml']) ?></td>

            <!-- Liga-Fahrer Dropdown -->
            <td>
              <?php if ($matched): ?>
              <div style="display:flex;align-items:center;gap:6px;background:rgba(76,255,176,.08);border:1px solid rgba(76,255,176,.3);border-radius:4px;padding:5px 8px">
                <span style="color:#4cffb0">✓</span>
                <div style="font-size:.85rem;font-weight:600"><?= h($matched['driver_name']) ?></div>
                <button type="button" onclick="showManualSelect(<?= $i ?>)"
                        class="btn btn-secondary btn-sm" style="margin-left:auto;padding:2px 6px;font-size:.68rem">✎</button>
              </div>
              <input type="hidden" name="driver_id[<?= $i ?>]" id="driver-id-<?= $i ?>" value="<?= $matched['driver_id'] ?>"/>
              <div id="manual-select-<?= $i ?>" style="display:none;margin-top:6px">
              <?php else: ?>
              <div style="font-size:.8rem;color:var(--secondary);margin-bottom:4px">⚠ Nicht erkannt</div>
              <input type="hidden" name="driver_id[<?= $i ?>]" id="driver-id-<?= $i ?>" value=""/>
              <div id="manual-select-<?= $i ?>">
              <?php endif; ?>
                <select class="form-control form-control-sm"
                        onchange="onDriverSelect(this, <?= $i ?>)"
                        style="min-width:160px">
                  <option value="">– Kein Liga-Fahrer –</option>
                  <?php foreach ($lineupDrivers as $ld): ?>
                  <option value="<?= $ld['driver_id'] ?>"
                          data-team="<?= $ld['team_id'] ?>"
                          <?= ($matched && $matched['driver_id']==$ld['driver_id']) ? 'selected' : '' ?>>
                    <?= $ld['number'] ? '#'.(int)$ld['number'].' ' : '' ?><?= h($ld['driver_name']) ?>
                    <?= $ld['team_name'] ? ' ('.$ld['team_name'].')' : '' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php if ($matched): ?></div><?php endif; ?>
            </td>

            <!-- Liga-Team -->
            <td>
              <?php $autoTeam = $matched ?? null; ?>
              <?php if ($autoTeam && $autoTeam['team_id']): ?>
              <div style="display:flex;align-items:center;gap:6px;font-size:.85rem" id="team-display-<?= $i ?>">
                <div style="width:10px;height:10px;border-radius:50%;background:<?= h($autoTeam['team_color']??'#666') ?>;flex-shrink:0"></div>
                <span><?= h($autoTeam['team_name']??'–') ?></span>
              </div>
              <input type="hidden" name="team_id[<?= $i ?>]" id="team-id-<?= $i ?>" value="<?= $autoTeam['team_id'] ?>"/>
              <?php else: ?>
              <select name="team_id[<?= $i ?>]" id="team-id-<?= $i ?>" class="form-control form-control-sm" style="min-width:130px">
                <option value="">– Kein Team –</option>
                <?php foreach ($lineupTeams as $lt): ?>
                <option value="<?= $lt['id'] ?>"><?= h($lt['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </td>

            <?php if (!$isQualify): ?>
            <!-- Runden -->
            <td style="font-family:var(--font-display);font-weight:700"><?= $e['laps'] ?></td>
            <!-- Zeit / Gap -->
            <td style="font-family:monospace;font-size:.82rem">
              <?= $i===0 ? h($e['total_time']) : h($e['gap']) ?>
            </td>
            <?php else: ?>
            <!-- Bestzeit -->
            <td style="font-family:monospace;font-size:.82rem"><?= h($e['fl_formatted']) ?></td>
            <!-- Abstand -->
            <td style="font-family:monospace;font-size:.8rem;color:var(--text2)"><?= $i>0 ? h($e['gap']) : '–' ?></td>
            <?php endif; ?>

            <!-- Schnellste Runde -->
            <td style="font-family:monospace;font-size:.8rem">
              <?= h($e['fl_formatted']) ?>
              <?php if ($e['is_fastest_lap']): ?><div><span class="fl-badge">FL ⚡</span></div><?php endif; ?>
            </td>

            <?php if (!$isQualify): ?>
            <!-- Punkte -->
            <td>
              <input type="number" name="points[<?= $i ?>]" id="pts-<?= $i ?>"
                     class="form-control form-control-sm" value="<?= $pts ?>"
                     min="0" step="0.5" style="width:64px"/>
            </td>
            <!-- FL Checkbox -->
            <td style="text-align:center">
              <input type="checkbox" name="is_fl[<?= $i ?>]"
                     <?= $e['is_fastest_lap'] ? 'checked' : '' ?>
                     onchange="onFlChange(<?= $i ?>, this)"/>
            </td>
            <!-- DNF -->
            <td style="text-align:center">
              <input type="checkbox" name="dnf[<?= $i ?>]" value="1"
                     <?= $e['dnf'] ? 'checked' : '' ?>
                     onchange="onDnfChange(<?= $i ?>, this)"/>
            </td>
            <!-- DSQ -->
            <td style="text-align:center">
              <input type="checkbox" name="dsq[<?= $i ?>]" value="1"
                     <?= $e['dsq'] ? 'checked' : '' ?>
                     onchange="onDnfChange(<?= $i ?>, this)"/>
            </td>
            <?php endif; ?>

          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div class="card-body" style="border-top:1px solid var(--border);padding:10px 16px;text-align:right">
      <button type="submit" class="btn btn-primary">💾 Importieren</button>
    </div>
  </div>
</form>

<?php endif; ?>

<style>
.dnf-row { opacity: .5; }
</style>
<script>
function showManualSelect(idx) {
    document.getElementById('manual-select-' + idx).style.display = 'block';
}
function onDriverSelect(sel, idx) {
    document.getElementById('driver-id-' + idx).value = sel.value;
    var opt = sel.options[sel.selectedIndex];
    var teamId = opt.getAttribute('data-team');
    if (teamId) {
        var tSel = document.getElementById('team-id-' + idx);
        if (tSel && tSel.tagName === 'SELECT') {
            for (var i=0;i<tSel.options.length;i++) {
                if (tSel.options[i].value == teamId) { tSel.selectedIndex = i; break; }
            }
        }
    }
}
function onFlChange(idx, cb) {
    if (cb.checked) {
        document.querySelectorAll('[name^="is_fl["]').forEach(function(el) {
            if (el !== cb) el.checked = false;
        });
    }
}
function onDnfChange(idx, cb) {
    var row = document.getElementById('row-' + idx);
    var dnf = document.querySelector('[name="dnf['+idx+']"]')?.checked || false;
    var dsq = document.querySelector('[name="dsq['+idx+']"]')?.checked || false;
    var dead = dnf || dsq;
    if (row) row.classList.toggle('dnf-row', dead);
    var pts = document.getElementById('pts-' + idx);
    if (dead && pts) pts.value = 0;
}
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
