<?php
// ============================================================
// includes/config.php  v1.1
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', ''); // Name der Datenbank
define('DB_USER', ''); // Datenbankbenutzer
define('DB_PASS', ''); // Passwort für Datenbankbenutzer
define('DB_CHARSET', 'utf8mb4');
define('SITE_ROOT', dirname(__DIR__));
define('SITE_URL',  ''); // URL zur Webseite zb. https://www.meineliga.de
define('UPLOAD_DIR', SITE_ROOT . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');
define('SESSION_LIFETIME', 86400);
define('APP_VERSION', '0.7.4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        try { $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); }
        catch (PDOException $e) { http_response_code(500); die(json_encode(['error'=>'DB fehler: '.$e->getMessage()])); }
    }
    return $pdo;
}
function getSetting(string $key, string $default=''): string {
    static $cache=[];
    if (!isset($cache[$key])) { $s=getDB()->prepare("SELECT value FROM settings WHERE `key`=?"); $s->execute([$key]); $r=$s->fetch(); $cache[$key]=$r?$r['value']:$default; }
    return $cache[$key];
}
function setSetting(string $key, string $value): void {
    getDB()->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$key,$value,$value]);
}
function getAllSettings(): array {
    $rows=getDB()->query("SELECT `key`,`value` FROM settings")->fetchAll(); $out=[];
    foreach($rows as $r) $out[$r['key']]=$r['value']; return $out;
}
function startSecureSession(): void {
    if(session_status()===PHP_SESSION_NONE){ini_set('session.cookie_httponly',1);ini_set('session.cookie_samesite','Strict');session_set_cookie_params(SESSION_LIFETIME);session_start();}
}
function isLoggedIn(): bool { startSecureSession(); return !empty($_SESSION['admin_id'])&&!empty($_SESSION['admin_user']); }
function requireLogin(): void { if(!isLoggedIn()){header('Location: '.SITE_URL.'/admin/login.php');exit;} }
function currentUser(): ?array { if(!isLoggedIn()) return null; return ['id'=>$_SESSION['admin_id'],'user'=>$_SESSION['admin_user'],'role'=>$_SESSION['admin_role']??'admin']; }

