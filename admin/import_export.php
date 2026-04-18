<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Import / Export'; $adminPage = 'import_export';
requireRole('admin');
$db = getDB();

// CSV-Felder Definition
$TRACK_FIELDS = ['name','location','country','length_km','corners','lap_record','lap_record_driver','lap_record_year','lat','lon','description'];
$TEAM_FIELDS  = ['name','abbreviation','color','car','nationality'];

// Aktive Saison
$activeSeason = $db->query("SELECT * FROM seasons WHERE is_active=1 LIMIT 1")->fetch();
$seasons      = $db->query("SELECT * FROM seasons ORDER BY year DESC")->fetchAll();

// ============================================================
// EXPORT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    verifyCsrf();
    $type = $_POST['type'] ?? '';

    if ($type === 'tracks') {
        $rows = $db->query("SELECT ".implode(',',$TRACK_FIELDS)." FROM tracks ORDER BY name ASC")->fetchAll();
        $filename = 'tracks_export_'.date('Y-m-d').'.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        fputcsv($out, $TRACK_FIELDS, ';');
        foreach ($rows as $r) fputcsv($out, array_map(fn($f) => $r[$f] ?? '', $TRACK_FIELDS), ';');
        fclose($out); exit;
    }

    if ($type === 'teams') {
        $sid = (int)($_POST['season_id'] ?? 0);
        if (!$sid) { $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Bitte Saison wählen.']; header('Location: '.SITE_URL.'/admin/import_export.php'); exit; }
        $rows = $db->prepare("SELECT t.".implode(',t.',$TEAM_FIELDS)." FROM teams t WHERE t.season_id=? ORDER BY t.name ASC");
        $rows->execute([$sid]); $rows = $rows->fetchAll();
        $season = array_values(array_filter($seasons, fn($s)=>$s['id']==$sid))[0] ?? null;
        $filename = 'teams_'.($season['name']??'season').'_'.date('Y-m-d').'.csv';
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, $TEAM_FIELDS, ';');
        foreach ($rows as $r) fputcsv($out, array_map(fn($f) => $r[$f] ?? '', $TEAM_FIELDS), ';');
        fclose($out); exit;
    }
}

