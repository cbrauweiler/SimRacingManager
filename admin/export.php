<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Grafik Export'; $adminPage = 'export';
$db = getDB();

requireRole('editor');
$seasons = $db->query("SELECT * FROM seasons ORDER BY year DESC, id DESC")->fetchAll();
$activeSeason = array_values(array_filter($seasons, fn($s)=>$s['is_active']))[0] ?? ($seasons[0]??null);
$sid = $activeSeason['id'] ?? 0;

$races = $db->query("
    SELECT rc.id, rc.round, rc.track_name, rc.race_date, s.id AS season_id, s.name AS season_name,
           (SELECT COUNT(*) FROM qualifying_results qr WHERE qr.race_id=rc.id) AS has_quali,
           (SELECT COUNT(*) FROM results r WHERE r.race_id=rc.id) AS has_result,
           (SELECT MIN(r.id) FROM results r WHERE r.race_id=rc.id) AS result_id
    FROM races rc JOIN seasons s ON s.id=rc.season_id
    ORDER BY rc.race_date DESC, rc.id DESC
")->fetchAll();

$exportBase = SITE_URL . '/exports/generate.php';

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Grafik <span style="color:var(--primary)">Export</span></div>
<div class="admin-page-sub">WEC-Style Grafiken für Social Media generieren und herunterladen</div>

<style>
.ex-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
@media(max-width:960px){.ex-grid{grid-template-columns:repeat(2,1fr)}}
.ex-card{background:var(--bg2);border:1px solid var(--border);border-radius:6px;overflow:hidden;transition:border-color .18s}
.ex-card:hover{border-color:var(--primary)}
.ex-head{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border)}
.ex-icon{font-size:1.5rem;flex-shrink:0}
.ex-title{font-family:var(--font-display);font-size:.95rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase}
.ex-body{padding:14px 16px}
.ex-btns{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}
.ex-note{font-size:.78rem;color:var(--text2);margin-top:8px}
.prev-wrap{margin-top:12px;border-radius:4px;overflow:hidden;border:1px solid var(--border);background:var(--bg3);min-height:80px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.prev-wrap img{width:100%;display:block}
.loading-txt{font-size:.75rem;color:var(--text2);padding:16px}
</style>

<!-- Selector -->
<div class="card mb-4">
  <div class="card-header"><h3>🎯 Saison &amp; Rennen wählen</h3></div>
  <div class="card-body">
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Saison <span class="text-muted" style="font-size:.75rem">(Kalender, Lineup, Wertungen)</span></label>
        <select id="s-season" class="form-control" onchange="upd()">
          <?php foreach($seasons as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$sid?'selected':'' ?>>
            <?= h($s['name']) ?> <?= h($s['year']??'') ?><?= $s['is_active']?' ★':'' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Rennen <span class="text-muted" style="font-size:.75rem">(Quali &amp; Rennergebnis)</span></label>
        <select id="s-race" class="form-control" onchange="upd()">
          <option value="">– Rennen wählen –</option>
          <?php foreach($races as $r): ?>
          <option value="<?= $r['id'] ?>"
                  data-sid="<?= $r['season_id'] ?>"
                  data-rid="<?= $r['result_id'] ?>"
                  data-hq="<?= $r['has_quali']>0?1:0 ?>"
                  data-hr="<?= $r['has_result']>0?1:0 ?>">
            R<?= $r['round'] ?> – <?= h($r['track_name']) ?>
            <?= $r['race_date']?' ('.date('d.m.Y',strtotime($r['race_date'])).')':'' ?>
            (<?= h($r['season_name']) ?>)
            <?= $r['has_quali']>0?' ⏱':'' ?><?= $r['has_result']>0?' 🏁':'' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
</div>

<!-- Export Cards -->
<div class="ex-grid">

  <!-- Kalender -->
  <div class="ex-card">
    <div class="ex-head"><span class="ex-icon">📅</span>
      <div><div class="ex-title">Rennkalender</div><div class="text-muted" style="font-size:.72rem">Alle Rennen der Saison</div></div>
    </div>
    <div class="ex-body">
      <div class="ex-btns">
        <button class="btn btn-primary btn-sm" onclick="preview('calendar','wec')">👁 Vorschau</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('calendar','wec')">⬇ WEC</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('calendar','instagram')">📱 Instagram</button>
      </div>
      <div class="prev-wrap" id="pv-calendar" onclick="preview('calendar','wec')">
        <span class="loading-txt">Klicken für Vorschau</span>
      </div>
    </div>
  </div>

  <!-- Lineup -->
  <div class="ex-card">
    <div class="ex-head"><span class="ex-icon">👥</span>
      <div><div class="ex-title">Saison Lineup</div><div class="text-muted" style="font-size:.72rem">Teams &amp; Fahrer</div></div>
    </div>
    <div class="ex-body">
      <div class="ex-btns">
        <button class="btn btn-primary btn-sm" onclick="preview('lineup','wec')">👁 Vorschau</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('lineup','wec')">⬇ WEC</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('lineup','instagram')">📱 Instagram</button>
      </div>
      <div class="prev-wrap" id="pv-lineup" onclick="preview('lineup','wec')">
        <span class="loading-txt">Klicken für Vorschau</span>
      </div>
    </div>
  </div>

  <!-- Qualifying -->
  <div class="ex-card">
    <div class="ex-head"><span class="ex-icon">⏱</span>
      <div><div class="ex-title">Qualifying</div><div class="text-muted" style="font-size:.72rem">Zeitenliste</div></div>
    </div>
    <div class="ex-body">
      <div id="q-ok" style="display:none">
        <div class="ex-btns">
          <button class="btn btn-primary btn-sm" onclick="preview('quali','wec')">👁 Vorschau</button>
          <button class="btn btn-secondary btn-sm" onclick="dl('quali','wec')">⬇ WEC</button>
          <button class="btn btn-secondary btn-sm" onclick="dl('quali','instagram')">📱 Instagram</button>
        </div>
        <div class="prev-wrap" id="pv-quali" onclick="preview('quali','wec')">
          <span class="loading-txt">Klicken für Vorschau</span>
        </div>
      </div>
      <div id="q-no" class="ex-note">← Rennen mit Qualifying wählen</div>
    </div>
  </div>

  <!-- Race Result -->
  <div class="ex-card">
    <div class="ex-head"><span class="ex-icon">🏁</span>
      <div><div class="ex-title">Rennergebnis</div><div class="text-muted" style="font-size:.72rem">Klassement mit Punkten</div></div>
    </div>
    <div class="ex-body">
      <div id="r-ok" style="display:none">
        <div class="ex-btns">
          <button class="btn btn-primary btn-sm" onclick="preview('race','wec')">👁 Vorschau</button>
          <button class="btn btn-secondary btn-sm" onclick="dl('race','wec')">⬇ WEC</button>
          <button class="btn btn-secondary btn-sm" onclick="dl('race','instagram')">📱 Instagram</button>
        </div>
        <div class="prev-wrap" id="pv-race" onclick="preview('race','wec')">
          <span class="loading-txt">Klicken für Vorschau</span>
        </div>
      </div>
      <div id="r-no" class="ex-note">← Rennen mit Ergebnis wählen</div>
    </div>
  </div>

  <!-- Driver Standings -->
  <div class="ex-card">
    <div class="ex-head"><span class="ex-icon">🏎</span>
      <div><div class="ex-title">Fahrerwertung</div><div class="text-muted" style="font-size:.72rem">WM-Stand mit Punktebalken</div></div>
    </div>
    <div class="ex-body">
      <div class="ex-btns">
        <button class="btn btn-primary btn-sm" onclick="preview('standings_driver','wec')">👁 Vorschau</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('standings_driver','wec')">⬇ WEC</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('standings_driver','instagram')">📱 Instagram</button>
      </div>
      <div class="prev-wrap" id="pv-standings_driver" onclick="preview('standings_driver','wec')">
        <span class="loading-txt">Klicken für Vorschau</span>
      </div>
    </div>
  </div>

  <!-- Team Standings -->
  <div class="ex-card">
    <div class="ex-head"><span class="ex-icon">🏭</span>
      <div><div class="ex-title">Teamwertung</div><div class="text-muted" style="font-size:.72rem">Konstrukteurswertung</div></div>
    </div>
    <div class="ex-body">
      <div class="ex-btns">
        <button class="btn btn-primary btn-sm" onclick="preview('standings_team','wec')">👁 Vorschau</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('standings_team','wec')">⬇ WEC</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('standings_team','instagram')">📱 Instagram</button>
      </div>
      <div class="prev-wrap" id="pv-standings_team" onclick="preview('standings_team','wec')">
        <span class="loading-txt">Klicken für Vorschau</span>
      </div>
    </div>
  </div>

</div>

<!-- Spezial-Grafiken -->
<div style="font-family:var(--font-display);font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text2);margin:28px 0 12px">✨ Spezial-Grafiken</div>
<div class="ex-grid">

  <!-- Top 10 Rennen -->
  <div class="ex-card">
    <div class="ex-head"><span class="ex-icon">🎖️</span>
      <div><div class="ex-title">Race Top 10</div><div class="text-muted" style="font-size:.72rem">Podium + P4–P10 mit Fotos</div></div>
    </div>
    <div class="ex-body">
      <div id="t10-ok" style="display:none">
        <div class="ex-btns">
          <button class="btn btn-primary btn-sm" onclick="preview('race_top10','wec')">👁 Vorschau</button>
          <button class="btn btn-secondary btn-sm" onclick="dl('race_top10','wec')">⬇ WEC</button>
          <button class="btn btn-secondary btn-sm" onclick="dl('race_top10','instagram')">📱 Instagram</button>
        </div>
        <div class="prev-wrap" id="pv-race_top10" onclick="preview('race_top10','wec')">
          <span class="loading-txt">Klicken für Vorschau</span>
        </div>
      </div>
      <div id="t10-no" class="ex-note">← Rennen mit Ergebnis wählen</div>
    </div>
  </div>

  <!-- Fahrer Champion -->
  <div class="ex-card">
    <div class="ex-head"><span class="ex-icon">👑</span>
      <div><div class="ex-title">Fahrer Champion</div><div class="text-muted" style="font-size:.72rem">WM-Sieger mit großem Foto</div></div>
    </div>
    <div class="ex-body">
      <div class="ex-btns">
        <button class="btn btn-primary btn-sm" onclick="preview('champion_driver','wec')">👁 Vorschau</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('champion_driver','wec')">⬇ WEC</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('champion_driver','instagram')">📱 Instagram</button>
      </div>
      <div class="prev-wrap" id="pv-champion_driver" onclick="preview('champion_driver','wec')">
        <span class="loading-txt">Klicken für Vorschau</span>
      </div>
    </div>
  </div>

  <!-- Team Champion -->
  <div class="ex-card">
    <div class="ex-head"><span class="ex-icon">🏆</span>
      <div><div class="ex-title">Team Champion</div><div class="text-muted" style="font-size:.72rem">Konstrukteurs-Meister mit Logo</div></div>
    </div>
    <div class="ex-body">
      <div class="ex-btns">
        <button class="btn btn-primary btn-sm" onclick="preview('champion_team','wec')">👁 Vorschau</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('champion_team','wec')">⬇ WEC</button>
        <button class="btn btn-secondary btn-sm" onclick="dl('champion_team','instagram')">📱 Instagram</button>
      </div>
      <div class="prev-wrap" id="pv-champion_team" onclick="preview('champion_team','wec')">
        <span class="loading-txt">Klicken für Vorschau</span>
      </div>
    </div>
  </div>

</div>

<!-- Modal -->
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal('modal')">
  <div class="modal" style="max-width:1000px;max-height:92vh;overflow:auto">
    <div class="modal-header">
      <h3 id="modal-title">Vorschau</h3>
      <button class="close-btn" onclick="closeModal('modal')">✕</button>
    </div>
    <div class="modal-body" style="padding:12px;text-align:center">
      <div id="modal-loading" style="padding:40px;color:var(--text2);font-size:.9rem">⏳ Grafik wird gerendert...</div>
      <img id="modal-img" src="" style="display:none;max-width:100%;border-radius:4px"/>
      <div id="modal-btns" style="display:none;margin-top:14px;display:none;gap:10px;justify-content:center">
        <a id="modal-dl" href="#" class="btn btn-primary">⬇ PNG herunterladen</a>
        <a id="modal-dl-ig" href="#" class="btn btn-secondary">📱 Instagram herunterladen</a>
        <button class="btn btn-secondary" onclick="closeModal('modal')">Schließen</button>
      </div>
    </div>
  </div>
</div>

<div class="notice notice-info mt-3" style="margin-top:20px">
  💡 Farben, Logo und Liganame werden automatisch aus deinen <a href="<?= SITE_URL ?>/admin/design.php" style="color:var(--primary)">Design-Einstellungen</a> übernommen.
</div>

<script>
const BASE = '<?= SITE_URL ?>/exports/generate.php';
let cSeason = <?= $sid ?>;
let cRace = null, cResultId = null, cHasQuali = false, cHasResult = false;

function upd() {
    cSeason = document.getElementById('s-season').value;
    const opt = document.getElementById('s-race').selectedOptions[0];
    cRace       = opt?.value || null;
    cResultId   = opt?.dataset.rid || null;
    cHasQuali   = opt?.dataset.hq === '1';
    cHasResult  = opt?.dataset.hr === '1';

    // Show/hide Quali
    document.getElementById('q-ok').style.display = (cRace && cHasQuali) ? 'block' : 'none';
    document.getElementById('q-no').style.display = (cRace && cHasQuali) ? 'none' : 'block';
    document.getElementById('q-no').textContent = !cRace ? '← Rennen wählen' : '⚠ Kein Qualifying für dieses Rennen';

    // Show/hide Race
    document.getElementById('r-ok').style.display = (cRace && cHasResult) ? 'block' : 'none';
    document.getElementById('r-no').style.display = (cRace && cHasResult) ? 'none' : 'block';
    document.getElementById('r-no').textContent = !cRace ? '← Rennen wählen' : '⚠ Kein Ergebnis für dieses Rennen';

    // Top 10
    document.getElementById('t10-ok').style.display = (cRace && cHasResult) ? 'block' : 'none';
    document.getElementById('t10-no').style.display = (cRace && cHasResult) ? 'none' : 'block';
    document.getElementById('t10-no').textContent = !cRace ? '← Rennen wählen' : '⚠ Kein Ergebnis für dieses Rennen';
}

function getUrl(type, fmt, download=false) {
    const needsRace = ['quali','race'].includes(type);
    const id = needsRace ? (type==='race' ? cResultId : cRace) : cSeason;
    if (!id) return null;
    // Cache-Busting fuer Vorschau: Timestamp verhindert dass der Browser
    // ein altes Bild aus dem Cache zeigt nach Datenänderungen
    const bust = download ? '' : '&_t=' + Date.now();
    return `${BASE}?type=${type}&id=${id}&format=${fmt}` + (download ? '&download=1' : '') + bust;
}

function preview(type, fmt) {
    const url = getUrl(type, fmt);
    if (!url) { alert('Bitte zuerst Saison/Rennen wählen!'); return; }

    // Update inline preview
    const pv = document.getElementById('pv-' + type);
    if (pv) {
        pv.innerHTML = '<span class="loading-txt">⏳ Wird gerendert...</span>';
        const img = new Image();
        img.onload = () => { pv.innerHTML = ''; pv.appendChild(img); };
        img.onerror = () => { pv.innerHTML = '<span class="loading-txt" style="color:var(--secondary)">❌ Fehler</span>'; };
        img.src = url;
        img.style.cssText = 'width:100%;display:block';
    }

    // Also show in modal
    const modal = document.getElementById('modal');
    const mImg  = document.getElementById('modal-img');
    const mLoad = document.getElementById('modal-loading');
    const mBtns = document.getElementById('modal-btns');
    const labels = {calendar:'Kalender',lineup:'Lineup',quali:'Qualifying',race:'Rennergebnis',standings_driver:'Fahrerwertung',standings_team:'Teamwertung',race_top10:'Race Top 10',champion_driver:'Fahrer Champion',champion_team:'Team Champion'};
    document.getElementById('modal-title').textContent = (labels[type]||type) + ' · ' + (fmt==='instagram'?'Instagram 1080×1080':'WEC 1200px');
    mImg.style.display = 'none';
    mLoad.style.display = 'block';
    mBtns.style.display = 'none';
    document.getElementById('modal-dl').href    = getUrl(type,fmt,true)||'#';
    document.getElementById('modal-dl-ig').href = getUrl(type,'instagram',true)||'#';
    modal.classList.add('open');

    const tmp = new Image();
    tmp.onload = () => {
        mLoad.style.display = 'none';
        mImg.src = url;
        mImg.style.display = 'block';
        mBtns.style.display = 'flex';
    };
    tmp.onerror = () => {
        // Lade die Fehlerdetails per fetch
        fetch(url.replace('&download=1',''))
            .then(r => r.text())
            .then(txt => {
                mLoad.innerHTML = '<div style="color:#ff8080;text-align:left;white-space:pre-wrap;font-family:monospace;font-size:.8rem;padding:8px">❌ Render-Fehler:<br/>' + txt.replace(/</g,'&lt;') + '</div>';
            })
            .catch(() => { mLoad.textContent = '❌ Render-Fehler – URL prüfen'; });
    };
    tmp.src = url;
}

function dl(type, fmt) {
    const url = getUrl(type, fmt, true);
    if (!url) { alert('Bitte zuerst Saison/Rennen wählen!'); return; }
    window.location.href = url;
}

upd();
</script>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
