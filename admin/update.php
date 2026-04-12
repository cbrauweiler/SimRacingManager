<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'System Update'; $adminPage = 'update';
requireRole('superadmin');

define('GITHUB_REPO',    'cbrauweiler/SimRacingManager');
define('GITHUB_API',     'https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest');
define('GITHUB_RELEASE', 'https://github.com/' . GITHUB_REPO . '/releases/latest');

// ============================================================
// GitHub Release-Info holen
// ============================================================
function fetchLatestRelease(): array {
    $ctx = stream_context_create(['http' => [
        'timeout'       => 8,
        'user_agent'    => 'SimRacingManager/' . APP_VERSION,
        'ignore_errors' => true,
    ]]);
    $json = @file_get_contents(GITHUB_API, false, $ctx);
    if (!$json) return ['error' => 'GitHub nicht erreichbar. Bitte später erneut versuchen.'];

    $data = json_decode($json, true);
    if (!$data || isset($data['message'])) {
        return ['error' => 'Keine Releases gefunden oder Rate-Limit erreicht.'];
    }
    return $data;
}

function versionNewer(string $remote, string $local): bool {
    $r = ltrim($remote, 'v');
    $l = ltrim($local,  'v');
    return version_compare($r, $l, '>');
}

// ============================================================
// POST: Update durchführen
// ============================================================
$updateLog = [];
$updateError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'do_update') {
        $zipUrl = $_POST['zip_url'] ?? '';
        if (!$zipUrl || !str_starts_with($zipUrl, 'https://github.com/')) {
            $updateError = 'Ungültige Download-URL.';
        } else {
            $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : dirname(__DIR__);
            $tmpZip   = sys_get_temp_dir() . '/srm_update_' . time() . '.zip';
            $tmpDir   = sys_get_temp_dir() . '/srm_update_' . time() . '/';

            // 1. ZIP herunterladen
            $updateLog[] = '📥 Lade Update herunter...';
            $ctx = stream_context_create(['http' => [
                'timeout'       => 30,
                'user_agent'    => 'SimRacingManager/' . APP_VERSION,
                'follow_location' => true,
            ]]);
            $zipContent = @file_get_contents($zipUrl, false, $ctx);
            if (!$zipContent || strlen($zipContent) < 1000) {
                $updateError = 'Download fehlgeschlagen. Bitte manuell aktualisieren.';
            } else {
                file_put_contents($tmpZip, $zipContent);
                $updateLog[] = '✅ Download OK (' . round(strlen($zipContent)/1024) . ' KB)';

                // 2. ZIP entpacken
                $zip = new ZipArchive();
                if ($zip->open($tmpZip) !== true) {
                    $updateError = 'ZIP konnte nicht geöffnet werden.';
                } else {
                    @mkdir($tmpDir, 0755, true);
                    $zip->extractTo($tmpDir);
                    $zip->close();
                    $updateLog[] = '📦 ZIP entpackt';

                    // 3. Dateien kopieren (außer geschützten)
                    $protect = [
                        'includes/config.inc.php',
                        'uploads/',
                        '.htaccess',
                        'install.php',
                    ];

                    // Unterordner im ZIP finden (GitHub packt in repo-name-version/)
                    $dirs = glob($tmpDir . '*', GLOB_ONLYDIR);
                    $srcDir = !empty($dirs) ? rtrim($dirs[0], '/') . '/' : $tmpDir;
                    $updateLog[] = '📁 Quelle: ' . basename(rtrim($srcDir, '/'));

                    $copied = 0;
                    $skipped = 0;
                    $iter = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($iter as $item) {
                        $relPath = substr($item->getPathname(), strlen($srcDir));
                        $destPath = $siteRoot . '/' . $relPath;

                        // Geschützte Dateien überspringen
                        $isProtected = false;
                        foreach ($protect as $p) {
                            if (str_starts_with($relPath, $p) || $relPath === $p) {
                                $isProtected = true; break;
                            }
                        }
                        if ($isProtected) { $skipped++; continue; }

                        if ($item->isDir()) {
                            @mkdir($destPath, 0755, true);
                        } else {
                            @copy($item->getPathname(), $destPath);
                            $copied++;
                        }
                    }

                    // 4. DB-Migrationen ausführen (falls vorhanden)
                    $migrationFile = $srcDir . 'install.sql';
                    if (file_exists($migrationFile)) {
                        try {
                            $db = getDB();
                            $sql = file_get_contents($migrationFile);
                            // Nur ALTER TABLE und INSERT ... ON DUPLICATE ausführen (keine DROP/CREATE)
                            $lines = explode("\n", $sql);
                            $stmt = '';
                            $migCount = 0;
                            foreach ($lines as $line) {
                                $trimmed = ltrim($line);
                                // Nur sichere Statements
                                if (preg_match('/^(ALTER TABLE|INSERT INTO.*ON DUPLICATE)/i', $trimmed)) {
                                    $stmt .= $line . "\n";
                                } elseif ($stmt && str_contains($line, ';')) {
                                    $stmt .= $line . "\n";
                                    try { $db->exec($stmt); $migCount++; } catch (\Throwable $e) { /* Spalte existiert schon etc. */ }
                                    $stmt = '';
                                } elseif ($stmt) {
                                    $stmt .= $line . "\n";
                                }
                            }
                            if ($migCount) $updateLog[] = "🗄️ {$migCount} DB-Migration(en) ausgeführt";
                        } catch (\Throwable $e) {
                            $updateLog[] = '⚠️ DB-Migration übersprungen: ' . $e->getMessage();
                        }
                    }

                    // 5. Aufräumen
                    @unlink($tmpZip);
                    // tmpDir rekursiv löschen
                    $ri = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($ri as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
                    @rmdir($tmpDir);

                    $updateLog[] = "✅ Update abgeschlossen! {$copied} Dateien aktualisiert, {$skipped} Dateien geschützt übersprungen.";
                    auditLog('system_update', 'system', 0, $_POST['new_version'] ?? '?');
                }
            }
        }
    }
}

