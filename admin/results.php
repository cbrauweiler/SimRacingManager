<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Race Results'; $adminPage = 'results';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin'); verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $db->prepare("DELETE FROM results WHERE id=?")->execute([(int)$_POST['id']]);
        auditLog('result_delete','results',(int)$_POST['id']);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'🗑 Ergebnis gelöscht.'];
        header('Location: '.SITE_URL.'/admin/results.php'); exit;
    }

    if ($action === 'discord_notify') {
        $resultId = (int)$_POST['result_id'];

        // Ergebnis holen
        $rStmt = $db->prepare("
            SELECT r.*, rc.track_name, rc.race_date, rc.round, rc.location,
                   s.name AS season_name, s.id AS season_id
            FROM results r
            JOIN races rc ON rc.id = r.race_id
            JOIN seasons s ON s.id = rc.season_id
            WHERE r.id = ?
        ");
        $rStmt->execute([$resultId]);
        $raceData = $rStmt->fetch();

        if (!$raceData) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Ergebnis nicht gefunden.'];
            header('Location: '.SITE_URL.'/admin/results.php'); exit;
        }

        // Top-3 Fahrer holen
        $bonusSql = buildBonusSql('re');
        $eStmt = $db->prepare("
            SELECT re.*, ({$bonusSql}) AS calc_pts,
                   d.name AS driver_name, t.name AS team_name
            FROM result_entries re
            LEFT JOIN drivers d ON d.id = re.driver_id
            LEFT JOIN teams t ON t.id = re.team_id
            WHERE re.result_id = ?
            ORDER BY re.dnf ASC, re.dsq ASC, re.position ASC
            LIMIT 3
        ");
        $eStmt->execute([$resultId]);
        $top3 = $eStmt->fetchAll();

        $embed = discordResultEmbed($raceData, $top3, $raceData['game'] ?? '', $resultId);
        $ok = discordNotify('', $embed);

        auditLog('discord_manual', 'results', $resultId, $raceData['track_name']);
        $_SESSION['flash'] = $ok
            ? ['type'=>'success','msg'=>'✅ Discord Webhook erfolgreich ausgelöst!']
            : ['type'=>'error','msg'=>'❌ Discord Webhook fehlgeschlagen. URL in den Einstellungen prüfen.'];
        header('Location: '.SITE_URL.'/admin/results.php'); exit;
    }
}

$results = $db->query("
    SELECT r.id, r.game, r.imported_at, r.notes,
           rc.id AS race_id, rc.track_name, rc.race_date, rc.round,
           s.name AS season_name,
           (SELECT COUNT(*) FROM result_entries re WHERE re.result_id=r.id) AS entry_count
    FROM results r
    JOIN races rc ON rc.id = r.race_id
    JOIN seasons s ON s.id = rc.season_id
    ORDER BY rc.race_date DESC, r.imported_at DESC
")->fetchAll();

$hasWebhook = !empty(getSetting('discord_webhook_url'));

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Race <span style="color:var(--primary)">Results</span></div>
<div class="admin-page-sub">Alle gespeicherten Rennergebnisse</div>

<div class="flex gap-2 mb-3" style="flex-wrap:wrap;align-items:center">
  <a href="<?= SITE_URL ?>/admin/import_lmu.php" class="btn btn-primary">🏎 LMU XML Import</a>
  <?php if(!$hasWebhook): ?>
    <span class="text-muted" style="font-size:.8rem">
      💡 Discord Webhook noch nicht konfiguriert –
      <a href="<?= SITE_URL ?>/admin/advanced.php#discord" style="color:var(--primary)">Jetzt einrichten →</a>
    </span>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <?php if ($results): ?>
    <div class="overflow-x">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Rd.</th>
          <th>Strecke</th>
          <th>Saison</th>
          <th>Datum</th>
          <th>Sim</th>
          <th>Fahrer</th>
          <th>Importiert</th>
          <th style="text-align:right">Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
          <td class="text-muted font-display" style="font-weight:700">R<?= (int)$r['round'] ?></td>
          <td><strong><?= h($r['track_name']) ?></strong></td>
          <td class="text-muted"><?= h($r['season_name']) ?></td>
          <td class="text-muted"><?= $r['race_date'] ? date('d.m.Y', strtotime($r['race_date'])) : '–' ?></td>
          <td><span class="badge badge-info"><?= h($r['game'] ?? '–') ?></span></td>
          <td><?= (int)$r['entry_count'] ?> Fahrer</td>
          <td class="text-muted" style="font-size:.78rem"><?= date('d.m.Y H:i', strtotime($r['imported_at'])) ?></td>
          <td>
            <div class="flex gap-1" style="justify-content:flex-end;flex-wrap:wrap">
              <a href="<?= SITE_URL ?>/results.php?id=<?= $r['id'] ?>"
                 class="btn btn-secondary btn-sm" target="_blank"
                 title="Ergebnis auf Website ansehen">👁</a>
              <a href="<?= SITE_URL ?>/admin/result_edit.php?id=<?= $r['id'] ?>"
                 class="btn btn-secondary btn-sm"
                 title="Ergebnis bearbeiten">✏️</a>

              <?php if ($hasWebhook): ?>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Discord Webhook jetzt manuell auslösen für <?= h(addslashes($r['track_name'])) ?>?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="discord_notify"/>
                <input type="hidden" name="result_id" value="<?= $r['id'] ?>"/>
                <button class="btn btn-secondary btn-sm" title="Discord Webhook manuell auslösen"
                        style="color:#5865F2;border-color:#5865F2">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle">
                    <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/>
                  </svg>
                </button>
              </form>
              <?php endif; ?>

              <form method="post" style="display:inline"
                    onsubmit="return confirm('Ergebnis wirklich löschen? Kann nicht rückgängig gemacht werden!')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="id" value="<?= $r['id'] ?>"/>
                <button class="btn btn-danger btn-sm" title="Ergebnis löschen">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?>
    <div style="padding:32px;text-align:center">
      <div style="font-size:2.5rem;margin-bottom:12px">🏁</div>
      <div class="text-muted mb-3">Noch keine Ergebnisse gespeichert.</div>
      <a href="<?= SITE_URL ?>/admin/import_lmu.php" class="btn btn-primary">LMU XML Import starten</a>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($hasWebhook): ?>
<div class="notice notice-info mt-3" style="font-size:.82rem">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="#5865F2" style="vertical-align:middle;margin-right:6px"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
  Discord Webhook aktiv – klicke das Discord-Symbol hinter einem Ergebnis um die Notification manuell (erneut) zu senden.
  Der Webhook enthält automatisch einen direkten Link zum Ergebnis auf der Website.
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
