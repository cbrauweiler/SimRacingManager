<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Race Anmeldung'; $adminPage = 'event_signup';
requireRole('editor');
$db = getDB();

// Wetter-Optionen
define('WEATHER_OPTIONS', [
    'Clear'               => ['emoji'=>'☀️',  'label'=>'Klar (Clear)'],
    'LightClouds'         => ['emoji'=>'🌤️', 'label'=>'Leicht bewölkt (Light Clouds)'],
    'PartiallyCloudy'     => ['emoji'=>'⛅',  'label'=>'Teilweise bewölkt (Partially Cloudy)'],
    'MostlyCloudy'        => ['emoji'=>'🌥️', 'label'=>'Überwiegend bewölkt (Mostly Cloudy)'],
    'Overcast'            => ['emoji'=>'☁️',  'label'=>'Bedeckt (Overcast)'],
    'CloudyDrizzle'       => ['emoji'=>'🌦️', 'label'=>'Bewölkt & Niesel (Cloudy & Drizzle)'],
    'CloudyLightRain'     => ['emoji'=>'🌧️', 'label'=>'Leichter Regen (Cloudy & Light Rain)'],
    'OvercastLightRain'   => ['emoji'=>'🌨️', 'label'=>'Bedeckt & leichter Regen (Overcast & Light Rain)'],
    'OvercastRain'        => ['emoji'=>'🌧️', 'label'=>'Regen (Overcast & Rain)'],
    'OvercastHeavyRain'   => ['emoji'=>'💧',  'label'=>'Starkregen (Overcast & Heavy Rain)'],
    'OvercastStorm'       => ['emoji'=>'⛈️',  'label'=>'Gewitter (Overcast & Storm)'],
    'Night'               => ['emoji'=>'🌙',  'label'=>'Nacht (Night)'],
    'Random'              => ['emoji'=>'🎲',  'label'=>'Zufall (Random)'],
    ''                    => ['emoji'=>'',    'label'=>'– nicht angegeben –'],
]);

// Bot-HTTP-Request via fsockopen (funktioniert auch ohne allow_url_fopen)
function botRequest(string $port, string $path, array $payload): ?array {
    try {
        $sock = @fsockopen('127.0.0.1', (int)$port, $errno, $errstr, 5);
        if (!$sock) return null;
        $body = json_encode($payload);
        $req  = "POST {$path} HTTP/1.1\r\n"
              . "Host: 127.0.0.1\r\n"
              . "Content-Type: application/json\r\n"
              . "Content-Length: " . strlen($body) . "\r\n"
              . "Connection: close\r\n\r\n"
              . $body;
        fwrite($sock, $req);
        $raw = '';
        while (!feof($sock)) $raw .= fgets($sock, 4096);
        fclose($sock);
        $respBody = substr($raw, strpos($raw, "\r\n\r\n") + 4);
        return $respBody ? json_decode(trim($respBody), true) : null;
    } catch (\Throwable $e) { return null; }
}

$botEnabled  = getSetting('discord_bot_enabled','0') === '1';
$botToken    = getSetting('discord_bot_token','');
$botChannel  = getSetting('discord_bot_channel','');
$botPort     = getSetting('discord_bot_port','3001');
$botReady    = $botEnabled && $botToken && $botChannel;

