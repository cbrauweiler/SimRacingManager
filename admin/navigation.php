<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Navigation'; $adminPage = 'navigation';
requireRole('admin');

define('NAV_DEFAULT', json_encode([
    ['key'=>'home',      'label'=>'Home',         'icon'=>'🏠', 'url'=>'/',             'visible'=>1],
    ['key'=>'news',      'label'=>'News',          'icon'=>'📰', 'url'=>'/news.php',     'visible'=>1],
    ['key'=>'season',    'label'=>'Saison',        'icon'=>'🏆', 'url'=>'/season.php',   'visible'=>1],
    ['key'=>'calendar',  'label'=>'Kalender',      'icon'=>'📅', 'url'=>'/calendar.php', 'visible'=>1],
    ['key'=>'results',   'label'=>'Ergebnisse',    'icon'=>'🏁', 'url'=>'/results.php',  'visible'=>1],
    ['key'=>'standings', 'label'=>'Wertung',       'icon'=>'📊', 'url'=>'/standings.php','visible'=>1],
    ['key'=>'teams',     'label'=>'Teams',         'icon'=>'👥', 'url'=>'/teams.php',    'visible'=>1],
    ['key'=>'hof',       'label'=>'Hall of Fame',  'icon'=>'🏅', 'url'=>'/hof.php',      'visible'=>0],
    ['key'=>'info',      'label'=>'Liga Info',     'icon'=>'ℹ️', 'url'=>'/info.php',     'visible'=>1],
], JSON_UNESCAPED_UNICODE));

function getNavItems(): array {
    $stored = getSetting('nav_items', '');
    $items  = $stored ? json_decode($stored, true) : null;
    if (!$items) $items = json_decode(NAV_DEFAULT, true);
    return $items ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        // Reihenfolge kommt als JSON-String vom JS
        $orderJson = $_POST['order'] ?? '';
        $order     = $orderJson ? json_decode($orderJson, true) : null;

        if ($order && is_array($order)) {
            $items = [];
            foreach ($order as $row) {
                $items[] = [
                    'key'     => preg_replace('/[^a-z0-9_]/', '', $row['key'] ?? ''),
                    'label'   => trim($row['label'] ?? ''),
                    'icon'    => trim($row['icon']  ?? ''),
                    'url'     => trim($row['url']   ?? '/'),
                    'visible' => (int)($row['visible'] ?? 0),
                ];
            }
            setSetting('nav_items', json_encode($items, JSON_UNESCAPED_UNICODE));
            auditLog('nav_save', 'settings', 0, count($items) . ' items');
            $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Navigation gespeichert!'];
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'❌ Fehler beim Speichern.'];
        }
        header('Location: ' . SITE_URL . '/admin/navigation.php'); exit;
    }

    if ($action === 'reset') {
        setSetting('nav_items', NAV_DEFAULT);
        auditLog('nav_reset', 'settings', 0);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Navigation zurückgesetzt.'];
        header('Location: ' . SITE_URL . '/admin/navigation.php'); exit;
    }
}

$items = getNavItems();
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Navigation <span style="color:var(--primary)">bearbeiten</span></div>
<div class="admin-page-sub">Reihenfolge per Drag & Drop, Einträge ein-/ausblenden, Labels anpassen</div>

<!-- ================================================================
  NUR ein Form auf der Seite – Reset läuft über separaten POST
================================================================ -->
<div class="card mb-3">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <h3>🔗 Menü-Einträge</h3>
    <div class="flex gap-2">
      <button type="button" class="btn btn-secondary btn-sm" id="btn-reset"
              onclick="resetNav()">↩ Standard</button>
      <button type="button" class="btn btn-primary btn-sm" id="btn-save"
              onclick="saveNav()">💾 Speichern</button>
    </div>
  </div>
  <div class="card-body">
    <div class="notice notice-info mb-3" style="font-size:.83rem">
      Ziehe Einträge an der <strong>⠿</strong> Griffleiste in die gewünschte Reihenfolge.
      Häkchen = sichtbar in der Navigation.
    </div>

    <div id="nav-list" style="display:flex;flex-direction:column;gap:6px">
      <?php foreach ($items as $item): ?>
      <div class="nav-row"
           data-key="<?= h($item['key']) ?>"
           data-url="<?= h($item['url']) ?>"
           style="display:flex;align-items:center;gap:10px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px">

        <span class="sortable-handle"
              style="cursor:grab;font-size:1.3rem;color:var(--text2);flex-shrink:0;user-select:none"
              title="Ziehen zum Sortieren">⠿</span>

        <input type="checkbox" class="nav-visible"
               <?= $item['visible'] ? 'checked' : '' ?>
               title="In Navigation anzeigen"
               style="width:18px;height:18px;flex-shrink:0"/>

        <input type="text" class="nav-icon form-control"
               value="<?= h($item['icon']) ?>"
               style="width:52px;padding:6px;text-align:center;font-size:1.1rem;flex-shrink:0"
               title="Icon (Emoji)" placeholder="🔗"/>

        <input type="text" class="nav-label form-control"
               value="<?= h($item['label']) ?>"
               style="flex:1;min-width:100px"
               placeholder="Bezeichnung"/>

        <input type="text" class="nav-url form-control"
               value="<?= h($item['url']) ?>"
               style="flex:1;min-width:130px;font-family:monospace;font-size:.82rem"
               placeholder="/seite.php"/>

        <span class="text-muted" style="font-size:.72rem;min-width:55px;flex-shrink:0"><?= h($item['key']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Verstecktes Form für Save und Reset -->
<form method="post" id="nav-form" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" id="nav-action" value="save"/>
  <input type="hidden" name="order"  id="nav-order"  value=""/>
</form>

<div class="notice notice-info" style="font-size:.82rem">
  💡 <strong>Hall of Fame</strong> ist standardmäßig deaktiviert – einfach das Häkchen setzen und speichern.
  Die Seite ist immer unter <code>/hof.php</code> erreichbar.
</div>

<!-- SortableJS von CDN -->
<?php
$sortableLocal = file_exists(dirname(__DIR__).'/assets/js/Sortable.min.js');
?>
<?php if($sortableLocal): ?>
<script src="<?= SITE_URL ?>/assets/js/Sortable.min.js"></script>
<?php else: ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<?php endif; ?>
<script>
// SortableJS initialisieren
var sortable = new Sortable(document.getElementById('nav-list'), {
    handle:    '.sortable-handle',
    animation: 150,
    ghostClass: 'sortable-ghost',
});

function collectOrder() {
    var rows  = document.querySelectorAll('#nav-list .nav-row');
    var order = [];
    rows.forEach(function(row) {
        order.push({
            key:     row.getAttribute('data-key'),
            url:     row.querySelector('.nav-url').value,
            label:   row.querySelector('.nav-label').value,
            icon:    row.querySelector('.nav-icon').value,
            visible: row.querySelector('.nav-visible').checked ? 1 : 0,
        });
    });
    return order;
}

function saveNav() {
    document.getElementById('nav-action').value = 'save';
    document.getElementById('nav-order').value  = JSON.stringify(collectOrder());
    document.getElementById('nav-form').submit();
}

function resetNav() {
    if (!confirm('Navigation auf Standard zurücksetzen?')) return;
    document.getElementById('nav-action').value = 'reset';
    document.getElementById('nav-order').value  = '';
    document.getElementById('nav-form').submit();
}
</script>
<style>
.sortable-ghost { opacity: .35; background: var(--bg2) !important; }
.sortable-handle:active { cursor: grabbing; }
</style>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
