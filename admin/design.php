<?php
// design.php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Design'; $adminPage = 'design';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin'); verifyCsrf();
    foreach (['color_primary','color_secondary','color_tertiary','color_bg','color_text'] as $c)
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $_POST[$c] ?? ''))
            setSetting($c, $_POST[$c]);
    // Custom CSS – nur Basis-Validierung (kein <script> erlaubt)
    $css = $_POST['custom_css'] ?? '';
    $css = str_ireplace(['<script','</script>','javascript:'], '', $css);
    setSetting('custom_css', $css);
    auditLog('design_save','settings',0,'Colors + Custom CSS');
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Design gespeichert! Seite neu laden um Vorschau zu sehen.'];
    header('Location: ' . SITE_URL . '/admin/design.php'); exit;
}
require_once __DIR__ . '/includes/layout.php';
$colors = [
    'color_primary'   => ['label'=>'Primärfarbe',    'hint'=>'Hauptfarbe – Buttons, Akzente, Hover'],
    'color_secondary' => ['label'=>'Sekundärfarbe',  'hint'=>'Akzentfarbe – Punkte, Highlights'],
    'color_tertiary'  => ['label'=>'Tertiärfarbe',   'hint'=>'Info-Farbe – Badges, Links'],
    'color_bg'        => ['label'=>'Hintergrundfarbe','hint'=>'Haupt-Hintergrund der Website'],
    'color_text'      => ['label'=>'Textfarbe',      'hint'=>'Primäre Textfarbe'],
];
?>
<div class="admin-page-title">Design <span style="color:var(--primary)">Anpassung</span></div>
<div class="admin-page-sub">Passe die Farben der Liga-Website an</div>
<form method="post"><?= csrfField() ?>
<div class="card mb-3">
  <div class="card-header"><h3>Farbpalette</h3></div>
  <div class="card-body">
    <div class="notice notice-info mb-3">ℹ️ Hinter- und Textfarbe sollten ausreichend Kontrast haben. Dunkle Hintergründe empfohlen.</div>
    <?php foreach ($colors as $key => $info): $val = getSetting($key, '#000000'); ?>
    <div class="form-group">
      <label><?= h($info['label']) ?></label>
      <div class="color-row">
        <div class="color-swatch">
          <input type="color" id="cp-<?= $key ?>" value="<?= h($val) ?>" oninput="document.getElementById('ch-<?= $key ?>').value=this.value"/>
        </div>
        <input type="text" id="ch-<?= $key ?>" name="<?= $key ?>" value="<?= h($val) ?>" class="form-control" style="max-width:140px" pattern="^#[0-9a-fA-F]{6}$" oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value))document.getElementById('cp-<?= $key ?>').value=this.value"/>
        <span class="text-muted" style="font-size:.82rem"><?= h($info['hint']) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="divider"></div>
    <div class="flex gap-1">
      <button type="button" class="btn btn-secondary btn-sm" onclick="setPreset('dark')">🌑 Dark (Standard)</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="setPreset('blue')">🔵 Blau</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="setPreset('green')">🟢 Grün</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="setPreset('light')">☀️ Hell</button>
    </div>
  </div>
</div>
<div class="card mb-3">
  <div class="card-header"><h3>Custom CSS</h3></div>
  <div class="card-body">
    <div class="notice notice-info mb-3">
      ℹ️ Eigenes CSS wird <strong>nach</strong> dem Standard-CSS geladen und überschreibt es.
      Nutze CSS-Variablen wie <code>var(--primary)</code>, <code>var(--bg2)</code> etc.
      Kein <code>&lt;style&gt;</code>-Tag nötig – nur reines CSS eintragen.
    </div>
    <div class="form-group">
      <label>Eigenes CSS</label>
      <textarea name="custom_css" class="form-control" rows="12"
        style="font-family:monospace;font-size:.82rem;resize:vertical"
        placeholder=".main-nav a { letter-spacing: .1em; }
.card { border-radius: 12px; }
.btn-primary { border-radius: 99px; }"
      ><?= h(getSetting('custom_css','')) ?></textarea>
    </div>
    <div class="flex gap-2 mt-2" style="flex-wrap:wrap">
      <button type="button" class="btn btn-secondary btn-sm" onclick="insertCss('/* Navigation */
.main-nav a { }
')">+ Navigation</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="insertCss('/* Karten */
.card { border-radius: 12px; }
')">+ Karten</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="insertCss('/* Buttons */
.btn-primary { border-radius: 99px; }
')">+ Buttons</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="insertCss('/* Schrift */
body { font-family: Arial, sans-serif; }
')">+ Schrift</button>
      <button type="button" class="btn btn-danger btn-sm" onclick="if(confirm('Custom CSS leeren?'))document.querySelector('[name=custom_css]').value=''">🗑 Leeren</button>
    </div>
  </div>
</div>
<button type="submit" class="btn btn-primary btn-lg">💾 Design speichern</button>
</form>
<script>
const presets = {
  dark:  {color_primary:'#e8333a',color_secondary:'#f5a623',color_tertiary:'#1a9fff',color_bg:'#0a0a0f',color_text:'#f0f0f5'},
  blue:  {color_primary:'#1a9fff',color_secondary:'#f5a623',color_tertiary:'#e8333a',color_bg:'#060d18',color_text:'#e8f0ff'},
  green: {color_primary:'#27ae60',color_secondary:'#f5a623',color_tertiary:'#1a9fff',color_bg:'#060f0a',color_text:'#e8ffe0'},
  light: {color_primary:'#e8333a',color_secondary:'#d48000',color_tertiary:'#0070cc',color_bg:'#f4f4f6',color_text:'#111118'},
};
function setPreset(name) {
  const p = presets[name]; if (!p) return;
  Object.entries(p).forEach(([k,v]) => {
    const cp = document.getElementById('cp-'+k);
    const ch = document.getElementById('ch-'+k);
    if (cp) cp.value = v; if (ch) ch.value = v;
  });
}
function insertCss(snippet) {
  const ta = document.querySelector('[name=custom_css]');
  const pos = ta.selectionStart;
  ta.value = ta.value.slice(0,pos) + snippet + ta.value.slice(ta.selectionEnd);
  ta.selectionStart = ta.selectionEnd = pos + snippet.length;
  ta.focus();
}
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
