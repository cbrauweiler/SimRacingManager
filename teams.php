<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = 'teams';
$db = getDB();

$activeSeason = $db->query("SELECT * FROM seasons WHERE is_active=1 LIMIT 1")->fetch();
$sid = $activeSeason['id'] ?? 0;
$teams = [];

if ($sid) {
    $stmt = $db->prepare("SELECT * FROM teams WHERE season_id=? ORDER BY name");
    $stmt->execute([$sid]);
    $teams = $stmt->fetchAll();

    foreach ($teams as &$t) {
        // Stammfahrer über season_entries
        $stmt2 = $db->prepare("
            SELECT d.id, d.name, d.nationality, d.photo_path,
                   se.number, se.is_reserve,
                   COALESCE(SUM(re.points + re.bonus_points), 0) AS season_pts,
                   COUNT(CASE WHEN re.position = 1 THEN 1 END) AS wins
            FROM season_entries se
            JOIN drivers d ON d.id = se.driver_id
            LEFT JOIN result_entries re ON re.driver_id = d.id
            LEFT JOIN results r ON r.id = re.result_id
            LEFT JOIN races rc ON rc.id = r.race_id AND rc.season_id = ?
            WHERE se.season_id = ? AND se.team_id = ? AND se.is_reserve = 0
            GROUP BY d.id, d.name, d.nationality, d.photo_path, se.number, se.is_reserve
            ORDER BY se.number ASC
        ");
        $stmt2->execute([$sid, $sid, $t['id']]);
        $t['drivers'] = $stmt2->fetchAll();

        // Reservefahrer
        $stmt3 = $db->prepare("
            SELECT d.id, d.name, d.nationality, d.photo_path, se.number
            FROM season_entries se
            JOIN drivers d ON d.id = se.driver_id
            WHERE se.season_id = ? AND se.team_id = ? AND se.is_reserve = 1
            ORDER BY se.number ASC
        ");
        $stmt3->execute([$sid, $t['id']]);
        $t['reserves'] = $stmt3->fetchAll();
    }
    unset($t); // Referenz aufloesen! Sonst wird das vorletzte Team doppelt angezeigt
}

// Ratings laden
$showRatings = getSetting('rating_show_public','1') === '1';
$ratingsMap  = [];
if ($showRatings && isset($activeSeason['id'])) {
    $rStmt = $db->prepare("SELECT * FROM driver_ratings WHERE season_id=?");
    $rStmt->execute([$activeSeason['id']]);
    foreach ($rStmt->fetchAll() as $r) $ratingsMap[$r['driver_id']] = $r;
}

$pageTitle = 'Teams & Fahrer – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="mb-3">
    <div class="section-title">Teams &amp; <span>Fahrer</span></div>
    <div class="section-sub">
      <?php if($activeSeason): ?>
        <?= h($activeSeason['name']) ?><?= $activeSeason['year'] ? ' '.$activeSeason['year'] : '' ?>
        <?php if($activeSeason['game']): ?> · 🎮 <?= h($activeSeason['game']) ?><?php endif; ?>
      <?php else: ?>Keine aktive Saison<?php endif; ?>
    </div>
  </div>

  <?php if(!$activeSeason): ?>
    <div class="card"><div class="card-body text-muted">Keine aktive Saison gesetzt.</div></div>
  <?php elseif($teams): ?>
  <div class="grid-3">
    <?php foreach($teams as $t): ?>
    <div class="card team-card">
      <div class="team-card-header" style="background:linear-gradient(135deg,<?= h($t['color']) ?>22,<?= h($t['color']) ?>55)">
        <?php if($t['logo_path']): ?>
          <img src="<?= h($t['logo_path']) ?>" alt="<?= h($t['name']) ?>"/>
        <?php else: ?>
          <span style="font-size:2.5rem">🚗</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div class="team-card-name" style="color:<?= h($t['color']) ?>"><?= h($t['name']) ?></div>
        <?php if($t['car']): ?><div class="text-muted mb-2" style="font-size:.82rem">🚘 <?= h($t['car']) ?></div><?php endif; ?>

        <?php if($t['drivers']): ?>
        <ul class="driver-list">
          <?php foreach($t['drivers'] as $d): ?>
          <li>
            <span class="driver-num"><?= $d['number'] ? '#'.(int)$d['number'] : '' ?></span>
            <a href="<?= SITE_URL ?>/driver.php?id=<?= $d['id'] ?>" style="display:flex;align-items:center;gap:8px;flex:1;text-decoration:none;color:var(--text)">
              <div class="driver-avatar">
                <?php if($d['photo_path']): ?><img src="<?= h($d['photo_path']) ?>" alt=""/>
                <?php else: ?><?= h(mb_substr($d['name'],0,2)) ?><?php endif; ?>
              </div>
              <span><?= h($d['name']) ?></span>
              <?php if ($showRatings && isset($ratingsMap[$d['id']])): ?>
              <?php $dr=$ratingsMap[$d['id']]; $ov=(float)$dr['overall']; ?>
              <span style="margin-left:6px;padding:1px 6px;border-radius:10px;font-family:var(--font-display);font-weight:900;font-size:.7rem;background:rgba(0,0,0,.3);color:<?= ratingBadgeColor($ov) ?>;border:1px solid <?= ratingBadgeColor($ov) ?>44"><?= number_format($ov,1) ?></span>
              <?php endif; ?>
            </a>
            <?php if($d['wins'] > 0): ?>
              <span class="badge badge-secondary" style="font-size:.6rem">🥇 <?= (int)$d['wins'] ?>x</span>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
          <div class="text-muted" style="font-size:.84rem;margin-bottom:8px">Noch keine Stammfahrer.</div>
        <?php endif; ?>

        <?php if($t['reserves']): ?>
        <div style="margin-top:10px;padding-top:8px;border-top:1px solid var(--border)">
          <div class="text-muted" style="font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px">Reservefahrer</div>
          <?php foreach($t['reserves'] as $r): ?>
          <a href="<?= SITE_URL ?>/driver.php?id=<?= $r['id'] ?>" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:var(--text2);font-size:.84rem;padding:3px 0">
            <div class="driver-avatar" style="width:24px;height:24px;font-size:.65rem;opacity:.7"><?= h(mb_substr($r['name'],0,2)) ?></div>
            <?= h($r['name']) ?>
            <span class="badge badge-info" style="font-size:.58rem;margin-left:auto">Reserve</span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php $totalPts = array_sum(array_column($t['drivers'], 'season_pts'));
        if($totalPts > 0): ?>
        <div class="flex justify-between mt-2 pt-2" style="border-top:1px solid var(--border)">
          <span class="text-muted" style="font-size:.78rem">Team-Punkte</span>
          <span class="pts-col" style="font-size:.9rem"><?= number_format((float)$totalPts, 1) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <div class="card"><div class="card-body text-muted">Noch keine Teams in der aktiven Saison.</div></div>
  <?php endif; ?>
</div>
<?php
function ratingBadgeColor(float $v): string {
    if ($v >= 8.5) return '#4cffb0';
    if ($v >= 7.0) return '#a0f080';
    if ($v >= 5.5) return '#f5a623';
    if ($v >= 4.0) return '#ff9040';
    return '#ff6060';
}
?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
