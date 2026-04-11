<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';

// Fallback falls discordSiteUrl() nicht in config.php vorhanden
if (!function_exists('discordSiteUrl')) {
    function discordSiteUrl(): string {
        if (defined('SITE_URL') && SITE_URL !== '') return rtrim(SITE_URL, '/');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}

$type   = $_GET['type']   ?? '';
$id     = (int)($_GET['id'] ?? 0);
$format = in_array($_GET['format']??'', ['wec','instagram']) ? $_GET['format'] : 'wec';
$dl     = isset($_GET['download']);

$allowed = ['calendar','lineup','quali','race','standings_driver','standings_team','race_top10','champion_driver','champion_team'];
if (!in_array($type, $allowed)) { http_response_code(400); die('Invalid type'); }

$db  = getDB();
$cfg = [
    'league'    => getSetting('league_name','SimRace Liga'),
    'abbr'      => getSetting('league_abbr','SRL'),
    'logo'      => getSetting('league_logo',''),
    'primary'   => getSetting('color_primary','#e8333a'),
    'secondary' => getSetting('color_secondary','#f5a623'),
];

$isInsta = ($format === 'instagram');
$W = $isInsta ? 1080 : 1200;
$H = $isInsta ? 1080 : 0;

$html = buildHtml($type, $id, $isInsta, $W, $db, $cfg);

$tmpHtml = sys_get_temp_dir() . '/srl_' . uniqid() . '.html';
$tmpPng  = sys_get_temp_dir() . '/srl_' . uniqid() . '.png';

if (file_put_contents($tmpHtml, $html) === false) {
    http_response_code(500);
    die('Fehler: HTML-Datei konnte nicht in ' . sys_get_temp_dir() . ' geschrieben werden.');
}

// Vollstaendigen Pfad finden
$bin = file_exists('/usr/bin/wkhtmltoimage')       ? '/usr/bin/wkhtmltoimage'
     : (file_exists('/usr/local/bin/wkhtmltoimage') ? '/usr/local/bin/wkhtmltoimage' : '');
if (!$bin) {
    @unlink($tmpHtml);
    http_response_code(500);
    die('wkhtmltoimage nicht gefunden. Bitte unter /usr/bin oder /usr/local/bin installieren.');
}

if (in_array($type,['race_top10','champion_driver','champion_team'])) { $H=1080; }
$opts = "--width {$W} --enable-local-file-access --quality 95 --no-stop-slow-scripts --log-level none";
if ($H > 0) $opts .= " --height {$H} --crop-h {$H}";
$cmd = escapeshellarg($bin) . " $opts " . escapeshellarg($tmpHtml) . " " . escapeshellarg($tmpPng) . " 2>&1";
exec($cmd, $cmdOut, $ret);

if (!file_exists($tmpPng) || filesize($tmpPng) < 500) {
    @unlink($tmpHtml);
    http_response_code(500);
    $errDetail = implode("\n", $cmdOut);
    die("Render fehlgeschlagen (Exit-Code: {$ret})\n\nKommando-Output:\n{$errDetail}\n\nHTML-Laenge: " . strlen($html) . " Bytes\nBinary: {$bin}");
}

$fn = "srl_{$type}_{$format}_" . date('Ymd_His') . ".png";
header('Content-Type: image/png');
if ($dl) header('Content-Disposition: attachment; filename="'.$fn.'"');
readfile($tmpPng);
@unlink($tmpHtml); @unlink($tmpPng);
exit;

// ====================================================================
function buildHtml(string $type, int $id, bool $ig, int $W, PDO $db, array $c): string {
    switch($type) {
        case 'calendar':         return tplCalendar($id,$ig,$W,$db,$c);
        case 'lineup':           return tplLineup($id,$ig,$W,$db,$c);
        case 'quali':            return tplQuali($id,$ig,$W,$db,$c);
        case 'race':             return tplRace($id,$ig,$W,$db,$c);
        case 'standings_driver': return tplDriver($id,$ig,$W,$db,$c);
        case 'standings_team':   return tplTeam($id,$ig,$W,$db,$c);
        case 'race_top10':       return tplTop10($id,$ig,$W,$db,$c);
        case 'champion_driver':  return tplChampionDriver($id,$ig,$W,$db,$c);
        case 'champion_team':    return tplChampionTeam($id,$ig,$W,$db,$c);
    }
    return '';
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

function css(array $c, bool $ig, int $W): string {
    $p=$c['primary']; $s=$c['secondary']; $sc=$ig?.88:1.;
    $mh=$ig?'min-height:1080px;':'';
    $cf=$ig?'display:flex;flex-direction:column;justify-content:space-between;min-height:1080px':'';
    return "
*{box-sizing:border-box;margin:0;padding:0}
body{width:{$W}px;font-family:'DejaVu Sans Condensed','FreeSans','Arial Narrow',Arial,sans-serif;background:#080c1e;color:#fff}
.w{width:{$W}px;{$mh}position:relative;overflow:hidden}
.bg{position:absolute;inset:0;background:linear-gradient(140deg,#06091a 0%,#0d1633 45%,#060d22 100%)}
.st{position:absolute;inset:0;background:repeating-linear-gradient(-55deg,transparent,transparent 80px,rgba(255,255,255,.017) 80px,rgba(255,255,255,.017) 81px)}
.g1{position:absolute;top:-150px;left:-100px;width:700px;height:580px;background:radial-gradient(ellipse,rgba(15,70,210,.26) 0%,transparent 65%)}
.g2{position:absolute;bottom:-80px;right:0;width:500px;height:380px;background:radial-gradient(ellipse,rgba(232,51,58,.1) 0%,transparent 65%)}
.sb{position:absolute;left:0;top:0;bottom:0;width:10px;background:linear-gradient(to bottom,{$p},{$s})}
.c{padding:" . ($ig?'52px':'50px') . " 52px 44px 70px;position:relative;z-index:2;{$cf}}
.lg{font-size:" . round(11*$sc) . "px;font-weight:700;letter-spacing:.22em;text-transform:uppercase;color:rgba(255,255,255,.38);margin-bottom:4px}
.t1{font-size:" . round(76*$sc) . "px;font-weight:900;font-style:italic;text-transform:uppercase;line-height:.82;letter-spacing:-.02em;color:#fff}
.t2{font-size:" . round(76*$sc) . "px;font-weight:900;font-style:italic;text-transform:uppercase;line-height:.82;letter-spacing:-.02em;color:{$p}}
.ab{font-size:" . round(40*$sc) . "px;font-weight:900;font-style:italic;color:{$p};letter-spacing:.04em;line-height:1}
.as{font-size:10px;color:rgba(255,255,255,.28);letter-spacing:.16em;text-transform:uppercase;margin-top:5px}
.pl{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);border-radius:3px;padding:6px 14px;margin-bottom:18px;font-size:" . round(11*$sc) . "px;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.5);font-weight:700}
.bx{background:rgba(8,13,32,.55);border:1px solid rgba(255,255,255,.08);border-radius:5px;overflow:hidden;margin-top:4px}
.th-row{display:flex;align-items:center;background:rgba(255,255,255,.055);border-bottom:2px solid {$p};padding:" . round(9*$sc) . "px 18px;gap:12px}
.th{font-size:10px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.36)}
.tr{display:flex;align-items:center;padding:" . round(12*$sc) . "px 18px;gap:12px;border-bottom:1px solid rgba(255,255,255,.05)}
.tr:nth-child(even){background:rgba(255,255,255,.022)}
.tr:last-child{border-bottom:none}
.p0{font-size:" . round(22*$sc) . "px;font-weight:900;font-style:italic;color:rgba(255,255,255,.18);min-width:38px}
.p1{font-size:" . round(22*$sc) . "px;font-weight:900;font-style:italic;color:#f5c842;min-width:38px}
.p2{font-size:" . round(22*$sc) . "px;font-weight:900;font-style:italic;color:#bdbdbd;min-width:38px}
.p3{font-size:" . round(22*$sc) . "px;font-weight:900;font-style:italic;color:#cd7f32;min-width:38px}
.nm{font-size:" . round(16*$sc) . "px;font-weight:700}
.sb2{font-size:" . round(11*$sc) . "px;color:rgba(255,255,255,.36);margin-top:2px}
.pts{font-size:" . round(20*$sc) . "px;font-weight:900;font-style:italic;color:{$p}}
.mn{font-family:monospace;font-size:" . round(14*$sc) . "px;color:{$s}}
.gp{font-size:" . round(12*$sc) . "px;color:rgba(255,255,255,.38)}
.dt{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:7px;vertical-align:middle;flex-shrink:0}
.bd{background:{$p};color:#fff;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:2px 8px;border-radius:3px;white-space:nowrap;margin-left:6px}
.fl{background:rgba(245,166,35,.18);color:{$s};font-size:10px;font-weight:700;padding:2px 7px;border-radius:2px;letter-spacing:.06em;margin-left:6px}
.rn{font-size:" . round(25*$sc) . "px;font-weight:900;font-style:italic;color:rgba(255,255,255,.13);min-width:60px}
.ft{display:flex;align-items:center;justify-content:space-between;margin-top:20px;padding-top:12px;border-top:1px solid rgba(255,255,255,.07);font-size:11px;color:rgba(255,255,255,.26);letter-spacing:.09em;text-transform:uppercase}
.ftg{background:rgba(232,51,58,.14);border:1px solid rgba(232,51,58,.28);color:{$p};padding:4px 13px;border-radius:3px;font-size:11px;letter-spacing:.1em;font-weight:700;text-transform:uppercase}
.hd{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:22px}
";
}

function wrap(string $body, string $css): string {
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'/><style>{$css}</style></head><body>{$body}</body></html>";
}

function hdr(array $c, string $line1, string $line2): string {
    $logo = $c['logo']
        ? "<img src='" . e($c['logo']) . "' style='max-height:52px;max-width:160px;object-fit:contain'/>"
        : "<div class='ab'>" . e($c['abbr']) . "</div><div class='as'>" . e($c['league']) . "</div>";
    return "<div class='hd'>
        <div><div class='lg'>" . e($c['league']) . "</div>
        <div class='t1'>{$line1}</div><div class='t2'>{$line2}</div></div>
        <div style='text-align:right;padding-top:4px'>{$logo}</div>
    </div>";
}

function ftr(array $c, string $tag=''): string {
    return "<div class='ft'>
        <span>" . e($c['league']) . "</span>
        " . ($tag ? "<div class='ftg'>" . e($tag) . "</div>" : '<span></span>') . "
        <span>v2.0 · Generated " . date('d.m.Y H:i') . "</span>
    </div>";
}

function getSeason(PDO $db, int $id): array|false {
    if ($id) { $s=$db->prepare("SELECT * FROM seasons WHERE id=?");$s->execute([$id]);return $s->fetch(); }
    return $db->query("SELECT * FROM seasons WHERE is_active=1 LIMIT 1")->fetch();
}

function pclass(int $pos): string { return $pos===1?'p1':($pos===2?'p2':($pos===3?'p3':'p0')); }

// ---- CALENDAR ----
function tplCalendar(int $sid, bool $ig, int $W, PDO $db, array $c): string {
    $sea=getSeason($db,$sid); if(!$sea) return '';
    $rs=$db->prepare("SELECT * FROM races WHERE season_id=? ORDER BY round ASC");$rs->execute([$sea['id']]);$rs=$rs->fetchAll();
    $rows='';
    foreach($rs as $r){
        $dt=$r['race_date']?strtoupper(date('d. M Y',strtotime($r['race_date']))):'TBD';
        $loc=$r['location']?($r['country']?e($r['location']).' · '.e($r['country']):e($r['location'])):'';
        $rows.="<div class='tr'>
            <div class='rn'>R{$r['round']}</div>
            <div style='flex:1;min-width:0'>
                <div class='nm'>".e($r['track_name'])."</div>
                ".($loc?"<div class='sb2'>{$loc}</div>":'')."
            </div>
            <div style='font-size:13px;font-weight:700;color:rgba(255,255,255,.48);white-space:nowrap'>{$dt}</div>
        </div>";
    }
    $meta=trim(($sea['game']??'').($sea['car_class']?' · '.$sea['car_class']:''));
    $body="<div class='w'><div class='bg'></div><div class='st'></div><div class='g1'></div><div class='g2'></div><div class='sb'></div>
    <div class='c'>
        ".hdr($c,e($sea['year']??''),'RENNKALENDER')."
        <div class='pl'>📅 &nbsp;".count($rs)." RENNEN".($meta?" &nbsp;·&nbsp; ".e($meta):'')."</div>
        <div class='bx'>
            <div class='th-row'><div class='th' style='min-width:60px'>RND</div><div class='th' style='flex:1'>STRECKE</div><div class='th' style='min-width:110px;text-align:right'>DATUM</div></div>
            {$rows}
        </div>
        ".ftr($c,e($sea['name']??''))."
    </div></div>";
    return wrap($body,css($c,$ig,$W));
}

// ---- LINEUP ----
function tplLineup(int $sid, bool $ig, int $W, PDO $db, array $c): string {
    $sea=getSeason($db,$sid); if(!$sea) return '';
    $ts=$db->prepare("SELECT * FROM teams WHERE season_id=? ORDER BY name");$ts->execute([$sea['id']]);$ts=$ts->fetchAll();
    $cols=$ig?1:2; $grid=$cols===2?"display:grid;grid-template-columns:1fr 1fr;gap:0 24px":"";
    $blocks='';
    foreach($ts as $t){
        $ds=$db->prepare("SELECT d.*,se.number,se.is_reserve FROM season_entries se JOIN drivers d ON d.id=se.driver_id WHERE se.season_id=? AND se.team_id=? ORDER BY se.is_reserve,se.number");
        $ds->execute([$sea['id'],$t['id']]);$ds=$ds->fetchAll();
        $dr='';
        foreach($ds as $d){
            $num=$d['number']?"<span style='color:".e($t['color']).";margin-right:5px;font-size:19px;font-weight:900;font-style:italic'>#".(int)$d['number']."</span>":'';
            $res=$d['is_reserve']?"<span style='font-size:9px;background:rgba(255,255,255,.1);color:rgba(255,255,255,.4);padding:1px 5px;border-radius:2px;margin-left:7px;letter-spacing:.07em'>RESERVE</span>":'';
            $dr.="<div class='tr'>{$num}<div class='nm' style='flex:1'>".e($d['name'])."{$res}</div>".($d['nationality']?"<div class='gp'>".e($d['nationality'])."</div>":'')."</div>";
        }
        $blocks.="<div style='margin-bottom:18px'>
            <div style='display:flex;align-items:center;gap:10px;padding:8px 14px;background:linear-gradient(90deg,".e($t['color'])."28,transparent);border-left:4px solid ".e($t['color']).";border-radius:3px 3px 0 0;'>
                <div style='width:12px;height:12px;border-radius:50%;background:".e($t['color']).";flex-shrink:0'></div>
                <div style='font-size:14px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;color:".e($t['color'])."'>".e($t['name'])."</div>
                ".($t['car']?"<div style='font-size:11px;color:rgba(255,255,255,.32);margin-left:auto'>".e($t['car'])."</div>":'')."
            </div>
            <div class='bx' style='border-top:none;border-radius:0 0 4px 4px'>{$dr}</div>
        </div>";
    }
    $body="<div class='w'><div class='bg'></div><div class='st'></div><div class='g1'></div><div class='sb'></div>
    <div class='c'>
        ".hdr($c,e($sea['year']??''),'LINEUP')."
        <div class='pl'>👥 &nbsp;".count($ts)." TEAMS &nbsp;·&nbsp; ".e($sea['name']??'')."</div>
        <div style='{$grid}'>{$blocks}</div>
        ".ftr($c,e($sea['name']??''))."
    </div></div>";
    return wrap($body,css($c,$ig,$W));
}

// ---- QUALIFYING ----
function tplQuali(int $raceId, bool $ig, int $W, PDO $db, array $c): string {
    $race=$db->prepare("SELECT rc.*,s.name AS sn,s.id AS sid FROM races rc JOIN seasons s ON s.id=rc.season_id WHERE rc.id=?");
    $race->execute([$raceId]);$race=$race->fetch(); if(!$race) return '';
    $en=$db->prepare("SELECT qr.*,d.name AS dn,t.name AS tn,t.color AS tc,se.number FROM qualifying_results qr LEFT JOIN drivers d ON d.id=qr.driver_id LEFT JOIN teams t ON t.id=qr.team_id LEFT JOIN season_entries se ON se.driver_id=qr.driver_id AND se.season_id=? WHERE qr.race_id=? ORDER BY qr.position ASC");
    $en->execute([$race['sid'],$raceId]);$en=$en->fetchAll();
    $rows='';
    foreach($en as $e){
        $pos=(int)$e['position']; $pc=pclass($pos);
        $pole=$pos===1?"<span class='bd'>POLE</span>":'';
        $num=$e['number']?"<span style='color:".e($c['primary']).";margin-right:5px;font-size:14px'>#".(int)$e['number']."</span>":'';
        $rows.="<div class='tr'>
            <div class='{$pc}'>{$pos}</div>
            <div style='flex:1;min-width:0'><div class='nm'>{$num}".e($e['dn']??$e['driver_name_raw']??'?')."{$pole}</div></div>
            <div style='display:flex;align-items:center;min-width:140px'><span class='dt' style='background:".e($e['tc']??'#666')."'></span><span style='font-size:12px;color:rgba(255,255,255,.46)'>".e($e['tn']??'–')."</span></div>
            <div class='mn' style='min-width:95px;text-align:right'>".e($e['lap_time']??'–')."</div>
            <div class='gp' style='min-width:75px;text-align:right'>".e($pos===1?'POLE':($e['gap']??''))."</div>
        </div>";
    }
    $dt=$race['race_date']?date('d.m.Y',strtotime($race['race_date'])):'TBD';
    $body="<div class='w'><div class='bg'></div><div class='st'></div><div class='g1'></div><div class='g2'></div><div class='sb'></div>
    <div class='c'>
        ".hdr($c,'QUALIFYING',e($race['track_name']))."
        <div class='pl'>⏱ &nbsp;RUNDE {$race['round']} &nbsp;·&nbsp; ".e($race['sn'])." &nbsp;·&nbsp; {$dt}</div>
        <div class='bx'>
            <div class='th-row'><div class='th' style='min-width:38px'>POS</div><div class='th' style='flex:1'>FAHRER</div><div class='th' style='min-width:140px'>TEAM</div><div class='th' style='min-width:95px;text-align:right'>BESTZEIT</div><div class='th' style='min-width:75px;text-align:right'>ABSTAND</div></div>
            {$rows}
        </div>
        ".ftr($c,"R{$race['round']} · ".e($race['track_name']))."
    </div></div>";
    return wrap($body,css($c,$ig,$W));
}


// ---- RACE ----
function tplRace(int $resultId, bool $ig, int $W, PDO $db, array $c): string {
    $res=$db->prepare("SELECT r.*,rc.track_name,rc.round,rc.race_date,rc.location,s.name AS sn,s.id AS sid,s.year AS sy FROM results r JOIN races rc ON rc.id=r.race_id JOIN seasons s ON s.id=rc.season_id WHERE r.id=?");
    $res->execute([$resultId]);$res=$res->fetch(); if(!$res) return '';
    $bsql=buildBonusSql('re');
    // Pole-Fahrer aus Qualifying
    $poleQ=$db->prepare("SELECT driver_id FROM qualifying_results WHERE race_id=? AND position=1 LIMIT 1");
    $poleQ->execute([$res['race_id']]);$poleDriverId=(int)($poleQ->fetchColumn()?:0);
    // Grid-Positionen aus qualifying_results
    $gridQ=$db->prepare("SELECT driver_id,position FROM qualifying_results WHERE race_id=? ORDER BY position ASC");
    $gridQ->execute([$res['race_id']]);
    $gridMap=[];
    foreach($gridQ->fetchAll() as $g) $gridMap[$g['driver_id']]=(int)$g['position'];
    $en=$db->prepare("SELECT re.*,({$bsql}) AS cp,d.name AS dn,t.name AS tn,t.color AS tc,se.number AS num,se.is_reserve FROM result_entries re LEFT JOIN drivers d ON d.id=re.driver_id LEFT JOIN teams t ON t.id=re.team_id LEFT JOIN season_entries se ON se.driver_id=re.driver_id AND se.season_id=? WHERE re.result_id=? ORDER BY re.dnf ASC,re.dsq ASC,re.position ASC");
    $en->execute([$res['sid'],$resultId]);$en=$en->fetchAll();
    $dt=$res['race_date']?date('d.m.Y',strtotime($res['race_date'])):'TBD';
    $rows='';
    foreach($en as $i=>$e){
        $pos=$e['dnf']?'DNF':($e['dsq']?'DSQ':(int)$e['position']);
        $pn=is_numeric($pos)?(int)$pos:99;
        $pc=pclass($pn);
        $opa=($e['dnf']||$e['dsq'])?'opacity:.45;':'';
        // Positionsänderung: grid → finish
        $gridPos=$gridMap[$e['driver_id']]??null;
        if($gridPos&&!$e['dnf']&&!$e['dsq']){
            $diff=$gridPos-$pn;
            if($diff>0)      $posChg="<span style='color:#4cffb0;font-weight:700'>▲".abs($diff)."</span>";
            elseif($diff<0)  $posChg="<span style='color:#ff8080;font-weight:700'>▼".abs($diff)."</span>";
            else             $posChg="<span style='color:rgba(255,255,255,.3)'>—</span>";
        } else { $posChg='<span style="color:rgba(255,255,255,.3)">–</span>'; }
        $isPole=($poleDriverId&&$e['driver_id']==$poleDriverId);
        $flBadge=$e['is_fastest_lap']?"<span style='background:rgba(245,166,35,.2);color:".e($c['secondary']).";font-size:10px;padding:1px 5px;border-radius:2px;margin-left:4px;font-weight:700'>FL</span>":'';
        $poleBadge=$isPole?"<span style='background:rgba(232,51,58,.2);color:".e($c['primary']).";font-size:10px;padding:1px 5px;border-radius:2px;margin-left:4px;font-weight:700'>POLE</span>":'';
        $res2=$e['is_reserve']?"<span style='font-size:8px;background:rgba(255,255,255,.1);color:rgba(255,255,255,.4);padding:0 4px;border-radius:2px;margin-left:4px'>RES</span>":'';
        $num=$e['num']?"<span style='color:".e($c['primary'])."'>#".(int)$e['num']."</span> ":'';
        $time=$pn===1?e($e['total_time']??'–'):($e['dnf']?'DNF':e($e['gap']??'–'));
        $fl_time=($e['fastest_lap']&&$e['is_fastest_lap'])
            ?'<span style="color:'.e($c['secondary']).'">'.$e['fastest_lap'].'</span>'
            :e($e['fastest_lap']??'–');
        $stops=$e['pitstops']??'–';
        $pts=number_format((float)$e['cp'],0);
        $rows.="<tr style='{$opa}'>
            <td class='{$pc}'>{$pos}</td>
            <td style='text-align:left;padding-left:8px'>
                <span style='font-weight:700'>{$num}".e($e['dn']??$e['driver_name_raw']??'?')."</span>{$res2}{$flBadge}{$poleBadge}
            </td>
            <td style='text-align:left'>
                <span class='tc-bar' style='background:".e($e['tc']??'#555')."'></span>
                <span class='tn'>".e($e['tn']??'–')."</span>
            </td>
            <td class='mono' style='min-width:90px'>{$time}</td>
            <td style='min-width:32px;text-align:center'>{$posChg}</td>
            <td style='color:rgba(255,255,255,.55)'>{$e['laps']}</td>
            <td style='color:rgba(255,255,255,.45)'>{$stops}</td>
            <td class='mono' style='min-width:72px'>{$fl_time}</td>
            <td class='pts-col' style='color:".e($c['primary'])."'>{$pts}</td>
        </tr>";
    }
    $W2=1400;
    $body="<div class='w' style='width:{$W2}px'><div class='bg'></div><div class='st'></div><div class='g1'></div><div class='g2'></div><div class='sb'></div>
    <div class='c'>
        ".hdr($c,'RENNERGEBNIS',e($res['track_name']))."
        <div class='pl'>🏁 RUNDE ".e($res['round'])." · ".e($res['sn'])." ".e($res['sy']??'')." · {$dt}".($res['location']?' · '.e($res['location']):'').($res['game']?' · '.e($res['game']):'')."</div>
        <table class='gt'>
            <thead><tr>
                <th style='width:42px'>POS</th>
                <th style='text-align:left;padding-left:8px;min-width:180px'>FAHRER</th>
                <th style='text-align:left;min-width:160px'>TEAM</th>
                <th style='min-width:90px'>ZEIT / GAP</th>
                <th style='min-width:40px' title='Positionsänderung'>+/-</th>
                <th style='min-width:36px'>RND</th>
                <th style='min-width:40px'>STOPS</th>
                <th style='min-width:72px'>BESTZEIT</th>
                <th style='min-width:44px'>PTS</th>
            </tr></thead>
            <tbody>{$rows}</tbody>
        </table>
        ".ftr($c,"R".e($res['round'])." · ".e($res['track_name']))."
    </div></div>";
    return wrap($body,css2($c,$ig,$W2));
}

// ---- DRIVER STANDINGS ----
function tplDriver(int $sid, bool $ig, int $W, PDO $db, array $c): string {
    $sea=getSeason($db,$sid); if(!$sea) return '';
    $bsql=buildBonusSql('re');
    $rf=getSetting('reserve_scores_driver','1')==='1'?'':'AND se.is_reserve=0';
    $sid2=$sea['id'];
    $st=$db->prepare("
        SELECT d.id,d.name,d.nationality,se.number,se.is_reserve,t.name AS tn,t.color AS tc,
            COALESCE(SUM({$bsql}),0) AS tp,
            COUNT(DISTINCT re.result_id) AS events,
            COUNT(CASE WHEN re.position=1 THEN 1 END) AS wins,
            COUNT(CASE WHEN re.position=2 THEN 1 END) AS p2s,
            COUNT(CASE WHEN re.position=3 THEN 1 END) AS p3s,
            COUNT(CASE WHEN re.position<=3 THEN 1 END) AS pods,
            COUNT(CASE WHEN re.position<=5 THEN 1 END) AS top5,
            COUNT(CASE WHEN re.position<=10 THEN 1 END) AS top10,
            COUNT(CASE WHEN re.dnf=1 OR re.dsq=1 THEN 1 END) AS dnfs,
            COUNT(CASE WHEN re.is_fastest_lap=1 THEN 1 END) AS fls,
            (SELECT COUNT(*) FROM qualifying_results qr WHERE qr.driver_id=d.id AND qr.position=1
             AND qr.race_id IN (SELECT rc.id FROM races rc WHERE rc.season_id=:s3)) AS poles,
            MIN(CASE WHEN re.dnf=0 AND re.dsq=0 THEN re.position END) AS best_pos
        FROM season_entries se JOIN drivers d ON d.id=se.driver_id
        LEFT JOIN teams t ON t.id=se.team_id
        LEFT JOIN result_entries re ON re.driver_id=d.id AND re.result_id IN (
            SELECT r.id FROM results r INNER JOIN races rc ON rc.id=r.race_id AND rc.season_id=:s2)
        WHERE se.season_id=:s1 {$rf}
        GROUP BY d.id,d.name,d.nationality,se.number,se.is_reserve,t.name,t.color
        ORDER BY tp DESC,wins DESC
    ");
    $st->execute([':s1'=>$sid2,':s2'=>$sid2,':s3'=>$sid2]);$dr=$st->fetchAll();
    // Vorherige Runde Punkte für GAP
    $prevPts=[];
    $rows=''; $leader=null;
    foreach($dr as $i=>$d){
        $pos=$i+1; $pc=pclass($pos);
        if($i===0) $leader=$d;
        $gap=$i===0?'–':number_format((float)$leader['tp']-(float)$d['tp'],1);
        $res2=$d['is_reserve']?"<span style='font-size:8px;background:rgba(255,255,255,.1);color:rgba(255,255,255,.4);padding:0 4px;border-radius:2px;margin-left:4px'>RES</span>":'';
        $num=$d['number']?'#'.(int)$d['number']:'';
        $rows.="<tr>
            <td class='{$pc}'>{$pos}</td>
            <td style='text-align:left;padding-left:8px'><span class='tc-bar' style='background:".e($d['tc']??'#555')."'></span><span style='font-weight:700'>".e($d['name'])."</span>{$res2}</td>
            <td style='text-align:left;color:rgba(255,255,255,.5);font-size:11px'>".e($d['tn']??'–')."</td>
            <td class='pts-col' style='color:".e($c['primary'])."'>".number_format((float)$d['tp'],0)."</td>
            <td style='color:rgba(255,255,255,.45);font-size:11px'>".($i===0?'–':'+'.number_format((float)$d['tp']-(float)($dr[$i-1]['tp']??$d['tp']),0))."</td>
            <td style='color:rgba(255,255,255,.4);font-size:11px'>".($i===0?'–':'-'.$gap)."</td>
            <td>".(int)$d['wins']."</td>
            <td>".(int)$d['p2s']."</td>
            <td>".(int)$d['p3s']."</td>
            <td>".(int)$d['pods']."</td>
            <td style='color:".e($d['fls']>0?$c['secondary']:'rgba(255,255,255,.4)')."'>".(int)$d['fls']."</td>
            <td style='color:".e($d['poles']>0?$c['primary']:'rgba(255,255,255,.4)')."'>".(int)$d['poles']."</td>
            <td style='color:".e($d['dnfs']>0?'#ff8080':'rgba(255,255,255,.4)')."'>".(int)$d['dnfs']."</td>
        </tr>";
    }
    $W2=1400;
    $body="<div class='w' style='width:{$W2}px'><div class='bg'></div><div class='st'></div><div class='g1'></div><div class='g2'></div><div class='sb'></div>
    <div class='c'>
        ".hdr($c,'FAHRERWERTUNG',e($sea['name']??'').' '.e($sea['year']??''))."
        <div class='pl'>🏎 ".count($dr)." FAHRER · ".e($sea['name']??'')."</div>
        <table class='gt'>
            <thead><tr>
                <th style='width:36px'>POS</th>
                <th style='text-align:left;padding-left:8px;min-width:160px'>FAHRER</th>
                <th style='text-align:left;min-width:150px'>TEAM</th>
                <th style='min-width:52px'>PTS</th>
                <th style='min-width:54px;font-size:9px'>+/- PREV</th>
                <th style='min-width:58px'>GAP</th>
                <th style='min-width:28px'>P1</th>
                <th style='min-width:28px'>P2</th>
                <th style='min-width:28px'>P3</th>
                <th style='min-width:36px'>POD</th>
                <th style='min-width:32px'>FL</th>
                <th style='min-width:36px'>POLE</th>
                <th style='min-width:32px'>DNF</th>
            </tr></thead>
            <tbody>{$rows}</tbody>
        </table>
        ".ftr($c,e($sea['name']??'').' '.e($sea['year']??''))."
    </div></div>";
    return wrap($body,css2($c,$ig,$W2));
}

// ---- TEAM STANDINGS ----
function tplTeam(int $sid, bool $ig, int $W, PDO $db, array $c): string {
    $sea=getSeason($db,$sid); if(!$sea) return '';
    $bsql=buildBonusSql('re');
    $rf=getSetting('reserve_scores_team','0')==='1'?'':'AND se.is_reserve=0';
    $sid2=$sea['id'];
    $st=$db->prepare("
        SELECT t.id,t.name,t.color,
            COALESCE(SUM({$bsql}),0) AS tp,
            COUNT(DISTINCT re.result_id) AS events,
            COUNT(CASE WHEN re.position=1 THEN 1 END) AS wins,
            COUNT(CASE WHEN re.position=2 THEN 1 END) AS p2s,
            COUNT(CASE WHEN re.position=3 THEN 1 END) AS p3s,
            COUNT(CASE WHEN re.position<=3 THEN 1 END) AS pods,
            COUNT(CASE WHEN re.is_fastest_lap=1 THEN 1 END) AS fls,
            (SELECT COUNT(*) FROM qualifying_results qr
             JOIN season_entries se2 ON se2.driver_id=qr.driver_id AND se2.team_id=t.id AND se2.season_id=t.season_id
             WHERE qr.position=1 AND qr.race_id IN (SELECT rc.id FROM races rc WHERE rc.season_id=t.season_id)) AS poles,
            MIN(CASE WHEN re.dnf=0 AND re.dsq=0 THEN re.position END) AS best_pos,
            COUNT(CASE WHEN re.dnf=1 OR re.dsq=1 THEN 1 END) AS dnfs
        FROM teams t
        LEFT JOIN season_entries se ON se.team_id=t.id AND se.season_id=t.season_id {$rf}
        LEFT JOIN result_entries re ON re.driver_id=se.driver_id AND re.result_id IN (
            SELECT r.id FROM results r INNER JOIN races rc ON rc.id=r.race_id AND rc.season_id=t.season_id)
        WHERE t.season_id=:s1 GROUP BY t.id,t.name,t.color ORDER BY tp DESC
    ");
    $st->execute([':s1'=>$sid2]);$ts=$st->fetchAll();
    $rows=''; $leader=null;
    foreach($ts as $i=>$t){
        $pos=$i+1; $pc=pclass($pos); $tc=e($t['color']??'#666');
        if($i===0) $leader=$t;
        $gap=$i===0?'–':number_format((float)$leader['tp']-(float)$t['tp'],1);
        $rows.="<tr>
            <td class='{$pc}'>{$pos}</td>
            <td style='text-align:left;padding-left:8px'>
                <span style='display:inline-block;width:10px;height:10px;border-radius:50%;background:{$tc};margin-right:8px;vertical-align:middle'></span>
                <span style='font-weight:700'>".e($t['name'])."</span>
            </td>
            <td class='pts-col' style='color:".e($c['primary'])."'>".number_format((float)$t['tp'],0)."</td>
            <td style='color:rgba(255,255,255,.45);font-size:11px'>".($i===0?'–':'+'.number_format((float)$t['tp']-(float)($ts[$i-1]['tp']??$t['tp']),0))."</td>
            <td style='color:rgba(255,255,255,.4);font-size:11px'>".($i===0?'–':'-'.$gap)."</td>
            <td>".(int)$t['wins']."</td>
            <td>".(int)$t['p2s']."</td>
            <td>".(int)$t['p3s']."</td>
            <td>".(int)$t['pods']."</td>
            <td style='color:".e($t['fls']>0?$c['secondary']:'rgba(255,255,255,.4)')."'>".(int)$t['fls']."</td>
            <td style='color:".e($t['poles']>0?$c['primary']:'rgba(255,255,255,.4)')."'>".(int)$t['poles']."</td>
            <td style='color:".e($t['dnfs']>0?'#ff8080':'rgba(255,255,255,.4)')."'>".(int)$t['dnfs']."</td>
        </tr>";
    }
    $W2=1400;
    $body="<div class='w' style='width:{$W2}px'><div class='bg'></div><div class='st'></div><div class='g1'></div><div class='g2'></div><div class='sb'></div>
    <div class='c'>
        ".hdr($c,'TEAMWERTUNG',e($sea['name']??'').' '.e($sea['year']??''))."
        <div class='pl'>🏭 ".count($ts)." TEAMS · ".e($sea['name']??'')."</div>
        <table class='gt'>
            <thead><tr>
                <th style='width:36px'>POS</th>
                <th style='text-align:left;padding-left:8px;min-width:200px'>TEAM</th>
                <th style='min-width:52px'>PTS</th>
                <th style='min-width:54px;font-size:9px'>+/- PREV</th>
                <th style='min-width:58px'>GAP</th>
                <th style='min-width:28px'>P1</th>
                <th style='min-width:28px'>P2</th>
                <th style='min-width:28px'>P3</th>
                <th style='min-width:36px'>POD</th>
                <th style='min-width:32px'>FL</th>
                <th style='min-width:36px'>POLE</th>
                <th style='min-width:32px'>DNF</th>
            </tr></thead>
            <tbody>{$rows}</tbody>
        </table>
        ".ftr($c,e($sea['name']??'').' '.e($sea['year']??''))."
    </div></div>";
    return wrap($body,css2($c,$ig,$W2));
}

// ---- CSS2: Tabellen-Layout fuer breite Exports ----
function css2(array $c, bool $ig, int $W): string {
    $p=$c['primary']; $s=$c['secondary'];
    return css($c,$ig,$W)."
.gt{width:100%;border-collapse:collapse;font-size:12px}
.gt thead tr{background:rgba(255,255,255,.055);border-bottom:2px solid {$p}}
.gt th{font-size:9px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.38);padding:8px 6px;text-align:center;white-space:nowrap}
.gt td{padding:9px 6px;text-align:center;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle;white-space:nowrap}
.gt tbody tr:nth-child(even){background:rgba(255,255,255,.02)}
.gt tbody tr:last-child td{border-bottom:none}
.tc-bar{display:inline-block;width:3px;height:14px;border-radius:2px;margin-right:7px;vertical-align:middle;flex-shrink:0}
.tn{font-size:11px;color:rgba(255,255,255,.45)}
.mono{font-family:monospace;font-size:12px;color:{$s}}
.pts-col{font-size:16px;font-weight:900;font-style:italic}
.p0{font-size:18px;font-weight:900;font-style:italic;color:rgba(255,255,255,.2)}
.p1{font-size:18px;font-weight:900;font-style:italic;color:#f5c842}
.p2{font-size:18px;font-weight:900;font-style:italic;color:#bdbdbd}
.p3{font-size:18px;font-weight:900;font-style:italic;color:#cd7f32}
";
}

// ============================================================
// Hilfsfunktionen für Spezial-Grafiken
// ============================================================
function srlBase(): string {
    if (defined('SITE_URL') && SITE_URL !== '') return rtrim(SITE_URL, '/');
    $s = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $s . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
if (!function_exists('discordSiteUrl')) {
    function discordSiteUrl(): string { return srlBase(); }
}
function srlImgPath(string $path): string {
    if (!$path) return '';
    if (str_starts_with($path, 'http')) return $path;
    $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(dirname(__FILE__)), '/');
    return 'file://' . $root . '/' . ltrim($path, '/');
}
function srlInitials(string $name): string {
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) return strtoupper(mb_substr($parts[0],0,1) . mb_substr(end($parts),0,1));
    return strtoupper(mb_substr($name,0,2));
}
function srlStatCell(string $val, string $label, string $color, int $fs=30): string {
    return "<td style='text-align:center;padding:14px 4px;width:20%'>
        <div style='font-size:{$fs}px;font-weight:900;font-style:italic;color:" . htmlspecialchars($color) . "'>" . htmlspecialchars($val) . "</div>
        <div style='font-size:9px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:rgba(255,255,255,.38);margin-top:3px'>" . htmlspecialchars($label) . "</div>
    </td>";
}
// CSS-Trophäe (kein Emoji nötig)
function srlTrophy(string $color, int $size=80): string {
    $s = $size; $h = (int)round($s*.55); $c = (int)round($s*.38); $st = (int)round($s*.12); $b = (int)round($s*.32);
    return "<div style='display:inline-block;position:relative;width:{$s}px;height:" . ($s+$st+$b) . "px'>
        <div style='position:absolute;top:0;left:0;right:0;height:{$h}px;background:" . htmlspecialchars($color) . ";border-radius:4px 4px 50% 50%;'></div>
        <div style='position:absolute;top:" . (int)round($h*.15) . "px;left:-" . (int)round($s*.18) . "px;width:" . (int)round($s*.2) . "px;height:{$c}px;background:" . htmlspecialchars($color) . ";border-radius:0 0 50% 50%;'></div>
        <div style='position:absolute;top:" . (int)round($h*.15) . "px;right:-" . (int)round($s*.18) . "px;width:" . (int)round($s*.2) . "px;height:{$c}px;background:" . htmlspecialchars($color) . ";border-radius:0 0 50% 50%;'></div>
        <div style='position:absolute;top:{$h}px;left:50%;margin-left:-" . (int)round($st/2) . "px;width:{$st}px;height:{$st}px;background:" . htmlspecialchars($color) . ";'></div>
        <div style='position:absolute;top:" . ($h+$st) . "px;left:50%;margin-left:-" . (int)round($b/2) . "px;width:{$b}px;height:" . (int)round($b*.28) . "px;background:" . htmlspecialchars($color) . ";border-radius:0 0 4px 4px;'></div>
    </div>";
}
// Konfetti-Punkte als CSS-Kreise
function srlConfetti(string $p, string $s2, int $W): string {
    $dots = '';
    $colors = [$p, $s2, '#ffffff', '#f5c842', $p, $s2, '#ffffff', $p, $s2, '#f5c842', '#ffffff', $p];
    $positions = [[8,6],[14,18],[22,8],[30,14],[42,4],[55,10],[65,18],[72,6],[80,12],[88,8],[93,16],[4,20]];
    foreach ($positions as $i => $pos) {
        $col = htmlspecialchars($colors[$i % count($colors)]);
        $sz  = rand(6,14);
        $op  = ['0.6','0.4','0.7','0.5','0.8'][$i % 5];
        $top = $pos[1];
        $left = $pos[0];
        $dots .= "<div style='position:absolute;top:{$top}%;left:{$left}%;width:{$sz}px;height:{$sz}px;border-radius:50%;background:{$col};opacity:{$op}'></div>";
    }
    return $dots;
}

// ============================================================
// TOP 10 – Podium mit Foto IM Kasten + großem Namen
// ============================================================
function tplTop10(int $resultId, bool $ig, int $W, PDO $db, array $c): string {
    $res = $db->prepare("SELECT r.*,rc.track_name,rc.round,rc.race_date,rc.location,s.name AS sn,s.id AS sid,s.year AS sy FROM results r JOIN races rc ON rc.id=r.race_id JOIN seasons s ON s.id=rc.season_id WHERE r.id=?");
    $res->execute([$resultId]); $res = $res->fetch();
    if (!$res) return wrap('<p style="color:red;padding:20px">Ergebnis nicht gefunden</p>', '');
    $bsql = buildBonusSql('re');
    $en = $db->prepare("SELECT re.*,({$bsql}) AS cp,d.name AS dn,d.photo_path AS dp,t.name AS tn,t.color AS tc,se.number AS num FROM result_entries re LEFT JOIN drivers d ON d.id=re.driver_id LEFT JOIN teams t ON t.id=re.team_id LEFT JOIN season_entries se ON se.driver_id=re.driver_id AND se.season_id=? WHERE re.result_id=? AND re.dnf=0 AND re.dsq=0 ORDER BY re.position ASC LIMIT 10");
    $en->execute([$res['sid'], $resultId]); $en = $en->fetchAll();

    $p  = $c['primary']; $s2 = $c['secondary'];
    $dt = $res['race_date'] ? date('d.m.Y', strtotime($res['race_date'])) : '';
    $W2 = 1080;

    // Podiumskarte – Foto als Hintergrund, Name groß drin
    $podCard = function(array $e, int $rank) use ($p, $s2): string {
        $hc     = ['','#f5c842','#c0c0c0','#cd7f32'][$rank] ?? '#888';
        $ht     = ['',210,170,150][$rank] ?? 130;
        $nameFs = ['',22,18,16][$rank] ?? 14;
        $pts    = number_format((float)($e['cp'] ?? 0), 0);
        $name   = htmlspecialchars($e['dn'] ?? '?');
        $team   = htmlspecialchars($e['tn'] ?? '');
        $tc     = htmlspecialchars($e['tc'] ?? $p);
        $num    = $e['num'] ? '#' . (int)$e['num'] : '';
        $init   = srlInitials($e['dn'] ?? 'XX');
        $photo  = srlImgPath($e['dp'] ?? '');

        // Innerer Bildbereich: Foto als <img> oben, Gradient-Overlay, Name unten drüber
        if ($photo) {
            $imgArea = "<div style='position:relative;height:{$ht}px;overflow:hidden;border-radius:6px 6px 0 0'>
                <img src='" . htmlspecialchars($photo) . "' width='100%' height='{$ht}' style='object-fit:cover;object-position:top center;display:block'/>
                <div style='position:absolute;bottom:0;left:0;right:0;height:" . (int)round($ht*.65) . "px;background:linear-gradient(to top,rgba(0,0,0,.85),transparent)'></div>
                <div style='position:absolute;bottom:8px;left:10px;right:36px'>
                    " . ($num ? "<div style='font-size:11px;color:" . htmlspecialchars($p) . ";font-weight:700;margin-bottom:1px'>{$num}</div>" : '') . "
                    <div style='font-size:{$nameFs}px;font-weight:900;color:#fff;line-height:1.1;text-shadow:0 1px 4px rgba(0,0,0,.8)'>{$name}</div>
                    <div style='font-size:9px;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.1em;margin-top:1px'>{$team}</div>
                </div>
                <div style='position:absolute;top:6px;left:8px;font-size:13px;font-weight:900;color:{$hc};text-shadow:0 1px 3px rgba(0,0,0,.6)'>P{$rank}</div>
                <div style='position:absolute;top:6px;right:6px;background:" . htmlspecialchars($p) . ";color:#fff;font-size:10px;font-weight:900;padding:2px 6px;border-radius:3px'>{$pts}</div>
            </div>";
        } else {
            // Kein Foto: Initialen-Platzhalter + Name darunter
            $imgArea = "<div style='position:relative;height:{$ht}px;background:linear-gradient(135deg,{$hc}22,rgba(255,255,255,.04));border-radius:6px 6px 0 0;overflow:hidden'>
                <div style='position:absolute;top:50%;left:50%;transform:translate(-50%,-60%);font-size:" . (int)round($ht*.35) . "px;font-weight:900;color:{$hc};opacity:.35;letter-spacing:.04em'>{$init}</div>
                <div style='position:absolute;bottom:8px;left:10px;right:36px'>
                    " . ($num ? "<div style='font-size:11px;color:" . htmlspecialchars($p) . ";font-weight:700;margin-bottom:1px'>{$num}</div>" : '') . "
                    <div style='font-size:{$nameFs}px;font-weight:900;color:#fff;line-height:1.1'>{$name}</div>
                    <div style='font-size:9px;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:.1em;margin-top:1px'>{$team}</div>
                </div>
                <div style='position:absolute;top:6px;left:8px;font-size:13px;font-weight:900;color:{$hc}'>P{$rank}</div>
                <div style='position:absolute;top:6px;right:6px;background:" . htmlspecialchars($p) . ";color:#fff;font-size:10px;font-weight:900;padding:2px 6px;border-radius:3px'>{$pts}</div>
            </div>";
        }
        return "<div style='background:rgba(8,13,32,.8);border:1px solid {$hc}44;border-radius:8px;border-top:3px solid {$hc};overflow:hidden;height:100%'>
            {$imgArea}
            <div style='height:3px;background:linear-gradient(90deg,{$tc},transparent)'></div>
        </div>";
    };

    $top3 = array_slice($en, 0, 3);
    $p2c = isset($top3[1]) ? $podCard($top3[1], 2) : '';
    $p1c = isset($top3[0]) ? $podCard($top3[0], 1) : '';
    $p3c = isset($top3[2]) ? $podCard($top3[2], 3) : '';

    // P4-P10
    $rows = '';
    foreach (array_slice($en, 3) as $e) {
        $pos   = (int)$e['position'];
        $tc    = htmlspecialchars($e['tc'] ?? '#555');
        $photo = srlImgPath($e['dp'] ?? '');
        $init  = srlInitials($e['dn'] ?? '??');
        $avatar = $photo
            ? "<img src='" . htmlspecialchars($photo) . "' width='30' height='30' style='border-radius:50%;object-fit:cover;object-position:top;border:2px solid {$tc};vertical-align:middle;display:inline-block'/>"
            : "<div style='display:inline-block;width:30px;height:30px;border-radius:50%;background:{$tc}33;border:2px solid {$tc};vertical-align:middle;text-align:center;line-height:28px;font-size:10px;font-weight:700;color:{$tc}'>{$init}</div>";
        $numSpan = $e['num'] ? "<span style='color:" . htmlspecialchars($p) . ";font-size:11px;margin-right:3px'>#" . (int)$e['num'] . "</span>" : '';
        $rows .= "<tr>
            <td style='padding:8px 10px;width:28px;font-size:15px;font-weight:900;font-style:italic;color:rgba(255,255,255,.22);vertical-align:middle'>{$pos}</td>
            <td style='padding:8px 4px;width:34px;vertical-align:middle'>{$avatar}</td>
            <td style='padding:8px 8px;font-size:13px;font-weight:700;vertical-align:middle'>{$numSpan}" . htmlspecialchars($e['dn'] ?? '?') . "</td>
            <td style='padding:8px 8px;font-size:11px;color:rgba(255,255,255,.38);vertical-align:middle'><span style='display:inline-block;width:3px;height:11px;background:{$tc};border-radius:1px;margin-right:5px;vertical-align:middle'></span>" . htmlspecialchars($e['tn'] ?? '-') . "</td>
            <td style='padding:8px 8px;font-family:monospace;font-size:11px;color:rgba(255,255,255,.4);text-align:right;vertical-align:middle'>" . htmlspecialchars($e['gap'] ?? '-') . "</td>
            <td style='padding:8px 10px;font-size:15px;font-weight:900;font-style:italic;color:" . htmlspecialchars($p) . ";text-align:right;vertical-align:middle'>" . number_format((float)$e['cp'], 0) . "</td>
        </tr>";
    }

    $css = css($c, true, $W2) . "body,.w{width:{$W2}px!important}";
    $body = "<div class='w' style='width:{$W2}px;min-height:{$W2}px'>
        <div class='bg'></div><div class='st'></div><div class='g1'></div><div class='g2'></div><div class='sb'></div>
        <div class='c' style='padding:36px 38px 34px 54px;min-height:{$W2}px'>
            <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:16px'><tr>
                <td valign='top'>
                    <div class='lg'>" . htmlspecialchars($res['sn']) . " " . htmlspecialchars($res['sy'] ?? '') . "</div>
                    <div class='t1'>TOP 10</div>
                    <div class='t2'>" . htmlspecialchars($res['track_name']) . "</div>
                    <div style='font-size:11px;color:rgba(255,255,255,.38);margin-top:5px'>RUNDE " . htmlspecialchars($res['round']) . " &middot; {$dt}" . ($res['location'] ? ' &middot; ' . htmlspecialchars($res['location']) : '') . "</div>
                </td>
                <td valign='top' align='right' width='120'>
                    <div class='ab'>" . htmlspecialchars($c['abbr']) . "</div>
                    <div class='as'>" . htmlspecialchars($c['league']) . "</div>
                </td>
            </tr></table>
            <table width='100%' cellpadding='4' cellspacing='0' style='margin-bottom:12px;table-layout:fixed'>
                <tr valign='bottom'>
                    <td width='31%'>{$p2c}</td>
                    <td width='38%'>{$p1c}</td>
                    <td width='31%'>{$p3c}</td>
                </tr>
            </table>
            <div style='background:rgba(8,13,32,.6);border:1px solid rgba(255,255,255,.08);border-radius:5px;overflow:hidden'>
                <table width='100%' cellpadding='0' cellspacing='0'>{$rows}</table>
            </div>
            " . ftr($c, "R" . htmlspecialchars($res['round']) . " &middot; " . htmlspecialchars($res['track_name'])) . "
        </div>
    </div>";
    return wrap($body, $css);
}

// ============================================================
// CHAMPION DRIVER
// ============================================================
function tplChampionDriver(int $sid, bool $ig, int $W, PDO $db, array $c): string {
    $sea = getSeason($db, $sid);
    if (!$sea) return wrap('<p style="color:red;padding:20px">Keine Saison</p>', '');
    $bsql = buildBonusSql('re');
    $rf   = getSetting('reserve_scores_driver','1')==='1' ? '' : 'AND se.is_reserve=0';
    $stmt = $db->prepare("
        SELECT d.id,d.name,d.nationality,d.photo_path,se.number,
               t.name AS tn,t.color AS tc,t.logo_path AS tl,
               COALESCE(SUM({$bsql}),0) AS tp,
               COUNT(CASE WHEN re.position=1 THEN 1 END) AS wins,
               COUNT(CASE WHEN re.position<=3 THEN 1 END) AS pods,
               COUNT(DISTINCT re.result_id) AS events,
               COUNT(CASE WHEN re.is_fastest_lap=1 THEN 1 END) AS fls
        FROM season_entries se JOIN drivers d ON d.id=se.driver_id
        LEFT JOIN teams t ON t.id=se.team_id
        LEFT JOIN result_entries re ON re.driver_id=d.id AND re.result_id IN (
            SELECT r.id FROM results r INNER JOIN races rc ON rc.id=r.race_id AND rc.season_id=:s2)
        WHERE se.season_id=:s1 {$rf}
        GROUP BY d.id,d.name,d.nationality,d.photo_path,se.number,t.name,t.color,t.logo_path
        ORDER BY tp DESC,wins DESC LIMIT 1");
    $stmt->execute([':s1'=>$sea['id'],':s2'=>$sea['id']]);
    $ch = $stmt->fetch();
    if (!$ch) return wrap('<p style="color:red;padding:20px">Keine Daten</p>','');

    $p     = $c['primary']; $s2 = $c['secondary'];
    $tc    = htmlspecialchars($ch['tc'] ?? $p);
    $W2    = 1080;
    $sname = htmlspecialchars($sea['name']??'').' '.htmlspecialchars($sea['year']??'');
    $photo = srlImgPath($ch['photo_path'] ?? '');
    $tlogo = srlImgPath($ch['tl'] ?? '');
    $init  = srlInitials($ch['name'] ?? 'XX');

    // Foto oder Initialen-Box
    $photoHtml = $photo
        ? "<div style='width:290px;height:350px;border-radius:8px;overflow:hidden;border:3px solid {$tc}'>
               <img src='" . htmlspecialchars($photo) . "' width='290' height='350' style='object-fit:cover;object-position:top center;display:block'/>
           </div>"
        : "<div style='width:290px;height:350px;border-radius:8px;background:linear-gradient(135deg,{$tc}22,rgba(255,255,255,.04));border:3px solid {$tc};text-align:center'>
               <div style='font-size:110px;font-weight:900;color:{$tc};opacity:.35;line-height:350px;letter-spacing:.02em'>{$init}</div>
           </div>";

    $tlogoHtml = $tlogo ? "<img src='" . htmlspecialchars($tlogo) . "' height='40' style='max-width:130px;object-fit:contain;display:block;margin-bottom:4px'/>" : '';
    $trophy = srlTrophy($p, 72);
    $confetti = srlConfetti($p, $s2, $W2);

    $css  = css($c,true,$W2) . "body,.w{width:{$W2}px!important}";
    $body = "<div class='w' style='width:{$W2}px;min-height:{$W2}px;overflow:hidden'>
        <div class='bg'></div><div class='st'></div>
        {$confetti}
        <div style='position:absolute;top:0;left:0;right:0;height:440px;background:linear-gradient(180deg,{$tc}1c,transparent)'></div>
        <div class='g1'></div><div class='sb'></div>
        <div class='c' style='padding:46px 46px 38px 62px;min-height:{$W2}px'>
            <!-- Header -->
            <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:28px'><tr>
                <td valign='top'>
                    <div style='font-size:11px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.35)'>" . htmlspecialchars($c['league']) . "</div>
                    <div style='font-size:54px;font-weight:900;font-style:italic;text-transform:uppercase;line-height:.85;color:#fff'>FAHRER</div>
                    <div style='font-size:54px;font-weight:900;font-style:italic;text-transform:uppercase;line-height:.85;color:{$tc}'>CHAMPION</div>
                    <div style='font-size:15px;color:rgba(255,255,255,.45);margin-top:8px'>{$sname}</div>
                </td>
                <td valign='top' align='right' width='160'>
                    {$tlogoHtml}
                    <div style='font-size:10px;color:rgba(255,255,255,.3);letter-spacing:.1em;text-transform:uppercase;text-align:right'>" . htmlspecialchars($ch['tn']??'') . "</div>
                </td>
            </tr></table>
            <!-- Foto + Infos -->
            <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:26px'><tr valign='bottom'>
                <td width='300'>{$photoHtml}</td>
                <td style='padding-left:26px' valign='bottom'>
                    " . ($ch['number'] ? "<div style='font-size:88px;font-weight:900;font-style:italic;color:{$tc};line-height:.8;opacity:.2'>#".(int)$ch['number']."</div>" : '') . "
                    <div style='font-size:46px;font-weight:900;text-transform:uppercase;line-height:.9;color:#fff;margin-bottom:8px'>" . htmlspecialchars($ch['name']) . "</div>
                    " . ($ch['nationality'] ? "<div style='font-size:14px;color:rgba(255,255,255,.42);margin-bottom:16px;letter-spacing:.08em;text-transform:uppercase'>" . htmlspecialchars($ch['nationality']) . "</div>" : '') . "
                    {$trophy}
                    <div style='font-size:11px;color:rgba(255,255,255,.35);letter-spacing:.1em;text-transform:uppercase;margin-top:8px'>MEISTER {$sname}</div>
                </td>
            </tr></table>
            <!-- Stats -->
            <table width='100%' cellpadding='0' cellspacing='0' style='background:rgba(8,13,32,.7);border:1px solid rgba(255,255,255,.09);border-radius:5px;margin-bottom:16px'>
                <tr>
                    " . srlStatCell(number_format((float)$ch['tp'],0),'PUNKTE',$p)
                     . srlStatCell((string)(int)$ch['wins'],'SIEGE','#f5c842')
                     . srlStatCell((string)(int)$ch['pods'],'PODIEN',$s2)
                     . srlStatCell((string)(int)$ch['events'],'RENNEN','rgba(255,255,255,.6)')
                     . srlStatCell((string)(int)$ch['fls'],'FL',$s2) . "
                </tr>
            </table>
            " . ftr($c,$sname) . "
        </div>
    </div>";
    return wrap($body,$css);
}

// ============================================================
// CHAMPION TEAM
// ============================================================
function tplChampionTeam(int $sid, bool $ig, int $W, PDO $db, array $c): string {
    $sea = getSeason($db,$sid);
    if (!$sea) return wrap('<p style="color:red;padding:20px">Keine Saison</p>','');
    $bsql = buildBonusSql('re');
    $rf   = getSetting('reserve_scores_team','0')==='1' ? '' : 'AND se.is_reserve=0';
    $stmt = $db->prepare("
        SELECT t.id,t.name,t.color,t.logo_path,t.car,t.nationality,
               COALESCE(SUM({$bsql}),0) AS tp,
               COUNT(CASE WHEN re.position=1 THEN 1 END) AS wins,
               COUNT(CASE WHEN re.position<=3 THEN 1 END) AS pods,
               COUNT(DISTINCT re.result_id) AS events,
               COUNT(CASE WHEN re.is_fastest_lap=1 THEN 1 END) AS fls
        FROM teams t
        LEFT JOIN season_entries se ON se.team_id=t.id AND se.season_id=t.season_id {$rf}
        LEFT JOIN result_entries re ON re.driver_id=se.driver_id AND re.result_id IN (
            SELECT r.id FROM results r INNER JOIN races rc ON rc.id=r.race_id AND rc.season_id=t.season_id)
        WHERE t.season_id=:s1
        GROUP BY t.id,t.name,t.color,t.logo_path,t.car,t.nationality
        ORDER BY tp DESC,wins DESC LIMIT 1");
    $stmt->execute([':s1'=>$sea['id']]);
    $ch = $stmt->fetch();
    if (!$ch) return wrap('<p style="color:red;padding:20px">Keine Daten</p>','');

    $p     = $c['primary']; $s2 = $c['secondary'];
    $tc    = htmlspecialchars($ch['color'] ?? $p);
    $W2    = 1080;
    $sname = htmlspecialchars($sea['name']??'').' '.htmlspecialchars($sea['year']??'');
    $tlogo = srlImgPath($ch['logo_path'] ?? '');
    $tinit = srlInitials($ch['name'] ?? 'TM');
    $trophy = srlTrophy($p, 72);
    $confetti = srlConfetti($p, $s2, $W2);

    $tlogoHtml = $tlogo
        ? "<div style='width:200px;height:200px;background:rgba(255,255,255,.07);border:2px solid {$tc};border-radius:10px;text-align:center;padding:16px'>
               <img src='" . htmlspecialchars($tlogo) . "' width='168' height='168' style='object-fit:contain;display:inline-block'/>
           </div>"
        : "<div style='width:200px;height:200px;background:{$tc}22;border:2px solid {$tc};border-radius:10px;text-align:center;line-height:200px;font-size:64px;font-weight:900;color:{$tc};opacity:.5'>{$tinit}</div>";

    // Fahrer
    $dstmt = $db->prepare("SELECT d.name,d.photo_path,se.number FROM season_entries se JOIN drivers d ON d.id=se.driver_id WHERE se.team_id=? AND se.season_id=? AND se.is_reserve=0 ORDER BY se.number LIMIT 4");
    $dstmt->execute([$ch['id'],$sea['id']]); $drivers = $dstmt->fetchAll();
    $dCells = '';
    foreach ($drivers as $dr) {
        $dph   = srlImgPath($dr['photo_path'] ?? '');
        $dinit = srlInitials($dr['name'] ?? '??');
        $dphHtml = $dph
            ? "<img src='" . htmlspecialchars($dph) . "' width='100' height='120' style='object-fit:cover;object-position:top;border-radius:5px;border:2px solid {$tc};display:block;margin:0 auto 5px'/>"
            : "<div style='width:100px;height:120px;border-radius:5px;background:{$tc}22;border:2px solid {$tc};margin:0 auto 5px;text-align:center;line-height:120px;font-size:32px;font-weight:900;color:{$tc};opacity:.6'>{$dinit}</div>";
        $dCells .= "<td style='text-align:center;padding:0 8px'>
            {$dphHtml}
            <div style='font-size:11px;font-weight:700;color:rgba(255,255,255,.8)'>" . htmlspecialchars($dr['name']) . "</div>
            " . ($dr['number'] ? "<div style='font-size:10px;color:" . htmlspecialchars($p) . "'>#".(int)$dr['number']."</div>" : '') . "
        </td>";
    }

    $css  = css($c,true,$W2) . "body,.w{width:{$W2}px!important}";
    $body = "<div class='w' style='width:{$W2}px;min-height:{$W2}px;overflow:hidden'>
        <div class='bg'></div><div class='st'></div>
        {$confetti}
        <div style='position:absolute;top:0;left:0;right:0;height:380px;background:linear-gradient(180deg,{$tc}1a,transparent)'></div>
        <div style='position:absolute;top:0;right:0;width:340px;height:340px;background:radial-gradient(circle,{$tc}18,transparent 70%)'></div>
        <div class='g1'></div><div class='sb'></div>
        <div class='c' style='padding:46px 46px 38px 62px;min-height:{$W2}px'>
            <!-- Header -->
            <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:26px'><tr>
                <td valign='top'>
                    <div style='font-size:11px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.35)'>" . htmlspecialchars($c['league']) . "</div>
                    <div style='font-size:54px;font-weight:900;font-style:italic;text-transform:uppercase;line-height:.85;color:#fff'>TEAM</div>
                    <div style='font-size:54px;font-weight:900;font-style:italic;text-transform:uppercase;line-height:.85;color:{$tc}'>CHAMPION</div>
                    <div style='font-size:15px;color:rgba(255,255,255,.45);margin-top:8px'>{$sname}</div>
                </td>
                <td valign='top' align='right' width='120'>
                    <div class='ab'>" . htmlspecialchars($c['abbr']) . "</div>
                    <div class='as'>" . htmlspecialchars($c['league']) . "</div>
                </td>
            </tr></table>
            <!-- Logo + Info -->
            <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:22px'><tr valign='middle'>
                <td width='220'>{$tlogoHtml}</td>
                <td style='padding-left:26px' valign='middle'>
                    <div style='font-size:46px;font-weight:900;text-transform:uppercase;line-height:.9;color:#fff;margin-bottom:8px'>" . htmlspecialchars($ch['name']) . "</div>
                    " . ($ch['car'] ? "<div style='font-size:14px;color:rgba(255,255,255,.45);margin-bottom:4px'>" . htmlspecialchars($ch['car']) . "</div>" : '') . "
                    " . ($ch['nationality'] ? "<div style='font-size:13px;color:rgba(255,255,255,.38);letter-spacing:.07em;text-transform:uppercase;margin-bottom:12px'>" . htmlspecialchars($ch['nationality']) . "</div>" : '') . "
                    {$trophy}
                </td>
            </tr></table>
            " . ($dCells ? "<table cellpadding='0' cellspacing='0' style='margin-bottom:20px'><tr>{$dCells}</tr></table>" : '') . "
            <!-- Stats -->
            <table width='100%' cellpadding='0' cellspacing='0' style='background:rgba(8,13,32,.7);border:1px solid rgba(255,255,255,.09);border-radius:5px;margin-bottom:16px'>
                <tr>
                    " . srlStatCell(number_format((float)$ch['tp'],0),'PUNKTE',$p)
                     . srlStatCell((string)(int)$ch['wins'],'SIEGE','#f5c842')
                     . srlStatCell((string)(int)$ch['pods'],'PODIEN',$s2)
                     . srlStatCell((string)(int)$ch['events'],'RENNEN','rgba(255,255,255,.6)')
                     . srlStatCell((string)(int)$ch['fls'],'FL',$s2) . "
                </tr>
            </table>
            " . ftr($c,$sname) . "
        </div>
    </div>";
    return wrap($body,$css);
}