// ============================================================
// IMPORT
// ============================================================
$importResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    verifyCsrf();
    $type    = $_POST['type'] ?? '';
    $mode    = $_POST['mode'] ?? 'skip'; // skip | update | replace
    $sid     = (int)($_POST['season_id'] ?? 0);
    $file        = $_FILES['csv_file'] ?? null;
    $templateFile = trim($_POST['template_file'] ?? '');

    // Template oder Upload?
    if ($templateFile) {
        $safePath = __DIR__ . '/templates/' . basename($templateFile);
        if (!file_exists($safePath)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Template nicht gefunden.']; header('Location: '.SITE_URL.'/admin/import_export.php'); exit;
        }
        $handle = fopen($safePath, 'r');
    } elseif ($file && $file['error'] === UPLOAD_ERR_OK) {
        $handle = fopen($file['tmp_name'], 'r');
    } else {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Bitte Template wählen oder Datei hochladen.']; header('Location: '.SITE_URL.'/admin/import_export.php'); exit;
    }
    // BOM entfernen
    $bom = fread($handle, 3);
    if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) rewind($handle);

    $header = fgetcsv($handle, 0, ';');
    if (!$header) { $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Leere CSV.']; header('Location: '.SITE_URL.'/admin/import_export.php'); exit; }
    $header = array_map('trim', $header);

    $inserted = 0; $updated = 0; $skipped = 0; $errors = [];

    if ($type === 'tracks') {
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) < 1) continue;
            $data = array_combine(array_slice($header, 0, count($row)), $row);
            $name = trim($data['name'] ?? '');
            if (!$name) { $skipped++; continue; }
            $exists = $db->prepare("SELECT id FROM tracks WHERE name=?")->execute([$name]) ? $db->prepare("SELECT id FROM tracks WHERE name=?") : null;
            $exStmt = $db->prepare("SELECT id FROM tracks WHERE name=?"); $exStmt->execute([$name]); $existing = $exStmt->fetchColumn();
            try {
                if ($existing) {
                    if ($mode === 'skip') { $skipped++; continue; }
                    $db->prepare("UPDATE tracks SET location=?,country=?,length_km=?,corners=?,lap_record=?,lap_record_driver=?,lap_record_year=?,lat=?,lon=?,description=? WHERE id=?")
                       ->execute([
                           $data['location']??null, $data['country']??null,
                           ($data['length_km']??''!==''?(float)$data['length_km']:null),
                           ($data['corners']??''!==''?(int)$data['corners']:null),
                           $data['lap_record']??null, $data['lap_record_driver']??null,
                           ($data['lap_record_year']??''!==''?(int)$data['lap_record_year']:null),
                           ($data['lat']??''!==''?(float)$data['lat']:null),
                           ($data['lon']??''!==''?(float)$data['lon']:null),
                           $data['description']??null,
                           $existing
                       ]);
                    $updated++;
                } else {
                    $db->prepare("INSERT INTO tracks (name,location,country,length_km,corners,lap_record,lap_record_driver,lap_record_year,lat,lon,description) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([
                           $name, $data['location']??null, $data['country']??null,
                           ($data['length_km']??''!==''?(float)$data['length_km']:null),
                           ($data['corners']??''!==''?(int)$data['corners']:null),
                           $data['lap_record']??null, $data['lap_record_driver']??null,
                           ($data['lap_record_year']??''!==''?(int)$data['lap_record_year']:null),
                           ($data['lat']??''!==''?(float)$data['lat']:null),
                           ($data['lon']??''!==''?(float)$data['lon']:null),
                           $data['description']??null,
                       ]);
                    $inserted++;
                }
            } catch (\Throwable $e) { $errors[] = "Strecke '$name': ".$e->getMessage(); }
        }
    }

    if ($type === 'teams') {
        if (!$sid) { $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Bitte Saison wählen.']; header('Location: '.SITE_URL.'/admin/import_export.php'); exit; }
        if ($mode === 'replace') {
            $db->prepare("DELETE FROM teams WHERE season_id=?")->execute([$sid]);
        }
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) < 1) continue;
            $data = array_combine(array_slice($header, 0, count($row)), $row);
            $name = trim($data['name'] ?? '');
            if (!$name) { $skipped++; continue; }
            $exStmt = $db->prepare("SELECT id FROM teams WHERE name=? AND season_id=?"); $exStmt->execute([$name,$sid]); $existing = $exStmt->fetchColumn();
            try {
                if ($existing && $mode !== 'replace') {
                    if ($mode === 'skip') { $skipped++; continue; }
                    $db->prepare("UPDATE teams SET abbreviation=?,color=?,car=?,nationality=? WHERE id=?")
                       ->execute([$data['abbreviation']??null, $data['color']??'#e8333a', $data['car']??null, $data['nationality']??null, $existing]);
                    $updated++;
                } else {
                    $db->prepare("INSERT INTO teams (season_id,name,abbreviation,color,car,nationality) VALUES (?,?,?,?,?,?)")
                       ->execute([$sid, $name, $data['abbreviation']??null, $data['color']??'#e8333a', $data['car']??null, $data['nationality']??null]);
                    $inserted++;
                }
            } catch (\Throwable $e) { $errors[] = "Team '$name': ".$e->getMessage(); }
        }
    }

    fclose($handle);
    auditLog('import_'.$type, $type, 0, "imported:{$inserted} updated:{$updated} skipped:{$skipped}");
    $msg = "✅ Import abgeschlossen: {$inserted} neu, {$updated} aktualisiert, {$skipped} übersprungen.";
    if ($errors) $msg .= ' ⚠️ '.count($errors).' Fehler.';
    $_SESSION['flash'] = ['type'=>'success','msg'=>$msg];
    $_SESSION['import_errors'] = $errors ?: null;
    header('Location: '.SITE_URL.'/admin/import_export.php'); exit;
}

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Import / <span style="color:var(--primary)">Export</span></div>
<div class="admin-page-sub">Teams und Strecken als CSV importieren oder exportieren (ohne Fotos/Logos)</div>

