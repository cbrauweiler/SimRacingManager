<?php
// admin/import_lmu.php – Le Mans Ultimate XML Import v3
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'LMU XML Import'; $adminPage = 'import_lmu';
$db = getDB();

// ============================================================
// LMU XML PARSER
// ============================================================
function parseLmuXml(string $xmlContent): array {
    // 1) BOM entfernen
    $xmlContent = ltrim($xmlContent, "\xEF\xBB\xBF");
    // 2) DOCTYPE-Block komplett entfernen (mehrzeilig, mit internem Subset [...])
    $xmlContent = preg_replace('/<!DOCTYPE[^[>]*(?:\[[^\]]*\])?\s*>/s', '', $xmlContent);
    // 3) Verbliebene Entity-Referenzen ersetzen
    $xmlContent = str_replace('&rFEnt;', 'rFactor Entity', $xmlContent);
    // 4) Ungültige Steuerzeichen entfernen
    $xmlContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $xmlContent);

    $prev = libxml_use_internal_errors(true);
    if (function_exists('libxml_disable_entity_loader')) {
        @libxml_disable_entity_loader(true);
    }
    $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NOCDATA);
    if (!$xml) {
        $errors = libxml_get_errors(); libxml_clear_errors(); libxml_use_internal_errors($prev);
        $msg = implode(' | ', array_map(fn($e) => trim($e->message).' (Z.'.$e->line.')', $errors));
        return ['error' => 'XML-Parse-Fehler: ' . ($msg ?: 'Unbekannt')];
    }
    libxml_use_internal_errors($prev);

    $isQualify = isset($xml->RaceResults->Qualify);
    $isRace    = isset($xml->RaceResults->Race);
    $session   = $isQualify ? $xml->RaceResults->Qualify : ($isRace ? $xml->RaceResults->Race : null);

    if (!$session) return ['error' => 'Weder Qualify noch Race-Session in der XML gefunden.'];

    $result = [
        'type'         => $isQualify ? 'qualify' : 'race',
        'track'        => (string)$xml->RaceResults->TrackVenue,
        'track_course' => (string)$xml->RaceResults->TrackCourse,
        'track_event'  => (string)$xml->RaceResults->TrackEvent,
        'track_length' => round((float)$xml->RaceResults->TrackLength / 1000, 3),
        'game_version' => (string)$xml->RaceResults->GameVersion,
        'game'         => 'Le Mans Ultimate',
        'datetime'     => (string)$session->TimeString,
        'entries'      => [],
    ];

    foreach ($session->Driver as $d) {
        $bestLap    = (float)$d->BestLapTime;
        $finishTime = isset($d->FinishTime) ? (float)$d->FinishTime : null;
        $status     = (string)$d->FinishStatus;
        $laps       = (int)$d->Laps;
        $dnf = in_array($status, ['None','Disconnected','DNF','']) || ($laps === 0);
        $dsq = ($status === 'Disqualified');

        $result['entries'][] = [
            'name'          => isset($d->Name) && trim((string)$d->Name) !== '' ? trim((string)$d->Name) : trim((string)$d->n),
            'position'      => (int)$d->ClassPosition,
            'grid_pos'      => isset($d->ClassGridPos) ? (int)$d->ClassGridPos : null,
            'car_number'    => (string)$d->CarNumber,
            'team_name_xml' => (string)$d->TeamName,   // Original aus XML – nur zur Info
            'car_type'      => (string)$d->CarType,
            'car_class'     => (string)$d->CarClass,
            'laps'          => $laps,
            'pitstops'      => (int)$d->Pitstops,
            'best_lap'      => $bestLap > 0 ? formatLapTime($bestLap) : '–',
            'best_lap_raw'  => $bestLap,
            'finish_time'   => $finishTime,
            'finish_status' => $status,
            'dnf'           => $dnf && !$dsq ? 1 : 0,
            'dsq'           => $dsq ? 1 : 0,
            'gap'           => '',
            'total_time'    => '',
            'is_fastest_lap'=> 0,
        ];
    }

    // Nach ClassPosition sortieren – DNF/DSQ ans Ende
    usort($result['entries'], function($a, $b) {
        $aDead = ($a['dnf'] || $a['dsq']) ? 1 : 0;
        $bDead = ($b['dnf'] || $b['dsq']) ? 1 : 0;
        if ($aDead !== $bDead) return $aDead - $bDead;
        return $a['position'] <=> $b['position'];
    });

    // Leader für Gap-Berechnung
    $leader = $result['entries'][0] ?? null;
    foreach ($result['entries'] as $i => &$e) {
        if ($result['type'] === 'race') {
            if ($i === 0 && $e['finish_time']) {
                $e['total_time'] = formatRaceTime($e['finish_time']);
            } elseif ($e['finish_time'] && $leader && $leader['finish_time']) {
                $gap = $e['finish_time'] - $leader['finish_time'];
                $e['gap'] = '+' . number_format($gap, 3) . 's';
            } elseif (!$e['dnf'] && $leader && $e['laps'] < $leader['laps']) {
                $diff = $leader['laps'] - $e['laps'];
                $e['gap'] = '+' . $diff . ' Runde' . ($diff > 1 ? 'n' : '');
            } elseif ($e['dnf']) {
                $e['gap'] = 'DNF';
            }
        } else { // qualify
            if ($i === 0) {
                $e['total_time'] = $e['best_lap'];
            } elseif ($e['best_lap_raw'] > 0 && $leader && $leader['best_lap_raw'] > 0) {
                $e['gap'] = '+' . number_format($e['best_lap_raw'] - $leader['best_lap_raw'], 3) . 's';
            } else {
                $e['gap'] = 'keine Zeit';
            }
        }
    }

    unset($e); // Referenz aus Gap-foreach aufloesen

    // Schnellste Runde im Rennen
    if ($result['type'] === 'race') {
        $fName = null; $fTime = PHP_FLOAT_MAX;
        foreach ($result['entries'] as $e) {
            if ($e['best_lap_raw'] > 0 && $e['best_lap_raw'] < $fTime) {
                $fTime = $e['best_lap_raw']; $fName = $e['name'];
            }
        }
        foreach ($result['entries'] as &$e) {
            $e['is_fastest_lap'] = ($e['name'] === $fName) ? 1 : 0;
        }
        unset($e); // Referenz aufloesen
    }

    return $result;
}