// Release-Info laden
$release     = fetchLatestRelease();
$hasError    = isset($release['error']);
$remoteVer   = $hasError ? null : ($release['tag_name'] ?? null);
$updateAvail = $remoteVer && versionNewer($remoteVer, APP_VERSION);
$isUpToDate  = $remoteVer && !$updateAvail;

// ZIP-URL aus Assets
$zipUrl = null;
if (!$hasError && isset($release['assets'])) {
    foreach ($release['assets'] as $asset) {
        if (str_ends_with($asset['name'], '.zip')) {
            $zipUrl = $asset['browser_download_url']; break;
        }
    }
}
// Fallback: Source ZIP
if (!$zipUrl && !$hasError) {
    $zipUrl = "https://github.com/" . GITHUB_REPO . "/archive/refs/tags/{$remoteVer}.zip";
}

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">System <span style="color:var(--primary)">Update</span></div>
<div class="admin-page-sub">Aktuelle Version prüfen und aktualisieren</div>

<!-- Update-Log -->
<?php if ($updateLog): ?>
<div class="card mb-3">
  <div class="card-header"><h3>📋 Update-Protokoll</h3></div>
  <div class="card-body">
    <?php if ($updateError): ?>
    <div class="notice notice-error mb-2">❌ <?= h($updateError) ?></div>
    <?php endif; ?>
    <div style="font-family:monospace;font-size:.84rem;line-height:2">
      <?php foreach ($updateLog as $line): ?>
      <div><?= h($line) ?></div>
      <?php endforeach; ?>
    </div>
    <?php if (!$updateError): ?>
    <div class="notice notice-info mt-3">
      ✅ Update fertig! <strong>Bitte Seite neu laden</strong> damit die neue Version aktiv wird.
    </div>
    <a href="<?= SITE_URL ?>/admin/update.php" class="btn btn-primary mt-2" style="display:inline-block">🔄 Seite neu laden</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Versions-Info -->
<div class="grid-2 mb-3" style="gap:16px">
  <div class="card">
    <div class="card-body" style="text-align:center;padding:24px">
      <div style="font-size:.78rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text2);margin-bottom:8px">Installierte Version</div>
      <div style="font-family:var(--font-display);font-size:2.2rem;font-weight:900;color:var(--primary)">v<?= APP_VERSION ?></div>
    </div>
  </div>
  <div class="card">
    <div class="card-body" style="text-align:center;padding:24px">
      <div style="font-size:.78rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text2);margin-bottom:8px">Aktuelle Version (GitHub)</div>
      <?php if ($hasError): ?>
      <div style="color:var(--text2);font-size:.88rem"><?= h($release['error']) ?></div>
      <?php else: ?>
      <div style="font-family:var(--font-display);font-size:2.2rem;font-weight:900;color:<?= $updateAvail ? '#f5a623' : '#4cffb0' ?>">
        <?= h($remoteVer) ?>
      </div>
      <?php if ($updateAvail): ?>
      <div class="badge badge-secondary" style="margin-top:8px">🆕 Update verfügbar</div>
      <?php elseif ($isUpToDate): ?>
      <div class="badge badge-success" style="margin-top:8px">✅ Aktuell</div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($updateAvail && !$updateLog): ?>
