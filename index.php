<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$pageTitle   = getSetting('league_name');
$currentPage = 'home';
$db = getDB();
$activeSeason = $db->query("SELECT * FROM seasons WHERE is_active=1 LIMIT 1")->fetch();
$sid = $activeSeason['id'] ?? 0;

// ---- Alle Stats strikt auf aktive Saison begrenzt (via season_entries) ----
if ($sid) {
    $q = $db->prepare("SELECT COUNT(*) FROM season_entries WHERE season_id=? AND is_reserve=0");
    $q->execute([$sid]); $cDrivers = (int)$q->fetchColumn();

    $q = $db->prepare("SELECT COUNT(*) FROM teams WHERE season_id=?");
    $q->execute([$sid]); $cTeams = (int)$q->fetchColumn();

    $q = $db->prepare("SELECT COUNT(*) FROM results r INNER JOIN races rc ON rc.id=r.race_id AND rc.season_id=?");
    $q->execute([$sid]); $cResults = (int)$q->fetchColumn();

    $q = $db->prepare("SELECT COUNT(*) FROM races WHERE season_id=?");
    $q->execute([$sid]); $cRounds = (int)$q->fetchColumn();

    $stats = ['drivers'=>$cDrivers, 'teams'=>$cTeams, 'results'=>$cResults, 'rounds'=>$cRounds];
} else {
    $stats = ['drivers'=>0, 'teams'=>0, 'results'=>0, 'rounds'=>0];
}
$news = $db->query("SELECT * FROM news WHERE published=1 ORDER BY created_at DESC LIMIT 3")->fetchAll();
$nextRace = null;
if ($activeSeason) {
    $stmt=$db->prepare("SELECT rc.*,s.name AS season_name FROM races rc JOIN seasons s ON s.id=rc.season_id WHERE rc.season_id=? AND rc.race_date>=CURDATE() ORDER BY rc.race_date ASC LIMIT 1");
    $stmt->execute([$activeSeason['id']]); $nextRace=$stmt->fetch();
}
$topDrivers=[];$chartLabels=[];$chartData=[];$chartColors=[];$progression=[];$raceLabels=[];
if ($activeSeason) {
    // Reserve + Bonus Settings
    $reserveScoresDriver = getSetting('reserve_scores_driver','1') === '1';
    $reserveFilterIndex  = $reserveScoresDriver ? '' : 'AND se.is_reserve = 0';
    $idxBonusSql         = buildBonusSql('re');

    $idxSql = "
        SELECT d.id, d.name, t.name AS team_name, t.color,
               COALESCE(SUM({$idxBonusSql}), 0) AS total_pts
        FROM season_entries se
        JOIN drivers d ON d.id = se.driver_id
        LEFT JOIN teams t ON t.id = se.team_id
        LEFT JOIN result_entries re
              ON re.driver_id = d.id
             AND re.result_id IN (
                 SELECT r.id FROM results r
                 INNER JOIN races rc ON rc.id = r.race_id AND rc.season_id = :sid2
             )
        WHERE se.season_id = :sid
        {$reserveFilterIndex}
        GROUP BY d.id, d.name, t.name, t.color
        ORDER BY total_pts DESC
        LIMIT 5
    ";
    $stmt=$db->prepare($idxSql);
    $stmt->execute([':sid'=>$activeSeason['id'],':sid2'=>$activeSeason['id']]);
    $topDrivers=$stmt->fetchAll();
    $chartLabels=array_map(fn($d)=>explode(' ',$d['name'])[0],$topDrivers);
    $chartData=array_map(fn($d)=>(float)$d['total_pts'],$topDrivers);
    $chartColors=array_map(fn($d)=>$d['color']??'#e8333a',$topDrivers);
    $ro=$db->prepare("SELECT rc.id,rc.track_name,rc.location FROM races rc WHERE rc.season_id=? ORDER BY rc.round ASC"); $ro->execute([$activeSeason['id']]); $allRaces=$ro->fetchAll();
    $raceLabels=array_map(fn($r)=>$r['location']?mb_substr($r['location'],0,10):mb_substr($r['track_name'],0,8),$allRaces);
    foreach(array_slice(array_column($topDrivers,'id'),0,3) as $did){
        $cumPts=0;$line=[];
        foreach($allRaces as $race){$s2=$db->prepare("SELECT COALESCE(SUM(re.points),0) FROM result_entries re JOIN results r ON r.id=re.result_id INNER JOIN races rc ON rc.id=r.race_id AND rc.season_id=? WHERE r.race_id=? AND re.driver_id=?");$s2->execute([$activeSeason['id'],$race['id'],$did]);$cumPts+=(float)$s2->fetchColumn();$line[]=$cumPts;}
        $dr=array_values(array_filter($topDrivers,fn($d)=>$d['id']==$did))[0]??null;
        if($dr)$progression[]=['name'=>explode(' ',$dr['name'])[0],'color'=>$dr['color']??'#e8333a','data'=>$line];
    }
}
require_once __DIR__ . '/includes/header.php';
?>
<div class="hero">
  <div class="hero-bg"></div><div class="hero-stripes"></div><div class="hero-glow"></div>
  <div class="container hero-content">
    <h1><?= h(getSetting('league_name')) ?><em>Willkommen</em></h1>
    <p class="hero-sub"><?= h(getSetting('league_desc')) ?></p>
    <?php
    // Social links aus Settings holen
    $socialLinks  = json_decode(getSetting('social_links','[]'), true) ?: [];
    $twitchLink   = '';
    $twitchChannel= '';
    $discordLink  = '';
    foreach($socialLinks as $sl) {
        if($sl['platform']==='twitch'  && !$twitchLink) {
            $twitchLink = $sl['url'];
            // Kanal aus URL extrahieren: twitch.tv/KANAL
            if(preg_match('~twitch\.tv/([a-zA-Z0-9_]+)~', $sl['url'], $m)) {
                $twitchChannel = $m[1];
            }
        }
        if($sl['platform']==='discord' && !$discordLink) $discordLink = $sl['url'];
    }
    ?>
    <div class="hero-widgets">
      <div class="hero-two-col">

        <!-- Linke Spalte: Countdown + Twitch Player -->
        <div class="hero-left">
          <?php if($nextRace): ?>
          <div class="countdown-box">
            <div class="countdown-label">🏁 Nächstes Rennen: <a href="<?= SITE_URL ?>/track.php?id=<?= $nextRace['track_id'] ?>"><strong><?= h($nextRace['track_name']) ?></strong></a> · <?= date('d.m.Y',strtotime($nextRace['race_date'])) ?><?= $nextRace['race_time']?' · '.substr($nextRace['race_time'],0,5).' Uhr':'' ?></div>
            <div class="countdown-timer" id="countdown">
              <div class="countdown-unit"><span id="cd-d">00</span><span>Tage</span></div>
              <div class="countdown-sep">:</div>
              <div class="countdown-unit"><span id="cd-h">00</span><span>Std</span></div>
              <div class="countdown-sep">:</div>
              <div class="countdown-unit"><span id="cd-m">00</span><span>Min</span></div>
              <div class="countdown-sep">:</div>
              <div class="countdown-unit"><span id="cd-s">00</span><span>Sek</span></div>
            </div>
          </div>
          <?php endif; ?>
          <?php if($twitchChannel): ?>
          <div class="twitch-embed-wrap">
            <div class="twitch-embed-header">
              <div style="display:flex;align-items:center;gap:8px">
                <svg viewBox="0 0 24 24" fill="#9147ff" width="14" height="14"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714z"/></svg>
                <span style="font-weight:700;font-size:.8rem"><?= h($twitchChannel) ?></span>
                <span class="twitch-live-badge" id="twitch-live-badge" style="display:none">LIVE</span>
              </div>
              <a href="<?= h($twitchLink) ?>" target="_blank" rel="noopener" style="font-size:.72rem;color:var(--text2)">Vollbild →</a>
            </div>
            <div class="twitch-embed-player">
              <div id="twitch-offline" class="twitch-offline">
                <svg viewBox="0 0 24 24" fill="currentColor" width="28" height="28" style="opacity:.25"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714z"/></svg>
                <div style="font-size:.78rem;color:var(--text2);margin-top:6px"><?= h($twitchChannel) ?> ist offline</div>
              </div>
              <div id="twitch-embed"></div>
            </div>
          </div>
          <script src="https://embed.twitch.tv/embed/v1.js"></script>
          <script>
          (function() {
              var channel = <?= json_encode($twitchChannel) ?>;
              var embed = new Twitch.Embed("twitch-embed", {
                  width: "100%", height: "100%",
                  channel: channel, layout: "video",
                  autoplay: false, muted: true, theme: "dark",
                  parent: [window.location.hostname]
              });
              embed.addEventListener(Twitch.Embed.VIDEO_READY, function() {
                  var player = embed.getPlayer();
                  player.addEventListener(Twitch.Player.ONLINE, function() {
                      document.getElementById('twitch-offline').style.display = 'none';
                      document.getElementById('twitch-embed').style.display   = 'block';
                      var b = document.getElementById('twitch-live-badge');
                      if (b) b.style.display = 'inline-block';
                  });
                  player.addEventListener(Twitch.Player.OFFLINE, function() {
                      document.getElementById('twitch-offline').style.display = 'flex';
                      document.getElementById('twitch-embed').style.display   = 'none';
                  });
              });
          })();
          </script>
          <?php endif; ?>
        </div>

        <!-- Rechte Spalte: Discord + Twitch Button -->
        <div class="hero-right">
          <?php if($discordLink): ?>
          <a href="<?= h($discordLink) ?>" target="_blank" rel="noopener" class="social-widget-box discord-box hero-side-btn">
            <div class="sw-icon">
              <svg viewBox="0 0 24 24" fill="currentColor" width="28" height="28"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.043.033.055a19.9 19.9 0 0 0 5.993 3.03.077.077 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>
            </div>
            <div class="sw-text">
              <div class="sw-title">Discord Community</div>
              <div class="sw-sub">Jetzt beitreten →</div>
            </div>
          </a>
          <?php endif; ?>
          <?php if($twitchLink): ?>
          <a href="<?= h($twitchLink) ?>" target="_blank" rel="noopener" class="social-widget-box twitch-box hero-side-btn">
            <div class="sw-icon">
              <svg viewBox="0 0 24 24" fill="currentColor" width="28" height="28"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714z"/></svg>
            </div>
            <div class="sw-text">
              <div class="sw-title">Live auf Twitch</div>
              <div class="sw-sub">Jetzt zuschauen →</div>
            </div>
            <div class="sw-live-dot"></div>
          </a>
          <?php endif; ?>
        </div>

      </div>
    </div>
    <div class="hero-actions mt-2">
      <a href="<?= SITE_URL ?>/standings.php" class="btn btn-primary btn-lg">📊 Wertung</a>
      <a href="<?= SITE_URL ?>/calendar.php" class="btn btn-secondary btn-lg">📅 Kalender</a>
    </div>
  </div>
