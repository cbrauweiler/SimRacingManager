<?php
// info.php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = 'info';
$pageTitle = 'Liga Info – ' . getSetting('league_name');
$infoHtml = getSetting('info_html', '<p>Hier stehen bald Infos über die Liga.</p>');
require_once __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="section-title">Liga <span>Info</span></div>
  <div class="section-sub">Alles über unsere Liga</div>
  <div class="card">
    <div class="card-body" style="line-height:1.85;max-width:900px">
      <?= $infoHtml ?>
    </div>
  </div>
</div>
<style>
  #app .card-body h1,#app .card-body h2,#app .card-body h3 { font-family:var(--font-display);text-transform:uppercase;letter-spacing:.04em;margin:24px 0 10px;color:var(--text); }
  #app .card-body p { margin-bottom:12px; }
  #app .card-body ul,#app .card-body ol { padding-left:20px;margin-bottom:12px; }
  #app .card-body li { margin-bottom:4px; }
  #app .card-body a { color:var(--primary); }
  #app .card-body table { border-collapse:collapse;width:100%;margin-bottom:16px; }
  #app .card-body th,#app .card-body td { border:1px solid var(--border);padding:8px 12px; }
  #app .card-body th { background:var(--bg3); }
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