// Rennen laden
$races = [];
try {
    $races = $db->query("
        SELECT rc.*, s.name AS season_name, s.year,
               (SELECT id FROM discord_events WHERE race_id=rc.id AND is_closed=0 LIMIT 1) AS open_event_id,
               (SELECT id FROM discord_events WHERE race_id=rc.id LIMIT 1) AS any_event_id
        FROM races rc
        JOIN seasons s ON s.id=rc.season_id
        WHERE s.is_active=1
        ORDER BY rc.round ASC
    ")->fetchAll();
} catch (\PDOException $e) {
    $races = $db->query("SELECT rc.*, s.name AS season_name, s.year, NULL AS open_event_id, NULL AS any_event_id FROM races rc JOIN seasons s ON s.id=rc.season_id WHERE s.is_active=1 ORDER BY rc.round ASC")->fetchAll();
}

// POST: Event erstellen + an Bot senden
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'post_event') {
        $raceId       = (int)$_POST['race_id'];
        $trainTime    = trim($_POST['time_training'] ?? '');
        $briefTime    = trim($_POST['time_briefing'] ?? '');
        $raceTime     = trim($_POST['time_race']     ?? '');
        // 5 Wetter-Slots je Session
        $wxTraining = array_map(fn($i) => $_POST["wx_training_{$i}"] ?? 'Clear', range(1,5));
        $wxQuali    = array_map(fn($i) => $_POST["wx_quali_{$i}"]    ?? 'Clear', range(1,5));
        $wxRace     = array_map(fn($i) => $_POST["wx_race_{$i}"]     ?? 'Clear', range(1,5));
        $deadlineHrs  = (int)($_POST['deadline_hours'] ?? 2);
        $mentionRole  = trim($_POST['mention_role']  ?? '');
        $extraInfo    = trim($_POST['extra_info']    ?? '');

        $race = $db->prepare("SELECT rc.*,s.name AS season_name,s.year FROM races rc JOIN seasons s ON s.id=rc.season_id WHERE rc.id=?");
        $race->execute([$raceId]); $race=$race->fetch();
        if (!$race) { $_SESSION['flash']=['type'=>'error','msg'=>'❌ Rennen nicht gefunden.']; header('Location: '.SITE_URL.'/admin/event_signup.php'); exit; }

        // Deadline berechnen
        $raceDateTime = $race['race_date'] . ' ' . ($race['race_time'] ?: '00:00:00');
        $deadline = date('Y-m-d H:i:s', strtotime($raceDateTime) - ($deadlineHrs * 3600));

        // Event in DB speichern
        $payloadJson = json_encode($payload ?? []);
        $db->prepare("INSERT INTO discord_events (race_id,channel_id,deadline,created_by,event_payload) VALUES (?,?,?,?,?)")
           ->execute([$raceId, $botChannel, $deadline, currentUser()['id'], '{}']);
        $eventId = (int)$db->lastInsertId();

        // Payload für Bot zusammenbauen
        $wo = WEATHER_OPTIONS;
        $payload = [
            'event_id'      => $eventId,
            'race_id'       => $raceId,
            'round'         => $race['round'],
            'track_name'    => $race['track_name'],
            'location'      => $race['location'] ?? '',
            'season_name'   => $race['season_name'],
            'race_date'     => $race['race_date'],
            'race_time'     => $race['race_time'],
            'time_training' => $trainTime,
            'time_briefing' => $briefTime,
            'time_race'     => $raceTime,
            'wx_training'   => array_map(fn($k) => ['key'=>$k,'emoji'=>$wo[$k]['emoji']??'❓'], $wxTraining),
            'wx_quali'      => array_map(fn($k) => ['key'=>$k,'emoji'=>$wo[$k]['emoji']??'❓'], $wxQuali),
            'wx_race'       => array_map(fn($k) => ['key'=>$k,'emoji'=>$wo[$k]['emoji']??'❓'], $wxRace),
            'deadline'      => $deadline,
            'deadline_hours'=> $deadlineHrs,
            'channel_id'    => $botChannel,
            'callback_url'  => SITE_URL . '/api/discord_interaction.php',
            'bot_secret'    => substr(hash('sha256', $botToken), 0, 32),
            'mention_role'  => $mentionRole,
            'extra_info'    => $extraInfo,
        ];

        // HTTP-Request an Bot
        $resp = botRequest($botPort, '/post-event', $payload);

        if ($resp && !empty($resp['message_id'])) {
            $db->prepare("UPDATE discord_events SET message_id=?, thread_id=? WHERE id=?")
               ->execute([$resp['message_id'], $resp['thread_id']??null, $eventId]);
            auditLog('discord_event_post','discord_events',$eventId,$race['track_name']);
            $_SESSION['flash']=['type'=>'success','msg'=>'✅ Anmeldung in Discord gepostet!'];
        } else {
            $db->prepare("DELETE FROM discord_events WHERE id=?")->execute([$eventId]);
            $err = $resp['error'] ?? ($response ?: 'Bot nicht erreichbar');
            $_SESSION['flash']=['type'=>'error','msg'=>'❌ Bot-Fehler: '.h($err)];
        }
        header('Location: '.SITE_URL.'/admin/event_signup.php'); exit;
    }

    if ($action === 'close_event') {
        $eventId = (int)$_POST['event_id'];
        $event = $db->prepare("SELECT * FROM discord_events WHERE id=?")->execute([$eventId]) ? $db->prepare("SELECT * FROM discord_events WHERE id=?") : null;
        $evStmt = $db->prepare("SELECT * FROM discord_events WHERE id=?");
        $evStmt->execute([$eventId]); $ev = $evStmt->fetch();
        if ($ev) {
            // Bot anweisen Buttons zu deaktivieren
            $payload = ['event_id'=>$eventId, 'message_id'=>$ev['message_id'], 'channel_id'=>$ev['channel_id'], 'bot_secret'=>substr(hash('sha256',$botToken),0,32)];
            botRequest($botPort, '/close-event', $payload);
            $db->prepare("UPDATE discord_events SET is_closed=1 WHERE id=?")->execute([$eventId]);
            auditLog('discord_event_close','discord_events',$eventId);
        }
        $_SESSION['flash']=['type'=>'success','msg'=>'✅ Anmeldung geschlossen.'];
        header('Location: '.SITE_URL.'/admin/event_signup.php'); exit;
    }

    if ($action === 'delete_event') {
        $eventId = (int)$_POST['event_id'];
        $evStmt = $db->prepare("SELECT * FROM discord_events WHERE id=?");
        $evStmt->execute([$eventId]); $ev = $evStmt->fetch();
        if ($ev) {
            // Bot anweisen Nachricht zu löschen
            $payload = ['event_id'=>$eventId,'message_id'=>$ev['message_id'],'channel_id'=>$ev['channel_id'],'thread_id'=>$ev['thread_id']??null,'bot_secret'=>substr(hash('sha256',$botToken),0,32)];
            botRequest($botPort, '/delete-event', $payload);
            $db->prepare("DELETE FROM discord_events WHERE id=?")->execute([$eventId]);
            auditLog('discord_event_delete','discord_events',$eventId);
        }
        $_SESSION['flash']=['type'=>'success','msg'=>'🗑 Anmeldung entfernt.'];
        header('Location: '.SITE_URL.'/admin/event_signup.php'); exit;
    }
}

