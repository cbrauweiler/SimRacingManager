<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Strafen'; $adminPage = 'penalties';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin'); verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $resultId = (int)$_POST['result_id'];
        $driverId = (int)$_POST['driver_id'] ?: null;
        $dnRaw    = trim($_POST['driver_name_raw'] ?? '');
        $type     = in_array($_POST['type'],['time','points','grid','warning','dsq']) ? $_POST['type'] : 'warning';
        $amount   = (float)($_POST['amount'] ?? 0);
        $reason   = trim($_POST['reason'] ?? '');

        // Strafe in penalties-Tabelle speichern
        $db->prepare("INSERT INTO penalties (result_id,driver_id,driver_name_raw,type,amount,reason,applied) VALUES (?,?,?,?,?,?,1)")
           ->execute([$resultId, $driverId, $dnRaw, $type, $amount, $reason]);
        $penId = (int)$db->lastInsertId();

        // Typ-spezifische Auswirkungen
        if ($driverId) {
            switch ($type) {
                case 'points':
                    // Punkteabzug: wird live in buildBonusSql berechnet
                    // KEINE direkte Änderung an result_entries mehr nötig
                    break;

                case 'dsq':
                    // Disqualifikation: Fahrer auf DSQ setzen, Punkte = 0
                    $db->prepare("UPDATE result_entries SET dsq=1, points=0 WHERE result_id=? AND driver_id=?")
                       ->execute([$resultId, $driverId]);
                    break;

                case 'time':
                    // Zeitstrafe: wird als Information gespeichert
                    // Kann Positionsänderung verursachen – position muss manuell angepasst werden
                    // Oder: falls gewünscht könnte man gap hier erhöhen
                    break;

                case 'grid':
                    // Startplatzstrafe: nur informativer Natur für vergangene Rennen
                    // Für zukünftige Rennen relevant – keine direkte DB-Änderung
                    break;

                case 'warning':
                    // Verwarnung: nur informativer Natur
                    break;
            }
        }

        auditLog('penalty_add', 'penalties', $penId, "{$type} {$amount} Fahrer #{$driverId} Rennen #{$resultId}: {$reason}");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'⚠️ Strafe gespeichert!'];
        header('Location: '.SITE_URL.'/admin/penalties.php'); exit;
    }

    if ($action === 'toggle') {
        $pen = $db->prepare("SELECT * FROM penalties WHERE id=?");
        $pen->execute([(int)$_POST['id']]); $pen = $pen->fetch();
        if ($pen) {
            $newApplied = $pen['applied'] ? 0 : 1;
            $db->prepare("UPDATE penalties SET applied=? WHERE id=?")->execute([$newApplied, $pen['id']]);

            // DSQ rückgängig machen wenn deaktiviert
            if ($pen['type'] === 'dsq' && $pen['driver_id']) {
                if (!$newApplied) {
                    $db->prepare("UPDATE result_entries SET dsq=0 WHERE result_id=? AND driver_id=?")
                       ->execute([$pen['result_id'], $pen['driver_id']]);
                } else {
                    $db->prepare("UPDATE result_entries SET dsq=1, points=0 WHERE result_id=? AND driver_id=?")
                       ->execute([$pen['result_id'], $pen['driver_id']]);
                }
            }
            auditLog('penalty_toggle','penalties',$pen['id'],'applied='.$newApplied);
            $_SESSION['flash'] = ['type'=>'success','msg'=>$newApplied ? '✅ Strafe aktiviert.' : '⏸ Strafe deaktiviert.'];
        }
        header('Location: '.SITE_URL.'/admin/penalties.php'); exit;
    }

    if ($action === 'delete') {
        $pen = $db->prepare("SELECT * FROM penalties WHERE id=?");
        $pen->execute([(int)$_POST['id']]); $pen = $pen->fetch();
        if ($pen && $pen['applied']) {
            // DSQ rückgängig beim Löschen
            if ($pen['type'] === 'dsq' && $pen['driver_id']) {
                $db->prepare("UPDATE result_entries SET dsq=0 WHERE result_id=? AND driver_id=?")
                   ->execute([$pen['result_id'], $pen['driver_id']]);
            }
        }
        $db->prepare("DELETE FROM penalties WHERE id=?")->execute([(int)$_POST['id']]);
        auditLog('penalty_delete','penalties',(int)$_POST['id']);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'🗑 Strafe gelöscht.'];
        header('Location: '.SITE_URL.'/admin/penalties.php'); exit;
    }
}

