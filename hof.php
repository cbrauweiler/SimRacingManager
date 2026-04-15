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

// ---- WM-Sieger je Saison ----
// Fahrer-Champion: höchste Gesamtpunkte pro Saison
$driverChampions = $db->query("
    SELECT s.id AS sid, s.name AS season_name, s.year,
           d.id AS driver_id, d.name AS driver_name, d.photo_path, d.nationality,
           t.name AS team_name, t.color AS team_color,
           COALESCE(SUM({$bsql}), 0) AS total_pts,
           COUNT(CASE WHEN re.position=1 AND re.dnf=0 AND re.dsq=0 THEN 1 END) AS wins
    FROM seasons s
    JOIN season_entries se ON se.season_id = s.id AND se.is_reserve = 0
    JOIN drivers d ON d.id = se.driver_id
    LEFT JOIN teams t ON t.id = se.team_id
    LEFT JOIN result_entries re ON re.driver_id = d.id
        AND re.result_id IN (SELECT r.id FROM results r JOIN races rc ON rc.id=r.race_id WHERE rc.season_id=s.id)
    GROUP BY s.id, s.name, s.year, d.id, d.name, d.photo_path, d.nationality, t.name, t.color
    HAVING total_pts > 0
    ORDER BY s.year DESC, s.id DESC, total_pts DESC, wins DESC
")->fetchAll();

// Pro Saison nur den Ersten behalten
$driverChampBySeaon = [];
foreach ($driverChampions as $row) {
    if (!isset($driverChampBySeaon[$row['sid']])) {
        $driverChampBySeaon[$row['sid']] = $row;
    }
}

// Team-Champion: höchste Gesamtpunkte aller Fahrer eines Teams pro Saison
$teamChampions = $db->query("
    SELECT s.id AS sid, s.name AS season_name, s.year,
           t.id AS team_id, t.name AS team_name, t.color AS team_color, t.logo_path,
           COALESCE(SUM({$bsql}), 0) AS total_pts,
           COUNT(CASE WHEN re.position=1 AND re.dnf=0 AND re.dsq=0 THEN 1 END) AS wins
    FROM seasons s
    JOIN teams t ON t.season_id = s.id
    LEFT JOIN season_entries se ON se.team_id = t.id AND se.season_id = s.id AND se.is_reserve = 0
    LEFT JOIN result_entries re ON re.driver_id = se.driver_id
        AND re.result_id IN (SELECT r.id FROM results r JOIN races rc ON rc.id=r.race_id WHERE rc.season_id=s.id)
    GROUP BY s.id, s.name, s.year, t.id, t.name, t.color, t.logo_path
    HAVING total_pts > 0
    ORDER BY s.year DESC, s.id DESC, total_pts DESC, wins DESC
")->fetchAll();

$teamChampBySeason = [];
foreach ($teamChampions as $row) {
    if (!isset($teamChampBySeason[$row['sid']])) {
        $teamChampBySeason[$row['sid']] = $row;
    }
}

// WEC Tiebreaker: Positionen je Saison laden und Champions korrekt ermitteln
function hofWecBest(array $rows, PDO $db, int $sid, string $type): ?array {
    if (empty($rows)) return null;
    if (count($rows) === 1) return $rows[0];

    // Positionen laden
    if ($type === 'driver') {
        $stmt = $db->prepare("
            SELECT re.driver_id AS eid, re.position, COUNT(*) AS cnt
            FROM result_entries re
            JOIN results r ON r.id=re.result_id
            JOIN races rc ON rc.id=r.race_id AND rc.season_id=?
            WHERE re.dnf=0 AND re.dsq=0 AND re.position IS NOT NULL
            GROUP BY re.driver_id, re.position
        ");
        $stmt->execute([$sid]);
    } else {
        $stmt = $db->prepare("
            SELECT se.team_id AS eid, re.position, COUNT(*) AS cnt
            FROM result_entries re
            JOIN season_entries se ON se.driver_id=re.driver_id AND se.season_id=?
            JOIN results r ON r.id=re.result_id
            JOIN races rc ON rc.id=r.race_id AND rc.season_id=?
            WHERE re.dnf=0 AND re.dsq=0 AND re.position IS NOT NULL
            GROUP BY se.team_id, re.position
        ");
        $stmt->execute([$sid, $sid]);
    }
    $posMap = [];
    foreach ($stmt->fetchAll() as $row) {
        $posMap[$row['eid']][(int)$row['position']] = (int)$row['cnt'];
    }

    $idKey = $type === 'driver' ? 'driver_id' : 'team_id';
    usort($rows, function($a, $b) use ($posMap, $idKey) {
        $diff = (float)$b['total_pts'] - (float)$a['total_pts'];
        if (abs($diff) > 0.001) return $diff > 0 ? 1 : -1;
        $aPos = $posMap[$a[$idKey]] ?? [];
        $bPos = $posMap[$b[$idKey]] ?? [];
        $max  = max(empty($aPos)?0:max(array_keys($aPos)), empty($bPos)?0:max(array_keys($bPos)), 20);
        for ($p = 1; $p <= $max; $p++) {
            $diff2 = ($bPos[$p]??0) - ($aPos[$p]??0);
            if ($diff2 !== 0) return $diff2;
        }
        return 0;
    });
    return $rows[0];
}

// Pro Saison den WEC-korrekten Champion ermitteln
// Alle Fahrer-Kandidaten pro Saison gruppieren
$driversBySeason = [];
foreach ($driverChampions as $row) {
    $driversBySeason[$row['sid']][] = $row;
}
$teamsBySeason = [];
foreach ($teamChampions as $row) {
    $teamsBySeason[$row['sid']][] = $row;
}

// Saisons mit Ergebnissen für die Champions-Tabelle
$champSeasons = [];
foreach ($driversBySeason as $sid => $dRows) {
    $bestDriver = hofWecBest($dRows, $db, $sid, 'driver');
    $tRows      = $teamsBySeason[$sid] ?? [];
    $bestTeam   = $tRows ? hofWecBest($tRows, $db, $sid, 'team') : null;
    if ($bestDriver) {
        $champSeasons[$sid] = [
            'sid'    => $sid,
            'name'   => $bestDriver['season_name'],
            'year'   => $bestDriver['year'],
            'driver' => $bestDriver,
            'team'   => $bestTeam,
        ];
    }
}
// Neueste Saison zuerst
krsort($champSeasons);

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

<?php if ($champSeasons): ?>
<div class="mt-4">
  <div class="section-title mb-3">🏆 <span>WM-Sieger</span> je Saison</div>
  <div class="card">
    <div class="card-body" style="padding:0">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:140px">Saison</th>
            <th>🏎️ Fahrer-Champion</th>
            <th style="width:120px">Punkte</th>
            <th>🚗 Team-Champion</th>
            <th style="width:120px">Punkte</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($champSeasons as $cs): ?>
          <?php $dc = $cs['driver']; $tc = $cs['team']; ?>
          <tr>
            <td>
              <span style="font-family:var(--font-display);font-weight:900;font-size:1rem"><?= h($cs['name']) ?></span>
              <?php if($cs['year']): ?><div class="text-muted" style="font-size:.78rem"><?= h($cs['year']) ?></div><?php endif; ?>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <?= hofAvatar($dc, 36) ?>
                <div>
                  <a href="<?= SITE_URL ?>/driver.php?id=<?= $dc['driver_id'] ?>"
                     style="font-weight:700;color:var(--text);text-decoration:none"><?= h($dc['driver_name']) ?></a>
                  <?php if ($dc['team_name']): ?>
                  <div style="display:flex;align-items:center;gap:5px;margin-top:2px">
                    <span style="width:8px;height:8px;border-radius:50%;background:<?= h($dc['team_color']??'#666') ?>;display:inline-block;flex-shrink:0"></span>
                    <span class="text-muted" style="font-size:.78rem"><?= h($dc['team_name']) ?></span>
                  </div>
                  <?php endif; ?>
                </div>
                <span style="margin-left:4px;font-size:.75rem;color:var(--secondary)">
                  <?php if($dc['wins']>0): ?>🥇 <?= (int)$dc['wins'] ?>x<?php endif; ?>
                </span>
              </div>
            </td>
            <td>
              <span style="font-family:var(--font-display);font-weight:900;font-size:1.1rem;color:var(--primary)">
                <?= number_format((float)$dc['total_pts'],1) ?>
              </span>
              <span class="text-muted" style="font-size:.75rem"> Pkt</span>
            </td>
            <td>
              <?php if ($tc): ?>
              <div style="display:flex;align-items:center;gap:10px">
                <?php if($tc['logo_path']): ?>
                  <img src="<?= h($tc['logo_path']) ?>" style="height:32px;width:32px;object-fit:contain;flex-shrink:0" alt=""/>
                <?php else: ?>
                  <div style="width:32px;height:32px;border-radius:50%;background:<?= h($tc['team_color']??'#666') ?>;flex-shrink:0"></div>
                <?php endif; ?>
                <div>
                  <span style="font-weight:700"><?= h($tc['team_name']) ?></span>
                  <?php if($tc['wins']>0): ?>
                  <div class="text-muted" style="font-size:.75rem">🥇 <?= (int)$tc['wins'] ?>x Sieg</div>
                  <?php endif; ?>
                </div>
              </div>
              <?php else: ?>
              <span class="text-muted">–</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($tc): ?>
              <span style="font-family:var(--font-display);font-weight:900;font-size:1.1rem;color:var(--secondary)">
                <?= number_format((float)$tc['total_pts'],1) ?>
              </span>
              <span class="text-muted" style="font-size:.75rem"> Pkt</span>
              <?php else: ?>–<?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
