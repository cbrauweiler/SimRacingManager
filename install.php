<?php
/**
 * SimRace Liga Manager – Web-Installer
 * Aufrufen: https://deine-domain.de/install.php
 * Nach der Installation: diese Datei LÖSCHEN!
 */

// Sicherheit: Installer deaktivieren wenn bereits installiert
$configFile = __DIR__ . '/includes/config.inc.php';
$sqlFile    = __DIR__ . '/install.sql';
$alreadyInstalled = file_exists($configFile)
    && strpos(file_get_contents($configFile), 'deine-domain.de') === false;

define('INSTALLER', true);
session_start();

$step  = (int)($_GET['step'] ?? 1);
$error = '';
$success = '';

// ============================================================
// Step 2: Verbindung testen + DB installieren
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $siteUrl = rtrim(trim($_POST['site_url'] ?? ''), '/');
    $dbHost  = trim($_POST['db_host'] ?? 'localhost');
    $dbName  = trim($_POST['db_name'] ?? '');
    $dbUser  = trim($_POST['db_user'] ?? '');
    $dbPass  = $_POST['db_pass'] ?? '';
    $dbChar  = 'utf8mb4';

    if (!$siteUrl || !$dbName || !$dbUser) {
        $error = 'Bitte alle Pflichtfelder ausfüllen.';
        $step  = 1;
    } else {
        // DB-Verbindung testen
        try {
            $pdo = new PDO("mysql:host={$dbHost};charset={$dbChar}", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            // Datenbank anlegen falls nicht vorhanden
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // SQL-Schema einlesen und ausführen
            if (!file_exists($sqlFile)) throw new Exception('install.sql nicht gefunden.');
            $sql = file_get_contents($sqlFile);
            // CREATE DATABASE und USE Zeilen überspringen (wir haben schon gewählt)
            $sql = preg_replace('/^CREATE DATABASE.*?;/mi', '', $sql);
            $sql = preg_replace('/^USE.*?;/mi', '', $sql);
            // Einzelne Statements ausführen
            foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
                if ($stmt) $pdo->exec($stmt);
            }

            // config.inc.php schreiben
            $configContent = "<?php\n"
                . "// SimRace Liga Manager – Konfiguration\n"
                . "// Generiert vom Installer am " . date('d.m.Y H:i') . "\n\n"
                . "define('SITE_URL', " . var_export($siteUrl, true) . ");\n"
                . "define('DB_HOST',  " . var_export($dbHost,  true) . ");\n"
                . "define('DB_NAME',  " . var_export($dbName,  true) . ");\n"
                . "define('DB_USER',  " . var_export($dbUser,  true) . ");\n"
                . "define('DB_PASS',  " . var_export($dbPass,  true) . ");\n"
                . "define('DB_CHARSET', 'utf8mb4');\n"
                . "define('SITE_ROOT', dirname(__DIR__));\n"
                . "define('UPLOAD_DIR', SITE_ROOT . '/uploads/');\n"
                . "define('UPLOAD_URL', SITE_URL . '/uploads/');\n";

            if (file_put_contents($configFile, $configContent) === false) {
                throw new Exception('config.inc.php konnte nicht geschrieben werden. Bitte manuell anlegen.');
            }

            $_SESSION['install_done'] = true;
            header('Location: install.php?step=3'); exit;

        } catch (Exception $e) {
            $error = $e->getMessage();
            $step  = 1;
        }
    }
}

