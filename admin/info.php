<?php
// info.php (Admin)
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Liga Info'; $adminPage = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin');
    setSetting('info_html', $_POST['info_html'] ?? '');
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Liga-Info gespeichert!'];
    header('Location: ' . SITE_URL . '/admin/info.php'); exit;
}
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Liga <span style="color:var(--primary)">Info</span></div>
<div class="admin-page-sub">Inhalt der öffentlichen Liga-Info Seite</div>
<form method="post">
<div class="card mb-3">
  <div class="card-header"><h3>Info-Text</h3></div>
  <div class="card-body">
    <div class="notice notice-info mb-3">ℹ️ HTML erlaubt: &lt;h2&gt;, &lt;h3&gt;, &lt;p&gt;, &lt;b&gt;, &lt;i&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;table&gt;, &lt;a&gt;, &lt;br&gt;</div>
    <div class="form-group">
      <label>HTML Inhalt</label>
      <textarea name="info_html" class="form-control" style="min-height:400px;font-family:monospace;font-size:.85rem"><?= h(getSetting('info_html')) ?></textarea>
    </div>
    <div class="form-group mt-2">
      <label>Vorschau</label>
      <div id="info-preview" class="card" style="padding:20px;line-height:1.8;min-height:100px;max-height:300px;overflow-y:auto">
        <?= getSetting('info_html') ?: '<span class="text-muted">Noch kein Inhalt.</span>' ?>
      </div>
    </div>
  </div>
</div>
<button type="submit" class="btn btn-primary btn-lg">💾 Info speichern</button>
</form>
<script>
document.querySelector('textarea[name=info_html]').addEventListener('input', function() {
    document.getElementById('info-preview').innerHTML = this.value || '<span class="text-muted">Vorschau...</span>';
});
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