// CSRF
function csrfToken(): string { startSecureSession(); if(empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function verifyCsrf(): void { $t=$_POST['_csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??''; if(!hash_equals(csrfToken(),$t)){http_response_code(403);die('CSRF ungültig. Seite neu laden.');} }
function csrfField(): string { return '<input type="hidden" name="_csrf" value="'.h(csrfToken()).'"/>'; }

// Audit Log
function auditLog(string $action, string $table='', int $id=0, string $details=''): void {
    try { $u=currentUser(); getDB()->prepare("INSERT INTO audit_log(user_id,username,action,target_table,target_id,details,ip)VALUES(?,?,?,?,?,?,?)")->execute([$u['id']??null,$u['user']??'system',$action,$table,$id,$details,$_SERVER['REMOTE_ADDR']??'']); } catch(Exception $e){}
}

// Discord
function discordSiteUrl(): string {
    // SITE_URL kann leer sein wenn relativ konfiguriert – dann aus HTTP_HOST ableiten
    if (SITE_URL !== '') return rtrim(SITE_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function discordNotify(string $content, array $embeds=[]): bool {
    $url = getSetting('discord_webhook_url');
    if (!$url) return false;

    // Payload – avatar_url nur wenn absolut und erreichbar
    $payload = [
        'content'  => $content,
        'embeds'   => $embeds,
        'username' => getSetting('league_name', 'SimRace Liga'),
    ];
    $logo = getSetting('league_logo','');
    if ($logo && str_starts_with($logo, 'http')) {
        $payload['avatar_url'] = $logo; // nur absolute URLs
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => $json,
        CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT       => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    // Discord gibt 204 No Content bei Erfolg zurück
    return ($code === 204 || ($code >= 200 && $code < 300));
}

function discordResultEmbed(array $race, array $entries, string $game, int $resultId=0): array {
    $base  = discordSiteUrl();
    $top3  = array_slice($entries, 0, 3);
    $medals= ['🥇','🥈','🥉'];
    $desc  = '';
    foreach ($top3 as $i => $e) {
        $name = $e['driver_name'] ?? $e['driver_name_raw'] ?? '?';
        $team = $e['team_name']   ?? '';
        $pts  = number_format((float)($e['calc_pts'] ?? $e['points'] ?? 0), 1);
        $desc .= "{$medals[$i]} **{$name}**" . ($team ? " · {$team}" : '') . " — **{$pts} Pkt**
";
    }
    $link  = $resultId ? $base . '/results.php?id=' . $resultId : '';
    $date  = $race['race_date'] ? date('d.m.Y', strtotime($race['race_date'])) : '';
    $embed = [
        'title'       => '🏁 Rennergebnis: ' . $race['track_name'],
        'description' => $desc ?: 'Keine Einträge.',
        'color'       => 15086685,
        'fields'      => [
            ['name'=>'Saison', 'value'=>$race['season_name']??'–', 'inline'=>true],
            ['name'=>'Runde',  'value'=>'R'.($race['round']??'?'), 'inline'=>true],
            ['name'=>'Datum',  'value'=>$date?:'–',                'inline'=>true],
        ],
        'footer' => ['text' => getSetting('league_name') . ' · ' . date('d.m.Y H:i')],
    ];
    if ($game) $embed['fields'][] = ['name'=>'Sim', 'value'=>$game, 'inline'=>true];
    if ($link) $embed['url'] = $link;
    return [$embed];
}

function discordNewsEmbed(array $news): array {
    $base  = discordSiteUrl();
    $link  = $base . '/news.php?slug=' . ($news['slug'] ?? $news['id']);
    $embed = [
        'title'       => '📰 ' . ($news['title'] ?? 'Neue News'),
        'description' => mb_substr(strip_tags($news['content'] ?? $news['excerpt'] ?? ''), 0, 280) . '...',
        'color'       => 3901635,
        'url'         => $link,
        'fields'      => [
            ['name'=>'Kategorie', 'value'=>$news['category']??'Allgemein', 'inline'=>true],
            ['name'=>'Autor',     'value'=>$news['author_name']??'–',      'inline'=>true],
        ],
        'footer' => ['text' => getSetting('league_name') . ' · ' . date('d.m.Y H:i')],
    ];
    if (!empty($news['image_path'])) {
        $imgUrl = str_starts_with($news['image_path'], 'http')
            ? $news['image_path']
            : $base . '/' . ltrim($news['image_path'], '/');
        $embed['thumbnail'] = ['url' => $imgUrl];
    }
    return [$embed];
}


// ============================================================
// E-Mail Versand (PHP mail() oder SMTP via cURL/socket)
// ============================================================
function sendMail(string $to, string $subject, string $body, string $toName=''): bool {
    $from     = getSetting('mail_from',        '');
    $fromName = getSetting('mail_from_name',   getSetting('league_name','SimRace Liga'));
    $useSmtp  = getSetting('mail_smtp',         '0') === '1';

    if (!$from) return false; // Absender muss konfiguriert sein

    $htmlBody = nl2br(htmlspecialchars($body));
    $fullHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"/>
<style>body{font-family:Arial,sans-serif;background:#f4f4f4;padding:20px}
.box{background:#fff;border-radius:6px;max-width:540px;margin:0 auto;padding:32px;border-top:4px solid '.getSetting('color_primary','#e8333a').'}
h2{margin:0 0 16px;color:#111}p{color:#444;line-height:1.6}
.btn{display:inline-block;padding:10px 22px;background:'.getSetting('color_primary','#e8333a').';color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;margin:16px 0}
.footer{margin-top:24px;font-size:.78rem;color:#999}</style></head>
<body><div class="box"><h2>'.getSetting('league_name','SimRace Liga').'</h2>' . $htmlBody . '<div class="footer">Diese E-Mail wurde automatisch von '.getSetting('league_name','SimRace Liga').' verschickt.</div></div></body></html>';

    if ($useSmtp) {
        return sendMailSmtp($to, $toName, $subject, $fullHtml, $from, $fromName);
    } else {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        return mail($to, $subject, $fullHtml, $headers);
    }
}

function sendMailSmtp(string $to, string $toName, string $subject, string $body, string $from, string $fromName): bool {
    $host = getSetting('mail_smtp_host', '');
    $port = (int)getSetting('mail_smtp_port', '587');
    $user = getSetting('mail_smtp_user', $from);
    $pass = getSetting('mail_smtp_pass', '');
    $enc  = getSetting('mail_smtp_enc',  'tls'); // tls | ssl | none

    if (!$host || !$pass) return false;

    // Socket-basierter SMTP-Client
    $prefix = ($enc === 'ssl') ? 'ssl://' : '';
    $sock = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
    if (!$sock) return false;

    $recv = function() use ($sock) {
        $r = '';
        while ($line = fgets($sock, 515)) { $r .= $line; if ($line[3] === ' ') break; }
        return $r;
    };
    $send = function(string $cmd) use ($sock, $recv) {
        fputs($sock, $cmd . "\r\n");
        return $recv();
    };

    try {
        $recv(); // Banner
        $send("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        if ($enc === 'tls') {
            $send("STARTTLS");
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $send("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }
        $send("AUTH LOGIN");
        $send(base64_encode($user));
        $r = $send(base64_encode($pass));
        if (strpos($r, '235') === false) { fclose($sock); return false; }

        $send("MAIL FROM: <{$from}>");
        $send("RCPT TO: <{$to}>");
        $send("DATA");

        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromEncoded    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $toEncoded      = $toName ? '=?UTF-8?B?' . base64_encode($toName) . '?= <' . $to . '>' : $to;
        $boundary = uniqid('srl_');

        $msg  = "From: {$fromEncoded} <{$from}>\r\n";
        $msg .= "To: {$toEncoded}\r\n";
        $msg .= "Subject: {$subjectEncoded}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($body));
        $msg .= "\r\n.";
        fputs($sock, $msg . "\r\n");
        $r = $recv();
        $send("QUIT");
        fclose($sock);
        return strpos($r, '250') !== false;
    } catch (\Throwable $e) {
        @fclose($sock);
        return false;
    }
}

function sendWelcomeMail(string $to, string $username, string $password): void {
    if (!$to) return;
    $league   = getSetting('league_name', 'SimRace Liga');
    $base = defined('SITE_URL') && SITE_URL !== '' ? rtrim(SITE_URL, '/') : '';
    if (!$base) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base   = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $adminUrl = $base . '/admin/';
    $subject  = "Willkommen im Admin-Bereich – {$league}";
    $body     = "Hallo {$username},\n\nDein Admin-Account für {$league} wurde erstellt.\n\n"
              . "Login: {$adminUrl}\n"
              . "Benutzername: {$username}\n"
              . "Passwort: {$password}\n\n"
              . "Bitte ändere dein Passwort nach dem ersten Login.\n\n"
              . "Viele Grüße,\nDein {$league} Team";
    sendMail($to, $subject, $body, $username);
}

function sendPasswordResetMail(string $to, string $username, string $token): void {
    if (!$to) return;
    $league   = getSetting('league_name', 'SimRace Liga');
    $resetUrl = SITE_URL . '/admin/reset_password.php?token=' . urlencode($token);
    $subject  = "Passwort zurücksetzen – {$league}";
    $body     = "Hallo {$username},\n\nDu hast eine Passwort-Zurücksetzen-Anfrage gestellt.\n\n"
              . "Klicke auf folgenden Link um dein Passwort zurückzusetzen (gültig für 1 Stunde):\n"
              . "<a href=\"{$resetUrl}\" class=\"btn\">Passwort zurücksetzen</a>\n\n"
              . "Falls du diese Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.\n\n"
              . "Viele Grüße,\nDein {$league} Team";
    sendMail($to, $subject, $body, $username);
}

// Helpers
function h(string $s): string { return htmlspecialchars($s,ENT_QUOTES,'UTF-8'); }
function slug(string $s): string { $s=mb_strtolower(trim($s)); foreach(['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'ae','Ö'=>'oe','Ü'=>'ue'] as $k=>$v) $s=str_replace($k,$v,$s); return trim(preg_replace('/[^a-z0-9]+/','-',$s),'-'); }
function jsonResponse(mixed $data, int $code=200): never { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit; }
function redirect(string $url): never { header('Location: '.$url); exit; }
function uploadFile(array $file, string $subdir, array $types=['image/jpeg','image/png','image/gif','image/svg+xml','image/webp']): string|false {
    if($file['error']!==UPLOAD_ERR_OK||!in_array($file['type'],$types)||$file['size']>10*1024*1024) return false;
    $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)); $name=uniqid('',true).'.'.$ext;
    $dir=UPLOAD_DIR.$subdir.'/'; if(!is_dir($dir)) mkdir($dir,0755,true);
    return move_uploaded_file($file['tmp_name'],$dir.$name)?UPLOAD_URL.$subdir.'/'.$name:false;
}

// Maintenance
if(getSetting('maintenance_mode','0')==='1'&&!isLoggedIn()&&!str_contains($_SERVER['REQUEST_URI']??'','/admin/')){
    http_response_code(503); die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Wartung</title><style>body{background:#0a0a0f;color:#f0f0f5;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center}</style></head><body><div><h1 style="color:#e8333a;font-size:4rem">🔧</h1><h2>Wartungsmodus aktiv</h2><p>Bitte später zurückkommen.</p></div></body></html>');
}

// ---- Live Bonus-Punkte Berechnung --------------------------------
// Berechnet Pole+FL Bonus on-the-fly anhand der Settings.
// Wird in Wertungsqueries als SQL-Expression eingebettet.
// Gibt einen SQL-Fragment-String zurück der zu SUM() addiert wird.
function buildBonusSql(string $reAlias = 're', string $qrAlias = 'qr', string $rcAlias = 'rc'): string {
    $flBonus   = getSetting('bonus_points_fl',    '1') === '1' ? 1 : 0;
    $poleBonus = getSetting('bonus_points_pole',  '1') === '1' ? 1 : 0;
    $flFin     = getSetting('fl_only_if_finished','1') === '1';
    $poleFin   = getSetting('pole_only_if_finished','0') === '1';
    $penEnabled = getSetting('penalties_enabled','1') === '1';

    $parts = ["{$reAlias}.points"]; // Basispunkte immer

    // FL-Bonus
    if ($flBonus) {
        $finCheck = $flFin ? "AND {$reAlias}.dnf=0 AND {$reAlias}.dsq=0" : "";
        $parts[] = "({$flBonus} * CASE WHEN {$reAlias}.is_fastest_lap=1 {$finCheck} THEN 1 ELSE 0 END)";
    }

    // Pole-Bonus
    if ($poleBonus) {
        $finCheck = $poleFin ? "AND {$reAlias}.dnf=0 AND {$reAlias}.dsq=0" : "";
        $parts[] = "({$poleBonus} * CASE WHEN {$reAlias}.driver_id IS NOT NULL
                        AND EXISTS (
                            SELECT 1 FROM qualifying_results qr2
                            WHERE qr2.race_id = (SELECT race_id FROM results WHERE id = {$reAlias}.result_id)
                              AND qr2.position = 1
                              AND qr2.driver_id = {$reAlias}.driver_id
                        ) {$finCheck} THEN 1 ELSE 0 END)";
    }

    // Punkteabzug durch Strafen (live, nicht direkt in result_entries)
    if ($penEnabled) {
        $parts[] = "- COALESCE((
            SELECT SUM(p.amount)
            FROM penalties p
            WHERE p.result_id = {$reAlias}.result_id
              AND p.driver_id = {$reAlias}.driver_id
              AND p.type = 'points'
              AND p.applied = 1
        ), 0)";
    }

    return implode(' + ', $parts);
}

// Berechnet den finalen Punktestand eines Fahrers in einem Rennen inkl. aller Strafen
// Gibt auch DSQ-Behandlung zurück (0 Punkte bei DSQ-Strafe)
function getPenaltyInfo(PDO $db, int $resultId, int $driverId): array {
    $penalties = $db->prepare("
        SELECT type, amount, reason FROM penalties
        WHERE result_id=? AND driver_id=? AND applied=1
        ORDER BY created_at ASC
    ");
    $penalties->execute([$resultId, $driverId]);
    return $penalties->fetchAll();
}

