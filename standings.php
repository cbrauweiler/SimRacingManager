<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = 'standings';
$db = getDB();

$activeSeason = $db->query("SELECT * FROM seasons WHERE is_active=1 LIMIT 1")->fetch();
$sid = $activeSeason['id'] ?? 0;

// Reserve-Filter
$reserveDriver = getSetting('reserve_scores_driver', '1') === '1';
$reserveTeam   = getSetting('reserve_scores_team',   '0') === '1';
$reserveFilter       = $reserveDriver ? '' : 'AND se.is_reserve = 0';
$teamReserveFilter   = $reserveTeam   ? '' : 'AND se.is_reserve = 0';

// Bonus-SQL live berechnet (Pole + FL anhand Settings)
$bonusSql = buildBonusSql('re');

// ----------------------------------------------------------------
// Fahrerwertung
// ----------------------------------------------------------------
$driverStandings = [];
if ($sid) {
    $sql = "
        SELECT
            d.id, d.name, d.nationality,
            se.number, se.is_reserve,
            t.name  AS team_name,
            t.color AS team_color,
            t.id    AS team_id,
            COALESCE(SUM({$bonusSql}), 0)                                        AS total_pts,
            COUNT(CASE WHEN re.position = 1 THEN 1 END)                          AS wins,
            COUNT(CASE WHEN re.position <= 3 THEN 1 END)                         AS podiums,
            COUNT(CASE WHEN re.dnf = 0 AND re.dsq = 0
                            AND re.position IS NOT NULL THEN 1 END)              AS starts,
            MIN(CASE WHEN re.dnf = 0 THEN re.position END)                       AS best_pos
        FROM season_entries se
        JOIN  drivers d ON d.id  = se.driver_id
        LEFT JOIN teams t ON t.id = se.team_id
        LEFT JOIN result_entries re
              ON re.driver_id = d.id
             AND re.result_id IN (
                 SELECT r.id FROM results r
                 INNER JOIN races rc ON rc.id = r.race_id AND rc.season_id = :sid2
             )
        WHERE se.season_id = :sid
        {$reserveFilter}
        GROUP BY d.id, d.name, d.nationality, se.number, se.is_reserve, t.name, t.color, t.id
        ORDER BY total_pts DESC, wins DESC, podiums DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':sid' => $sid, ':sid2' => $sid]);
    $driverStandings = $stmt->fetchAll();
}

// ----------------------------------------------------------------
// Teamwertung
// ----------------------------------------------------------------
$teamStandings = [];
if ($sid) {
    $sql2 = "
        SELECT
            t.id, t.name, t.color,
            COALESCE(SUM({$bonusSql}), 0)  AS total_pts,
            COUNT(CASE WHEN re.position = 1 THEN 1 END)    AS wins,
            COUNT(CASE WHEN re.position <= 3 THEN 1 END)   AS podiums
        FROM teams t
        LEFT JOIN season_entries se
              ON se.team_id = t.id
             AND se.season_id = t.season_id
             {$teamReserveFilter}
        LEFT JOIN result_entries re
              ON re.driver_id = se.driver_id
             AND re.result_id IN (
                 SELECT r.id FROM results r
                 INNER JOIN races rc ON rc.id = r.race_id AND rc.season_id = :sid2
             )
        WHERE t.season_id = :sid
        GROUP BY t.id, t.name, t.color
        ORDER BY total_pts DESC
    ";
    $stmt2 = $db->prepare($sql2);
    $stmt2->execute([':sid' => $sid, ':sid2' => $sid]);
    $teamStandings = $stmt2->fetchAll();
}

