<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Dashboard';
$adminPage  = 'dashboard';
require_once __DIR__ . '/includes/layout.php';

$db = getDB();
$stats = [
    'news'    => $db->query("SELECT COUNT(*) FROM news WHERE published=1")->fetchColumn(),
    'seasons' => $db->query("SELECT COUNT(*) FROM seasons")->fetchColumn(),
    'teams'   => $db->query("SELECT COUNT(*) FROM teams")->fetchColumn(),
    'drivers' => $db->query("SELECT COUNT(*) FROM season_entries WHERE is_reserve=0")->fetchColumn(),
    'races'   => $db->query("SELECT COUNT(*) FROM races")->fetchColumn(),
    'results' => $db->query("SELECT COUNT(*) FROM results")->fetchColumn(),
];
$recentNews = $db->query("SELECT title, created_at FROM news ORDER BY created_at DESC LIMIT 5")->fetchAll();
$recentResults = $db->query("
    SELECT r.id, r.imported_at, rc.track_name, s.name AS season_name
    FROM results r JOIN races rc ON rc.id=r.race_id JOIN seasons s ON s.id=rc.season_id
    ORDER BY r.imported_at DESC LIMIT 5")->fetchAll();
$nextRace = $db->query("
    SELECT rc.*, s.name AS season_name FROM races rc
    JOIN seasons s ON s.id=rc.season_id WHERE s.is_active=1 AND rc.race_date >= CURDATE()
    ORDER BY rc.race_date ASC LIMIT 1")->fetch();
?>

<?php
// Update-Check (gecacht für 6h in Session)
if (empty($_SESSION['update_check_time']) || time() - $_SESSION['update_check_time'] > 21600) {
    $ctx = stream_context_create(['http'=>['timeout'=>4,'user_agent'=>'SimRacingManager/'.APP_VERSION,'ignore_errors'=>true]]);
    $rel = @json_decode(@file_get_contents('https://api.github.com/repos/cbrauweiler/SimRacingManager/releases/latest', false, $ctx), true);
    $_SESSION['update_check_result'] = $rel['tag_name'] ?? null;
    $_SESSION['update_check_time']   = time();
}
$latestVer = $_SESSION['update_check_result'] ?? null;
$updateAvail = $latestVer && version_compare(ltrim($latestVer,'v'), APP_VERSION, '>');
?>
<?php if ($updateAvail): ?>
<div class="notice notice-warning mb-3" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
  <span>🆕 <strong>Update verfügbar:</strong> Version <?= h($latestVer) ?> ist auf GitHub veröffentlicht.</span>
  <a href="<?= SITE_URL ?>/admin/update.php" class="btn btn-secondary btn-sm">Update installieren →</a>
</div>
<?php endif; ?>
<div class="admin-page-title">Dashboard</div>
<div class="admin-page-sub">Willkommen zurück, <?= h($cu['user']) ?>!</div>

<!-- Stats -->
<div class="grid-4 mb-4" style="gap:14px">
  <?php foreach ([
    ['num'=>$stats['news'],    'lbl'=>'News',    'icon'=>'📰'],
    ['num'=>$stats['teams'],   'lbl'=>'Teams',   'icon'=>'🚗'],
    ['num'=>$stats['drivers'], 'lbl'=>'Fahrer',  'icon'=>'🏎️'],
    ['num'=>$stats['results'], 'lbl'=>'Ergebnisse','icon'=>'🏁'],
  ] as $st): ?>
  <div class="dash-stat">
    <div style="font-size:1.6rem;margin-bottom:6px"><?= $st['icon'] ?></div>
    <div class="num"><?= $st['num'] ?></div>
    <div class="lbl"><?= $st['lbl'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid-2" style="gap:20px">
  <!-- Recent News -->
  <div class="card">
    <div class="card-header">
      <h3>📰 Letzte News</h3>
      <a href="<?= SITE_URL ?>/admin/news.php" class="btn btn-secondary btn-sm">Alle</a>
    </div>
    <div class="card-body" style="padding:0">
      <?php if ($recentNews): foreach ($recentNews as $n): ?>
      <div class="activity-item" style="padding:10px 18px">
        <div class="activity-icon">📰</div>
        <div>
          <div class="activity-text"><?= h($n['title']) ?></div>
          <div class="activity-time"><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></div>
        </div>
      </div>
      <?php endforeach; else: ?>
      <div style="padding:18px" class="text-muted">Keine News vorhanden.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Results + Next Race -->
  <div>
    <?php if ($nextRace): ?>
    <div class="card mb-3">
      <div class="card-header"><h3>⏭ Nächstes Rennen</h3></div>
      <div class="card-body">
        <div class="font-display font-black" style="font-size:1.4rem"><?= h($nextRace['track_name']) ?></div>
        <div class="text-muted"><?= h($nextRace['season_name']) ?> · Runde <?= (int)$nextRace['round'] ?></div>
        <div class="flex gap-1 mt-2">
          <span class="badge badge-secondary">📅 <?= date('d.m.Y', strtotime($nextRace['race_date'])) ?></span>
          <?php if ($nextRace['race_time']): ?>
            <span class="badge badge-info">⏰ <?= substr($nextRace['race_time'],0,5) ?> Uhr</span>
          <?php endif; ?>
        </div>
        <!--<a href="<?= SITE_URL ?>/admin/upload.php" class="btn btn-primary btn-sm mt-2">Ergebnis hochladen</a>-->
      </div>
    </div>
    <?php endif; ?>
    <div class="card">
      <div class="card-header">
        <h3>🏁 Letzte Ergebnisse</h3>
        <a href="<?= SITE_URL ?>/admin/results.php" class="btn btn-secondary btn-sm">Alle</a>
      </div>
      <div class="card-body" style="padding:0">
        <?php if ($recentResults): foreach ($recentResults as $r): ?>
        <div class="activity-item" style="padding:10px 18px">
          <div class="activity-icon">🏁</div>
          <div>
            <div class="activity-text"><?= h($r['track_name']) ?> <span class="text-muted" style="font-size:.8rem">(<?= h($r['season_name']) ?>)</span></div>
            <div class="activity-time"><?= date('d.m.Y H:i', strtotime($r['imported_at'])) ?></div>
          </div>
          <a href="<?= SITE_URL ?>/results.php?id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm" target="_blank" style="margin-left:auto">Ansehen</a>
        </div>
        <?php endforeach; else: ?>
        <div style="padding:18px" class="text-muted">Noch keine Ergebnisse.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