function formatLapTime(float $s): string {
    if ($s <= 0) return '–';
    return sprintf('%d:%06.3f', floor($s/60), fmod($s, 60));
}
function formatRaceTime(float $s): string {
    if ($s <= 0) return '–';
    $h = floor($s/3600); $m = floor(($s%3600)/60); $ss = floor($s)%60; $ms = round(fmod($s,1)*1000);
    return $h > 0 ? sprintf('%d:%02d:%02d.%03d',$h,$m,$ss,$ms) : sprintf('%d:%02d.%03d',$m,$ss,$ms);
}

// ============================================================
// SAVE RESULT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'save_lmu') {
    requireRole('admin'); verifyCsrf();

    $raceId      = (int)$_POST['race_id'];
    $sessionType = $_POST['session_type'] ?? 'race';
    $count       = (int)$_POST['entry_count'];

    if ($sessionType === 'qualify') {
        $db->prepare("DELETE FROM qualifying_results WHERE race_id=?")->execute([$raceId]);
        for ($i = 0; $i < $count; $i++) {
            $db->prepare("INSERT INTO qualifying_results (race_id,driver_id,driver_name_raw,team_id,position,lap_time,gap) VALUES (?,?,?,?,?,?,?)")
               ->execute([
                   $raceId,
                   (int)($_POST["driver_id"][$i]??0) ?: null,
                   trim($_POST["driver_name_raw"][$i]??''),
                   (int)($_POST["team_id"][$i]??0)   ?: null,
                   (int)($_POST["position"][$i]??$i+1),
                   trim($_POST["lap_time"][$i]??''),
                   trim($_POST["gap"][$i]??''),
               ]);
        }
        auditLog('qualifying_import_lmu','qualifying_results',$raceId,"$count entries");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Qualifying gespeichert! $count Fahrer importiert."];
        header('Location: '.SITE_URL.'/admin/import_lmu.php'); exit;

    } else {
        $db->prepare("INSERT INTO results (race_id,game,notes) VALUES (?,?,?)")
           ->execute([$raceId,'Le Mans Ultimate',trim($_POST['notes']??'')]);
        $resultId = (int)$db->lastInsertId();
        $fastestDriverId = null;

        for ($i = 0; $i < $count; $i++) {
            $driverId = (int)($_POST["driver_id"][$i]??0) ?: null;
            $teamId   = (int)($_POST["team_id"][$i]??0)   ?: null;
            $isFl     = isset($_POST["is_fl"][$i]) ? 1 : 0;
            $isDnf    = isset($_POST["dnf"][$i])   ? 1 : 0;
            $isDsq    = isset($_POST["dsq"][$i])   ? 1 : 0;
            $pts      = (float)($_POST["points"][$i]??0);
            // bonus_points = 0 beim Import; Pole+FL werden live in der Wertung berechnet
            $bonus    = 0.0;
            if ($isFl && $driverId) $fastestDriverId = $driverId;

            $db->prepare("INSERT INTO result_entries (result_id,position,driver_id,driver_name_raw,team_id,team_name_raw,laps,total_time,gap,fastest_lap,is_fastest_lap,dnf,dsq,points,bonus_points) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $resultId,
                   (int)($_POST["position"][$i]??$i+1),
                   $driverId,
                   trim($_POST["driver_name_raw"][$i]??''),
                   $teamId,
                   trim($_POST["team_name_raw"][$i]??''),
                   (int)($_POST["laps"][$i]??0),
                   trim($_POST["total_time"][$i]??''),
                   trim($_POST["gap"][$i]??''),
                   trim($_POST["lap_time"][$i]??''),
                   $isFl, $isDnf, $isDsq, $pts, $bonus,
               ]);
        }
        if ($fastestDriverId) {
            $db->prepare("UPDATE results SET fastest_lap_driver_id=? WHERE id=?")->execute([$fastestDriverId,$resultId]);
        }
        // Discord
        if (getSetting('discord_notify_results','1')==='1') {
            $rd = $db->prepare("SELECT rc.*,s.name AS season_name FROM races rc JOIN seasons s ON s.id=rc.season_id WHERE rc.id=?");
            $rd->execute([$raceId]); $raceData = $rd->fetch();
            if ($raceData) {
                $t3 = $db->prepare("SELECT re.*,d.name AS driver_name FROM result_entries re LEFT JOIN drivers d ON d.id=re.driver_id WHERE re.result_id=? ORDER BY re.position LIMIT 3");
                $t3->execute([$resultId]);
                discordNotify('', discordResultEmbed($raceData, $t3->fetchAll(), 'Le Mans Ultimate'));
            }
        }
        auditLog('result_import_lmu','results',$resultId,"$count entries");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Rennergebnis gespeichert! $count Fahrer. Wertung aktualisiert."];
        header('Location: '.SITE_URL.'/admin/results.php'); exit;
    }
}