</div>
<style>
.hero-widgets{margin-bottom:20px}
.countdown-box{background:rgba(0,0,0,.45);border:1px solid var(--primary-subtle);border-radius:6px;padding:14px 20px;backdrop-filter:blur(8px)}
.countdown-label{font-size:.8rem;color:var(--text2);margin-bottom:10px}
.countdown-timer{display:flex;align-items:center;gap:6px}
.countdown-unit{display:flex;flex-direction:column;align-items:center;min-width:52px}
.countdown-unit>span:first-child{font-family:var(--font-display);font-size:2rem;font-weight:900;color:var(--primary);line-height:1}
.countdown-unit>span:last-child{font-size:.6rem;color:var(--text2);text-transform:uppercase;letter-spacing:.1em;margin-top:2px}
.countdown-sep{font-family:var(--font-display);font-size:1.8rem;font-weight:900;color:var(--primary);margin-bottom:14px}
.social-widgets{display:flex;flex-direction:column;gap:10px}
.social-widget-box{display:flex;align-items:center;gap:12px;padding:12px 18px;border-radius:6px;text-decoration:none;transition:filter .2s,transform .2s;position:relative;min-width:220px}
.social-widget-box:hover{filter:brightness(1.12);transform:translateY(-1px);text-decoration:none}
.twitch-box{background:rgba(145,70,255,.2);border:1px solid rgba(145,70,255,.45);color:#bf94ff}
.discord-box{background:rgba(88,101,242,.2);border:1px solid rgba(88,101,242,.45);color:#8ea1e1}
.sw-icon{flex-shrink:0;display:flex;align-items:center}
.sw-text{flex:1}
.sw-title{font-family:var(--font-display);font-weight:700;font-size:.95rem;letter-spacing:.04em}
.sw-sub{font-size:.72rem;opacity:.75;margin-top:1px}
.sw-live-dot{width:8px;height:8px;border-radius:50%;background:#f00;box-shadow:0 0 6px #f006;animation:livepulse 1.4s ease-in-out infinite}
@keyframes livepulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.3)}}
@media(max-width:640px){.hero-widgets{flex-direction:column}.social-widgets{flex-direction:row;flex-wrap:wrap}}
</style>
<div class="container section">
  <?php if($activeSeason): ?>
  <div class="flex flex-center gap-2 mb-2" style="flex-wrap:wrap">
    <span class="badge badge-primary" style="font-size:.78rem;padding:5px 14px">🏆 Aktive Saison: <?= h($activeSeason['name']) ?><?= $activeSeason['year'] ? ' '.$activeSeason['year'] : '' ?></span>
    <?php if($activeSeason['game']): ?><span class="badge badge-muted" style="font-size:.75rem">🎮 <?= h($activeSeason['game']) ?></span><?php endif; ?>
    <?php if($activeSeason['car_class']??''): ?><span class="badge badge-muted" style="font-size:.75rem">🚗 <?= h($activeSeason['car_class']) ?></span><?php endif; ?>
  </div>
  <?php else: ?>
  <div class="alert alert-info mb-3">ℹ️ Keine aktive Saison gesetzt. Alle Zahlen zeigen 0. <a href="<?= SITE_URL ?>/admin/seasons.php" style="color:var(--tertiary)">→ Im Admin aktivieren</a></div>
  <?php endif; ?>
  <div class="grid-4 mb-4">
    <?php
    $seasonLabel = $activeSeason ? h($activeSeason['name']).' '.h($activeSeason['year']??'') : '–';
    foreach([
      ['num'=>$stats['drivers'], 'lbl'=>'Fahrer',        'icon'=>'🏎'],
      ['num'=>$stats['teams'],   'lbl'=>'Teams',          'icon'=>'🚗'],
      ['num'=>$stats['results'], 'lbl'=>'Rennergebnisse', 'icon'=>'🏁'],
      ['num'=>$stats['rounds'],  'lbl'=>'Runden geplant', 'icon'=>'📅'],
    ] as $st): ?>
    <div class="card"><div class="stat-box">
      <div style="font-size:1.5rem;margin-bottom:4px"><?= $st['icon'] ?></div>
      <div class="stat-number"><?= $st['num'] ?></div>
      <div class="stat-label"><?= $st['lbl'] ?></div>
    </div></div>
    <?php endforeach; ?>
  </div>

  <div class="grid-2 mb-4" style="gap:24px">
    <div>
      <div class="section-title">Aktuelle <span>News</span></div>
      <div class="section-sub">Neuigkeiten</div>
      <?php if($news): ?>
      <div style="display:flex;flex-direction:column;gap:14px">
        <?php foreach($news as $n): ?>
        <a href="<?= SITE_URL ?>/news.php?slug=<?= h($n['slug']) ?>" style="text-decoration:none">
        <div class="card news-card" style="display:flex;flex-direction:row">
          <div class="news-card-img" style="width:110px;min-width:110px;height:auto"><?php if($n['image_path']): ?><img src="<?= h($n['image_path']) ?>" alt=""/><?php else: ?>📰<?php endif; ?><span class="news-tag"><?= h($n['category']) ?></span></div>
          <div class="news-card-body"><div class="news-date"><?= date('d.m.Y',strtotime($n['created_at'])) ?></div><div class="news-card-title"><?= h($n['title']) ?></div><div class="news-excerpt" style="font-size:.82rem"><?= h(mb_substr($n['excerpt']?:strip_tags($n['content']),0,90)) ?>…</div></div>
        </div></a>
        <?php endforeach; ?>
      </div>
      <a href="<?= SITE_URL ?>/news.php" class="btn btn-secondary btn-sm mt-2">Alle News →</a>
      <?php else: ?><div class="card"><div class="card-body text-muted">Noch keine News.</div></div><?php endif; ?>
    </div>
    <div>
      <div class="section-title">Meisterschaft <span>Wertung</span></div>
      <div class="section-sub"><?= $activeSeason ? h($activeSeason['name']).' '.h($activeSeason['year']??'') : 'Keine aktive Saison' ?></div>
      <?php if($topDrivers): ?>
      <div class="card mb-3"><div class="card-body"><canvas id="standings-bar" height="160"></canvas></div></div>
      <?php foreach($topDrivers as $i=>$d): ?>
      <a href="<?= SITE_URL ?>/driver.php?id=<?= $d['id'] ?>" style="text-decoration:none">
      <div class="race-item" style="margin-bottom:8px;cursor:pointer">
        <span class="pos-col <?= $i===0?'pos-1':($i===1?'pos-2':($i===2?'pos-3':'')) ?>" style="font-size:1.4rem;min-width:32px"><?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':($i+1).'.')) ?></span>
        <div class="flex-1"><div class="font-display font-bold"><?= h($d['name']) ?></div>
          <div class="text-muted" style="font-size:.8rem"><span class="team-dot" style="background:<?= h($d['color']??'#666') ?>"></span><?= h($d['team_name']??'') ?></div></div>
        <div class="pts-col"><?= number_format((float)$d['total_pts'],1) ?></div>
      </div></a>
      <?php endforeach; ?>
      <a href="<?= SITE_URL ?>/standings.php" class="btn btn-secondary btn-sm mt-2">Gesamtwertung →</a>
      <?php else: ?><div class="text-muted">Noch keine Ergebnisse.</div><?php endif; ?>
    </div>
  </div>
  <?php if(!empty($progression)&&count($allRaces??[])>1): ?>
  <div class="card mb-4">
    <div class="card-header"><h3>📈 Meisterschaftsverlauf</h3></div>
    <div class="card-body"><canvas id="prog-chart" height="100"></canvas></div>
  </div>
  <?php endif; ?>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