// Tabellen prüfen + Events laden
$tablesExist = false;
try {
    // Prüft ob beide Tabellen mit korrekten Spalten existieren
    $db->query("SELECT event_id FROM race_signups LIMIT 1");
    $db->query("SELECT id FROM discord_events LIMIT 1");
    $tablesExist = true;
} catch (\PDOException $e) {
    // Migration nötig
}

$events = [];
if ($tablesExist) {
    $events = $db->query("
        SELECT de.*, rc.track_name, rc.round, rc.race_date, rc.race_time, s.name AS season_name,
               (SELECT COUNT(*) FROM race_signups WHERE event_id=de.id AND status='accepted')  AS cnt_yes,
               (SELECT COUNT(*) FROM race_signups WHERE event_id=de.id AND status='declined')  AS cnt_no,
               (SELECT COUNT(*) FROM race_signups WHERE event_id=de.id AND status='maybe')     AS cnt_maybe
        FROM discord_events de
        JOIN races rc ON rc.id=de.race_id
        JOIN seasons s ON s.id=rc.season_id
        ORDER BY de.sent_at DESC LIMIT 20
    ")->fetchAll();
}

require_once __DIR__ . '/includes/layout.php';
$wo = WEATHER_OPTIONS;
$defaultHrs = getSetting('discord_signup_hours','2');
?>
<div class="admin-page-title">Race <span style="color:var(--primary)">Anmeldung</span></div>
<div class="admin-page-sub">Discord Anmeldeformular für das nächste Rennen posten</div>

<?php if (!$tablesExist): ?>
<div class="notice notice-error mb-3">
  ❌ <strong>Datenbank-Migration fehlt.</strong>
  Bitte folgende SQL-Migration in phpMyAdmin ausführen:
  <pre style="margin-top:8px;font-size:.78rem;background:var(--bg3);padding:10px;border-radius:4px;overflow-x:auto">-- race_signups: event_id + discord_events Tabelle hinzufügen
ALTER TABLE `race_signups`
  ADD COLUMN IF NOT EXISTS `event_id` INT NOT NULL DEFAULT 0 AFTER `id`,
  ADD COLUMN IF NOT EXISTS `discord_avatar` VARCHAR(200) NULL DEFAULT NULL;

-- Foreign Key + Unique Key (falls noch nicht vorhanden)
ALTER TABLE `race_signups`
  DROP INDEX IF EXISTS `unique_signup`,
  ADD UNIQUE KEY IF NOT EXISTS `uq_event_user` (`event_id`, `discord_user_id`);

-- discord_events Tabelle (falls noch nicht vorhanden)
CREATE TABLE IF NOT EXISTS `discord_events` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `race_id`     INT NOT NULL,
  `message_id`  VARCHAR(30)  NULL DEFAULT NULL,
  `channel_id`  VARCHAR(30)  NULL DEFAULT NULL,
  `thread_id`   VARCHAR(30)  NULL DEFAULT NULL,
  `deadline`    DATETIME     NULL DEFAULT NULL,
  `is_closed`   TINYINT(1)   NOT NULL DEFAULT 0,
  `sent_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `created_by`  INT          NULL DEFAULT NULL,
  FOREIGN KEY (`race_id`) REFERENCES `races`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
</div>
<?php endif; ?>
<?php if (!$botReady): ?>
<div class="notice notice-warning mb-3">
  ⚠️ <strong>Discord Bot nicht konfiguriert.</strong>
  Bitte zuerst unter <a href="<?= SITE_URL ?>/admin/advanced.php#bot" style="color:var(--primary)">Erweitert → Discord Bot</a>
  Token, Channel-ID und Port eintragen und den Bot aktivieren.
</div>
<?php endif; ?>

<div class="grid-2" style="gap:20px;align-items:start">

  <!-- LINKS: Neues Event erstellen -->
  <div class="card">
    <div class="card-header"><h3>📋 Anmeldung erstellen</h3></div>
    <div class="card-body">
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="post_event"/>

        <div class="form-group">
          <label>Rennen *</label>
          <select name="race_id" class="form-control" required>
            <option value="">– Rennen wählen –</option>
            <?php foreach ($races as $r): ?>
            <option value="<?= $r['id'] ?>" <?= $r['open_event_id']?'disabled':'' ?>>
              R<?= (int)$r['round'] ?> – <?= h($r['track_name']) ?>
              <?= $r['race_date'] ? ' ('.date('d.m.Y',strtotime($r['race_date'])).')' : '' ?>
              <?= $r['open_event_id'] ? ' ⚠ Bereits aktiv' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>📣 Discord-Rolle markieren</label>
          <input type="text" name="mention_role" class="form-control"
                 value="<?= h(getSetting('discord_bot_mention_role','')) ?>"
                 placeholder="z.B. 1234567890123456789 oder @everyone"/>
          <div class="form-hint">Rollen-ID, <code>@everyone</code> oder <code>@here</code>. Leer = keine Markierung.</div>
        </div>

        <div class="form-group">
          <label>📝 Zusätzliche Informationen <span class="text-muted" style="font-weight:400;font-size:.75rem">(optional, Discord-Formatierung erlaubt)</span></label>
          <textarea name="extra_info" class="form-control" rows="3"
                    placeholder="z.B. **Pflichtlektüre:** Regelwerk beachten! ⚠️ Reifenstrategie: Mindestens 1 Pflichtboxenstopp."
                    style="font-family:monospace;font-size:.85rem;resize:vertical"></textarea>
          <div class="form-hint">Erscheint vor dem Zeitplan im Discord-Post. Unterstützt **fett**, *kursiv*, __unterstrichen__, \`code\`, > Zitat</div>
        </div>

        <div class="divider"></div>
        <div class="form-group"><label style="font-weight:700;color:var(--text)">⏰ Zeitplan</label></div>
        <div class="form-row cols-3">
          <div class="form-group">
            <label>Training</label>
            <input type="time" name="time_training" class="form-control"
                   value="<?= h(getSetting('discord_default_time_training','')) ?>"/>
          </div>
          <div class="form-group">
            <label>Briefing</label>
            <input type="time" name="time_briefing" class="form-control"
                   value="<?= h(getSetting('discord_default_time_briefing','')) ?>"/>
          </div>
          <div class="form-group">
            <label>Event-Start</label>
            <input type="time" name="time_race" class="form-control"
                   value="<?= h(getSetting('discord_default_time_race','')) ?>"/>
          </div>
        </div>

        <div class="divider"></div>
        <div class="form-group"><label style="font-weight:700;color:var(--text)">🌤️ Wetter (5 Zeitslots je Session: Verlauf von Beginn bis Ende)</label></div>
        <?php foreach ([
            ['prefix'=>'wx_training', 'label'=>'🏋️ Training'],
            ['prefix'=>'wx_quali',    'label'=>'⏱️ Qualifying'],
            ['prefix'=>'wx_race',     'label'=>'🏁 Rennen'],
        ] as $wx): ?>
        <div class="form-group">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
            <label style="margin:0"><?= $wx['label'] ?></label>
            <div style="display:flex;align-items:center;gap:6px">
              <select class="form-control form-control-sm" id="sync-<?= $wx['prefix'] ?>" style="font-size:.78rem;padding:3px 6px;width:auto">
                <?php foreach ($wo as $key => $opt): ?>
                <option value="<?= $key ?>"><?= $opt['emoji'] ? $opt['emoji'].' ' : '' ?><?= h($opt['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-secondary btn-sm"
                      style="font-size:.75rem;padding:3px 8px;white-space:nowrap"
                      onclick="syncWeather('<?= $wx['prefix'] ?>')">Alle gleich</button>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php for ($s=1; $s<=5; $s++): ?>
            <div style="flex:1;min-width:100px">
              <div class="text-muted" style="font-size:.7rem;margin-bottom:3px;text-align:center">Slot <?= $s ?></div>
              <select name="<?= $wx['prefix'] ?>_<?= $s ?>" id="<?= $wx['prefix'] ?>_<?= $s ?>" class="form-control" style="padding:5px 6px;font-size:.82rem">
                <?php foreach ($wo as $key => $opt): ?>
                <option value="<?= $key ?>" <?= $key===''?'selected':'' ?>><?= $opt['emoji'] ? $opt['emoji'].' ' : '' ?><?= h($opt['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endfor; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <div class="divider"></div>
        <div class="form-group">
          <label>⏳ Anmeldefrist (Stunden vor Rennstart)</label>
          <input type="number" name="deadline_hours" class="form-control" value="<?= $defaultHrs ?>" min="0" max="72" style="max-width:120px"/>
          <div class="form-hint">0 = keine Frist</div>
        </div>

        <button type="submit" class="btn btn-primary w-full" <?= !$botReady?'disabled':'' ?>>
          📢 In Discord posten
        </button>
      </form>
    </div>
  </div>

  <!-- RECHTS: Wettervorschau + Bisherige Anmeldungen -->
  <div style="display:flex;flex-direction:column;gap:16px">

  <!-- Wettervorschau -->
  <div class="card" id="weather-card">
    <div class="card-header">
      <h3>🌤️ Wettervorschau <span class="text-muted" style="font-size:.8rem;font-weight:400">via Open-Meteo</span></h3>
    </div>
    <div class="card-body">
      <div id="wx-location-hint" class="text-muted mb-2" style="font-size:.78rem">
        📍 Rennen wählen oder Koordinaten manuell eingeben
      </div>
      <div class="flex gap-2 mb-2" style="flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0;flex:1;min-width:100px">
          <label style="font-size:.75rem">Lat</label>
          <input type="text" id="wx-lat-input" class="form-control" placeholder="z.B. 26.0325"
                 value="<?= h(getSetting('weather_location_lat','')) ?>"/>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:100px">
          <label style="font-size:.75rem">Lon</label>
          <input type="text" id="wx-lon-input" class="form-control" placeholder="z.B. 50.5106"
                 value="<?= h(getSetting('weather_location_lon','')) ?>"/>
        </div>
      </div>
      <div class="flex gap-2 mb-3" style="flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0;flex:0 0 auto">
          <label style="font-size:.75rem">Datum</label>
          <input type="date" id="wx-date" class="form-control"
                 value="<?= date('Y-m-d') ?>"
                 min="<?= date('Y-m-d') ?>"
                 max="<?= date('Y-m-d', strtotime('+16 days')) ?>"/>
        </div>
        <div class="form-group" style="margin:0;flex:0 0 auto">
          <label style="font-size:.75rem">Tage (max. 16)</label>
          <input type="number" id="wx-days" class="form-control" value="1" min="1" max="16" style="width:70px"/>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="loadWeather()" style="margin-bottom:1px">
          🔍 Laden
        </button>
      </div>
      <div id="wx-result" style="font-size:.83rem"></div>
    </div>
  </div>

  <!-- Aktive Events -->
  <div class="card">
    <div class="card-header"><h3>📊 Bisherige Anmeldungen</h3></div>
    <div class="card-body" style="padding:0;max-height:600px;overflow-y:auto">
      <?php if ($events): foreach ($events as $ev): ?>
      <div style="padding:14px 16px;border-bottom:1px solid var(--border);border-left:4px solid <?= $ev['is_closed'] ? 'var(--primary)' : '#4cffb0' ?>;transition:border-color .3s">
        <div class="flex justify-between align-center gap-2 mb-1" style="flex-wrap:wrap">
          <div>
            <strong>R<?= (int)$ev['round'] ?> · <?= h($ev['track_name']) ?></strong>
            <span class="text-muted" style="font-size:.8rem;margin-left:6px"><?= h($ev['season_name']) ?></span>
            <?php if ($ev['is_closed']): ?>
              <span class="badge badge-muted" style="font-size:.65rem">Geschlossen</span>
            <?php else: ?>
              <span class="badge badge-success" style="font-size:.65rem">Aktiv</span>
            <?php endif; ?>
          </div>
          <div class="flex gap-1">
            <?php if (!$ev['is_closed']): ?>
            <form method="post" style="display:inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="close_event"/>
              <input type="hidden" name="event_id" value="<?= $ev['id'] ?>"/>
              <button class="btn btn-secondary btn-sm" title="Anmeldung schließen">⏹</button>
            </form>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Anmeldung und alle Antworten löschen?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_event"/>
              <input type="hidden" name="event_id" value="<?= $ev['id'] ?>"/>
              <button class="btn btn-danger btn-sm">🗑</button>
            </form>
          </div>
        </div>
        <div class="flex gap-3" style="font-size:.85rem;margin-top:6px">
          <span style="color:#4cffb0">✅ <?= (int)$ev['cnt_yes'] ?></span>
          <span style="color:#ff8080">❌ <?= (int)$ev['cnt_no'] ?></span>
          <span style="color:#f5a623">❓ <?= (int)$ev['cnt_maybe'] ?></span>
        </div>
        <?php if ($ev['deadline'] && !$ev['is_closed']): ?>
        <div class="text-muted" style="font-size:.75rem;margin-top:4px">
          ⏳ Frist: <?= date('d.m.Y H:i', strtotime($ev['deadline'])) ?> Uhr
        </div>
        <?php endif; ?>
        <!-- Teilnehmerliste aufklappen -->
        <div id="signup-detail-<?= $ev['id'] ?>" style="display:none;margin-top:8px">
          <?php
          $sups = $db->prepare("SELECT * FROM race_signups WHERE event_id=? ORDER BY status ASC, changed_at ASC");
          $sups->execute([$ev['id']]); $sups=$sups->fetchAll();
          foreach (['accepted'=>['✅','#4cffb0'],'declined'=>['❌','#ff8080'],'maybe'=>['❓','#f5a623']] as $st=>[$ico,$col]):
            $filtered = array_filter($sups, fn($s)=>$s['status']===$st);
            if ($filtered): ?>
          <div style="margin-top:4px">
            <?php foreach ($filtered as $su): ?>
            <div style="font-size:.82rem;color:<?= $col ?>"><?= $ico ?> <?= h($su['discord_username']) ?></div>
            <?php endforeach; ?>
          </div>
          <?php endif; endforeach; ?>
        </div>
        <?php if ($sups ?? false): ?>
        <button type="button" class="btn btn-secondary btn-sm" style="margin-top:6px;font-size:.72rem"
                onclick="var d=document.getElementById('signup-detail-<?= $ev['id'] ?>');d.style.display=d.style.display==='none'?'block':'none'">
          👥 Teilnehmer anzeigen
        </button>
        <?php endif; ?>
      </div>
      <?php endforeach; else: ?>
      <div style="padding:18px" class="text-muted">Noch keine Anmeldungen erstellt.</div>
      <?php endif; ?>
    </div>
  </div>

  </div><!-- /RECHTS -->

</div>
<script>
// ── Wettervorschau (Open-Meteo) ────────────────────────────
// ── Wettervorschau (Open-Meteo) ────────────────────────────
var WX_LAT  = '<?= h(getSetting('weather_location_lat','')) ?>';
var WX_LON  = '<?= h(getSetting('weather_location_lon','')) ?>';
var WX_NAME = '<?= h(getSetting('weather_location_name','Standort')) ?>';

// Beim Rennen-Wechsel: Koordinaten aus Strecke übernehmen falls vorhanden
document.addEventListener('DOMContentLoaded', function() {
    var raceSel = document.querySelector('select[name="race_id"]');
    if (!raceSel) return;
    raceSel.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        var lat = opt.getAttribute('data-lat');
        var lon = opt.getAttribute('data-lon');
        var loc = opt.getAttribute('data-loc');
        var latInp = document.getElementById('wx-lat-input');
        var lonInp = document.getElementById('wx-lon-input');
        var hint   = document.getElementById('wx-location-hint');
        if (lat && lon) {
            WX_LAT  = lat;
            WX_LON  = lon;
            WX_NAME = loc || opt.text;
            if (latInp) latInp.value = lat;
            if (lonInp) lonInp.value = lon;
            if (hint) hint.textContent = '📍 ' + WX_NAME + ' (' + lat + ', ' + lon + ')';
        } else {
            // Keine Koordinaten an der Strecke – Felder leeren damit User manuell eingibt
            if (latInp) latInp.value = '';
            if (lonInp) lonInp.value = '';
            WX_LAT = ''; WX_LON = '';
            if (hint) hint.textContent = '⚠️ Keine Koordinaten für diese Strecke hinterlegt – bitte manuell eingeben';
        }
    });
});

// WMO Wettercodes → Emoji + Label
var WMO_MAP = {
    0:  ['☀️','Klar'],
    1:  ['🌤️','Überwiegend klar'], 2: ['⛅','Teilweise bewölkt'], 3: ['☁️','Bedeckt'],
    45: ['🌫️','Nebel'], 48: ['🌫️','Raureif-Nebel'],
    51: ['🌦️','Leichter Niesel'], 53: ['🌦️','Niesel'], 55: ['🌧️','Starker Niesel'],
    61: ['🌧️','Leichter Regen'], 63: ['🌧️','Regen'], 65: ['🌧️','Starker Regen'],
    71: ['🌨️','Leichter Schnee'], 73: ['❄️','Schnee'], 75: ['❄️','Starker Schnee'],
    80: ['🌦️','Leichte Schauer'], 81: ['🌧️','Schauer'], 82: ['⛈️','Starke Schauer'],
    95: ['⛈️','Gewitter'], 96: ['⛈️','Gewitter mit Hagel'], 99: ['⛈️','Starkes Gewitter'],
};

// 5 gleichmäßig verteilte Stunden zwischen 10 und 20 Uhr: 10,12,14,17,20
var HOUR_SLOTS = [10, 12, 14, 17, 20];

// Mapping Open-Meteo WMO → unser WEATHER_OPTIONS key (Annäherung)
var WMO_TO_KEY = {
    0: 'Clear', 1: 'LightClouds', 2: 'PartiallyCloudy', 3: 'Overcast',
    45: 'Overcast', 48: 'Overcast',
    51: 'CloudyDrizzle', 53: 'CloudyDrizzle', 55: 'CloudyLightRain',
    61: 'CloudyLightRain', 63: 'OvercastRain', 65: 'OvercastHeavyRain',
    71: 'OvercastLightRain', 73: 'OvercastRain', 75: 'OvercastHeavyRain',
    80: 'CloudyLightRain', 81: 'OvercastRain', 82: 'OvercastHeavyRain',
    95: 'OvercastStorm', 96: 'OvercastStorm', 99: 'OvercastStorm',
};

function loadWeather() {
    var date = document.getElementById('wx-date').value;
    var days = parseInt(document.getElementById('wx-days').value) || 1;
    // Manuelle Eingabefelder haben Vorrang vor JS-Variablen
    var latInput = document.getElementById('wx-lat-input');
    var lonInput = document.getElementById('wx-lon-input');
    if (latInput && latInput.value.trim()) WX_LAT = latInput.value.trim();
    if (lonInput && lonInput.value.trim()) WX_LON = lonInput.value.trim();
    if (!date) { alert('Bitte ein Datum auswählen.'); return; }
    if (!WX_LAT || !WX_LON) { alert('Bitte Koordinaten eingeben (Lat/Lon) oder ein Rennen mit gepflegter Strecke wählen.'); return; }

    // Enddatum berechnen
    var startD = new Date(date);
    var endD   = new Date(date);
    endD.setDate(endD.getDate() + days - 1);
    var endDate = endD.toISOString().slice(0,10);

    var el = document.getElementById('wx-result');
    el.innerHTML = '<div class="text-muted">⏳ Lade Vorhersage...</div>';

    var url = 'https://api.open-meteo.com/v1/forecast'
        + '?latitude=' + WX_LAT
        + '&longitude=' + WX_LON
        + '&hourly=weathercode,temperature_2m,precipitation_probability,wind_speed_10m'
        + '&start_date=' + date
        + '&end_date=' + endDate
        + '&timezone=Europe%2FBerlin'
        + '&wind_speed_unit=kmh';

    fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(data){ renderWeather(data, date, days, startD); })
        .catch(function(){ el.innerHTML = '<div class="notice notice-error">❌ API-Fehler. Bitte erneut versuchen.</div>'; });
}

function renderWeather(data, startDate, days, startD) {
    var el    = document.getElementById('wx-result');
    var times = data.hourly.time;
    var codes = data.hourly.weathercode;
    var temps = data.hourly.temperature_2m;
    var precs = data.hourly.precipitation_probability;
    var winds = data.hourly.wind_speed_10m;

    var html = '<div style="display:flex;flex-direction:column;gap:16px">';

    for (var d = 0; d < days; d++) {
        var dayD = new Date(startD);
        dayD.setDate(dayD.getDate() + d);
        var dayStr   = dayD.toISOString().slice(0,10);
        var dayLabel = dayD.toLocaleDateString('de-DE',{weekday:'long',day:'2-digit',month:'2-digit',year:'numeric'});

        // 5 Slots für diesen Tag
        var slots = [];
        for (var s = 0; s < HOUR_SLOTS.length; s++) {
            var h   = HOUR_SLOTS[s];
            var key = dayStr + 'T' + String(h).padStart(2,'0') + ':00';
            var idx = times.indexOf(key);
            if (idx === -1) continue;
            var wmo  = codes[idx];
            var info = WMO_MAP[wmo] || ['❓','Unbekannt'];
            slots.push({
                hour:    h,
                emoji:   info[0],
                label:   info[1],
                temp:    temps[idx] !== undefined ? Math.round(temps[idx]) + '°C' : '–',
                prec:    precs[idx] !== undefined ? precs[idx] + '%' : '–',
                wind:    winds[idx] !== undefined ? Math.round(winds[idx]) + ' km/h' : '–',
                wmo:     wmo,
                wxKey:   WMO_TO_KEY[wmo] || '',
            });
        }

        html += '<div>';
        html += '<div style="font-weight:700;font-size:.85rem;margin-bottom:8px;color:var(--text2)">' + dayLabel + '</div>';
        html += '<div style="display:flex;gap:6px;flex-wrap:wrap">';

        slots.forEach(function(slot) {
            html += '<div style="flex:1;min-width:90px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:8px;text-align:center">';
            html += '<div style="font-size:.72rem;color:var(--text2);margin-bottom:4px">' + String(slot.hour).padStart(2,'0') + ':00</div>';
            html += '<div style="font-size:1.6rem;line-height:1">' + slot.emoji + '</div>';
            html += '<div style="font-size:.72rem;color:var(--text2);margin-top:3px;line-height:1.3">' + slot.label + '</div>';
            html += '<div style="font-size:.78rem;font-weight:700;margin-top:5px">' + slot.temp + '</div>';
            html += '<div style="font-size:.68rem;color:var(--text2)">💧' + slot.prec + ' 💨' + slot.wind + '</div>';
            // Übernehmen-Button
            if (days === 1) {
                html += '<button type="button" onclick="applySlot(' + slots.indexOf(slot) + ','' + slot.wxKey + '')" '
                    + 'class="btn btn-secondary btn-sm" style="margin-top:6px;font-size:.65rem;padding:2px 6px;width:100%">→ übernehmen</button>';
            }
            html += '</div>';
        });

        html += '</div>';

        // Slots auf Wetterfelder übertragen (nur bei 1 Tag)
        if (days === 1 && slots.length > 0) {
            html += '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">';
            html += '<span style="font-size:.75rem;color:var(--text2)">Alle Slots übernehmen:</span>';
            ['wx_training','wx_quali','wx_race'].forEach(function(prefix) {
                var label = prefix==='wx_training'?'Training':prefix==='wx_quali'?'Qualifying':'Rennen';
                html += '<button type="button" onclick="applyAllSlots('' + prefix + '',window._wxSlots)" '
                    + 'class="btn btn-secondary btn-sm" style="font-size:.72rem">'
                    + '→ ' + label + '</button>';
            });
            html += '</div>';
            // Slots global speichern für Übernahme
            html += '<script>window._wxSlots = ' + JSON.stringify(slots.map(function(s){return s.wxKey;})) + ';<\/script>';
        }

        html += '</div>';
    }

    html += '</div>';
    el.innerHTML = html;
}

function applyAllSlots(prefix, slotKeys) {
    if (!slotKeys) return;
    for (var i = 0; i < Math.min(5, slotKeys.length); i++) {
        var sel = document.getElementById(prefix + '_' + (i+1));
        if (sel && slotKeys[i]) sel.value = slotKeys[i];
    }
    // Sync-Dropdown aktualisieren
    var sync = document.getElementById('sync-' + prefix);
    if (sync && slotKeys[0]) sync.value = slotKeys[0];
}

function applySlot(slotIdx, wxKey) {
    // Einzelnen Slot auf alle Sessions anwenden (befüllt Slot i+1)
    ['wx_training','wx_quali','wx_race'].forEach(function(prefix) {
        var sel = document.getElementById(prefix + '_' + (slotIdx+1));
        if (sel && wxKey) sel.value = wxKey;
    });
}

// ── Wetter-Sync ────────────────────────────────────────────
function syncWeather(prefix) {
    var val = document.getElementById('sync-' + prefix).value;
    for (var i = 1; i <= 5; i++) {
        var sel = document.getElementById(prefix + '_' + i);
        if (sel) sel.value = val;
    }
}
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
