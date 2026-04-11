<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = 'teams';
$db = getDB();

$driverId = (int)($_GET['id'] ?? 0);
if (!$driverId) { header('Location: ' . SITE_URL . '/teams.php'); exit; }

// Fahrer mit aktuellem Team aus aktiver Saison
$stmt = $db->prepare("
    SELECT d.*,
           t.name AS team_name, t.color, t.id AS team_id, t.logo_path AS team_logo,
           se.number, se.is_reserve
    FROM drivers d
    LEFT JOIN seasons s ON s.is_active = 1
    LEFT JOIN season_entries se ON se.driver_id = d.id AND se.season_id = s.id
    LEFT JOIN teams t ON t.id = se.team_id
    WHERE d.id = ?
    LIMIT 1
");
$stmt->execute([$driverId]);
$driver = $stmt->fetch();
if (!$driver) { header('Location: ' . SITE_URL . '/teams.php'); exit; }

// Career stats
$stats = $db->prepare("
    SELECT
        COUNT(DISTINCT re.result_id) AS starts,
        SUM(re.points) AS total_pts,
        COUNT(CASE WHEN re.position=1 THEN 1 END) AS wins,
        COUNT(CASE WHEN re.position=2 THEN 1 END) AS p2,
        COUNT(CASE WHEN re.position=3 THEN 1 END) AS p3,
        COUNT(CASE WHEN re.is_fastest_lap=1 THEN 1 END) AS fastest_laps,
        COUNT(CASE WHEN re.dnf=1 THEN 1 END) AS dnfs,
        MIN(re.position) AS best_finish,
        AVG(re.position) AS avg_finish
    FROM result_entries re
    WHERE re.driver_id=? AND re.dnf=0
");
$stats->execute([$driverId]);
$career = $stats->fetch();

// All results
$results = $db->prepare("
    SELECT re.position, re.points, re.bonus_points, re.gap, re.total_time, re.fastest_lap, re.is_fastest_lap, re.dnf, re.dsq,
           rc.track_id, rc.track_name, rc.location, rc.race_date, rc.round, s.name AS season_name,
           t.name AS team_name_res, t.color AS team_color
    FROM result_entries re
    JOIN results r ON r.id=re.result_id
    JOIN races rc ON rc.id=r.race_id
    JOIN seasons s ON s.id=rc.season_id
    LEFT JOIN teams t ON t.id=re.team_id
    WHERE re.driver_id=?
    ORDER BY rc.race_date DESC
");
$results->execute([$driverId]);
$raceResults = $results->fetchAll();

// Points per race for chart (last 15)
$chartData = array_reverse(array_slice($raceResults, 0, 15));
$chartLabels = array_map(fn($r) => $r['location'] ? mb_substr($r['location'],0,10) : mb_substr($r['track_name'],0,8), $chartData);
$chartPoints = array_map(fn($r) => (float)($r['points']+$r['bonus_points']), $chartData);

// Qualifying results
$qResults = $db->prepare("
    SELECT qr.position, qr.lap_time, qr.gap, rc.track_id, rc.track_name, rc.race_date, rc.round, s.name AS season_name
    FROM qualifying_results qr
    JOIN races rc ON rc.id=qr.race_id
    JOIN seasons s ON s.id=rc.season_id
    WHERE qr.driver_id=?
    ORDER BY rc.race_date DESC LIMIT 20
");
$qResults->execute([$driverId]);
$qualiResults = $qResults->fetchAll();

$pageTitle = h($driver['name']) . ' – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';
?>

<div class="container section">
  <!-- Profile Header -->
  <div class="card mb-4" style="overflow:visible">
    <div style="background:linear-gradient(135deg,<?= h($driver['color']??'#333') ?>22,<?= h($driver['color']??'#333') ?>55,var(--bg2));height:120px;position:relative">
      <div style="position:absolute;bottom:-50px;left:28px;display:flex;align-items:flex-end;gap:20px">
        <div style="width:100px;height:100px;border-radius:50%;border:4px solid <?= h($driver['color']??'var(--primary)') ?>;background:var(--bg3);overflow:hidden;display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-size:2rem;font-weight:900;flex-shrink:0">
          <?php if ($driver['photo_path']): ?><img src="<?= h($driver['photo_path']) ?>" style="width:100%;height:100%;object-fit:cover" alt=""/>
          <?php else: ?><?= h(mb_substr($driver['name'],0,2)) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="card-body" style="padding-top:60px">
      <div class="flex flex-center justify-between" style="flex-wrap:wrap;gap:12px">
        <div>
          <?php if ($driver['number']): ?><div style="font-family:var(--font-display);font-size:1rem;color:var(--primary);font-weight:700">#<?= (int)$driver['number'] ?></div><?php endif; ?>
          <h1 style="font-family:var(--font-display);font-size:clamp(1.8rem,5vw,3rem);font-weight:900;line-height:1"><?= h($driver['name']) ?></h1>
          <div class="flex flex-center gap-2 mt-1">
            <?php if ($driver['nationality']): ?><span class="badge badge-muted"><?= h($driver['nationality']) ?></span><?php endif; ?>
            <?php if ($driver['is_reserve']): ?><span class="badge badge-info">Reserve</span><?php endif; ?>
            <?php if ($driver['team_name']): ?>
              <a href="<?= SITE_URL ?>/teams.php" style="text-decoration:none">
                <span class="badge" style="background:<?= h($driver['color']??'#666') ?>33;color:<?= h($driver['color']??'var(--primary)') ?>">
                  <span class="team-dot" style="background:<?= h($driver['color']??'#666') ?>"></span><?= h($driver['team_name']) ?>
                </span>
              </a>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($driver['team_logo']): ?><img src="<?= h($driver['team_logo']) ?>" style="max-height:60px;object-fit:contain" alt=""/><?php endif; ?>
      </div>
      <?php if ($driver['bio']): ?><p class="text-muted mt-2" style="max-width:700px;line-height:1.7"><?= h($driver['bio']) ?></p><?php endif; ?>
    </div>
  </div>

  <!-- Career Stats -->
  <div class="grid-4 mb-4">
    <?php foreach ([
      ['num'=>(int)$career['starts'],  'lbl'=>'Starts',      'icon'=>'🏁'],
      ['num'=>(int)$career['wins'],    'lbl'=>'Siege',       'icon'=>'🥇'],
      ['num'=>number_format((float)$career['total_pts'],1), 'lbl'=>'Punkte', 'icon'=>'📊'],
      ['num'=>(int)$career['fastest_laps'],'lbl'=>'FL Runden','icon'=>'⚡'],
    ] as $st): ?>
    <div class="card"><div class="stat-box">
      <div style="font-size:1.5rem;margin-bottom:4px"><?= $st['icon'] ?></div>
      <div class="stat-number"><?= $st['num'] ?></div>
      <div class="stat-label"><?= $st['lbl'] ?></div>
    </div></div>
    <?php endforeach; ?>
  </div>

  <!-- Chart + Extra Stats -->
  <div class="grid-2 mb-4" style="gap:20px">
    <div class="card">
      <div class="card-header"><h3>📈 Punkte je Rennen (letzte <?= count($chartData) ?>)</h3></div>
      <div class="card-body">
        <canvas id="pts-chart" height="180"></canvas>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3>📊 Statistiken</h3></div>
      <div class="card-body">
        <?php
        $podiums = (int)$career['wins'] + (int)$career['p2'] + (int)$career['p3'];
        $rows = [
          ['label'=>'Podien','val'=>$podiums],
          ['label'=>'P2 (2. Plätze)','val'=>(int)$career['p2']],
          ['label'=>'P3 (3. Plätze)','val'=>(int)$career['p3']],
          ['label'=>'Bestes Ergebnis','val'=>$career['best_finish']?'P'.(int)$career['best_finish']:'–'],
          ['label'=>'Ø Platzierung','val'=>$career['avg_finish']?round((float)$career['avg_finish'],1):'–'],
          ['label'=>'DNF','val'=>(int)$career['dnfs']],
        ];
        foreach ($rows as $r): ?>
        <div class="flex justify-between" style="padding:7px 0;border-bottom:1px solid var(--border);font-size:.9rem">
          <span class="text-muted"><?= $r['label'] ?></span>
          <strong><?= $r['val'] ?></strong>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Race Results -->
  <!-- Sheet Tabs -->
  <div class="sheet-tabs">
    <div class="sheet-tab active" data-group="driver" data-tab="races" onclick="sheetTab('driver','races')">
      <span class="tab-dot"></span>🏁 Rennergebnisse
      <?php if($raceResults): ?><span class="badge badge-muted" style="font-size:.65rem;margin-left:4px"><?= count($raceResults) ?></span><?php endif; ?>
    </div>
    <div class="sheet-tab" data-group="driver" data-tab="quali" onclick="sheetTab('driver','quali')" style="--primary:var(--tertiary)">
      <span class="tab-dot" style="background:var(--tertiary)"></span>⏱ Qualifying
      <?php if($qualiResults): ?><span class="badge badge-muted" style="font-size:.65rem;margin-left:4px"><?= count($qualiResults) ?></span><?php endif; ?>
    </div>
  </div>

  <div class="sheet-panel active" data-group="driver" data-tab="races">
  <div class="sheet-panel-inner">
    <?php if ($raceResults): ?>
    <div class="overflow-x">
    <table class="data-table">
      <thead><tr><th>Saison</th><th>Runde</th><th>Strecke</th><th>Ergebnis</th><th>Zeit/Gap</th><th>Punkte</th></tr></thead>
      <tbody>
        <?php foreach ($raceResults as $r): ?>
        <tr>
          <td class="text-muted"><?= h($r['season_name']) ?></td>
          <td class="text-muted">R<?= (int)$r['round'] ?></td>
          <td><strong><a href="<?= SITE_URL ?>/track.php?id=<?= $r['track_id'] ?>"><?= h($r['track_name']) ?></a></strong><div class="text-muted" style="font-size:.75rem"><?= $r['race_date']?date('d.m.Y',strtotime($r['race_date'])):'–' ?></div></td>
          <td>
            <?php if ($r['dnf']): ?><span class="badge badge-muted">DNF</span>
            <?php elseif ($r['dsq']): ?><span class="badge badge-muted">DSQ</span>
            <?php else: ?>
              <span class="pos-col <?= $r['position']==1?'pos-1':($r['position']==2?'pos-2':($r['position']==3?'pos-3':'')) ?>" style="font-family:var(--font-display);font-weight:900;font-size:1.1rem">P<?= (int)$r['position'] ?></span>
            <?php endif; ?>
            <?php if ($r['is_fastest_lap']): ?><span class="fl-badge">FL</span><?php endif; ?>
          </td>
          <td class="gap-col"><?= h($r['position']==1?($r['total_time']??'–'):($r['gap']??'–')) ?></td>
          <td class="pts-col"><?= number_format((float)($r['points']+$r['bonus_points']),1) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?><div class="text-muted mt-2">Noch keine Rennergebnisse.</div><?php endif; ?>
  </div></div><!-- /races panel -->

  <div class="sheet-panel" data-group="driver" data-tab="quali">
  <div class="sheet-panel-inner">
    <?php if ($qualiResults): ?>
    <div class="overflow-x">
    <table class="data-table">
      <thead><tr><th>Saison</th><th>Runde</th><th>Strecke</th><th>Pos</th><th>Rundenzeit</th><th>Abstand</th></tr></thead>
      <tbody>
        <?php foreach ($qualiResults as $q): ?>
        <tr>
          <td class="text-muted"><?= h($q['season_name']) ?></td>
		  <td class="text-muted">R<?= (int)$q['round'] ?></td>
          <td><strong><a href="<?= SITE_URL ?>/track.php?id=<?= $q['track_id'] ?>"><?= h($q['track_name']) ?></a></strong><div class="text-muted" style="font-size:.75rem"><?= $q['race_date']?date('d.m.Y',strtotime($q['race_date'])):'–' ?></div></td>
          <td class="pos-col <?= $q['position']==1?'pos-1':($q['position']==2?'pos-2':($q['position']==3?'pos-3':'')) ?>">P<?= (int)$q['position'] ?></td>
          <td style="font-family:monospace"><?= h($q['lap_time']??'–') ?></td>
          <td class="gap-col"><?= h($q['gap']??'–') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?><div class="text-muted mt-2">Noch keine Qualifying-Ergebnisse.</div><?php endif; ?>
  </div></div><!-- /quali panel -->
</div>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('pts-chart');
if (ctx) {
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($chartLabels) ?>,
      datasets: [{
        label: 'Punkte',
        data: <?= json_encode($chartPoints) ?>,
        backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() + 'b3',
        borderColor: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim(),
        borderWidth: 1,
        borderRadius: 3,
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { color: '#8888a0', font: { family: 'Barlow Condensed', weight: '700' } }, grid: { color: '#2a2a3a' } },
        y: { ticks: { color: '#8888a0' }, grid: { color: '#2a2a3a' }, beginAtZero: true }
      }
    }
  });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
