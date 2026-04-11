<?php
// admin/lineup.php – Saison-Lineup: Fahrer ↔ Team Zuordnung je Saison
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Saison-Lineup'; $adminPage = 'lineup';
$db = getDB();

// Aktive oder gewählte Saison
$seasons = $db->query("SELECT * FROM seasons ORDER BY year DESC, id DESC")->fetchAll();
$activeSeason = array_values(array_filter($seasons, fn($s) => $s['is_active']))[0] ?? ($seasons[0] ?? null);
$selectedSeasonId = (int)($_GET['season'] ?? ($activeSeason['id'] ?? 0));
$selectedSeason = null;
foreach ($seasons as $s) { if ($s['id'] == $selectedSeasonId) { $selectedSeason = $s; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin(); verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Einzelnen Eintrag speichern / aktualisieren
    if ($action === 'save_entry') {
        $seasonId  = (int)$_POST['season_id'];
        $driverId  = (int)$_POST['driver_id'];
        $teamId    = (int)$_POST['team_id'] ?: null;
        $number    = (int)$_POST['number'] ?: null;
        $isReserve = isset($_POST['is_reserve']) ? 1 : 0;

        // Stammfahrer-Limit: max 2 pro Team
        if ($teamId && !$isReserve) {
            $count = $db->prepare("SELECT COUNT(*) FROM season_entries WHERE season_id=? AND team_id=? AND is_reserve=0 AND driver_id!=?");
            $count->execute([$seasonId, $teamId, $driverId]);
            if ($count->fetchColumn() >= 2) {
                $_SESSION['flash'] = ['type'=>'warning','msg'=>'⚠️ Dieses Team hat bereits 2 Stammfahrer! Als Reserve eingetragen.'];
                $isReserve = 1;
            }
        }

        // UPSERT: existiert bereits ein Eintrag für diesen Fahrer in dieser Saison?
        $existing = $db->prepare("SELECT id FROM season_entries WHERE season_id=? AND driver_id=?");
        $existing->execute([$seasonId, $driverId]);
        $existingEntry = $existing->fetch();

        if ($existingEntry) {
            $db->prepare("UPDATE season_entries SET team_id=?, number=?, is_reserve=? WHERE id=?")
               ->execute([$teamId, $number, $isReserve, $existingEntry['id']]);
            $msg = '✅ Lineup-Eintrag aktualisiert!';
        } else {
            $db->prepare("INSERT INTO season_entries (season_id, driver_id, team_id, number, is_reserve) VALUES (?,?,?,?,?)")
               ->execute([$seasonId, $driverId, $teamId, $number, $isReserve]);
            $msg = '✅ Fahrer zum Lineup hinzugefügt!';
        }
        auditLog('lineup_save', 'season_entries', $selectedSeasonId, "Driver $driverId → Team $teamId");
        $_SESSION['flash'] = ['type'=>'success','msg'=>$msg];
        header('Location: '.SITE_URL.'/admin/lineup.php?season='.$seasonId); exit;
    }

    // Eintrag entfernen (nur aus dieser Saison, Fahrer bleibt global erhalten)
    if ($action === 'remove_entry') {
        $db->prepare("DELETE FROM season_entries WHERE id=?")->execute([(int)$_POST['entry_id']]);
        auditLog('lineup_remove', 'season_entries', (int)$_POST['entry_id']);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'🗑 Fahrer aus Lineup entfernt (Stammdaten bleiben erhalten).'];
        header('Location: '.SITE_URL.'/admin/lineup.php?season='.$selectedSeasonId); exit;
    }

    // Team wechseln für bestehenden Eintrag
    if ($action === 'change_team') {
        $entryId  = (int)$_POST['entry_id'];
        $newTeamId = (int)$_POST['new_team_id'] ?: null;
        $number    = (int)$_POST['number'] ?: null;
        $isReserve = isset($_POST['is_reserve']) ? 1 : 0;
        $db->prepare("UPDATE season_entries SET team_id=?, number=?, is_reserve=? WHERE id=?")
           ->execute([$newTeamId, $number, $isReserve, $entryId]);
        auditLog('lineup_change_team', 'season_entries', $entryId);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Zuordnung geändert!'];
        header('Location: '.SITE_URL.'/admin/lineup.php?season='.$selectedSeasonId); exit;
    }

    // Vorherige Saison kopieren
    if ($action === 'copy_from_season') {
        $fromSeasonId = (int)$_POST['from_season_id'];
        $toSeasonId   = (int)$_POST['to_season_id'];

        // Teams der Quellsaison holen
        $oldTeams = $db->prepare("SELECT * FROM teams WHERE season_id=?");
        $oldTeams->execute([$fromSeasonId]);
        $teamMap = []; // old_id => new_id
        foreach ($oldTeams->fetchAll() as $ot) {
            // Team in Zielsaison anlegen (falls noch nicht vorhanden)
            $exists = $db->prepare("SELECT id FROM teams WHERE season_id=? AND name=?");
            $exists->execute([$toSeasonId, $ot['name']]);
            $existingTeam = $exists->fetch();
            if ($existingTeam) {
                $teamMap[$ot['id']] = $existingTeam['id'];
            } else {
                $db->prepare("INSERT INTO teams (season_id,name,abbreviation,color,logo_path,car,nationality) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$toSeasonId,$ot['name'],$ot['abbreviation'],$ot['color'],$ot['logo_path'],$ot['car'],$ot['nationality']]);
                $newTeamId = (int)$db->lastInsertId();
                $teamMap[$ot['id']] = $newTeamId;
            }
        }

        // Lineup-Einträge kopieren
        $oldEntries = $db->prepare("SELECT * FROM season_entries WHERE season_id=?");
        $oldEntries->execute([$fromSeasonId]);
        $copied = 0;
        foreach ($oldEntries->fetchAll() as $oe) {
            // Prüfen ob Fahrer bereits in Zielsaison eingetragen
            $alreadyIn = $db->prepare("SELECT COUNT(*) FROM season_entries WHERE season_id=? AND driver_id=?");
            $alreadyIn->execute([$toSeasonId, $oe['driver_id']]);
            if ($alreadyIn->fetchColumn() == 0) {
                $newTeamId = $teamMap[$oe['team_id']] ?? null;
                $db->prepare("INSERT INTO season_entries (season_id,driver_id,team_id,number,is_reserve) VALUES (?,?,?,?,?)")
                   ->execute([$toSeasonId, $oe['driver_id'], $newTeamId, $oe['number'], $oe['is_reserve']]);
                $copied++;
            }
        }
        auditLog('lineup_copy', 'season_entries', $toSeasonId, "Copied from season $fromSeasonId: $copied entries");
        $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ $copied Einträge aus der Vorjahressaison übernommen (Teams wurden neu angelegt)!"];
        header('Location: '.SITE_URL.'/admin/lineup.php?season='.$toSeasonId); exit;
    }
}

// Aktuelle Lineup-Einträge der gewählten Saison
$lineupEntries = [];
if ($selectedSeasonId) {
    $stmt = $db->prepare("
        SELECT se.id AS entry_id, se.number, se.is_reserve,
               d.id AS driver_id, d.name AS driver_name, d.nationality, d.photo_path,
               t.id AS team_id, t.name AS team_name, t.color AS team_color
        FROM season_entries se
        JOIN drivers d ON d.id = se.driver_id
        LEFT JOIN teams t ON t.id = se.team_id
        WHERE se.season_id = ?
        ORDER BY t.name ASC, se.is_reserve ASC, se.number ASC
    ");
    $stmt->execute([$selectedSeasonId]);
    $lineupEntries = $stmt->fetchAll();
}

// Teams der aktuell gewählten Saison
$teamsInSeason = [];
if ($selectedSeasonId) {
    $stmt = $db->prepare("SELECT * FROM teams WHERE season_id=? ORDER BY name");
    $stmt->execute([$selectedSeasonId]);
    $teamsInSeason = $stmt->fetchAll();
}

// Alle globalen Fahrer
$allDrivers = $db->query("SELECT * FROM drivers ORDER BY name")->fetchAll();

// Fahrer die noch NICHT in der gewählten Saison sind
$assignedDriverIds = array_column($lineupEntries, 'driver_id');
$unassignedDrivers = array_filter($allDrivers, fn($d) => !in_array($d['id'], $assignedDriverIds));

// Andere Saisons zum Kopieren
$otherSeasons = array_filter($seasons, fn($s) => $s['id'] != $selectedSeasonId);

// Lineup nach Teams gruppieren
$byTeam = [];
foreach ($lineupEntries as $e) {
    $key = $e['team_id'] ?? 0;
    $byTeam[$key][] = $e;
}

require_once __DIR__ . '/includes/layout.php';
?>

<div class="admin-page-title">Saison <span style="color:var(--primary)">Lineup</span></div>
<div class="admin-page-sub">Fahrer–Team Zuordnung je Saison. Fahrer bleiben global erhalten – nur die Zuordnung ist saisonspezifisch.</div>

<!-- Season Selector -->
<div class="flex flex-center gap-2 mb-4" style="flex-wrap:wrap">
  <?php foreach($seasons as $s): ?>
    <a href="?season=<?= $s['id'] ?>"
       class="btn btn-sm <?= $s['id']==$selectedSeasonId ? 'btn-primary' : 'btn-secondary' ?>">
      <?= h($s['name']) ?> <?= h($s['year']??'') ?>
      <?php if($s['is_active']): ?> ★<?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if(!$selectedSeason): ?>
  <div class="notice notice-warning">⚠️ Keine Saison ausgewählt. Bitte zuerst eine <a href="<?= SITE_URL ?>/admin/seasons.php">Saison anlegen</a>.</div>
<?php else: ?>

<div class="grid-2" style="gap:20px;align-items:start">

  <!-- Linke Seite: Aktuelles Lineup -->
  <div>
    <div class="card mb-3">
      <div class="card-header">
        <h3>📋 Lineup: <?= h($selectedSeason['name']) ?> <?= h($selectedSeason['year']??'') ?></h3>
        <span class="badge <?= $selectedSeason['is_active'] ? 'badge-primary' : 'badge-muted' ?>">
          <?= count($lineupEntries) ?> Fahrer
        </span>
      </div>

      <?php if($lineupEntries): ?>
        <?php foreach($byTeam as $teamId => $entries): ?>
          <?php $team = $teamId ? array_values(array_filter($teamsInSeason, fn($t)=>$t['id']==$teamId))[0]??null : null; ?>
          <div style="border-bottom:2px solid <?= $team ? h($team['color']) : 'var(--border)' ?>;">
            <div style="padding:8px 16px;background:<?= $team ? h($team['color']).'18' : 'var(--bg3)' ?>;display:flex;align-items:center;gap:8px">
              <?php if($team): ?>
                <div style="width:12px;height:12px;border-radius:50%;background:<?= h($team['color']) ?>"></div>
                <strong style="font-family:var(--font-display);letter-spacing:.04em"><?= h($team['name']) ?></strong>
                <?php if($team['car']): ?><span class="text-muted" style="font-size:.78rem">· <?= h($team['car']) ?></span><?php endif; ?>
              <?php else: ?>
                <span class="text-muted" style="font-size:.82rem">⚠️ Kein Team zugeordnet</span>
              <?php endif; ?>
            </div>
            <?php foreach($entries as $e): ?>
            <div class="flex flex-center gap-2" style="padding:9px 16px;border-bottom:1px solid var(--border)20">
              <?php if($e['photo_path']): ?>
                <img src="<?= h($e['photo_path']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0"/>
              <?php else: ?>
                <div class="driver-avatar" style="width:32px;height:32px;font-size:.68rem;flex-shrink:0"><?= h(mb_substr($e['driver_name'],0,2)) ?></div>
              <?php endif; ?>
              <div class="flex-1">
                <div style="font-size:.92rem;font-weight:600">
                  <?php if($e['number']): ?><span style="color:var(--primary);font-family:var(--font-display);font-weight:900;margin-right:6px">#<?= (int)$e['number'] ?></span><?php endif; ?>
                  <?= h($e['driver_name']) ?>
                  <?php if($e['is_reserve']): ?><span class="badge badge-info" style="font-size:.6rem;margin-left:6px">Reserve</span><?php endif; ?>
                </div>
                <?php if($e['nationality']): ?><div class="text-muted" style="font-size:.73rem"><?= h($e['nationality']) ?></div><?php endif; ?>
              </div>
              <!-- Quick-Edit inline -->
              <button class="btn btn-secondary btn-sm"
                      onclick="toggleEdit(<?= $e['entry_id'] ?>)"
                      title="Bearbeiten">✏️</button>
              <form method="post" style="display:inline" onsubmit="return confirm('Fahrer aus diesem Saison-Lineup entfernen?\n(Stammdaten bleiben erhalten)')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="remove_entry"/>
                <input type="hidden" name="entry_id" value="<?= $e['entry_id'] ?>"/>
                <button class="btn btn-danger btn-sm" title="Aus Lineup entfernen">✕</button>
              </form>
            </div>
            <!-- Inline Edit Form -->
            <div id="edit-<?= $e['entry_id'] ?>" style="display:none;padding:12px 16px;background:var(--bg3);border-bottom:1px solid var(--border)">
              <form method="post" class="flex flex-center gap-2" style="flex-wrap:wrap">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="change_team"/>
                <input type="hidden" name="entry_id" value="<?= $e['entry_id'] ?>"/>
                <div>
                  <label style="font-size:.7rem;color:var(--text2);display:block;margin-bottom:3px">TEAM</label>
                  <select name="new_team_id" class="form-control form-control-sm" style="min-width:150px">
                    <option value="">– Kein Team –</option>
                    <?php foreach($teamsInSeason as $t): ?>
                      <option value="<?= $t['id'] ?>" <?= $t['id']==$e['team_id']?'selected':'' ?>><?= h($t['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label style="font-size:.7rem;color:var(--text2);display:block;margin-bottom:3px">#NR</label>
                  <input type="number" name="number" class="form-control form-control-sm" value="<?= $e['number']??(int)$e['number'] ?>" style="width:70px" min="1" max="999"/>
                </div>
                <div style="display:flex;align-items:flex-end;padding-bottom:2px">
                  <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;cursor:pointer">
                    <input type="checkbox" name="is_reserve" <?= $e['is_reserve']?'checked':'' ?>/> Reserve
                  </label>
                </div>
                <div style="display:flex;align-items:flex-end;gap:6px">
                  <button type="submit" class="btn btn-primary btn-sm">💾</button>
                  <button type="button" class="btn btn-secondary btn-sm" onclick="toggleEdit(<?= $e['entry_id'] ?>)">✕</button>
                </div>
              </form>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div style="padding:20px" class="text-muted text-center">
          Noch keine Fahrer in diesem Lineup.<br/>
          <a href="#add-driver" class="btn btn-primary btn-sm mt-2" onclick="document.getElementById('add-section').scrollIntoView({behavior:'smooth'})">Fahrer hinzufügen →</a>
        </div>
      <?php endif; ?>
    </div>

    <!-- Saison kopieren -->
    <?php if(count($otherSeasons) > 0 && count($lineupEntries) == 0): ?>
    <div class="card">
      <div class="card-header"><h3>📋 Aus anderer Saison übernehmen</h3></div>
      <div class="card-body">
        <div class="notice notice-info mb-3">Teams und Fahrer werden aus der gewählten Saison in diese Saison kopiert. Bereits zugeordnete Fahrer werden übersprungen.</div>
        <form method="post" class="flex flex-center gap-2">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="copy_from_season"/>
          <input type="hidden" name="to_season_id" value="<?= $selectedSeasonId ?>"/>
          <select name="from_season_id" class="form-control" required>
            <option value="">– Saison wählen –</option>
            <?php foreach($otherSeasons as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= h($s['year']??'') ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-secondary" onclick="return confirm('Lineup aus gewählter Saison übernehmen?')">📋 Übernehmen</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Rechte Seite: Fahrer hinzufügen -->
  <div id="add-section">
    <div class="card mb-3">
      <div class="card-header"><h3 id="add-driver">➕ Fahrer zum Lineup hinzufügen</h3></div>
      <div class="card-body">
        <?php if(!$teamsInSeason): ?>
          <div class="notice notice-warning">⚠️ Zuerst <a href="<?= SITE_URL ?>/admin/teams.php">Teams für diese Saison anlegen</a>!</div>
        <?php elseif(!$allDrivers): ?>
          <div class="notice notice-warning">⚠️ Zuerst <a href="<?= SITE_URL ?>/admin/drivers.php">Fahrer anlegen</a>!</div>
        <?php else: ?>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save_entry"/>
          <input type="hidden" name="season_id" value="<?= $selectedSeasonId ?>"/>

          <div class="form-group">
            <label>Fahrer *</label>
            <select name="driver_id" class="form-control" required>
              <option value="">– Fahrer wählen –</option>
              <?php
              // Unzugeordnete zuerst
              $unassigned = array_filter($allDrivers, fn($d) => !in_array($d['id'], $assignedDriverIds));
              $assigned   = array_filter($allDrivers, fn($d) =>  in_array($d['id'], $assignedDriverIds));
              if($unassigned): ?>
                <optgroup label="── Noch nicht in dieser Saison ──">
                  <?php foreach($unassigned as $d): ?>
                    <option value="<?= $d['id'] ?>"><?= h($d['name']) ?><?= $d['nationality'] ? ' ['.$d['nationality'].']' : '' ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if($assigned): ?>
                <optgroup label="── Bereits eingetragen (Update) ──">
                  <?php foreach($assigned as $d): ?>
                    <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Team</label>
            <select name="team_id" class="form-control">
              <option value="">– Kein Team –</option>
              <?php foreach($teamsInSeason as $t): ?>
                <option value="<?= $t['id'] ?>" style="color:<?= h($t['color']) ?>"><?= h($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-row cols-2">
            <div class="form-group">
              <label>Startnummer</label>
              <input type="number" name="number" class="form-control" min="1" max="999" placeholder="z.B. 7"/>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:.9rem">
                <input type="checkbox" name="is_reserve"/>
                <span>Reservefahrer</span>
              </label>
            </div>
          </div>

          <button type="submit" class="btn btn-primary w-full">➕ Zum Lineup hinzufügen</button>
        </form>
        <?php endif; ?>
      </div>
      <div class="card-footer">
        <div class="text-muted" style="font-size:.8rem">
          Fahrer nicht in der Liste? → <a href="<?= SITE_URL ?>/admin/drivers.php" style="color:var(--primary)">Fahrer anlegen</a> &nbsp;|&nbsp;
          Team fehlt? → <a href="<?= SITE_URL ?>/admin/teams.php" style="color:var(--primary)">Team anlegen</a>
        </div>
      </div>
    </div>

    <!-- Übersicht: Belegung -->
    <?php if($teamsInSeason): ?>
    <div class="card">
      <div class="card-header"><h3>📊 Team-Belegung</h3></div>
      <div class="card-body" style="padding:0">
        <?php foreach($teamsInSeason as $t):
          $starters = array_filter($lineupEntries, fn($e) => $e['team_id']==$t['id'] && !$e['is_reserve']);
          $reserves = array_filter($lineupEntries, fn($e) => $e['team_id']==$t['id'] &&  $e['is_reserve']);
          $starterCount = count($starters);
        ?>
        <div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px">
          <div style="width:10px;height:10px;border-radius:50%;background:<?= h($t['color']) ?>;flex-shrink:0"></div>
          <div class="flex-1">
            <div style="font-weight:600;font-size:.9rem"><?= h($t['name']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= $starterCount ?>/2 Stammfahrer
              <?= count($reserves) > 0 ? '· '.count($reserves).' Reserve' : '' ?>
            </div>
          </div>
          <div style="display:flex;gap:4px">
            <?php for($i=0;$i<2;$i++): ?>
              <div style="width:14px;height:14px;border-radius:50%;border:2px solid <?= h($t['color']) ?>;background:<?= $i<$starterCount ? h($t['color']) : 'transparent' ?>"></div>
            <?php endfor; ?>
          </div>
          <?php if($starterCount < 2): ?>
            <span class="badge badge-warning" style="font-size:.65rem;background:rgba(245,166,35,.15);color:var(--secondary)">
              <?= 2-$starterCount ?> frei
            </span>
          <?php else: ?>
            <span class="badge badge-success" style="font-size:.65rem">✓ Voll</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; // selectedSeason ?>

<script>
function toggleEdit(id) {
    const el = document.getElementById('edit-' + id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