<?php if (!empty($_SESSION['import_errors'])): ?>
<div class="card mb-3">
  <div class="card-header"><h3>⚠️ Import-Fehler</h3></div>
  <div class="card-body" style="font-size:.83rem;font-family:monospace">
    <?php foreach ($_SESSION['import_errors'] as $e): ?>
    <div style="color:#ff8080"><?= h($e) ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php unset($_SESSION['import_errors']); endif; ?>

<div class="grid-2" style="gap:20px">

  <!-- EXPORT -->
  <div class="card">
    <div class="card-header"><h3>📤 Export</h3></div>
    <div class="card-body">

      <div class="notice notice-info mb-3" style="font-size:.83rem">
        CSV-Datei mit Semikolon als Trennzeichen, UTF-8 kodiert (Excel-kompatibel).
      </div>

      <!-- Strecken Export -->
      <form method="post" class="mb-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="export"/>
        <input type="hidden" name="type"   value="tracks"/>
        <div class="form-group">
          <label style="font-weight:700">🏎️ Strecken exportieren</label>
          <div class="text-muted" style="font-size:.8rem;margin-bottom:8px">
            Felder: Name, Ort, Land, Länge, Kurven, Rundenrekord, Fahrer, Jahr, Lat, Lon, Beschreibung
          </div>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">⬇️ tracks.csv herunterladen</button>
      </form>

      <hr style="border-color:var(--border);margin:16px 0"/>

      <!-- Teams Export -->
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="export"/>
        <input type="hidden" name="type"   value="teams"/>
        <div class="form-group">
          <label style="font-weight:700">👥 Teams exportieren</label>
          <div class="text-muted" style="font-size:.8rem;margin-bottom:8px">
            Felder: Name, Kürzel, Farbe, Auto, Nationalität
          </div>
          <select name="season_id" class="form-control" required>
            <option value="">– Saison wählen –</option>
            <?php foreach ($seasons as $s): ?>
            <option value="<?= $s['id'] ?>" <?= ($activeSeason && $s['id']==$activeSeason['id'])?'selected':'' ?>>
              <?= h($s['name']) ?> <?= h($s['year']??'') ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">⬇️ teams.csv herunterladen</button>
      </form>
    </div>
  </div>

  <!-- IMPORT -->
  <div class="card">
    <div class="card-header"><h3>📥 Import</h3></div>
    <div class="card-body">

      <div class="notice notice-warning mb-3" style="font-size:.83rem">
        ⚠️ CSV muss dieselbe Spaltenstruktur wie der Export haben.
        Erste Zeile = Spaltenheader.
      </div>

      <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="import"/>

        <div class="form-group">
          <label>Typ *</label>
          <select name="type" class="form-control" id="import-type" onchange="toggleSeasonField()">
            <option value="">– wählen –</option>
            <option value="tracks">🏎️ Strecken</option>
            <option value="teams">👥 Teams</option>
          </select>
        </div>

        <div class="form-group" id="season-field" style="display:none">
          <label>Saison (für Teams) *</label>
          <select name="season_id" class="form-control">
            <option value="">– Saison wählen –</option>
            <?php foreach ($seasons as $s): ?>
            <option value="<?= $s['id'] ?>" <?= ($activeSeason && $s['id']==$activeSeason['id'])?'selected':'' ?>>
              <?= h($s['name']) ?> <?= h($s['year']??'') ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Konflikt-Behandlung *</label>
          <select name="mode" class="form-control">
            <option value="skip">Überspringen (bestehende behalten)</option>
            <option value="update">Aktualisieren (bestehende überschreiben)</option>
            <option value="replace">Ersetzen (alle vorhandenen zuerst löschen)</option>
          </select>
        </div>

        <!-- Template oder eigene Datei -->
        <div class="form-group" id="template-group" style="display:none">
          <label>📂 Template wählen</label>
          <select name="template_file" id="template-select" class="form-control">
            <option value="">– kein Template –</option>
          </select>
          <div class="form-hint">Vorgefertigte CSV-Dateien aus <code>admin/templates/</code></div>
        </div>

        <div class="form-group">
          <label>📄 Eigene CSV-Datei <span class="text-muted" style="font-weight:400;font-size:.75rem">(überschreibt Template-Auswahl)</span></label>
          <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv"
                 id="csv-file-input" onchange="onFileSelect()"/>
        </div>

        <button type="submit" class="btn btn-primary" id="import-btn" disabled
                onclick="return confirm('CSV importieren? Je nach Konflikt-Einstellung können Daten überschrieben werden.')">
          ⬆️ Importieren
        </button>
      </form>
    </div>
  </div>