const barCtx=document.getElementById('standings-bar');
if(barCtx)new Chart(barCtx,{type:'bar',data:{labels:<?= json_encode($chartLabels) ?>,datasets:[{data:<?= json_encode($chartData) ?>,backgroundColor:<?= json_encode(array_map(fn($c)=>$c.'cc',$chartColors)) ?>,borderColor:<?= json_encode($chartColors) ?>,borderWidth:2,borderRadius:3}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#8888a0',font:{family:'Barlow Condensed',weight:'700'}},grid:{color:'#2a2a3a'}},y:{ticks:{color:'#8888a0'},grid:{color:'#2a2a3a'},beginAtZero:true}}}});
const progCtx=document.getElementById('prog-chart');
<?php if(!empty($progression)): ?>
if(progCtx)new Chart(progCtx,{type:'line',data:{labels:<?= json_encode($raceLabels) ?>,datasets:<?= json_encode(array_map(fn($p)=>['label'=>$p['name'],'data'=>$p['data'],'borderColor'=>$p['color'],'backgroundColor'=>$p['color'].'22','borderWidth'=>2,'pointRadius'=>4,'tension'=>0.3],$progression)) ?>},options:{responsive:true,interaction:{mode:'index',intersect:false},plugins:{legend:{labels:{color:'#f0f0f5',font:{family:'Barlow Condensed',size:13}}}},scales:{x:{ticks:{color:'#8888a0',font:{family:'Barlow Condensed',weight:'700'}},grid:{color:'#2a2a3a'}},y:{ticks:{color:'#8888a0'},grid:{color:'#2a2a3a'},beginAtZero:true}}}});
<?php endif; ?>
<?php if($nextRace&&$nextRace['race_date']): ?>
const rt=new Date("<?= $nextRace['race_date'] ?><?= $nextRace['race_time']?' '.$nextRace['race_time']:' 20:00:00' ?>").getTime();
function cd(){const diff=rt-Date.now();if(diff<=0){document.getElementById('countdown').innerHTML='<span style="color:var(--secondary);font-family:var(--font-display);font-size:1.5rem;font-weight:900">🏁 Rennen läuft!</span>';return;}const d=Math.floor(diff/86400000),h=Math.floor((diff%86400000)/3600000),m=Math.floor((diff%3600000)/60000),s=Math.floor((diff%60000)/1000);['d','h','m','s'].forEach((k,i)=>{const el=document.getElementById('cd-'+k);if(el)el.textContent=String([d,h,m,s][i]).padStart(2,'0');});}cd();setInterval(cd,1000);
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