// ============================================================
// HTML ausgeben
// ============================================================
$stepTitles = ['1'=>'Konfiguration', '2'=>'Wird installiert...', '3'=>'Fertig!'];
$doneBg = '#0a0a0f'; $donePrimary = '#e8333a';
?>
<!DOCTYPE html><html lang="de"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>SimRace Liga Manager – Installer</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;900&family=Barlow:wght@400;500&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a0f;color:#f0f0f5;font-family:'Barlow',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{background:#131320;border:1px solid #2a2a3a;border-radius:8px;width:100%;max-width:520px;overflow:hidden}
.box-header{background:#e8333a;padding:24px 32px;text-align:center}
.box-header h1{font-family:'Barlow Condensed',sans-serif;font-size:1.8rem;font-weight:900;text-transform:uppercase;letter-spacing:.05em;color:#fff}
.box-header p{color:rgba(255,255,255,.75);font-size:.85rem;margin-top:4px}
.steps{display:flex;border-bottom:1px solid #2a2a3a}
.step{flex:1;padding:12px;text-align:center;font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#555;border-right:1px solid #2a2a3a}
.step:last-child{border-right:none}
.step.active{color:#e8333a;border-bottom:2px solid #e8333a}
.step.done{color:#4cffb0}
.body{padding:28px 32px}
label{display:block;font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#8888a0;margin-bottom:6px;margin-top:16px}
label:first-child{margin-top:0}
input[type=text],input[type=url],input[type=password]{width:100%;background:#1c1c28;border:1px solid #2a2a3a;border-radius:4px;padding:10px 14px;color:#f0f0f5;font-size:.95rem;outline:none;transition:border-color .2s}
input:focus{border-color:#e8333a}
.hint{font-size:.78rem;color:#666;margin-top:4px}
.btn{display:block;width:100%;margin-top:24px;padding:12px;background:#e8333a;color:#fff;border:none;border-radius:4px;font-family:'Barlow Condensed',sans-serif;font-size:1.1rem;font-weight:900;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;transition:filter .2s}
.btn:hover{filter:brightness(1.1)}
.btn-sec{background:#2a2a3a;color:#f0f0f5}
.error{background:rgba(200,50,50,.12);border:1px solid rgba(200,50,50,.35);color:#ff8080;padding:12px 16px;border-radius:4px;margin-bottom:18px;font-size:.88rem}
.success{background:rgba(39,174,96,.1);border:1px solid rgba(39,174,96,.3);color:#4cffb0;padding:12px 16px;border-radius:4px;margin-bottom:18px;font-size:.88rem}
.check{font-size:3rem;text-align:center;margin:16px 0}
.info-box{background:#1c1c28;border:1px solid #2a2a3a;border-radius:4px;padding:14px;margin-top:16px;font-size:.83rem;line-height:1.7}
.info-box code{background:#2a2a3a;padding:2px 6px;border-radius:3px;font-size:.8rem}
.warn{background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.3);color:#f5a623;padding:12px 16px;border-radius:4px;margin-bottom:16px;font-size:.85rem}
</style>
</head><body>
<div class="box">
  <div class="box-header">
    <h1>SimRace Liga Manager</h1>
    <p>Web-Installer · Schritt <?= $step ?> von 3</p>
  </div>
  <div class="steps">
    <div class="step <?= $step>=1?($step>1?'done':'active'):'' ?>">1 · Konfiguration</div>
    <div class="step <?= $step>=2?($step>2?'done':'active'):'' ?>">2 · Installation</div>
    <div class="step <?= $step>=3?'active':'' ?>">3 · Fertig</div>
  </div>
  <div class="body">

<?php if ($alreadyInstalled && $step === 1): ?>
  <div class="warn">⚠️ <strong>Bereits installiert!</strong> Eine <code>config.inc.php</code> mit angepassten Einstellungen existiert bereits. Bitte diese Datei löschen wenn du neu installieren möchtest.</div>
<?php endif; ?>

<?php if ($step === 1): ?>
  <?php if ($error): ?><div class="error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <p style="color:#8888a0;font-size:.88rem;margin-bottom:20px">Fülle die folgenden Felder aus. Die Zugangsdaten werden in <code>/includes/config.inc.php</code> gespeichert.</p>

  <form method="post" action="install.php?step=2">
    <label>Website-URL *</label>
    <input type="url" name="site_url" required placeholder="https://deine-domain.de"
           value="<?= htmlspecialchars((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.$_SERVER['HTTP_HOST']) ?>"/>
    <div class="hint">Vollständige URL ohne abschließenden Slash</div>

    <label>Datenbank-Host *</label>
    <input type="text" name="db_host" required placeholder="localhost" value="localhost"/>

    <label>Datenbankname *</label>
    <input type="text" name="db_name" required placeholder="simracing_liga"/>

    <label>Datenbank-Benutzer *</label>
    <input type="text" name="db_user" required placeholder="root"/>

    <label>Datenbank-Passwort</label>
    <input type="password" name="db_pass" placeholder="(leer lassen falls kein Passwort)"/>

    <button type="submit" class="btn">Installieren →</button>
  </form>

<?php elseif ($step === 3 || isset($_SESSION['install_done'])): ?>
  <?php unset($_SESSION['install_done']); ?>
  <div class="check">✅</div>
  <div class="success" style="text-align:center"><strong>Installation erfolgreich!</strong></div>

  <div class="info-box">
    <strong>Standard-Login:</strong><br/>
    Benutzer: <code>admin</code><br/>
    Passwort: <code>admin123</code><br/><br/>
    <strong>⚠️ Passwort sofort nach dem ersten Login ändern!</strong>
  </div>

  <div class="info-box" style="margin-top:12px;border-color:rgba(200,50,50,.4)">
    <strong>🔴 Wichtig – Installer löschen!</strong><br/>
    Lösche <code>install.php</code> vom Server oder setze in der config den Schutz.<br/>
    Solange die Datei existiert ist sie öffentlich zugänglich.
  </div>

  <a href="admin/login.php" class="btn" style="margin-top:20px;text-decoration:none;text-align:center">Zum Admin-Login →</a>
  <a href="admin/login.php" class="btn btn-sec" style="margin-top:8px;text-decoration:none;text-align:center">Website besuchen</a>

<?php endif; ?>

  </div>
</div>
</body></html>
