<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = 'calendar';
$db = getDB();

$trackId = (int)($_GET['id'] ?? 0);
if (!$trackId) { header('Location: ' . SITE_URL . '/calendar.php'); exit; }

$stmt = $db->prepare("SELECT * FROM tracks WHERE id=?");
$stmt->execute([$trackId]);
$track = $stmt->fetch();
if (!$track) { header('Location: ' . SITE_URL . '/calendar.php'); exit; }

// All races on this track
$races = $db->prepare("
    SELECT rc.id, rc.round, rc.race_date, s.name AS season_name,
           r.id AS result_id,
           (SELECT re.driver_name_raw FROM result_entries re WHERE re.result_id=r.id AND re.position=1 LIMIT 1) AS winner_raw,
           (SELECT d.name FROM result_entries re JOIN drivers d ON d.id=re.driver_id WHERE re.result_id=r.id AND re.position=1 LIMIT 1) AS winner_name
    FROM races rc
    JOIN seasons s ON s.id=rc.season_id
    LEFT JOIN results r ON r.race_id=rc.id
    WHERE rc.track_name = ? OR rc.track_id = ?
    ORDER BY rc.race_date DESC
");
$races->execute([$track['name'], $trackId]);
$trackRaces = $races->fetchAll();

$pageTitle = h($track['name']) . ' – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';
?>

<div class="container section">
  <!-- Track Header -->
  <?php if ($track['image_path']): ?>
  <div style="width:100%;height:280px;border-radius:6px;overflow:hidden;margin-bottom:24px;position:relative">
    <img src="<?= h($track['image_path']) ?>" style="width:100%;height:100%;object-fit:cover" alt="<?= h($track['name']) ?>"/>
    <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(10,10,15,.9) 0%,transparent 60%)"></div>
    <div style="position:absolute;bottom:24px;left:28px">
      <div class="section-title" style="margin:0"><?= h($track['name']) ?></div>
      <div class="text-muted"><?= h($track['location']??'') ?><?= $track['country']?' · '.h($track['country']):'' ?></div>
    </div>
  </div>
  <?php else: ?>
  <div class="section-title"><?= h($track['name']) ?></div>
  <div class="section-sub"><?= h($track['location']??'') ?><?= $track['country']?' · '.h($track['country']):'' ?></div>
  <?php endif; ?>

  <div class="grid-2 mb-4" style="gap:20px;align-items:start">
    <!-- Info Card -->
    <div class="card">
      <div class="card-header"><h3>🏎 Streckeninformationen</h3></div>
      <div class="card-body">
        <?php $infos = [
          ['icon'=>'📍','label'=>'Ort','val'=>trim(($track['location']??'').' '.($track['country']??''))],
          ['icon'=>'📏','label'=>'Länge','val'=>$track['length_km']?number_format((float)$track['length_km'],3).' km':'–'],
          ['icon'=>'🔄','label'=>'Kurven','val'=>$track['corners']?((int)$track['corners']).' Kurven':'–'],
          ['icon'=>'⚡','label'=>'Streckenrekord','val'=>$track['lap_record']??'–'],
          ['icon'=>'🏎','label'=>'Rekordhalter','val'=>$track['lap_record_driver']??'–'],
          ['icon'=>'📅','label'=>'Rekordjahr','val'=>$track['lap_record_year']??'–'],
        ];
        foreach ($infos as $inf): if(!$inf['val']||$inf['val']==='–') continue; ?>
        <div class="flex flex-center gap-2" style="padding:9px 0;border-bottom:1px solid var(--border)">
          <span style="font-size:1.1rem;min-width:24px"><?= $inf['icon'] ?></span>
          <span class="text-muted" style="min-width:130px;font-size:.88rem"><?= $inf['label'] ?></span>
          <strong style="font-size:.9rem"><?= h((string)$inf['val']) ?></strong>
        </div>
        <?php endforeach; ?>
        <?php if ($track['description']): ?>
        <p class="text-muted mt-2" style="font-size:.88rem;line-height:1.7"><?= h($track['description']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Layout -->
    <div>
      <?php if ($track['layout_path']): ?>
      <div class="card mb-3">
        <div class="card-header"><h3>🗺 Streckenlayout</h3></div>
        <div class="card-body" style="text-align:center">
          <img src="<?= h($track['layout_path']) ?>" style="max-height:250px;max-width:100%;object-fit:contain" alt="Layout"/>
        </div>
      </div>
      <?php endif; ?>

      <!-- Stats on this track in league -->
      <div class="card">
        <div class="card-header"><h3>🏆 Liga-Statistiken</h3></div>
        <div class="card-body">
          <div class="flex justify-between" style="padding:7px 0;border-bottom:1px solid var(--border)">
            <span class="text-muted">Rennen gefahren</span><strong><?= count($trackRaces) ?></strong>
          </div>
          <?php
          // Most wins on track
          $winners = [];
          foreach ($trackRaces as $r) {
            $w = $r['winner_name'] ?: $r['winner_raw'];
            if ($w) $winners[$w] = ($winners[$w]??0)+1;
          }
          arsort($winners);
          if ($winners): $top=array_key_first($winners); ?>
          <div class="flex justify-between" style="padding:7px 0;border-bottom:1px solid var(--border)">
            <span class="text-muted">Meiste Siege</span><strong><?= h($top) ?> (<?= $winners[$top] ?>x)</strong>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Race History -->
  <?php if ($trackRaces): ?>
  <div class="section-title">Rennen auf dieser <span>Strecke</span></div>
  <div class="section-sub">Liga-Historie</div>
  <?php foreach ($trackRaces as $r): ?>
  <div class="race-item">
    <div class="race-round">R<?= (int)$r['round'] ?></div>
    <div class="flex-1">
      <div class="race-track-name"><?= h($r['season_name']) ?></div>
      <div class="race-track-loc"><?= $r['race_date']?date('d.m.Y',strtotime($r['race_date'])):'TBD' ?></div>
    </div>
    <?php $winner=$r['winner_name']??$r['winner_raw']; if($winner): ?>
    <div class="text-muted" style="font-size:.88rem">🥇 <?= h($winner) ?></div>
    <?php endif; ?>
    <?php if ($r['result_id']): ?>
    <a href="<?= SITE_URL ?>/results.php?id=<?= $r['result_id'] ?>" class="race-status done">Ergebnis</a>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
