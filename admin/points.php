<?php
// points.php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Punktesystem'; $adminPage = 'points';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $pts = $_POST['points'] ?? [];
    $pts = array_map('intval', array_filter($pts, fn($v) => $v !== ''));
    setSetting('points_system', implode(',', $pts));
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Punktesystem gespeichert!'];
    header('Location: '.SITE_URL.'/admin/points.php'); exit;
}
$current = array_map('trim', explode(',', getSetting('points_system','25,18,15,12,10,8,6,4,2,1')));
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Punkte<span style="color:var(--primary)">system</span></div>
<div class="admin-page-sub">Punkteverteilung je Platzierung konfigurieren</div>

<div class="grid-2" style="gap:20px;align-items:start">
  <div class="card">
    <div class="card-header"><h3>Punkte je Platz</h3></div>
    <div class="card-body">
      <div class="notice notice-info mb-3">ℹ️ Gilt für alle neuen Ergebnisimporte. Bestehende Punkte werden nicht automatisch angepasst.</div>
      <div class="flex gap-1 flex-wrap mb-3">
        <button type="button" class="btn btn-secondary btn-sm" onclick="setPointsPreset('f1')">🏎 F1 System</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="setPointsPreset('top10')">Top 10</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="setPointsPreset('simple')">Einfach</button>
      </div>
      <form method="post">
        <div id="points-rows"></div>
        <div class="flex gap-1 mt-2 mb-3">
          <button type="button" class="btn btn-secondary btn-sm" onclick="addPointsRow()">+ Platz hinzufügen</button>
        </div>
        <button type="submit" class="btn btn-primary">💾 Speichern</button>
      </form>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3>Vorschau</h3></div>
    <div class="card-body">
      <table class="admin-table" id="pts-preview-table">
        <thead><tr><th>Platz</th><th>Punkte</th></tr></thead>
        <tbody id="pts-preview-body"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
const initialPts = <?= json_encode(array_map('intval',$current)) ?>;
document.addEventListener('DOMContentLoaded', () => {
    buildPointsRows(initialPts);
    updatePreview(initialPts);
    document.getElementById('points-rows').addEventListener('input', updatePreviewFromForm);
});

function updatePreview(pts) {
    const body = document.getElementById('pts-preview-body');
    body.innerHTML = pts.map((p,i) => `<tr><td class="pos-col ${i===0?'pos-1':i===1?'pos-2':i===2?'pos-3':''}">P${i+1}</td><td class="pts-col">${p}</td></tr>`).join('');
}
function updatePreviewFromForm() {
    const inputs = document.querySelectorAll('#points-rows input[type=number]');
    updatePreview([...inputs].map(i => parseInt(i.value)||0));
}
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