$results = $db->query("SELECT r.id,rc.track_name,rc.race_date,s.name AS season_name
    FROM results r JOIN races rc ON rc.id=r.race_id JOIN seasons s ON s.id=rc.season_id
    ORDER BY rc.race_date DESC LIMIT 30")->fetchAll();

$allDrivers = $db->query("SELECT * FROM drivers ORDER BY name")->fetchAll();

$penalties = $db->query("
    SELECT p.*,d.name AS driver_name,rc.track_name,rc.race_date,s.name AS season_name,rc.round
    FROM penalties p
    LEFT JOIN drivers d ON d.id=p.driver_id
    LEFT JOIN results r ON r.id=p.result_id
    LEFT JOIN races rc ON rc.id=r.race_id
    LEFT JOIN seasons s ON s.id=rc.season_id
    ORDER BY p.created_at DESC
")->fetchAll();

$typeLabels = ['time'=>'Zeitstrafe','points'=>'Punkteabzug','grid'=>'Startplatz','warning'=>'Verwarnung','dsq'=>'Disqualifikation'];
$typeColors = ['time'=>'badge-secondary','points'=>'badge-primary','grid'=>'badge-info','warning'=>'badge-muted','dsq'=>'badge-muted'];
$typeEffects = [
    'time'    => 'Informativ – Gap/Ergebnis ggf. manuell anpassen',
    'points'  => 'Wird automatisch von der Wertung abgezogen',
    'grid'    => 'Informativ – keine direkte Wertungsänderung',
    'warning' => 'Informativ – keine direkte Wertungsänderung',
    'dsq'     => 'Fahrer wird auf DSQ gesetzt, Punkte = 0',
];

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Strafen & <span style="color:var(--primary)">Penalties</span></div>
<div class="admin-page-sub">Zeitstrafen, Punkteabzüge und Verwarnungen verwalten</div>

<div class="notice notice-info mb-3" style="font-size:.84rem">
  <strong>Auswirkungen auf die Wertung:</strong>
  <ul style="margin:6px 0 0 16px;line-height:1.8">
    <li><strong>Punkteabzug</strong> – wird automatisch live von Fahrer- und Team-WM abgezogen</li>
    <li><strong>Disqualifikation</strong> – setzt Fahrer auf DSQ, Punkte werden auf 0 gesetzt</li>
    <li><strong>Zeitstrafe / Startplatz / Verwarnung</strong> – informativ, keine automatische Wertungsänderung</li>
  </ul>
</div>

<div class="grid-2" style="gap:20px;align-items:start">
  <div class="card">
    <div class="card-header"><h3>⚠️ Neue Strafe</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save"/>
        <div class="form-group">
          <label>Rennen *</label>
          <select name="result_id" class="form-control" required>
            <option value="">– Rennen wählen –</option>
            <?php foreach($results as $r): ?>
            <option value="<?= $r['id'] ?>"><?= h($r['track_name']) ?> (<?= $r['race_date']?date('d.m.Y',strtotime($r['race_date'])):'–' ?>) – <?= h($r['season_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Fahrer</label>
          <select name="driver_id" class="form-control">
            <option value="">– Fahrer wählen –</option>
            <?php foreach($allDrivers as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Oder Name (manuell)</label>
          <input type="text" name="driver_name_raw" class="form-control" placeholder="Fahrername wenn nicht registriert"/>
        </div>
        <div class="form-row cols-2">
          <div class="form-group">
            <label>Strafart *</label>
            <select name="type" class="form-control" id="penalty-type" onchange="updateAmountLabel()">
              <?php foreach($typeLabels as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="amount-group">
            <label id="amount-label">Betrag (Sekunden)</label>
            <input type="number" name="amount" class="form-control" id="amount-field" step="0.001" min="0" placeholder="z.B. 5"/>
          </div>
        </div>
        <div class="notice notice-warning" id="type-effect" style="font-size:.82rem;margin-bottom:12px">
          Informativ – keine direkte Wertungsänderung
        </div>
        <div class="form-group">
          <label>Begründung *</label>
          <textarea name="reason" class="form-control" style="min-height:80px" required placeholder="z.B. Kollision in Kurve 3, Abkürzung..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-full">⚠️ Strafe verhängen</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>📋 Alle Strafen (<?= count($penalties) ?>)</h3></div>
    <div class="card-body" style="padding:0;max-height:600px;overflow-y:auto">
      <?php if($penalties): foreach($penalties as $p): ?>
      <div style="padding:12px 16px;border-bottom:1px solid var(--border);opacity:<?= $p['applied']?'1':'.5' ?>">
        <div class="flex flex-center justify-between gap-2 mb-1">
          <div>
            <span class="badge <?= $typeColors[$p['type']]??'badge-muted' ?>">
              <?= $typeLabels[$p['type']]??$p['type'] ?><?= $p['amount']>0?' ('.$p['amount'].')':'' ?>
            </span>
            <?php if(!$p['applied']): ?><span class="badge badge-muted" style="font-size:.65rem">DEAKTIVIERT</span><?php endif; ?>
            <strong style="margin-left:8px"><?= h($p['driver_name']??$p['driver_name_raw']??'–') ?></strong>
          </div>
          <div class="flex gap-1">
            <!-- Toggle aktivieren/deaktivieren -->
            <form method="post" style="display:inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="toggle"/>
              <input type="hidden" name="id" value="<?= $p['id'] ?>"/>
              <button class="btn btn-secondary btn-sm" title="<?= $p['applied']?'Deaktivieren':'Aktivieren' ?>">
                <?= $p['applied'] ? '⏸' : '▶' ?>
              </button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Strafe wirklich löschen?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete"/>
              <input type="hidden" name="id" value="<?= $p['id'] ?>"/>
              <button class="btn btn-danger btn-sm">🗑</button>
            </form>
          </div>
        </div>
        <div class="text-muted" style="font-size:.82rem">
          R<?= (int)$p['round'] ?> · <?= h($p['track_name']??'–') ?> · <?= $p['race_date']?date('d.m.Y',strtotime($p['race_date'])):'–' ?> · <?= h($p['season_name']??'–') ?>
        </div>
        <div style="font-size:.82rem;color:var(--text2);margin-top:2px;font-style:italic">"<?= h($p['reason']) ?>"</div>
        <div style="font-size:.75rem;margin-top:4px;color:var(--text2)">→ <?= $typeEffects[$p['type']] ?? '' ?></div>
      </div>
      <?php endforeach; else: ?>
      <div style="padding:18px" class="text-muted">Noch keine Strafen.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const amountLabels = {time:'Betrag (Sekunden)',points:'Punkte-Abzug',grid:'Startplätze (hinten)',warning:'–',dsq:'–'};
const effects = <?= json_encode($typeEffects) ?>;
function updateAmountLabel() {
    const v = document.getElementById('penalty-type').value;
    document.getElementById('amount-label').textContent = amountLabels[v] || 'Betrag';
    document.getElementById('amount-group').style.display = ['time','points','grid'].includes(v) ? 'block' : 'none';
    document.getElementById('type-effect').textContent = '→ ' + (effects[v] || '');
    document.getElementById('type-effect').className = 'notice ' + (['points','dsq'].includes(v) ? 'notice-warning' : 'notice-info') + ' mb-3';
    document.getElementById('type-effect').style.fontSize = '.82rem';
}
updateAmountLabel();
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
