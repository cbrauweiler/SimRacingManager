<?php
// season.php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = 'season';
$db = getDB();
$seasons = $db->query("SELECT s.*, (SELECT COUNT(*) FROM teams t WHERE t.season_id = s.id) AS team_count, (SELECT COUNT(*) FROM races r WHERE r.season_id = s.id) AS race_count FROM seasons s ORDER BY year DESC, id DESC")->fetchAll();
$pageTitle = 'Saison – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="section-title">Saison <span>Übersicht</span></div>
  <div class="section-sub">Alle Saisons</div>
  <?php if ($seasons): ?>
  <div class="grid-2">
    <?php foreach ($seasons as $s): ?>
    <div class="card">
      <div class="card-header">
        <h3><?= h($s['name']) ?> <?= $s['year']?'('.$s['year'].')':'' ?></h3>
        <span class="badge <?= $s['is_active']?'badge-primary':'badge-muted' ?>"><?= $s['is_active']?'Aktiv':'Inaktiv' ?></span>
      </div>
      <div class="card-body">
        <?php if ($s['game']): ?><div class="text-muted mb-1" style="font-size:.88rem">🎮 <?= h($s['game']) ?></div><?php endif; ?>
        <?php if ($s['car_class']): ?><div class="text-muted mb-1" style="font-size:.88rem">🚗 <?= h($s['car_class']) ?></div><?php endif; ?>
        <?php if ($s['description']): ?><p class="text-muted mt-2" style="font-size:.88rem"><?= h($s['description']) ?></p><?php endif; ?>
        <div class="flex gap-2 mt-2">
          <span class="badge badge-info"><?= (int)$s['team_count'] ?> Teams</span>
          <span class="badge badge-secondary"><?= (int)$s['race_count'] ?> Rennen</span>
        </div>
        <div class="flex gap-1 mt-2">
          <a href="<?= SITE_URL ?>/calendar.php?season=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">Kalender</a>
          <a href="<?= SITE_URL ?>/standings.php?season=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">Wertung</a>
          <a href="<?= SITE_URL ?>/teams.php?season=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">Teams</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?><div class="card"><div class="card-body text-muted">Noch keine Saisons angelegt.</div></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
