<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = 'hof';
$db = getDB();

// Alle Saisons oder aktive Saison filtern
$seasons = $db->query("SELECT * FROM seasons ORDER BY year DESC")->fetchAll();
$filterSid = (int)($_GET['season'] ?? 0);

// SQL-Filter
$seasonFilter    = $filterSid ? "AND rc.season_id = {$filterSid}" : "";
$seasonFilterSE  = $filterSid ? "AND se.season_id = {$filterSid}" : "";

$bsql = buildBonusSql('re');

// ---- Meiste Punkte ----
$topPoints = $db->query("
    SELECT d.id, d.name, d.photo_path, d.nationality,
           COALESCE(SUM({$bsql}), 0) AS val,
           COUNT(DISTINCT re.result_id) AS races
    FROM drivers d
    JOIN result_entries re ON re.driver_id = d.id
    JOIN results r ON r.id = re.result_id
    JOIN races rc ON rc.id = r.race_id
    WHERE re.dsq = 0 {$seasonFilter}
    GROUP BY d.id, d.name, d.photo_path, d.nationality
    HAVING val > 0
    ORDER BY val DESC LIMIT 10
")->fetchAll();

// ---- Meiste Siege ----
$topWins = $db->query("
    SELECT d.id, d.name, d.photo_path, d.nationality,
           COUNT(*) AS val
    FROM drivers d
    JOIN result_entries re ON re.driver_id = d.id AND re.position = 1 AND re.dnf = 0 AND re.dsq = 0
    JOIN results r ON r.id = re.result_id
    JOIN races rc ON rc.id = r.race_id
    WHERE 1=1 {$seasonFilter}
    GROUP BY d.id, d.name, d.photo_path, d.nationality
    ORDER BY val DESC LIMIT 10
")->fetchAll();

// ---- Meiste Podien ----
$topPodiums = $db->query("
    SELECT d.id, d.name, d.photo_path, d.nationality,
           COUNT(*) AS val
    FROM drivers d
    JOIN result_entries re ON re.driver_id = d.id AND re.position <= 3 AND re.dnf = 0 AND re.dsq = 0
    JOIN results r ON r.id = re.result_id
    JOIN races rc ON rc.id = r.race_id
    WHERE 1=1 {$seasonFilter}
    GROUP BY d.id, d.name, d.photo_path, d.nationality
    ORDER BY val DESC LIMIT 10
")->fetchAll();

// ---- Meiste Fastest Laps ----
$topFL = $db->query("
    SELECT d.id, d.name, d.photo_path, d.nationality,
           COUNT(*) AS val
    FROM drivers d
    JOIN result_entries re ON re.driver_id = d.id AND re.is_fastest_lap = 1
    JOIN results r ON r.id = re.result_id
    JOIN races rc ON rc.id = r.race_id
    WHERE 1=1 {$seasonFilter}
    GROUP BY d.id, d.name, d.photo_path, d.nationality
    ORDER BY val DESC LIMIT 10
")->fetchAll();

// ---- Meiste Poles (Qualifying P1) ----
$topPoles = $db->query("
    SELECT d.id, d.name, d.photo_path, d.nationality,
           COUNT(*) AS val
    FROM drivers d
    JOIN qualifying_results qr ON qr.driver_id = d.id AND qr.position = 1
    JOIN races rc ON rc.id = qr.race_id
    WHERE 1=1 {$seasonFilter}
    GROUP BY d.id, d.name, d.photo_path, d.nationality
    ORDER BY val DESC LIMIT 10
")->fetchAll();

// ---- Meiste Starts ----
$topStarts = $db->query("
    SELECT d.id, d.name, d.photo_path, d.nationality,
           COUNT(DISTINCT re.result_id) AS val
    FROM drivers d
    JOIN result_entries re ON re.driver_id = d.id
    JOIN results r ON r.id = re.result_id
    JOIN races rc ON rc.id = r.race_id
    WHERE 1=1 {$seasonFilter}
    GROUP BY d.id, d.name, d.photo_path, d.nationality
    ORDER BY val DESC LIMIT 10
")->fetchAll();

// ---- Beste Siegquote (min. 5 Starts) ----
$topWinRate = $db->query("
    SELECT d.id, d.name, d.photo_path, d.nationality,
           COUNT(DISTINCT re.result_id) AS starts,
           SUM(CASE WHEN re.position=1 AND re.dnf=0 AND re.dsq=0 THEN 1 ELSE 0 END) AS wins,
           ROUND(100.0 * SUM(CASE WHEN re.position=1 AND re.dnf=0 AND re.dsq=0 THEN 1 ELSE 0 END)
                 / COUNT(DISTINCT re.result_id), 1) AS val
    FROM drivers d
    JOIN result_entries re ON re.driver_id = d.id
    JOIN results r ON r.id = re.result_id
    JOIN races rc ON rc.id = r.race_id
    WHERE 1=1 {$seasonFilter}
    GROUP BY d.id, d.name, d.photo_path, d.nationality
    HAVING starts >= 5 AND wins > 0
    ORDER BY val DESC LIMIT 10
")->fetchAll();

$pageTitle = 'Hall of Fame – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';

// Hilfsfunktion: Fahrer-Avatar
function hofAvatar(array $d, int $size=40): string {
    $initials = implode('', array_map(fn($p) => mb_strtoupper(mb_substr($p,0,1)),
        array_filter(explode(' ', $d['name']))));
    $initials = mb_substr($initials, 0, 2);
    if ($d['photo_path']) {
        return "<img src='".h($d['photo_path'])."' style='width:{$size}px;height:{$size}px;border-radius:50%;object-fit:cover;object-position:top' alt=''/>";
    }
    return "<div style='width:{$size}px;height:{$size}px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-weight:900;font-size:".round($size*.35)."px;flex-shrink:0'>".h($initials)."</div>";
}

// Rang-Farbe
function hofRankColor(int $rank): string {
    return ['', '#f5c842', '#c0c0c0', '#cd7f32'][$rank] ?? 'var(--text2)';
}
?>

<div class="container section">
  <div class="flex justify-between mb-3" style="flex-wrap:wrap;gap:12px;align-items:flex-end">
    <div>
      <div class="section-title">Hall of <span>Fame</span></div>
      <div class="section-sub">Die besten Fahrer aller Zeiten<?= $filterSid ? ' – '.h(array_values(array_filter($seasons,fn($s)=>$s['id']==$filterSid))[0]['name']??'') : ' (alle Saisons)' ?></div>
    </div>
    <?php if (count($seasons) > 1): ?>
    <form method="get">
      <select name="season" class="form-control" onchange="this.form.submit()" style="background:var(--bg2);color:var(--text);border-color:var(--border);padding:8px 12px;border-radius:4px">
        <option value="0">Alle Saisons</option>
        <?php foreach ($seasons as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $s['id']==$filterSid?'selected':'' ?>><?= h($s['name']) ?> <?= h($s['year']??'') ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <?php
  $categories = [
      ['data'=>$topPoints,  'title'=>'🏆 Meiste Punkte',       'unit'=>'Pkt',    'format'=>fn($v)=>number_format((float)$v,1)],
      ['data'=>$topWins,    'title'=>'🥇 Meiste Siege',        'unit'=>'Siege',  'format'=>fn($v)=>(int)$v],
      ['data'=>$topPodiums, 'title'=>'🥈 Meiste Podien',       'unit'=>'Podien', 'format'=>fn($v)=>(int)$v],
      ['data'=>$topPoles,   'title'=>'⚡ Meiste Pole Positions','unit'=>'Poles',  'format'=>fn($v)=>(int)$v],
      ['data'=>$topFL,      'title'=>'⚡ Meiste Fastest Laps',  'unit'=>'FL',     'format'=>fn($v)=>(int)$v],
      ['data'=>$topStarts,  'title'=>'🏁 Meiste Starts',       'unit'=>'Starts', 'format'=>fn($v)=>(int)$v],
      ['data'=>$topWinRate, 'title'=>'📈 Beste Siegquote',     'unit'=>'%',      'format'=>fn($v)=>$v.'%', 'sub'=>fn($r)=>'('.(int)$r['wins'].' von '.(int)$r['starts'].' Starts)'],
  ];
  ?>

  <div class="grid-2" style="gap:20px">
  <?php foreach ($categories as $cat):
    if (!$cat['data']) continue;
    $maxVal = (float)$cat['data'][0]['val'];
  ?>
  <div class="card">
    <div class="card-header"><h3><?= $cat['title'] ?></h3></div>
    <div class="card-body" style="padding:0">
      <?php foreach ($cat['data'] as $rank => $d):
        $val     = ($cat['format'])($d['val']);
        $barPct  = $maxVal > 0 ? round((float)$d['val'] / $maxVal * 100) : 0;
        $rankCol = hofRankColor($rank + 1);
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border)">
        <div style="font-family:var(--font-display);font-size:1.1rem;font-weight:900;color:<?= $rankCol ?>;min-width:26px;text-align:center">
          <?= $rank < 3 ? ['🥇','🥈','🥉'][$rank] : ($rank+1) ?>
        </div>
        <div style="flex-shrink:0"><?= hofAvatar($d, 36) ?></div>
        <div style="flex:1;min-width:0">
          <a href="<?= SITE_URL ?>/driver.php?id=<?= $d['id'] ?>"
             style="font-weight:700;font-size:.92rem;color:var(--text);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block">
            <?= h($d['name']) ?>
          </a>
          <?php if (isset($cat['sub'])): ?>
          <div class="text-muted" style="font-size:.72rem"><?= ($cat['sub'])($d) ?></div>
          <?php elseif($d['nationality']): ?>
          <div class="text-muted" style="font-size:.72rem"><?= h($d['nationality']) ?></div>
          <?php endif; ?>
          <!-- Balken -->
          <div style="height:3px;background:var(--border);border-radius:2px;margin-top:4px;overflow:hidden">
            <div style="height:100%;width:<?= $barPct ?>%;background:<?= $rankCol==='var(--text2)' ? 'var(--primary)' : $rankCol ?>;border-radius:2px;transition:width .4s"></div>
          </div>
        </div>
        <div style="font-family:var(--font-display);font-size:1.2rem;font-weight:900;color:<?= $rankCol==='var(--text2)' ? 'var(--primary)' : $rankCol ?>;min-width:50px;text-align:right">
          <?= $val ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