// ============================================================
// STEP 1: Rennen wählen (GET oder POST mit race_id_selected)
// ============================================================
$races      = $db->query("SELECT rc.*, s.id AS season_id, s.name AS season_name, s.year AS season_year FROM races rc JOIN seasons s ON s.id=rc.season_id ORDER BY s.year DESC, rc.race_date DESC")->fetchAll();
$selectedRaceId = (int)($_REQUEST['race_id_selected'] ?? 0);
$selectedRace   = null;
$seasonId       = 0;
$lineupDrivers  = []; // Fahrer des Saison-Lineups
$lineupTeams    = []; // Teams der Saison
$pointsArr      = array_map('intval', explode(',', getSetting('points_system','25,18,15,12,10,8,6,4,2,1')));
$parsedData     = null;
$parseError     = null;

if ($selectedRaceId) {
    foreach ($races as $r) { if ($r['id'] == $selectedRaceId) { $selectedRace = $r; break; } }
    if ($selectedRace) {
        $seasonId = $selectedRace['season_id'];

        // Alle Fahrer des Season-Lineups für diese Saison mit Team
        $stmt = $db->prepare("
            SELECT
                d.id        AS driver_id,
                d.name      AS driver_name,
                d.nationality,
                d.photo_path,
                se.number,
                se.is_reserve,
                t.id        AS team_id,
                t.name      AS team_name,
                t.color     AS team_color
            FROM season_entries se
            JOIN drivers d ON d.id = se.driver_id
            LEFT JOIN teams t ON t.id = se.team_id
            WHERE se.season_id = ?
            ORDER BY t.name ASC, se.number ASC
        ");
        $stmt->execute([$seasonId]);
        $lineupDrivers = $stmt->fetchAll();

        // Teams der Saison
        $stmt2 = $db->prepare("SELECT * FROM teams WHERE season_id=? ORDER BY name");
        $stmt2->execute([$seasonId]);
        $lineupTeams = $stmt2->fetchAll();
    }
}

// ============================================================
// STEP 2: XML parsen (nur wenn Rennen gewählt)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'parse') {
    requireRole('admin'); verifyCsrf();

    if (!$selectedRaceId) {
        $parseError = 'Bitte zuerst ein Rennen auswählen!';
    } else {
        $uploadError = $_FILES['xml_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError === UPLOAD_ERR_OK) {
            $tmpPath  = $_FILES['xml_file']['tmp_name'];
            $origName = $_FILES['xml_file']['name'];
            $fileSize = $_FILES['xml_file']['size'];
            if ($fileSize > 10*1024*1024) {
                $parseError = 'Datei zu groß (max. 10MB).';
            } elseif (!str_ends_with(strtolower($origName), '.xml')) {
                $parseError = 'Nur .xml Dateien erlaubt.';
            } else {
                $xmlContent = file_get_contents($tmpPath);
                if (!$xmlContent || strlen($xmlContent) < 50) {
                    $parseError = 'Datei leer oder nicht lesbar. Größe: '.$fileSize.' Bytes';
                } else {
                    $parsedData = parseLmuXml($xmlContent);
                    if (isset($parsedData['error'])) { $parseError = $parsedData['error']; $parsedData = null; }
                }
            }
        } elseif ($uploadError === UPLOAD_ERR_NO_FILE) {
            $parseError = 'Keine Datei ausgewählt.';
        } elseif (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE])) {
            $parseError = 'Datei zu groß für PHP. Limit: '.ini_get('upload_max_filesize');
        } else {
            $parseError = 'Upload-Fehler (Code '.$uploadError.'). upload_tmp_dir: '.sys_get_temp_dir().' | beschreibbar: '.(is_writable(sys_get_temp_dir())?'ja':'NEIN!');
        }
    }
}

require_once __DIR__ . '/includes/layout.php';
?>

<div class="admin-page-title">LMU <span style="color:var(--primary)">XML Import</span></div>
<div class="admin-page-sub">Le Mans Ultimate Quali- und Rennergebnisse importieren</div>

<!-- ============================================================
     SCHRITT 1: Rennen wählen