<!-- Update verfügbar -->
<div class="card mb-3">
  <div class="card-header">
    <h3>🆕 Update verfügbar: <?= h($remoteVer) ?></h3>
  </div>
  <div class="card-body">

    <?php if (!empty($release['body'])): ?>
    <div style="background:var(--bg3);border:1px solid var(--border);border-radius:5px;padding:16px;margin-bottom:16px;font-size:.86rem;line-height:1.7;white-space:pre-wrap;max-height:240px;overflow-y:auto"><?= h($release['body']) ?></div>
    <?php endif; ?>

    <div class="notice notice-warning mb-3" style="font-size:.84rem">
      ⚠️ <strong>Vor dem Update:</strong> Erstelle ein Backup der Datenbank und der Dateien.
      Geschützte Dateien (<code>config.inc.php</code>, <code>uploads/</code>, <code>.htaccess</code>) werden nicht überschrieben.
    </div>

    <div class="flex gap-2" style="flex-wrap:wrap">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action"      value="do_update"/>
        <input type="hidden" name="zip_url"     value="<?= h($zipUrl) ?>"/>
        <input type="hidden" name="new_version" value="<?= h($remoteVer) ?>"/>
        <button type="submit" class="btn btn-primary"
                onclick="return confirm('Update auf <?= h($remoteVer) ?> installieren?\n\nBitte stelle sicher dass du ein Backup hast!')">
          ⬆️ Jetzt auf <?= h($remoteVer) ?> updaten
        </button>
      </form>
      <a href="<?= h($release['html_url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary">
        📋 Release Notes auf GitHub
      </a>
    </div>

    <div class="notice notice-info mt-3" style="font-size:.82rem">
      💡 Alternativ: Manuelles Update über <code>git pull</code> auf dem Server oder
      <a href="<?= h($zipUrl) ?>" target="_blank">ZIP herunterladen</a> und Dateien manuell hochladen.
    </div>
  </div>
</div>

<?php elseif ($isUpToDate): ?>
<div class="notice notice-info">✅ Du verwendest die aktuellste Version. Kein Update notwendig.</div>

<?php elseif (!$hasError): ?>
<div class="notice notice-info">ℹ️ Versionsinformationen geladen. Kein Update verfügbar.</div>
<?php endif; ?>

<!-- System-Info -->
<div class="card mt-3">
  <div class="card-header"><h3>🖥️ System-Informationen</h3></div>
  <div class="card-body">
    <table style="width:100%;font-size:.86rem;border-collapse:collapse">
      <?php
      $db = getDB();
      $mysqlVer = $db->query("SELECT VERSION()")->fetchColumn();
      $rows = [
        ['PHP Version',        PHP_VERSION . (version_compare(PHP_VERSION,'8.1','>=') ? ' ✅' : ' ⚠️ mind. 8.1 empfohlen')],
        ['MySQL Version',      $mysqlVer],
        ['App Version',        'v' . APP_VERSION],
        ['SITE_URL',           SITE_URL],
        ['Upload-Verzeichnis', is_writable(dirname(__DIR__).'/uploads') ? '✅ Schreibbar' : '❌ Nicht schreibbar'],
        ['wkhtmltoimage',      (file_exists('/usr/bin/wkhtmltoimage')||file_exists('/usr/local/bin/wkhtmltoimage')) ? '✅ Installiert' : '⚠️ Nicht gefunden'],
        ['ZipArchive',         class_exists('ZipArchive') ? '✅ Verfügbar' : '❌ Fehlt (für Auto-Update benötigt)'],
        ['allow_url_fopen',    ini_get('allow_url_fopen') ? '✅ Aktiv' : '⚠️ Deaktiviert (für Update-Check benötigt)'],
        ['max_input_vars',     ini_get('max_input_vars')],
      ];
      foreach ($rows as $r): ?>
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:8px 0;color:var(--text2);width:220px"><?= $r[0] ?></td>
        <td style="padding:8px 0;font-weight:500"><?= h($r[1]) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
