<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = 'results';
$db = getDB();

// --- Single result detail view ---
$resultId = (int)($_GET['id'] ?? 0);
if ($resultId) {
    $stmt = $db->prepare("
        SELECT r.*, rc.track_name, rc.location, rc.race_date, rc.round,
               s.name AS season_name, s.id AS season_id
        FROM results r
        JOIN races rc ON rc.id = r.race_id
        JOIN seasons s ON s.id = rc.season_id
        WHERE r.id = ?
    ");
    $stmt->execute([$resultId]);
    $result = $stmt->fetch();
    if (!$result) { header('Location: ' . SITE_URL . '/results.php'); exit; }

    // Rennergebnis-Einträge
    $bonusSql = buildBonusSql('re');
    $stmt2 = $db->prepare("
        SELECT re.*, d.name AS driver_name, t.name AS team_name, t.color,
               se.number AS driver_number,
               ({$bonusSql}) AS calc_pts
        FROM result_entries re
        LEFT JOIN drivers d ON d.id = re.driver_id
        LEFT JOIN teams t ON t.id = re.team_id
        LEFT JOIN races rc ON rc.id = (SELECT race_id FROM results WHERE id = re.result_id)
        LEFT JOIN season_entries se ON se.driver_id = re.driver_id AND se.season_id = rc.season_id
        WHERE re.result_id = ?
        ORDER BY re.position ASC
    ");
    $stmt2->execute([$resultId]);
    $entries = $stmt2->fetchAll();

    // Qualifying-Ergebnis für dieses Rennen
    $stmt3 = $db->prepare("
        SELECT qr.*, d.name AS driver_name, t.name AS team_name, t.color,
               se.number AS driver_number
        FROM qualifying_results qr
        LEFT JOIN drivers d ON d.id = qr.driver_id
        LEFT JOIN teams t ON t.id = qr.team_id
        LEFT JOIN season_entries se ON se.driver_id = qr.driver_id AND se.season_id = (
            SELECT season_id FROM races WHERE id = qr.race_id
        )
        WHERE qr.race_id = ?
        ORDER BY qr.position ASC
    ");
    $stmt3->execute([$result['race_id']]);
    $qualiEntries = $stmt3->fetchAll();

    $hasQuali = count($qualiEntries) > 0;

    // Strafen für dieses Rennen laden
    $penStmt = $db->prepare("
        SELECT p.*, d.name AS driver_name
        FROM penalties p
        LEFT JOIN drivers d ON d.id = p.driver_id
        WHERE p.result_id = ? AND p.applied = 1
        ORDER BY p.type ASC, p.created_at ASC
    ");
    $penStmt->execute([$resultId]);
    $penaltiesList = $penStmt->fetchAll();
    // Strafen pro Fahrer gruppieren für schnellen Zugriff
    $penByDriver = [];
    foreach ($penaltiesList as $pen) {
        if ($pen['driver_id']) $penByDriver[$pen['driver_id']][] = $pen;
    }

    $pageTitle = $result['track_name'] . ' Ergebnis – ' . getSetting('league_name');
    require_once __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <a href="<?= SITE_URL ?>/results.php" class="btn btn-secondary btn-sm mb-3">← Alle Ergebnisse</a>

  <!-- Header -->
  <div class="flex flex-center justify-between mb-3" style="flex-wrap:wrap;gap:12px">
    <div>
      <div class="text-muted" style="font-size:.8rem;margin-bottom:4px">
        Runde <?= (int)$result['round'] ?> · <?= h($result['season_name']) ?>
      </div>
      <div class="section-title"><?= h($result['track_name']) ?> <span>Ergebnis</span></div>
      <div class="section-sub">
        <?= $result['race_date'] ? date('d.m.Y', strtotime($result['race_date'])) : '' ?>
        <?= $result['location'] ? ' · '.h($result['location']) : '' ?>
      </div>
    </div>
    <?php if($result['game']): ?>
      <span class="badge badge-info" style="font-size:.85rem;padding:6px 14px"><?= h($result['game']) ?></span>
    <?php endif; ?>
  </div>

  <!-- Sheet Tabs -->
  <div class="sheet-tabs">
    <div class="sheet-tab active" data-group="result" data-tab="race" onclick="sheetTab('result','race')">
      <span class="tab-dot"></span>🏁 Rennergebnis
      <span class="badge badge-muted" style="font-size:.65rem;margin-left:4px"><?= count($entries) ?></span>
    </div>
    <?php if($hasQuali): ?>
    <div class="sheet-tab" data-group="result" data-tab="quali" onclick="sheetTab('result','quali')" style="--primary:var(--tertiary)">
      <span class="tab-dot" style="background:var(--tertiary)"></span>⏱ Qualifying
      <span class="badge badge-muted" style="font-size:.65rem;margin-left:4px"><?= count($qualiEntries) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- Rennergebnis Panel -->
  <div class="sheet-panel active" data-group="result" data-tab="race">
  <div class="sheet-panel-inner">
    <?php if($entries): ?>
    <div class="overflow-x">
    <table class="data-table">
      <thead><tr>
        <th>Pos</th><th>Fahrer</th><th>Team</th><th>Runden</th>
        <th>Zeit / Abstand</th><th>Schnellste Runde</th><th>Punkte</th>
      </tr></thead>
      <tbody>
        <?php foreach($entries as $e): ?>
        <tr <?= $e['dnf'] ? 'style="opacity:.55"' : '' ?> <?= $e['dsq'] ? 'style="opacity:.4;text-decoration:line-through"' : '' ?>>
          <td class="pos-col <?= $e['position']==1?'pos-1':($e['position']==2?'pos-2':($e['position']==3?'pos-3':'')) ?>">
            <?= $e['dnf'] ? 'DNF' : ($e['dsq'] ? 'DSQ' : (int)$e['position']) ?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="driver-avatar"><?= h(mb_substr($e['driver_name']??$e['driver_name_raw'],0,2)) ?></div>
              <div>
                <div class="font-bold"><?= h($e['driver_name'] ?? $e['driver_name_raw']) ?></div>
                <?php if($e['driver_number']): ?>
                  <div class="text-muted" style="font-size:.72rem">#<?= (int)$e['driver_number'] ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <?php if($e['team_name']): ?>
              <span class="team-dot" style="background:<?= h($e['color']??'#666') ?>"></span><?= h($e['team_name']) ?>
            <?php else: ?>–<?php endif; ?>
          </td>
          <td><?= $e['laps'] ?: '–' ?></td>
          <td class="gap-col">
            <?= $e['position'] == 1 ? h($e['total_time'] ?: '–') : h($e['gap'] ?: '+–') ?>
          </td>
          <td style="font-family:monospace;font-size:.84rem">
            <?php if($e['fastest_lap']): ?>
              <?= h($e['fastest_lap']) ?>
              <?php if($e['is_fastest_lap']): ?><span class="fl-badge">FL</span><?php endif; ?>
            <?php else: ?>–<?php endif; ?>
          </td>
          <td class="pts-col">
            <?= number_format((float)$e['calc_pts'], 1) ?>
            <?php if(!empty($penByDriver[$e['driver_id']])): ?>
              <?php foreach($penByDriver[$e['driver_id']] as $pen): ?>
                <?php if($pen['type']==='points'): ?>
                  <span class="fl-badge" style="background:rgba(232,51,58,.2);color:#ff8080" title="<?= h($pen['reason']) ?>">-<?= (float)$pen['amount'] ?></span>
                <?php elseif($pen['type']==='dsq'): ?>
                  <span class="fl-badge" style="background:rgba(200,50,50,.2);color:#ff8080" title="<?= h($pen['reason']) ?>">DSQ</span>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if($penaltiesList): ?>
    <div style="margin-top:16px;padding:12px 16px;background:var(--bg3);border-radius:var(--radius);border-left:3px solid var(--secondary)">
      <div style="font-family:var(--font-display);font-size:.75rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text2);margin-bottom:8px">⚠️ Strafen</div>
      <?php foreach($penaltiesList as $pen): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:5px 0;border-bottom:1px solid var(--border);font-size:.84rem">
        <span class="badge <?= ['points'=>'badge-primary','dsq'=>'badge-muted','time'=>'badge-secondary','grid'=>'badge-info','warning'=>'badge-muted'][$pen['type']]??'badge-muted' ?>">
          <?= ['time'=>'Zeitstrafe','points'=>'Punkteabzug','grid'=>'Startplatz','warning'=>'Verwarnung','dsq'=>'DSQ'][$pen['type']]??$pen['type'] ?>
          <?= $pen['amount']>0?' ('.$pen['amount'].')':'' ?>
        </span>
        <strong><?= h($pen['driver_name']??$pen['driver_name_raw']??'–') ?></strong>
        <span class="text-muted">– <?= h($pen['reason']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php else: ?><div class="text-muted">Keine Rennergebnisse.</div><?php endif; ?>
  </div></div><!-- /race panel -->

  <!-- Qualifying Panel -->
  <?php if($hasQuali): ?>
  <div class="sheet-panel" data-group="result" data-tab="quali">
  <div class="sheet-panel-inner">
    <div class="overflow-x">
    <table class="data-table">
      <thead><tr>
        <th>Pos</th><th>Fahrer</th><th>Team</th><th>Bestzeit</th><th>Abstand</th>
      </tr></thead>
      <tbody>
        <?php foreach($qualiEntries as $q): ?>
        <tr>
          <td class="pos-col <?= $q['position']==1?'pos-1':($q['position']==2?'pos-2':($q['position']==3?'pos-3':'')) ?>">
            <?= $q['position']==1 ? '🏆 P1' : 'P'.(int)$q['position'] ?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="driver-avatar"><?= h(mb_substr($q['driver_name']??$q['driver_name_raw'],0,2)) ?></div>
              <div>
                <div class="font-bold"><?= h($q['driver_name'] ?? $q['driver_name_raw']) ?></div>
                <?php if($q['driver_number']): ?>
                  <div class="text-muted" style="font-size:.72rem">#<?= (int)$q['driver_number'] ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <?php if($q['team_name']): ?>
              <span class="team-dot" style="background:<?= h($q['color']??'#666') ?>"></span><?= h($q['team_name']) ?>
            <?php else: ?>–<?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:.88rem;color:var(--secondary)"><?= h($q['lap_time'] ?: '–') ?></td>
          <td class="gap-col"><?= h($q['gap'] ?: ($q['position']==1?'Pole':'–')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div></div><!-- /quali panel -->
  <?php endif; ?>

</div>
<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// --- Results list with season dropdown ---
$seasons = $db->query("SELECT * FROM seasons ORDER BY year DESC, id DESC")->fetchAll();
$activeSeason = array_values(array_filter($seasons, fn($s) => $s['is_active']))[0] ?? ($seasons[0] ?? null);
$filterSeasonId = (int)($_GET['season'] ?? ($activeSeason['id'] ?? 0));
$selectedSeason = null;
foreach ($seasons as $s) { if ($s['id'] == $filterSeasonId) { $selectedSeason = $s; break; } }

$whereClause = $filterSeasonId ? "AND s.id = $filterSeasonId" : "";
$results = $db->query("
    SELECT r.id, r.game, r.imported_at,
           rc.track_name, rc.race_date, rc.round, rc.location,
           s.name AS season_name, s.id AS season_id
    FROM results r
    JOIN races rc ON rc.id = r.race_id
    JOIN seasons s ON s.id = rc.season_id
    WHERE 1=1 $whereClause
    ORDER BY rc.race_date DESC, r.imported_at DESC
")->fetchAll();

$pageTitle = 'Ergebnisse – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="flex flex-center justify-between mb-3" style="flex-wrap:wrap;gap:12px">
    <div>
      <div class="section-title">Renn<span>ergebnisse</span></div>
      <div class="section-sub">
        <?= $selectedSeason ? h($selectedSeason['name']).' '.h($selectedSeason['year']??'') : 'Alle Saisons' ?>
        – <?= count($results) ?> Ergebnis<?= count($results) !== 1 ? 'se' : '' ?>
      </div>
    </div>
    <?php if(count($seasons) > 0): ?>
    <form method="get" style="display:flex;align-items:center;gap:10px">
      <label style="font-family:var(--font-display);font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text2)">Saison</label>
      <select name="season" onchange="this.form.submit()" style="background:var(--bg2);color:var(--text);border:1px solid var(--border);border-radius:4px;padding:8px 14px;font-family:var(--font-body);font-size:.9rem;outline:none;cursor:pointer">
        <option value="">Alle Saisons</option>
        <?php foreach($seasons as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$filterSeasonId ? 'selected' : '' ?>>
            <?= h($s['name']) ?><?= $s['year'] ? ' '.$s['year'] : '' ?><?= $s['is_active'] ? ' ★' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <?php if($results): ?>
    <?php
    $currentSeasonId = null;
    foreach($results as $r):
      if(!$filterSeasonId && $r['season_id'] !== $currentSeasonId):
        $currentSeasonId = $r['season_id'];
    ?>
      <div style="font-family:var(--font-display);font-size:.78rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text2);padding:16px 0 8px;border-bottom:1px solid var(--border);margin-bottom:8px">
        <?= h($r['season_name']) ?>
      </div>
    <?php endif; ?>
    <div class="race-item" style="cursor:pointer" onclick="location.href='<?= SITE_URL ?>/results.php?id=<?= $r['id'] ?>'">
      <div class="race-round">Runde <?= (int)$r['round'] ?></div>
      <div class="flex-1">
        <div class="race-track-name"><?= h($r['track_name']) ?></div>
        <div class="race-track-loc">
          <?= $r['location'] ? h($r['location']).' · ' : '' ?>
          <?= $r['race_date'] ? date('d.m.Y', strtotime($r['race_date'])) : '–' ?>
          <?php if($r['game']): ?> · <span class="badge badge-info" style="font-size:.68rem"><?= h($r['game']) ?></span><?php endif; ?>
        </div>
      </div>
      <a href="<?= SITE_URL ?>/results.php?id=<?= $r['id'] ?>" class="race-status done">Ergebnis ansehen →</a>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="card">
      <div class="card-body text-muted">
        Keine Ergebnisse für<?= $selectedSeason ? ' '.h($selectedSeason['name']) : ' diese Auswahl' ?> gefunden.
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
