<?php
// Temporäre Hilfsseite – nach dem Testen löschen!
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
requireLogin();
$adminTitle = 'PHP Upload Info'; $adminPage = '';
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">PHP <span style="color:var(--primary)">Upload Info</span></div>
<div class="admin-page-sub">Servereinstellungen für den Datei-Upload</div>

<div class="card">
<div class="card-body">
<?php
$checks = [
    'PHP Version'           => PHP_VERSION,
    'upload_max_filesize'   => ini_get('upload_max_filesize'),
    'post_max_size'         => ini_get('post_max_size'),
    'max_execution_time'    => ini_get('max_execution_time').'s',
    'memory_limit'          => ini_get('memory_limit'),
    'file_uploads'          => ini_get('file_uploads') ? '✅ Aktiv' : '❌ Deaktiviert',
    'upload_tmp_dir'        => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
    'Tmp beschreibbar'      => is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()) ? '✅ Ja' : '❌ NEIN – Problem!',
    'SimpleXML vorhanden'   => extension_loaded('simplexml') ? '✅ Ja' : '❌ NEIN – Problem!',
    'libxml Version'        => LIBXML_DOTTED_VERSION,
    'libxml_disable_entity' => function_exists('libxml_disable_entity_loader') ? '✅ vorhanden' : '⚠ nicht vorhanden (PHP 8.0+, OK)',
];
foreach ($checks as $label => $val): ?>
<div class="flex justify-between" style="padding:8px 0;border-bottom:1px solid var(--border);font-size:.9rem">
  <span class="text-muted"><?= $label ?></span>
  <strong><?= $val ?></strong>
</div>
<?php endforeach; ?>
<div class="mt-3 notice notice-warning">⚠️ Diese Seite nach dem Testen löschen!</div>
</div>
</div>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
