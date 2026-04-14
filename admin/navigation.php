<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Navigation'; $adminPage = 'navigation';
requireRole('admin');

// Standard-Navigation
define('NAV_DEFAULT', json_encode([
    ['key'=>'home',      'label'=>'Home',         'icon'=>'🏠', 'url'=>'/',             'visible'=>1],
    ['key'=>'news',      'label'=>'News',          'icon'=>'📰', 'url'=>'/news.php',      'visible'=>1],
    ['key'=>'season',    'label'=>'Saison',        'icon'=>'🏆', 'url'=>'/season.php',    'visible'=>1],
    ['key'=>'calendar',  'label'=>'Kalender',      'icon'=>'📅', 'url'=>'/calendar.php',  'visible'=>1],
    ['key'=>'results',   'label'=>'Ergebnisse',    'icon'=>'🏁', 'url'=>'/results.php',   'visible'=>1],
    ['key'=>'standings', 'label'=>'Wertung',       'icon'=>'📊', 'url'=>'/standings.php', 'visible'=>1],
    ['key'=>'teams',     'label'=>'Teams',         'icon'=>'👥', 'url'=>'/teams.php',     'visible'=>1],
    ['key'=>'hof',       'label'=>'Hall of Fame',  'icon'=>'🏅', 'url'=>'/hof.php',       'visible'=>0],
    ['key'=>'info',      'label'=>'Liga Info',     'icon'=>'ℹ️', 'url'=>'/info.php',      'visible'=>1],
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
        $keys    = $_POST['keys']    ?? [];
        $labels  = $_POST['labels']  ?? [];
        $icons   = $_POST['icons']   ?? [];
        $urls    = $_POST['urls']    ?? [];
        $visible = $_POST['visible'] ?? [];

        $items = [];
        foreach ($keys as $i => $key) {
            $items[] = [
                'key'     => preg_replace('/[^a-z0-9_]/', '', $key),
                'label'   => trim($labels[$i] ?? $key),
                'icon'    => trim($icons[$i]  ?? ''),
                'url'     => trim($urls[$i]   ?? '/'),
                'visible' => isset($visible[$i]) ? 1 : 0,
            ];
        }
        setSetting('nav_items', json_encode($items, JSON_UNESCAPED_UNICODE));
        auditLog('nav_save', 'settings', 0, count($items) . ' items');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Navigation gespeichert!'];
        header('Location: ' . SITE_URL . '/admin/navigation.php'); exit;
    }

    if ($action === 'reset') {
        setSetting('nav_items', NAV_DEFAULT);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Navigation zurückgesetzt.'];
        header('Location: ' . SITE_URL . '/admin/navigation.php'); exit;
    }
}

$items = getNavItems();
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Navigation <span style="color:var(--primary)">bearbeiten</span></div>
<div class="admin-page-sub">Reihenfolge per Drag & Drop ändern, Einträge ein-/ausblenden und Labels anpassen</div>

<form method="post" id="nav-form">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save"/>

  <div class="card mb-3">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
      <h3>🔗 Menü-Einträge</h3>
      <button type="submit" class="btn btn-primary btn-sm">💾 Speichern</button>
    </div>
    <div class="card-body">
      <div class="notice notice-info mb-3" style="font-size:.83rem">
        Ziehe Einträge per <strong>⠿</strong> in die gewünschte Reihenfolge.
        Deaktivierte Einträge werden in der Navigation ausgeblendet.
      </div>

      <div id="nav-list" style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($items as $i => $item): ?>
        <div class="nav-item-row" data-index="<?= $i ?>" style="display:flex;align-items:center;gap:10px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px 14px;cursor:default">
          <span class="drag-handle" style="cursor:grab;font-size:1.2rem;color:var(--text2);flex-shrink:0">⠿</span>

          <!-- Sichtbar -->
          <input type="checkbox" name="visible[<?= $i ?>]"
                 id="vis-<?= $i ?>"
                 <?= $item['visible'] ? 'checked' : '' ?>
                 style="width:18px;height:18px;flex-shrink:0"
                 title="Sichtbar"/>

          <!-- Icon -->
          <input type="text" name="icons[<?= $i ?>]"
                 value="<?= h($item['icon']) ?>"
                 class="form-control" style="width:52px;padding:6px;text-align:center;font-size:1.1rem"
                 title="Icon (Emoji)"/>

          <!-- Label -->
          <input type="text" name="labels[<?= $i ?>]"
                 value="<?= h($item['label']) ?>"
                 class="form-control" style="flex:1;min-width:100px"
                 placeholder="Bezeichnung"/>

          <!-- URL -->
          <input type="text" name="urls[<?= $i ?>]"
                 value="<?= h($item['url']) ?>"
                 class="form-control" style="flex:1;min-width:120px;font-family:monospace;font-size:.82rem"
                 placeholder="/seite.php"/>

          <!-- Key (hidden) -->
          <input type="hidden" name="keys[<?= $i ?>]" value="<?= h($item['key']) ?>"/>

          <span class="text-muted" style="font-size:.75rem;min-width:60px"><?= h($item['key']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card-body" style="border-top:1px solid var(--border);display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap">
      <form method="post" style="display:inline" onsubmit="return confirm('Navigation auf Standard zurücksetzen?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reset"/>
        <button type="submit" class="btn btn-secondary">↩ Standard wiederherstellen</button>
      </form>
      <button type="submit" form="nav-form" class="btn btn-primary">💾 Speichern</button>
    </div>
  </div>
</form>

<div class="notice notice-info" style="font-size:.82rem">
  💡 <strong>Hall of Fame</strong> ist standardmäßig deaktiviert. Aktiviere den Eintrag um sie in der Navigation anzuzeigen.
  Die Seite ist immer unter <code>/hof.php</code> erreichbar.
</div>

<script>
// Drag & Drop Sortierung
var list = document.getElementById('nav-list');
var dragEl = null, dragOver = null;

list.querySelectorAll('.drag-handle').forEach(function(handle) {
    handle.addEventListener('mousedown', function(e) {
        dragEl = handle.closest('.nav-item-row');
        dragEl.style.opacity = '.5';
        dragEl.style.cursor  = 'grabbing';
    });
});

list.addEventListener('dragover', function(e) { e.preventDefault(); });

// Simpler Drag & Drop ohne HTML5 drag API (kompatibel)
document.addEventListener('mousemove', function(e) {
    if (!dragEl) return;
    var rows = Array.from(list.querySelectorAll('.nav-item-row'));
    rows.forEach(function(row) {
        if (row === dragEl) return;
        var rect = row.getBoundingClientRect();
        var mid  = rect.top + rect.height / 2;
        if (e.clientY < mid) {
            list.insertBefore(dragEl, row);
        }
    });
});

document.addEventListener('mouseup', function() {
    if (!dragEl) return;
    dragEl.style.opacity = '1';
    dragEl.style.cursor  = 'default';
    dragEl = null;
    // Index-Attribute und Input-Namen aktualisieren
    reindex();
});

function reindex() {
    list.querySelectorAll('.nav-item-row').forEach(function(row, i) {
        row.setAttribute('data-index', i);
        row.querySelectorAll('input').forEach(function(inp) {
            inp.name = inp.name.replace(/\[\d+\]/, '[' + i + ']');
            if (inp.id) inp.id = inp.id.replace(/-\d+$/, '-' + i);
        });
        var lbl = row.querySelector('label');
        if (lbl) lbl.setAttribute('for', 'vis-' + i);
    });
}
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