</div>

<!-- CSV-Vorlagen -->
<div class="card mt-3">
  <div class="card-header"><h3>📋 CSV-Spalten Referenz</h3></div>
  <div class="card-body">
    <div class="grid-2" style="gap:20px">
      <div>
        <div style="font-weight:700;margin-bottom:6px;font-size:.85rem">🏎️ Strecken</div>
        <code style="font-size:.78rem;background:var(--bg3);padding:8px;border-radius:4px;display:block;line-height:2">
          name;location;country;length_km;corners;<br/>
          lap_record;lap_record_driver;lap_record_year;<br/>
          lat;lon;description
        </code>
      </div>
      <div>
        <div style="font-weight:700;margin-bottom:6px;font-size:.85rem">👥 Teams</div>
        <code style="font-size:.78rem;background:var(--bg3);padding:8px;border-radius:4px;display:block;line-height:2">
          name;abbreviation;color;car;nationality
        </code>
        <div class="text-muted mt-2" style="font-size:.75rem">
          color als HEX: z.B. <code>#e8333a</code>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
// Template-Daten für JS vorbereiten
$allTemplates = [];
$tplDir = __DIR__ . '/templates/';
foreach (glob($tplDir . '*.csv') ?: [] as $f) {
    $name = basename($f, '.csv');
    $type = stripos($name, 'track') !== false ? 'tracks' : (stripos($name, 'team') !== false ? 'teams' : 'other');
    $allTemplates[] = ['name'=>$name, 'file'=>basename($f), 'type'=>$type];
}
?>
<script>
var TEMPLATES = <?= json_encode($allTemplates, JSON_UNESCAPED_UNICODE) ?>;

function toggleSeasonField() {
    var type = document.getElementById('import-type').value;
    document.getElementById('season-field').style.display = type === 'teams' ? 'block' : 'none';
    updateTemplates(type);
    updateImportBtn();
}

function updateTemplates(type) {
    var sel     = document.getElementById('template-select');
    var group   = document.getElementById('template-group');
    var filtered = TEMPLATES.filter(function(t){ return !type || t.type === type; });

    sel.innerHTML = '<option value="">– kein Template –</option>';
    filtered.forEach(function(t) {
        var opt = document.createElement('option');
        opt.value       = t.file;
        opt.textContent = t.name.replace(/_/g,' ').replace(/\w/g, c => c.toUpperCase());
        sel.appendChild(opt);
    });

    group.style.display = filtered.length > 0 ? 'block' : 'none';
    sel.addEventListener('change', updateImportBtn);
}

function onFileSelect() {
    updateImportBtn();
}

function updateImportBtn() {
    var type      = document.getElementById('import-type').value;
    var tplSel    = document.getElementById('template-select').value;
    var fileInput = document.getElementById('csv-file-input');
    var hasFile   = fileInput && fileInput.files && fileInput.files.length > 0;
    var ready     = type && (tplSel || hasFile);
    document.getElementById('import-btn').disabled = !ready;
}

// Init
updateTemplates('');
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
