<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Social Links'; $adminPage = 'social';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin');
    $platforms = $_POST['platform'] ?? [];
    $urls      = $_POST['url']      ?? [];
    $links = [];
    foreach ($platforms as $i => $p) {
        $url = trim($urls[$i] ?? '');
        if ($url) $links[] = ['platform' => $p, 'url' => $url];
    }
    setSetting('social_links', json_encode($links));
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Social Links gespeichert!'];
    header('Location: ' . SITE_URL . '/admin/social.php'); exit;
}
require_once __DIR__ . '/includes/layout.php';
$links = json_decode(getSetting('social_links','[]'), true) ?: [];
$platforms = ['twitter','instagram','youtube','twitch','discord','facebook','tiktok','other'];
$platformLabels = ['twitter'=>'Twitter / X','instagram'=>'Instagram','youtube'=>'YouTube','twitch'=>'Twitch','discord'=>'Discord','facebook'=>'Facebook','tiktok'=>'TikTok','other'=>'Sonstiges'];
?>
<div class="admin-page-title">Social <span style="color:var(--primary)">Links</span></div>
<div class="admin-page-sub">Social-Media Links für den Footer der Website</div>
<form method="post">
<div class="card mb-3">
  <div class="card-header">
    <h3>Links verwalten</h3>
    <button type="button" class="btn btn-secondary btn-sm" onclick="addLinkRow()">+ Hinzufügen</button>
  </div>
  <div class="card-body">
    <div id="social-rows">
      <?php if ($links): foreach ($links as $i => $lnk): ?>
      <div class="flex flex-center gap-2 mb-2 social-row">
        <select name="platform[]" class="form-control" style="max-width:180px">
          <?php foreach ($platforms as $p): ?>
            <option value="<?= $p ?>" <?= $p===$lnk['platform']?'selected':'' ?>><?= $platformLabels[$p] ?></option>
          <?php endforeach; ?>
        </select>
        <input type="url" name="url[]" class="form-control" value="<?= h($lnk['url']) ?>" placeholder="https://..." required/>
        <button type="button" class="btn btn-danger btn-sm btn-icon" onclick="this.closest('.social-row').remove()">✕</button>
      </div>
      <?php endforeach; else: ?>
      <div class="text-muted" id="no-links-hint">Noch keine Links. Klicke "+ Hinzufügen".</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<button type="submit" class="btn btn-primary btn-lg">💾 Links speichern</button>
</form>
<script>
const platformOpts = <?= json_encode(array_map(fn($p)=>['v'=>$p,'l'=>$platformLabels[$p]],$platforms)) ?>;
function addLinkRow() {
    document.getElementById('no-links-hint')?.remove();
    const div = document.createElement('div');
    div.className = 'flex flex-center gap-2 mb-2 social-row';
    div.innerHTML = `<select name="platform[]" class="form-control" style="max-width:180px">
        ${platformOpts.map(o=>`<option value="${o.v}">${o.l}</option>`).join('')}
    </select>
    <input type="url" name="url[]" class="form-control" placeholder="https://..." required/>
    <button type="button" class="btn btn-danger btn-sm btn-icon" onclick="this.closest('.social-row').remove()">✕</button>`;
    document.getElementById('social-rows').appendChild(div);
}
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
