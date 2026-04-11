<?php
// calendar.php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = 'calendar';
$db = getDB();
$seasons = $db->query("SELECT * FROM seasons ORDER BY year DESC")->fetchAll();
$activeSeason = array_values(array_filter($seasons, fn($s) => $s['is_active']));
$activeSeason = $activeSeason[0] ?? ($seasons[0] ?? null);
$seasonId = (int)($_GET['season'] ?? ($activeSeason['id'] ?? 0));
$races = [];
if ($seasonId) {
    $stmt = $db->prepare("SELECT r.*, res.id AS result_id FROM races r LEFT JOIN results res ON res.race_id = r.id WHERE r.season_id = ? ORDER BY r.round ASC");
    $stmt->execute([$seasonId]);
    $races = $stmt->fetchAll();
}
$today = date('Y-m-d');
$pageTitle = 'Rennkalender – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="flex flex-center justify-between mb-3" style="flex-wrap:wrap;gap:12px">
    <div>
      <div class="section-title">Renn<span>kalender</span></div>
      <div class="section-sub"><?= $activeSeason ? h($activeSeason['name']).' '.h($activeSeason['year']??'') : '' ?></div>
    </div>
    <?php if (count($seasons) > 1): ?>
    <form method="get">
      <select name="season" class="form-control" onchange="this.form.submit()" style="background:var(--bg2);color:var(--text);border-color:var(--border);padding:8px 12px;border-radius:4px">
        <?php foreach ($seasons as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$seasonId?'selected':'' ?>><?= h($s['name']) ?> <?= h($s['year']??'') ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>
  <?php if ($races): $nextDone = false; foreach ($races as $r):
    $isPast = $r['race_date'] < $today;
    $isNext = !$nextDone && !$isPast; if ($isNext) $nextDone = true;
  ?>
  <div class="race-item <?= $isPast?'past':'' ?> <?= $isNext?'next-race':'' ?>">
    <div class="race-round">Runde <?= (int)$r['round'] ?></div>
    <div class="flex-1">
      <div class="race-track-name"><a href="<?= SITE_URL ?>/track.php?id=<?= $r['track_id'] ?>"><?= h($r['track_name']) ?></a></div>
      <div class="race-track-loc"><?= h($r['location']??'') ?><?= $r['country']?' · '.h($r['country']):'' ?></div>
    </div>
    <div style="text-align:right">
      <div class="race-date-badge"><?= $r['race_date']?date('d.m.Y',strtotime($r['race_date'])):'TBD' ?><?= $r['race_time']?' · '.substr($r['race_time'],0,5).' Uhr':'' ?></div>
      <?php if ($isPast && $r['result_id']): ?>
        <a href="<?= SITE_URL ?>/results.php?id=<?= $r['result_id'] ?>" class="race-status done">Ergebnis ansehen</a>
      <?php elseif ($isPast): ?>
        <span class="race-status done">Kein Ergebnis</span>
      <?php elseif ($isNext): ?>
        <span class="race-status next">Nächstes Rennen</span>
      <?php else: ?>
        <span class="race-status upcoming">Geplant</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; else: ?>
    <div class="card"><div class="card-body text-muted">Noch keine Rennen geplant.</div></div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