============================================================ -->
<div class="card mb-4" style="<?= $selectedRace ? 'border-color:var(--primary)' : '' ?>">
  <div class="card-header">
    <h3>
      <span style="background:var(--primary);color:#fff;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;margin-right:8px;font-weight:900">1</span>
      Rennen auswählen
    </h3>
    <?php if($selectedRace): ?><span class="badge badge-primary">✓ Ausgewählt</span><?php endif; ?>
  </div>
  <div class="card-body">
    <form method="get" id="race-select-form">
      <div class="form-row cols-2" style="align-items:flex-end">
        <div class="form-group" style="margin-bottom:0">
          <label>Rennen *</label>
          <select name="race_id_selected" class="form-control" onchange="this.form.submit()" required>
            <option value="">── Rennen wählen ──</option>
            <?php
            $currentSeason = null;
            foreach($races as $r):
              // Trennlinie zwischen Saisons
              $seasonLabel = $r['season_name'].' '.($r['season_year']??'');
              if ($seasonLabel !== $currentSeason):
                $currentSeason = $seasonLabel;
            ?>
              <optgroup label="── <?= h($seasonLabel) ?> ──">
            <?php endif; ?>
                <option value="<?= $r['id'] ?>" <?= $r['id']==$selectedRaceId?'selected':'' ?>>
                  Runde <?= (int)$r['round'] ?> – <?= h($r['track_name']) ?>
                  <?= $r['race_date'] ? ' ('.date('d.m.Y',strtotime($r['race_date'])).')' : ' (TBD)' ?>
                </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if($selectedRace): ?>
        <div style="padding-bottom:0">
          <div style="background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:10px 14px;font-size:.88rem">
            <div class="font-display font-bold" style="font-size:1rem"><?= h($selectedRace['track_name']) ?></div>
            <div class="text-muted">
              <?= h($selectedRace['season_name']) ?> <?= h($selectedRace['season_year']??'') ?>
              · Runde <?= (int)$selectedRace['round'] ?>
              <?= $selectedRace['race_date'] ? ' · '.date('d.m.Y',strtotime($selectedRace['race_date'])) : '' ?>
            </div>
            <div class="mt-1">
              <span class="badge badge-info"><?= count($lineupDrivers) ?> Fahrer im Lineup</span>
              <span class="badge badge-muted"><?= count($lineupTeams) ?> Teams</span>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </form>

    <?php if($selectedRace && $lineupDrivers): ?>
    <!-- Lineup-Vorschau -->
    <div class="mt-3">
      <div style="font-family:var(--font-display);font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text2);margin-bottom:8px">
        Saison-Lineup – wird für automatisches Matching verwendet
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:8px">
        <?php foreach($lineupDrivers as $ld): ?>
        <div style="display:flex;align-items:center;gap:6px;background:var(--bg3);border:1px solid <?= h($ld['team_color']??'var(--border)') ?>44;border-radius:4px;padding:5px 10px;font-size:.82rem">
          <div style="width:8px;height:8px;border-radius:50%;background:<?= h($ld['team_color']??'#666') ?>;flex-shrink:0"></div>
          <?php if($ld['number']): ?><span style="font-family:var(--font-display);font-weight:900;color:var(--primary);font-size:.88rem">#<?= (int)$ld['number'] ?></span><?php endif; ?>
          <span><?= h($ld['driver_name']) ?></span>
          <?php if($ld['is_reserve']): ?><span class="badge badge-info" style="font-size:.58rem">R</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php elseif($selectedRace && !$lineupDrivers): ?>
    <div class="notice notice-warning mt-2">
      ⚠️ Noch keine Fahrer im Lineup für diese Saison.
      <a href="<?= SITE_URL ?>/admin/lineup.php?season=<?= $seasonId ?>" style="color:var(--primary)">→ Saison-Lineup pflegen</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============================================================
     SCHRITT 2: XML hochladen (nur wenn Rennen gewählt)
============================================================ -->
<div class="card mb-4" style="<?= !$selectedRace ? 'opacity:.5;pointer-events:none' : ($parsedData ? 'border-color:var(--primary)' : '') ?>">
  <div class="card-header">
    <h3>
      <span style="background:<?= $selectedRace?'var(--primary)':'var(--border)' ?>;color:#fff;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;margin-right:8px;font-weight:900">2</span>
      XML-Datei hochladen
    </h3>
    <?php if($parsedData): ?><span class="badge badge-primary">✓ Geparst</span><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if(!$selectedRace): ?>
      <div class="text-muted">← Bitte zuerst Schritt 1 abschließen.</div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data" id="parse-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="parse"/>
      <input type="hidden" name="race_id_selected" value="<?= $selectedRaceId ?>"/>

      <div class="upload-zone" id="upload-zone"
           onclick="document.getElementById('xml-input').click()"
           ondrop="handleDrop(event)"
           ondragover="event.preventDefault();this.classList.add('drag')"
           ondragleave="this.classList.remove('drag')">
        <div class="upload-icon">📂</div>
        <div class="upload-text"><strong>LMU XML-Datei hierher ziehen</strong> oder klicken</div>
        <div class="upload-text mt-1" style="font-size:.78rem">
          Qualifying: <code>...Q1.xml</code> &nbsp;·&nbsp; Rennen: <code>...R1.xml</code>
        </div>
        <div id="file-name-display" style="margin-top:8px;font-size:.88rem;color:var(--primary);font-weight:700;min-height:22px"></div>
      </div>
      <input type="file" name="xml_file" id="xml-input" accept=".xml" style="display:none" onchange="onFileChosen(this)"/>

      <button type="submit" class="btn btn-primary mt-2" id="parse-btn" disabled>
        🔍 Datei parsen &amp; Vorschau
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if($parseError): ?>
<div class="card mb-4" style="border-color:#c0392b">
  <div class="card-header" style="background:rgba(192,57,43,.1)">
    <h3 style="color:#ff8080">❌ Fehler</h3>
  </div>
  <div class="card-body">
    <div style="color:#ff8080;margin-bottom:10px;font-size:.95rem"><?= h($parseError) ?></div>
    <div class="text-muted" style="font-size:.82rem">
      PHP upload_max_filesize: <strong><?= ini_get('upload_max_filesize') ?></strong> &nbsp;|&nbsp;
      post_max_size: <strong><?= ini_get('post_max_size') ?></strong> &nbsp;|&nbsp;
      Temp-Dir: <strong><?= sys_get_temp_dir() ?></strong>
      (<?= is_writable(sys_get_temp_dir()) ? '<span style="color:#4cffb0">beschreibbar</span>' : '<span style="color:#ff8080">NICHT beschreibbar!</span>' ?>)
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ============================================================
     SCHRITT 3: Vorschau & Speichern
