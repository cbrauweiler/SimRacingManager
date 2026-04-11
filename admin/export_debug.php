<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
requireLogin();
$adminTitle = 'Export Debug'; $adminPage = 'export';
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Export <span style="color:var(--primary)">Debug</span></div>
<div class="admin-page-sub">Diagnose für den Grafik-Export</div>

<div class="card">
<div class="card-body" style="font-family:monospace;font-size:.85rem;line-height:2">
<?php
function row(string $label, string $value, bool $ok = true): void {
    $color = $ok ? '#4cffb0' : '#ff8080';
    $icon  = $ok ? '✅' : '❌';
    echo "<div><span style='color:var(--text2);display:inline-block;width:280px'>{$label}</span> {$icon} <strong style='color:{$color}'>{$value}</strong></div>";
}

// 1) PHP exec() verfügbar?
$execDisabled = in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
row('PHP exec() verfügbar', $execDisabled ? 'GESPERRT in disable_functions!' : 'Ja', !$execDisabled);

// 2) PHP shell_exec() verfügbar?
$shellDisabled = in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))));
row('PHP shell_exec() verfügbar', $shellDisabled ? 'GESPERRT' : 'Ja', !$shellDisabled);

// 3) disable_functions anzeigen
$df = ini_get('disable_functions');
row('disable_functions', $df ?: '(keine)', empty($df));

// 4) wkhtmltoimage Pfad finden
$paths = ['/usr/bin/wkhtmltoimage', '/usr/local/bin/wkhtmltoimage', '/opt/bin/wkhtmltoimage'];
$foundPath = null;
foreach ($paths as $p) {
    if (file_exists($p)) { $foundPath = $p; break; }
}
row('wkhtmltoimage gefunden', $foundPath ?? 'Nicht gefunden in üblichen Pfaden!', $foundPath !== null);

// 5) which wkhtmltoimage
$which = '';
if (!$execDisabled) {
    exec('which wkhtmltoimage 2>&1', $wo, $wr);
    $which = implode('', $wo);
    row('which wkhtmltoimage', $which ?: '(kein Output)', !empty($which));
}

// 6) Version abrufen
if (!$execDisabled && ($foundPath || $which)) {
    $bin = $foundPath ?? $which;
    exec(escapeshellarg($bin) . ' --version 2>&1', $vo, $vr);
    $ver = implode(' ', $vo);
    row('wkhtmltoimage Version', $ver ?: '(kein Output)', str_contains($ver, '0.12'));
}

// 7) Temp-Dir beschreibbar?
$tmp = sys_get_temp_dir();
$tmpWritable = is_writable($tmp);
row('Temp-Dir (' . $tmp . ')', $tmpWritable ? 'Beschreibbar' : 'NICHT beschreibbar!', $tmpWritable);

// 8) PHP-User
$phpUser = exec('whoami 2>&1') ?: posix_getpwuid(posix_geteuid())['name'] ?? '?';
row('PHP läuft als User', $phpUser, true);

// 9) Render-Test
echo "<hr style='border-color:var(--border);margin:16px 0'/>";
echo "<div style='font-weight:700;margin-bottom:10px'>🧪 Render-Test:</div>";

if ($execDisabled) {
    echo "<div style='color:#ff8080'>❌ Render-Test nicht möglich – exec() ist gesperrt.<br/>Bitte in der php.ini oder .htaccess die disable_functions prüfen.</div>";
} else {
    $bin = $foundPath ?? trim($which ?? '');
    if (!$bin) {
        echo "<div style='color:#ff8080'>❌ wkhtmltoimage nicht gefunden. Bitte installieren.</div>";
    } else {
        // Minimales Test-HTML
        $testHtml = sys_get_temp_dir() . '/srl_test_' . uniqid() . '.html';
        $testPng  = sys_get_temp_dir() . '/srl_test_' . uniqid() . '.png';
        file_put_contents($testHtml, '<!DOCTYPE html><html><head><style>body{width:400px;background:#e8333a;color:#fff;font-family:Arial;padding:20px;font-size:24px;font-weight:bold}</style></head><body>SRL Export Test ✓</body></html>');

        $cmd = escapeshellarg($bin) . " --width 400 --enable-local-file-access --quality 90 --no-stop-slow-scripts " . escapeshellarg($testHtml) . " " . escapeshellarg($testPng) . " 2>&1";
        exec($cmd, $out, $ret);

        $outStr = implode("\n", $out);
        $exists = file_exists($testPng) && filesize($testPng) > 500;

        row('Render-Befehl Exit-Code', (string)$ret, $ret === 0);
        row('PNG erstellt', $exists ? 'Ja (' . filesize($testPng) . ' Bytes)' : 'Nein / zu klein', $exists);

        if ($outStr) {
            echo "<div style='margin-top:8px;padding:10px;background:var(--bg3);border-radius:3px;font-size:.8rem;color:var(--text2);white-space:pre-wrap'>" . h($outStr) . "</div>";
        }

        if ($exists) {
            echo "<div style='margin-top:10px'><img src='data:image/png;base64," . base64_encode(file_get_contents($testPng)) . "' style='border-radius:4px;border:1px solid var(--border)'/></div>";
            echo "<div style='color:#4cffb0;margin-top:6px;font-weight:700'>✅ Export funktioniert!</div>";
        }

        @unlink($testHtml); @unlink($testPng);
    }
}

// 10) Lösungsvorschläge
echo "<hr style='border-color:var(--border);margin:16px 0'/>";
echo "<div style='font-weight:700;margin-bottom:8px'>💡 Häufige Ursachen & Lösungen:</div>";
$hints = [
    'exec() gesperrt' => 'In php.ini oder .htaccess: <code>disable_functions</code> – exec, shell_exec entfernen. Bei Plesk/ISPConfig in den PHP-Einstellungen der Domain.',
    'wkhtmltoimage nicht gefunden' => 'Pfad-Problem: <code>sudo ln -s /usr/local/bin/wkhtmltoimage /usr/bin/wkhtmltoimage</code>',
    'Temp-Dir nicht beschreibbar' => 'PHP-User darf nicht in ' . sys_get_temp_dir() . ' schreiben. Alternativ: <code>upload_tmp_dir</code> in php.ini anpassen.',
    'safe_mode aktiv (PHP < 5.4)' => 'safe_mode deaktivieren in php.ini: <code>safe_mode = Off</code>',
    'open_basedir Restriktion' => 'open_basedir muss /tmp und /usr/bin einschließen. In Plesk Domain-PHP-Einstellungen prüfen.',
];
foreach ($hints as $prob => $sol) {
    echo "<div style='margin-bottom:8px'><strong style='color:var(--secondary)'>{$prob}:</strong><br/><span style='color:var(--text2);font-size:.82rem'>{$sol}</span></div>";
}
?>
</div>
</div>

<div class="notice notice-warning mt-3">
  ⚠️ Diese Seite nach der Diagnose wieder löschen!
</div>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
