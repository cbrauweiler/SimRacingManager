<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Liga Einstellungen';
$adminPage  = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin');
    $fields = ['league_name','league_abbr','league_sub','league_desc'];
    foreach ($fields as $f) {
        setSetting($f, trim($_POST[$f] ?? ''));
    }
    // Logo upload
    if (!empty($_FILES['logo_file']['name'])) {
        $url = uploadFile($_FILES['logo_file'], 'logos');
        if ($url) setSetting('league_logo', $url);
    } elseif (isset($_POST['league_logo'])) {
        setSetting('league_logo', trim($_POST['league_logo']));
    }
    // Favicon upload
    if (!empty($_FILES['favicon_file']['name'])) {
        $url = uploadFile($_FILES['favicon_file'], 'logos');
        if ($url) setSetting('league_favicon', $url);
    } elseif (isset($_POST['league_favicon'])) {
        setSetting('league_favicon', trim($_POST['league_favicon']));
    }
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Einstellungen gespeichert!'];
    header('Location: ' . SITE_URL . '/admin/settings.php'); exit;
}

require_once __DIR__ . '/includes/layout.php';
$logo    = getSetting('league_logo');
$favicon = getSetting('league_favicon');
?>

<div class="admin-page-title">Liga <span style="color:var(--primary)">Einstellungen</span></div>
<div class="admin-page-sub">Name, Logo, Favicon und Basisinfos der Liga</div>

<form method="post" enctype="multipart/form-data">
<div class="card mb-3">
  <div class="card-header"><h3>Basisinformationen</h3></div>
  <div class="card-body">
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Liga Name *</label>
        <input type="text" name="league_name" class="form-control" value="<?= h(getSetting('league_name')) ?>" required/>
      </div>
      <div class="form-group">
        <label>Kürzel (Abbr.) *</label>
        <input type="text" name="league_abbr" class="form-control" value="<?= h(getSetting('league_abbr')) ?>" maxlength="6" required/>
        <div class="input-hint">Max. 6 Zeichen, z.B. "SRL", "GTL"</div>
      </div>
    </div>
    <div class="form-group">
      <label>Subline / Slogan</label>
      <input type="text" name="league_sub" class="form-control" value="<?= h(getSetting('league_sub')) ?>" placeholder="Management System"/>
      <div class="input-hint">Erscheint unter dem Logo in der Navigation</div>
    </div>
    <div class="form-group">
      <label>Kurzbeschreibung (Footer & Meta)</label>
      <input type="text" name="league_desc" class="form-control" value="<?= h(getSetting('league_desc')) ?>" placeholder="Die kompetitivste Simracing-Liga..."/>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3>Logo</h3></div>
  <div class="card-body">
    <div class="form-row cols-2">
      <div>
        <div class="form-group">
          <label>Logo URL</label>
          <input type="text" name="league_logo" id="logo-url" class="form-control" value="<?= h($logo) ?>" placeholder="https://... oder leer lassen"/>
        </div>
        <div class="form-group">
          <label>Logo Datei hochladen</label>
          <input type="file" name="logo_file" id="logo-file" accept="image/*" class="form-control" onchange="previewImg(this,'logo-preview')"/>
          <div class="input-hint">PNG, SVG, JPG – empfohlen: transparenter Hintergrund</div>
        </div>
      </div>
      <div style="text-align:center">
        <div class="input-hint mb-1">Vorschau</div>
        <div style="background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:20px;display:flex;align-items:center;justify-content:center;min-height:100px">
          <?php if ($logo): ?>
          <img id="logo-preview" src="<?= h($logo) ?>" style="max-height:80px;max-width:200px;object-fit:contain"/>
          <?php else: ?>
          <img id="logo-preview" src="" style="max-height:80px;max-width:200px;object-fit:contain;display:none"/>
          <span class="text-muted" id="logo-placeholder">Kein Logo gesetzt</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><h3>Favicon</h3></div>
  <div class="card-body">
    <div class="form-row cols-2">
      <div>
        <div class="form-group">
          <label>Favicon URL</label>
          <input type="text" name="league_favicon" class="form-control" value="<?= h($favicon) ?>" placeholder="https://..."/>
        </div>
        <div class="form-group">
          <label>Favicon Datei hochladen</label>
          <input type="file" name="favicon_file" accept="image/*,.ico" class="form-control" onchange="previewImg(this,'favicon-preview')"/>
          <div class="input-hint">ICO, PNG – 16x16 oder 32x32px empfohlen</div>
        </div>
      </div>
      <div style="text-align:center">
        <div class="input-hint mb-1">Vorschau</div>
        <div style="background:var(--bg3);border:1px solid var(--border);border-radius:4px;padding:20px;display:flex;align-items:center;justify-content:center;min-height:100px">
          <?php if ($favicon): ?>
          <img id="favicon-preview" src="<?= h($favicon) ?>" style="max-height:32px;object-fit:contain"/>
          <?php else: ?>
          <img id="favicon-preview" src="" style="max-height:32px;object-fit:contain;display:none"/>
          <span class="text-muted">Kein Favicon gesetzt</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<button type="submit" class="btn btn-primary btn-lg">💾 Einstellungen speichern</button>
</form>

<script>
function previewImg(input, previewId) {
    const file = input.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById(previewId);
        img.src = e.target.result; img.style.display = 'block';
        const ph = document.getElementById('logo-placeholder');
        if (ph) ph.style.display = 'none';
    };
    reader.readAsDataURL(file);
}
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