============================================================ -->
<?php if($parsedData && $selectedRace):
    $isQualify = $parsedData['type'] === 'qualify';
    $typeLabel  = $isQualify ? 'Qualifying' : 'Rennen';

    // ---- MATCHING LOGIK ----
    // Quelle: $e['name'] = Inhalt des <n>-Tags aus der LMU XML
    // Matching NUR nach Fahrername gegen Saison-Lineup
    // Team kommt IMMER aus season_entries der gewählten Saison

    // Index aufbauen: lowercase-name → lineup-eintrag
    $lineupByName = [];
    foreach ($lineupDrivers as $ld) {
        $key = mb_strtolower(trim($ld['driver_name']));
        $lineupByName[$key] = $ld;
    }

    $matches = [];
    foreach ($parsedData['entries'] as $i => $e) {
        $xmlName = mb_strtolower(trim($e['name']));
        $matched = null;

        // 1) Exakter Match (case-insensitiv)
        if (isset($lineupByName[$xmlName])) {
            $matched = $lineupByName[$xmlName];
        }

        // 2) Fuzzy: BEIDE Teile (Vorname + Nachname) müssen übereinstimmen
        //    Verhindert Fehl-Matches bei gleichen Vor- oder Nachnamen
        if (!$matched) {
            $xmlParts = array_filter(explode(' ', $xmlName), fn($p) => strlen($p) >= 2);
            if (count($xmlParts) >= 2) {
                foreach ($lineupDrivers as $ld) {
                    $ldParts = array_filter(explode(' ', mb_strtolower($ld['driver_name'])), fn($p) => strlen($p) >= 2);
                    $common  = array_intersect($xmlParts, $ldParts);
                    // Beide Teile müssen matchen – kein Single-Token-Match
                    if (count($common) >= 2) { $matched = $ld; break; }
                }
            }
        }

        // 3) Wenn nur ein Name-Teil vorhanden (Gamer-Tag o.ä.) → kein Auto-Match
        $matches[$i] = $matched;
    }

    $matchCount = count(array_filter($matches));
    $totalCount = count($parsedData['entries']);

    // Debug-Info für jeden Fahrer
    $debugInfo = [];
    foreach ($parsedData['entries'] as $i => $e) {
        $xmlName = mb_strtolower(trim($e['name']));
        $debugInfo[$i] = [
            'xml_name'     => $e['name'],
            'xml_name_lc'  => $xmlName,
            'matched'      => $matches[$i] ? $matches[$i]['driver_name'] : null,
            'lineup_names' => array_column($lineupDrivers, 'driver_name'),
        ];
    }
?>

<!-- Info Banner -->
<div class="card mb-3" style="border-color:var(--<?= $isQualify?'tertiary':'primary' ?>)">
  <div class="card-body">
    <div class="flex flex-center gap-3" style="flex-wrap:wrap">
      <div style="font-size:2.2rem"><?= $isQualify?'⏱':'🏁' ?></div>
      <div class="flex-1">
        <div style="font-family:var(--font-display);font-size:1.3rem;font-weight:900">
          <?= $typeLabel ?>-Ergebnis – <?= h($parsedData['track']) ?>
        </div>
        <div class="text-muted" style="font-size:.85rem">
          📅 <?= h($parsedData['datetime']) ?>
          · <?= h($parsedData['game']) ?> <?= h($parsedData['game_version']??'') ?>
          <?php if($parsedData['track_length']): ?> · <?= $parsedData['track_length'] ?> km<?php endif; ?>
          <?php if($parsedData['track_event']): ?> · <?= h($parsedData['track_event']) ?><?php endif; ?>
        </div>
      </div>
      <div style="text-align:right">
        <div style="font-family:var(--font-display);font-size:1.8rem;font-weight:900;color:<?= $matchCount===$totalCount?'#4cffb0':'var(--secondary)' ?>">
          <?= $matchCount ?>/<?= $totalCount ?>
        </div>
        <div class="text-muted" style="font-size:.72rem">Fahrer erkannt</div>
      </div>
    </div>

    <?php if($matchCount < $totalCount): ?>
    <div class="notice notice-warning mt-2" style="margin-bottom:0">
      ⚠️ <?= $totalCount - $matchCount ?> Fahrer nicht automatisch erkannt.
      Bitte manuell im Lineup zuordnen oder unbekannte Gastfahrer lassen (werden als Rohname gespeichert).
      <a href="<?= SITE_URL ?>/admin/lineup.php?season=<?= $seasonId ?>" target="_blank" style="color:var(--primary)">→ Lineup bearbeiten</a>
    </div>
    <?php endif; ?>

    <!-- Debug Panel (kann nach erfolgreicher Einrichtung ausgeblendet werden) -->
    <details class="mt-2" style="background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:10px 14px">
      <summary style="cursor:pointer;font-family:var(--font-display);font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text2)">
        🔍 Matching-Debug anzeigen (<?= $matchCount ?>/<?= $totalCount ?> erkannt)
      </summary>
      <div style="margin-top:10px;font-size:.8rem">
        <div style="margin-bottom:8px">
          <strong>Fahrer im Saison-Lineup (<?= count($lineupDrivers) ?>):</strong>
          <?php if($lineupDrivers): ?>
            <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">
              <?php foreach($lineupDrivers as $ld): ?>
                <span style="background:var(--bg2);border:1px solid var(--border);border-radius:3px;padding:2px 6px">
                  <?= h($ld['driver_name']) ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <span style="color:var(--primary)">⚠ LEER! Saison-Lineup für diese Saison enthält keine Fahrer.</span>
            <a href="<?= SITE_URL ?>/admin/lineup.php?season=<?= $seasonId ?>" target="_blank" class="btn btn-primary btn-sm" style="margin-left:8px">Lineup befüllen →</a>
          <?php endif; ?>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:.78rem">
          <thead>
            <tr style="border-bottom:1px solid var(--border)">
              <th style="text-align:left;padding:4px 8px;color:var(--text2)">XML Name (aus &lt;n&gt;)</th>
              <th style="text-align:left;padding:4px 8px;color:var(--text2)">Lowercase-Key</th>
              <th style="text-align:left;padding:4px 8px;color:var(--text2)">Match</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($debugInfo as $di): ?>
            <tr style="border-bottom:1px solid var(--border)20">
              <td style="padding:4px 8px;font-weight:600"><?= h($di['xml_name']) ?></td>
              <td style="padding:4px 8px;font-family:monospace;color:var(--text2)"><?= h($di['xml_name_lc']) ?></td>
              <td style="padding:4px 8px">
                <?php if($di['matched']): ?>
                  <span style="color:#4cffb0">✓ <?= h($di['matched']) ?></span>
                <?php else: ?>
                  <span style="color:var(--secondary)">⚠ Kein Match</span>
                  <?php
                  // Zeige ähnlichste Lineup-Namen
                  $similar = [];
                  foreach($di['lineup_names'] as $ln) {
                      similar_text(mb_strtolower($di['xml_name']), mb_strtolower($ln), $pct);
                      if ($pct > 50) $similar[] = h($ln).' ('.round($pct).'%)';
                  }
                  if($similar): ?>
                    <span style="color:var(--text2);font-size:.72rem"> → Ähnlich: <?= implode(', ', $similar) ?></span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </div>