$pageTitle = 'Wertung – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="mb-3">
    <div class="section-title">WM <span>Wertung</span></div>
    <div class="section-sub">
      <?php if($activeSeason): ?>
        <?= h($activeSeason['name']) ?><?= $activeSeason['year'] ? ' '.$activeSeason['year'] : '' ?>
        <?php if($activeSeason['game']): ?> · 🎮 <?= h($activeSeason['game']) ?><?php endif; ?>
      <?php else: ?>Keine aktive Saison<?php endif; ?>
    </div>
  </div>

  <?php if(!$activeSeason): ?>
    <div class="card"><div class="card-body text-muted">Keine aktive Saison gesetzt.</div></div>
  <?php else: ?>

  <!-- Sheet Tabs -->
  <div class="sheet-tabs">
    <div class="sheet-tab active" data-group="wertung" data-tab="drivers" onclick="sheetTab('wertung','drivers')">
      <span class="tab-dot"></span>🏎 Fahrerwertung
    </div>
    <div class="sheet-tab" data-group="wertung" data-tab="teams" onclick="sheetTab('wertung','teams')" style="--primary:var(--secondary)">
      <span class="tab-dot" style="background:var(--secondary)"></span>🏭 Teamwertung
    </div>
  </div>

  <!-- Fahrerwertung -->
  <div class="sheet-panel active" data-group="wertung" data-tab="drivers">
  <div class="sheet-panel-inner">
    <?php if($driverStandings): ?>
    <?php
    // Bonus-Info-Badge anzeigen
    $showFlBonus   = getSetting('bonus_points_fl',  '1') === '1';
    $showPoleBonus = getSetting('bonus_points_pole', '1') === '1';
    ?>
    <?php if($showFlBonus || $showPoleBonus): ?>
    <div class="flex gap-1 mb-2" style="font-size:.78rem;flex-wrap:wrap;align-items:center">
      <span class="text-muted">Bonus:</span>
      <?php if($showPoleBonus): ?><span class="badge badge-secondary">🏆 Pole +1<?= getSetting('pole_only_if_finished','0')==='1' ? ' (nur Ziel)' : '' ?></span><?php endif; ?>
      <?php if($showFlBonus): ?><span class="badge badge-secondary">⚡ FL +1<?= getSetting('fl_only_if_finished','1')==='1' ? ' (nur Ziel)' : '' ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="overflow-x">
    <table class="data-table">
      <thead><tr>
        <th>Pos</th><th>Fahrer</th><th>Team</th>
        <th>Punkte</th><th>Siege</th><th>Podien</th><th>Starts</th><th>Beste</th>
      </tr></thead>
      <tbody>
        <?php foreach($driverStandings as $i => $d): ?>
        <tr>
          <td class="pos-col <?= $i===0?'pos-1':($i===1?'pos-2':($i===2?'pos-3':'')) ?>"><?= $i+1 ?></td>
          <td>
            <a href="<?= SITE_URL ?>/driver.php?id=<?= $d['id'] ?>" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:var(--text)">
              <div class="driver-avatar"><?= h(mb_substr($d['name'],0,2)) ?></div>
              <div>
                <div class="font-display font-bold">
                  <?= h($d['name']) ?>
                  <?php if($d['is_reserve']): ?><span class="badge badge-info" style="font-size:.6rem;vertical-align:middle;margin-left:4px">Reserve</span><?php endif; ?>
                </div>
                <?php if($d['number'] || $d['nationality']): ?>
                  <div class="text-muted" style="font-size:.72rem">
                    <?= $d['number'] ? '#'.(int)$d['number'] : '' ?>
                    <?= $d['nationality'] ? ($d['number']?' · ':'').h($d['nationality']) : '' ?>
                  </div>
                <?php endif; ?>
              </div>
            </a>
          </td>
          <td>
            <?php if($d['team_name']): ?>
              <span class="team-dot" style="background:<?= h($d['team_color']) ?>"></span><?= h($d['team_name']) ?>
            <?php else: ?>–<?php endif; ?>
          </td>
          <td class="pts-col"><?= number_format((float)$d['total_pts'], 1) ?></td>
          <td><?= (int)$d['wins'] ?></td>
          <td><?= (int)$d['podiums'] ?></td>
          <td><?= (int)$d['starts'] ?></td>
          <td><?= $d['best_pos'] ? 'P'.(int)$d['best_pos'] : '–' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?>
      <div class="text-muted mt-2">Noch keine Ergebnisse in dieser Saison.</div>
    <?php endif; ?>
  </div></div><!-- /sheet-panel drivers -->

  <!-- Teamwertung -->
  <div class="sheet-panel" data-group="wertung" data-tab="teams">
  <div class="sheet-panel-inner">
    <?php if($teamStandings): ?>
    <div class="overflow-x">
    <table class="data-table">
      <thead><tr><th>Pos</th><th>Team</th><th>Punkte</th><th>Siege</th><th>Podien</th></tr></thead>
      <tbody>
        <?php foreach($teamStandings as $i => $t): ?>
        <tr>
          <td class="pos-col <?= $i===0?'pos-1':($i===1?'pos-2':($i===2?'pos-3':'')) ?>"><?= $i+1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:14px;height:14px;border-radius:50%;background:<?= h($t['color']) ?>;flex-shrink:0"></div>
              <strong><?= h($t['name']) ?></strong>
            </div>
          </td>
          <td class="pts-col"><?= number_format((float)$t['total_pts'], 1) ?></td>
          <td><?= (int)$t['wins'] ?></td>
          <td><?= (int)$t['podiums'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?>
      <div class="text-muted mt-2">Noch keine Ergebnisse in dieser Saison.</div>
    <?php endif; ?>
  </div></div><!-- /sheet-panel teams -->

  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