</div>

<!-- Step 3 Form -->
<div class="card">
  <div class="card-header">
    <h3>
      <span style="background:var(--primary);color:#fff;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;margin-right:8px;font-weight:900">3</span>
      Zuordnung prüfen &amp; speichern
    </h3>
  </div>
  <div class="card-body">
    <form method="post" id="save-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_lmu"/>
      <input type="hidden" name="session_type" value="<?= $parsedData['type'] ?>"/>
      <input type="hidden" name="race_id" value="<?= $selectedRaceId ?>"/>
      <input type="hidden" name="entry_count" value="<?= $totalCount ?>"/>

      <?php if(!$isQualify): ?>
      <div class="form-group mb-3" style="max-width:400px">
        <label>Notizen (intern, optional)</label>
        <input type="text" name="notes" class="form-control" placeholder="z.B. Regenrennen, Safety Car..."/>
      </div>
      <?php endif; ?>

      <div class="overflow-x">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Pos</th>
            <?php if(!$isQualify): ?><th title="Startplatz">Grid</th><?php endif; ?>
            <th>Fahrer (LMU XML)</th>
            <th>Team (LMU XML)</th>
            <th>↔ Liga-Fahrer</th>
            <th>↔ Liga-Team</th>
            <?php if(!$isQualify): ?><th>Runden</th><?php endif; ?>
            <th><?= $isQualify?'Bestzeit':'Zeit / Gap' ?></th>
            <?php if(!$isQualify): ?>
            <th>Schnellste R.</th>
            <th>Punkte</th>
            <th title="Schnellste Runde Bonus">FL</th>
            <th>DNF</th>
            <th>DSQ</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach($parsedData['entries'] as $i => $e):
            $matched = $matches[$i];
            $pts = ($e['dnf'] || $e['dsq']) ? 0 : ($pointsArr[$i] ?? 0);
          ?>
          <tr id="row-<?= $i ?>" class="<?= ($e['dnf']||$e['dsq'])?'dnf-row':'' ?>">

            <!-- Pos -->
            <td>
              <span class="pos-col <?= $i===0?'pos-1':($i===1?'pos-2':($i===2?'pos-3':'')) ?>" style="font-family:var(--font-display);font-weight:900"><?= $e['position'] ?></span>
              <!-- Hidden fields -->
              <input type="hidden" name="position[<?= $i ?>]"       value="<?= $e['position'] ?>"/>
              <input type="hidden" name="driver_name_raw[<?= $i ?>]" value="<?= h($e['name']) ?>"/>
              <input type="hidden" name="team_name_raw[<?= $i ?>]"   value="<?= h($e['team_name_xml']) ?>"/>
              <input type="hidden" name="laps[<?= $i ?>]"            value="<?= $e['laps'] ?>"/>
              <input type="hidden" name="total_time[<?= $i ?>]"      value="<?= h($i===0?$e['total_time']:'') ?>"/>
              <input type="hidden" name="gap[<?= $i ?>]"             value="<?= h($i>0?$e['gap']:'') ?>"/>
              <input type="hidden" name="lap_time[<?= $i ?>]"        value="<?= h($e['best_lap']) ?>"/>
            </td>

            <?php if(!$isQualify): ?>
            <td class="text-muted" style="font-size:.82rem"><?= $e['grid_pos']??'–' ?></td>
            <?php endif; ?>

            <!-- Fahrername aus XML -->
            <td>
              <div style="font-weight:600;font-size:.9rem;white-space:nowrap">
                <?= h($e['name']) ?>
              </div>
              <div class="text-muted" style="font-size:.7rem">#<?= h($e['car_number']) ?></div>
            </td>

            <!-- Teamname aus XML (nur Info, wird NICHT für Matching verwendet) -->
            <td class="text-muted" style="font-size:.78rem;font-style:italic;max-width:120px">
              <?= h($e['team_name_xml']) ?>
            </td>

            <!-- Liga-Fahrer Dropdown (primäres Matching) -->
            <td>
              <?php if($matched): ?>
              <!-- Automatisch erkannt -->
              <div style="display:flex;align-items:center;gap:6px;background:rgba(76,255,176,.08);border:1px solid rgba(76,255,176,.3);border-radius:4px;padding:5px 8px">
                <span style="color:#4cffb0;font-size:.9rem">✓</span>
                <div>
                  <div style="font-size:.85rem;font-weight:600"><?= h($matched['driver_name']) ?></div>
                  <?php if($matched['number']): ?><div class="text-muted" style="font-size:.7rem">#<?= (int)$matched['number'] ?></div><?php endif; ?>
                </div>
                <button type="button" onclick="showManualSelect(<?= $i ?>)" class="btn btn-secondary btn-sm" style="margin-left:auto;padding:2px 6px;font-size:.68rem" title="Manuell ändern">✎</button>
              </div>
              <input type="hidden" name="driver_id[<?= $i ?>]" id="driver-id-<?= $i ?>" value="<?= $matched['driver_id'] ?>"/>
              <div id="manual-select-<?= $i ?>" style="display:none;margin-top:6px">
              <?php else: ?>
              <!-- Nicht erkannt – manuell auswählen -->
              <div style="display:flex;align-items:center;gap:4px;margin-bottom:4px">
                <span style="color:var(--secondary);font-size:.8rem">⚠ Nicht erkannt</span>
              </div>
              <input type="hidden" name="driver_id[<?= $i ?>]" id="driver-id-<?= $i ?>" value=""/>
              <div id="manual-select-<?= $i ?>">
              <?php endif; ?>
                <select class="form-control form-control-sm"
                        onchange="onDriverSelect(this, <?= $i ?>)"
                        style="min-width:160px">
                  <option value="">– Kein Liga-Fahrer –</option>
                  <?php foreach($lineupDrivers as $ld): ?>
                    <option value="<?= $ld['driver_id'] ?>"
                            data-team="<?= $ld['team_id'] ?>"
                            <?= ($matched && $matched['driver_id']==$ld['driver_id']) ? 'selected' : '' ?>>
                      <?php if($ld['number']): ?>#<?= (int)$ld['number'] ?> <?php endif; ?>
                      <?= h($ld['driver_name']) ?>
                      <?= $ld['team_name'] ? ' ('.$ld['team_name'].')' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php if($matched): ?></div><?php endif; ?>
            </td>

            <!-- Liga-Team (aus season_entries, nicht aus XML) -->
            <td>
              <?php
              $autoTeam = $matched ? $matched : null;
              ?>
              <?php if($autoTeam && $autoTeam['team_id']): ?>
              <div style="display:flex;align-items:center;gap:6px;font-size:.85rem" id="team-display-<?= $i ?>">
                <div style="width:10px;height:10px;border-radius:50%;background:<?= h($autoTeam['team_color']??'#666') ?>;flex-shrink:0"></div>
                <span><?= h($autoTeam['team_name']??'–') ?></span>
              </div>
              <input type="hidden" name="team_id[<?= $i ?>]" id="team-id-<?= $i ?>" value="<?= $autoTeam['team_id'] ?>"/>
              <?php else: ?>
              <select name="team_id[<?= $i ?>]" id="team-id-<?= $i ?>" class="form-control form-control-sm" style="min-width:130px">
                <option value="">– Kein Team –</option>
                <?php foreach($lineupTeams as $lt): ?>
                  <option value="<?= $lt['id'] ?>" style="border-left:3px solid <?= h($lt['color']) ?>">
                    <?= h($lt['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </td>

            <!-- Runden (nur Rennen) -->
            <?php if(!$isQualify): ?>
            <td style="font-family:var(--font-display);font-weight:700;font-size:.95rem"><?= $e['laps'] ?></td>
            <?php endif; ?>

            <!-- Zeit -->
            <td style="font-family:monospace;font-size:.85rem;white-space:nowrap">
              <?php if($isQualify): ?>
                <?= h($e['best_lap']) ?>
                <?php if($i > 0 && $e['gap'] !== 'keine Zeit'): ?>
                  <div class="text-muted" style="font-size:.72rem"><?= h($e['gap']) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <?= $i===0 ? h($e['total_time']) : h($e['gap']) ?>
              <?php endif; ?>
            </td>

            <?php if(!$isQualify): ?>
            <!-- Schnellste Runde -->
            <td style="font-family:monospace;font-size:.8rem;white-space:nowrap">
              <?= h($e['best_lap']) ?>
              <?php if($e['is_fastest_lap']): ?><div><span class="fl-badge">FL ⚡</span></div><?php endif; ?>
            </td>

            <!-- Punkte -->
            <td>
              <input type="number" name="points[<?= $i ?>]" id="pts-<?= $i ?>"
                     class="form-control form-control-sm" value="<?= $pts ?>"
                     min="0" step="0.5" style="width:68px"/>
            </td>

            <!-- FL Bonus -->
            <td style="text-align:center">
              <input type="checkbox" name="is_fl[<?= $i ?>]"
                     title="Schnellste Runde (+1 Bonuspunkt)"
                     <?= $e['is_fastest_lap'] ? 'checked' : '' ?>
                     onchange="onFlChange(<?= $i ?>, this)"/>
            </td>

            <!-- DNF -->
            <td style="text-align:center">
              <input type="checkbox" name="dnf[<?= $i ?>]"
                     title="Did Not Finish – <?= h($e['finish_status']) ?>"
                     <?= $e['dnf'] ? 'checked' : '' ?>
                     onchange="onDnfChange(<?= $i ?>, this)"/>
            </td>

            <!-- DSQ -->
            <td style="text-align:center">
              <input type="checkbox" name="dsq[<?= $i ?>]"
                     title="Disqualifiziert"
                     <?= $e['dsq'] ? 'checked' : '' ?>
                     onchange="onDnfChange(<?= $i ?>, this)"/>
            </td>
            <?php endif; // !isQualify ?>

          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <!-- Punktesystem Info -->
      <?php if(!$isQualify): ?>
      <div class="flex flex-center gap-2 mt-3" style="flex-wrap:wrap;font-size:.82rem;color:var(--text2)">
        <span>📊 Punkte:</span>
        <?php foreach(array_slice($pointsArr,0,10) as $pi=>$pv): ?>
          <span style="background:var(--bg3);padding:2px 6px;border-radius:3px">P<?= $pi+1 ?>: <strong style="color:var(--primary)"><?= $pv ?></strong></span>
        <?php endforeach; ?>
        <?php if(getSetting('bonus_points_pole','1')==='1'): ?>
          <?php
            $poleCheck = $db->prepare("SELECT d.name FROM qualifying_results qr LEFT JOIN drivers d ON d.id=qr.driver_id WHERE qr.race_id=? AND qr.position=1 LIMIT 1");
            $poleCheck->execute([$selectedRaceId]);
            $poleName = $poleCheck->fetchColumn();
          ?>
          <span style="background:rgba(245,166,35,.1);padding:2px 6px;border-radius:3px">
            🏆 Pole +1<?php if($poleName): ?> (<?= h($poleName) ?>)<?php else: ?> <span style="color:var(--primary);font-size:.7rem">⚠ kein Quali</span><?php endif; ?>
          </span>
        <?php endif; ?>
        <?php if(getSetting('bonus_points_fl','1')==='1'): ?><span style="background:rgba(245,166,35,.1);padding:2px 6px;border-radius:3px"><span class="fl-badge">FL</span> +1</span><?php endif; ?>
        <a href="<?= SITE_URL ?>/admin/points.php" style="color:var(--primary);font-size:.78rem">Ändern →</a>
      </div>
      <?php endif; ?>

      <div class="flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary btn-lg">
          <?= $isQualify ? '⏱ Qualifying speichern' : '🏁 Rennergebnis speichern' ?>
        </button>
        <a href="<?= SITE_URL ?>/admin/import_lmu.php?race_id_selected=<?= $selectedRaceId ?>" class="btn btn-secondary btn-lg">↩ Neue Datei</a>
        <a href="<?= SITE_URL ?>/admin/lineup.php?season=<?= $seasonId ?>" class="btn btn-secondary" target="_blank">📋 Lineup bearbeiten</a>
      </div>
    </form>
  </div>
</div>

<?php endif; // parsedData && selectedRace ?>

<style>
.dnf-row { opacity: .5; }
.admin-table th, .admin-table td { white-space: nowrap; vertical-align: middle; }
</style>

<script>
// Lineup-Daten als JS-Map (immer verfügbar, auch vor dem Parsen)
const lineupMap = {};
<?php foreach($lineupDrivers as $ld): ?>
lineupMap[<?= (int)$ld['driver_id'] ?>] = {
    teamId:    <?= $ld['team_id'] ? (int)$ld['team_id'] : 'null' ?>,
    teamName:  <?= json_encode($ld['team_name'] ?? '') ?>,
    teamColor: <?= json_encode($ld['team_color'] ?? '#666') ?>,
    number:    <?= $ld['number'] ? (int)$ld['number'] : 'null' ?>,
};
<?php endforeach; ?>

// Fahrer-Dropdown geändert → Team automatisch setzen
function onDriverSelect(select, idx) {
    const dId = parseInt(select.value);
    document.getElementById('driver-id-' + idx).value = dId || '';

    const teamSel  = document.getElementById('team-id-'      + idx);
    const teamDisp = document.getElementById('team-display-' + idx);

    if (dId && lineupMap[dId]) {
        const info = lineupMap[dId];
        if (teamSel) {
            if (teamSel.tagName === 'SELECT') {
                teamSel.value = info.teamId || '';
            } else {
                teamSel.value = info.teamId || '';
            }
        }
        if (teamDisp && info.teamName) {
            teamDisp.innerHTML = `<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${info.teamColor};margin-right:4px"></span>${info.teamName}`;
        }
    }
}

// Manuelles Dropdown einblenden (bei bereits erkannten Fahrern)
function showManualSelect(idx) {
    const el = document.getElementById('manual-select-' + idx);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// DNF / DSQ → Punkte deaktivieren
function onDnfChange(idx, cb) {
    const pts = document.getElementById('pts-' + idx);
    const row = document.getElementById('row-' + idx);
    const isDnfOrDsq = (document.querySelector(`[name="dnf[${idx}]"]`)?.checked  || false) ||
                       (document.querySelector(`[name="dsq[${idx}]"]`)?.checked || false);
    if (pts) { pts.disabled = isDnfOrDsq; if (isDnfOrDsq) pts.value = 0; }
    if (row) row.classList.toggle('dnf-row', isDnfOrDsq);
}

// FL Toggle – immer nur einer
function onFlChange(idx, cb) {
    if (cb.checked) {
        document.querySelectorAll('[name^="is_fl"]').forEach(el => {
            if (el !== cb) el.checked = false;
        });
    }
}

// Drag & Drop
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('upload-zone')?.classList.remove('drag');
    const file = e.dataTransfer.files[0];
    if (file && file.name.toLowerCase().endsWith('.xml')) {
        const dt = new DataTransfer();
        dt.items.add(file);
        const input = document.getElementById('xml-input');
        input.files = dt.files;
        onFileChosen(input);
        document.getElementById('parse-form').submit();
    }
}

// Datei gewählt → Name + Typ-Hint anzeigen, Button freischalten
function onFileChosen(input) {
    const f = input.files[0];
    if (!f) return;
    const upper = f.name.toUpperCase();
    const hint  = upper.includes('Q1') || upper.includes('Q2') ? '  →  ⏱ Qualifying erkannt'  :
                  upper.includes('R1') || upper.includes('R2') ? '  →  🏁 Rennen erkannt'      : '';
    const disp = document.getElementById('file-name-display');
    if (disp) disp.textContent = '📄 ' + f.name + hint;
    const btn = document.getElementById('parse-btn');
    if (btn) btn.disabled = false;
}
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
