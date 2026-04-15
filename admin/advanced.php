<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'Erweiterte Einstellungen'; $adminPage = 'advanced';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('admin'); verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'bot') {
        setSetting('discord_bot_token',   trim($_POST['discord_bot_token']   ?? ''));
        setSetting('discord_bot_channel', trim($_POST['discord_bot_channel'] ?? ''));
        setSetting('discord_bot_port',    trim($_POST['discord_bot_port']    ?? '3001'));
        setSetting('discord_bot_enabled', isset($_POST['discord_bot_enabled']) ? '1' : '0');
        setSetting('discord_signup_hours',trim($_POST['discord_signup_hours'] ?? '2'));

        // config.json für Bot schreiben
        $botToken  = getSetting('discord_bot_token','');
        $botPort   = (int)getSetting('discord_bot_port','3001');
        $botSecret = substr(hash('sha256', $botToken), 0, 32);
        $configPath = dirname(__DIR__) . '/bot/config.json';
        $configData = json_encode([
            'token'        => $botToken,
            'port'         => $botPort,
            'callback_url' => SITE_URL . '/api/discord_interaction.php',
            'bot_secret'   => $botSecret,
        ], JSON_PRETTY_PRINT);
        @file_put_contents($configPath, $configData);

        auditLog('settings_bot');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Bot-Einstellungen gespeichert!'];
        header('Location: '.SITE_URL.'/admin/advanced.php#bot'); exit;
    }
    if ($action === 'mail') {
        setSetting('mail_from',      trim($_POST['mail_from']??''));
        setSetting('mail_from_name', trim($_POST['mail_from_name']??''));
        setSetting('mail_smtp',      isset($_POST['mail_smtp'])?'1':'0');
        setSetting('mail_smtp_host', trim($_POST['mail_smtp_host']??''));
        setSetting('mail_smtp_port', trim($_POST['mail_smtp_port']??'587'));
        setSetting('mail_smtp_user', trim($_POST['mail_smtp_user']??''));
        setSetting('mail_smtp_enc',  in_array($_POST['mail_smtp_enc']??'tls',['tls','ssl','none'])?$_POST['mail_smtp_enc']:'tls');
        if (!empty($_POST['mail_smtp_pass'])) setSetting('mail_smtp_pass', $_POST['mail_smtp_pass']);
        auditLog('settings_mail');
        $_SESSION['flash']=['type'=>'success','msg'=>'✅ Mail-Einstellungen gespeichert!'];
        header('Location: '.SITE_URL.'/admin/advanced.php#mail'); exit;
    }
    if ($action === 'mail_test') {
        $testTo = trim($_POST['test_email']??'');
        if (!$testTo) { $_SESSION['flash']=['type'=>'error','msg'=>'❌ Bitte Test-E-Mail-Adresse eingeben!']; }
        elseif (!getSetting('mail_from','')) { $_SESSION['flash']=['type'=>'error','msg'=>'❌ Absender-E-Mail muss konfiguriert sein!']; }
        else {
            $ok = sendMail($testTo, 'Test-Mail – '.getSetting('league_name'), "Dies ist eine Test-E-Mail von ".getSetting('league_name').".\n\nWenn du diese E-Mail siehst, funktioniert der Mail-Versand korrekt!");
            $_SESSION['flash'] = $ok
                ? ['type'=>'success','msg'=>'✅ Test-Mail an '.$testTo.' gesendet!']
                : ['type'=>'error','msg'=>'❌ Mail-Versand fehlgeschlagen. Einstellungen prüfen.'];
        }
        header('Location: '.SITE_URL.'/admin/advanced.php#mail'); exit;
    }
    if ($action === 'discord') {
        setSetting('discord_webhook_url', trim($_POST['discord_webhook_url']??''));
        setSetting('discord_notify_results', isset($_POST['discord_notify_results'])?'1':'0');
        setSetting('discord_notify_news', isset($_POST['discord_notify_news'])?'1':'0');
        auditLog('settings_discord');
        $_SESSION['flash']=['type'=>'success','msg'=>'✅ Discord Einstellungen gespeichert!'];
        header('Location: '.SITE_URL.'/admin/advanced.php#discord'); exit;
    }
    if ($action === 'discord_test') {
        $webhookUrl = getSetting('discord_webhook_url');
        if (!$webhookUrl) { $_SESSION['flash']=['type'=>'error','msg'=>'❌ Kein Webhook URL gesetzt!']; }
        else {
            discordNotify('🏁 **Test-Nachricht** von '.getSetting('league_name').' – Webhook funktioniert! 🎉');
            $_SESSION['flash']=['type'=>'success','msg'=>'✅ Test-Nachricht gesendet! Prüfe deinen Discord-Channel.'];
        }
        header('Location: '.SITE_URL.'/admin/advanced.php#discord'); exit;
    }
    if ($action === 'system') {
        setSetting('maintenance_mode', isset($_POST['maintenance_mode'])?'1':'0');
        setSetting('qualifying_enabled', isset($_POST['qualifying_enabled'])?'1':'0');
        setSetting('penalties_enabled', isset($_POST['penalties_enabled'])?'1':'0');
        setSetting('bonus_points_pole', isset($_POST['bonus_points_pole'])?'1':'0');
        setSetting('bonus_points_fl', isset($_POST['bonus_points_fl'])?'1':'0');
        setSetting('reserve_scores_driver', isset($_POST['reserve_scores_driver'])?'1':'0');
        setSetting('reserve_scores_team',   isset($_POST['reserve_scores_team'])?'1':'0');
        setSetting('fl_only_if_finished',    isset($_POST['fl_only_if_finished'])?'1':'0');
        setSetting('pole_only_if_finished',  isset($_POST['pole_only_if_finished'])?'1':'0');
        setSetting('google_analytics', trim($_POST['google_analytics']??''));
        auditLog('settings_system');
        $_SESSION['flash']=['type'=>'success','msg'=>'✅ System-Einstellungen gespeichert!'];
        header('Location: '.SITE_URL.'/admin/advanced.php#system'); exit;
    }
}

require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">Erweiterte <span style="color:var(--primary)">Einstellungen</span></div>
<div class="admin-page-sub">E-Mail, Discord Webhook, System-Optionen und Integrationen</div>

<!-- Mail -->
<div class="card mb-4" id="mail">
  <div class="card-header">
    <h3>📧 E-Mail Einstellungen</h3>
    <span class="badge <?= getSetting('mail_from','')?'badge-success':'badge-muted' ?>"><?= getSetting('mail_from','')?'Konfiguriert':'Nicht konfiguriert' ?></span>
  </div>
  <div class="card-body">
    <form method="post"><?= csrfField() ?>
    <input type="hidden" name="action" value="mail"/>

    <div class="form-row cols-2">
      <div class="form-group">
        <label>Absender-E-Mail *</label>
        <input type="email" name="mail_from" class="form-control" value="<?= h(getSetting('mail_from','')) ?>" placeholder="liga@example.de"/>
        <div class="form-hint">Von dieser Adresse werden Mails versendet</div>
      </div>
      <div class="form-group">
        <label>Absender-Name</label>
        <input type="text" name="mail_from_name" class="form-control" value="<?= h(getSetting('mail_from_name', getSetting('league_name',''))) ?>" placeholder="SimRace Liga"/>
      </div>
    </div>

    <div class="form-group">
      <label class="checkbox-label">
        <input type="checkbox" name="mail_smtp" <?= getSetting('mail_smtp','0')==='1'?'checked':'' ?> onchange="toggleSmtp(this.checked)"/>
        SMTP verwenden (statt lokalem PHP mail())
      </label>
    </div>

    <div id="smtp-settings" style="display:<?= getSetting('mail_smtp','0')==='1'?'block':'none' ?>">
      <div class="notice notice-info mb-3">
        <strong>Gmail:</strong> Host: smtp.gmail.com · Port: 587 · TLS · Benutze ein App-Passwort (kein Gmail-Passwort).<br/>
        <strong>IONOS:</strong> Host: smtp.ionos.de · Port: 587 · TLS<br/>
        <strong>Outlook/Hotmail:</strong> Host: smtp-mail.outlook.com · Port: 587 · TLS
      </div>
      <div class="form-row cols-3">
        <div class="form-group">
          <label>SMTP Host</label>
          <input type="text" name="mail_smtp_host" class="form-control" value="<?= h(getSetting('mail_smtp_host','')) ?>" placeholder="smtp.gmail.com"/>
        </div>
        <div class="form-group">
          <label>Port</label>
          <input type="number" name="mail_smtp_port" class="form-control" value="<?= h(getSetting('mail_smtp_port','587')) ?>" placeholder="587"/>
        </div>
        <div class="form-group">
          <label>Verschlüsselung</label>
          <select name="mail_smtp_enc" class="form-control">
            <option value="tls" <?= getSetting('mail_smtp_enc','tls')==='tls'?'selected':'' ?>>TLS (empfohlen)</option>
            <option value="ssl" <?= getSetting('mail_smtp_enc','tls')==='ssl'?'selected':'' ?>>SSL</option>
            <option value="none" <?= getSetting('mail_smtp_enc','tls')==='none'?'selected':'' ?>>Keine</option>
          </select>
        </div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label>SMTP Benutzername</label>
          <input type="text" name="mail_smtp_user" class="form-control" value="<?= h(getSetting('mail_smtp_user','')) ?>" placeholder="deine@email.de" autocomplete="off"/>
        </div>
        <div class="form-group">
          <label>SMTP Passwort</label>
          <input type="password" name="mail_smtp_pass" class="form-control" placeholder="<?= getSetting('mail_smtp_pass','')?'••••••••••••':'Passwort eingeben' ?>" autocomplete="new-password"/>
          <div class="form-hint">Leer lassen um gespeichertes Passwort beizubehalten</div>
        </div>
      </div>
    </div>

    <div class="flex gap-2">
      <button type="submit" class="btn btn-primary">💾 Speichern</button>
    </div>
    </form>

    <?php if(getSetting('mail_from','')): ?>
    <div class="divider mt-3"></div>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;margin-top:12px"><?= csrfField() ?>
      <input type="hidden" name="action" value="mail_test"/>
      <div class="form-group" style="margin:0;flex:1">
        <label>Test-Mail senden an</label>
        <input type="email" name="test_email" class="form-control" placeholder="test@example.de"/>
      </div>
      <button type="submit" class="btn btn-secondary">📤 Senden</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<!-- Discord Bot -->
<div class="card mb-4" id="bot">
  <div class="card-header">
    <h3>🤖 Discord Bot (Race Anmeldung)</h3>
    <?php
    $botEnabled = getSetting('discord_bot_enabled','0')==='1';
    $botPort    = getSetting('discord_bot_port','3001');
    // Health-Check
    $botStatus  = 'Nicht konfiguriert';
    $botOk      = false;
    if ($botEnabled && getSetting('discord_bot_token','')) {
        $ctx = stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true]]);
        $h   = @file_get_contents('http://127.0.0.1:'.$botPort.'/health', false, $ctx);
        $hd  = $h ? json_decode($h, true) : null;
        if ($hd && ($hd['status']??'')  === 'ok') {
            $botStatus = '✅ Online · ' . h($hd['bot_tag'] ?? '') . ' · ' . (int)($hd['open_events']??0) . ' offene Event(s)';
            $botOk = true;
        } else {
            $botStatus = '⚠️ Nicht erreichbar (läuft der Bot?)';
        }
    }
    ?>
    <span class="badge <?= $botOk ? 'badge-success' : 'badge-muted' ?>"><?= $botStatus ?></span>
  </div>
  <div class="card-body">
    <div class="notice notice-info mb-3" style="font-size:.83rem">
      Für die Race-Anmeldung via Discord wird ein Bot benötigt.
      Einrichtung: <a href="<?= SITE_URL ?>/bot/README.md" target="_blank">bot/README.md</a> lesen →
      Node.js installieren → <code>cd bot && npm install && node bot.js</code>
    </div>
    <form method="post"><?= csrfField() ?>
    <input type="hidden" name="action" value="bot"/>
    <div class="form-group">
      <label class="checkbox-label">
        <input type="checkbox" name="discord_bot_enabled" <?= getSetting('discord_bot_enabled','0')==='1'?'checked':'' ?>/>
        Bot aktivieren
      </label>
    </div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Bot-Token *</label>
        <input type="password" name="discord_bot_token" class="form-control"
               placeholder="<?= getSetting('discord_bot_token','') ? '••••••••••••' : 'Bot-Token aus Discord Developer Portal' ?>"
               autocomplete="new-password"/>
        <div class="form-hint">Leer lassen um gespeicherten Token zu behalten</div>
      </div>
      <div class="form-group">
        <label>Channel-ID *</label>
        <input type="text" name="discord_bot_channel" class="form-control"
               value="<?= h(getSetting('discord_bot_channel','')) ?>"
               placeholder="z.B. 1234567890123456789"/>
        <div class="form-hint">Rechtsklick auf Channel → ID kopieren</div>
      </div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group">
        <label>Bot-Port</label>
        <input type="number" name="discord_bot_port" class="form-control"
               value="<?= h(getSetting('discord_bot_port','3001')) ?>" min="1024" max="65535" style="max-width:120px"/>
        <div class="form-hint">Standard: 3001 (muss frei sein)</div>
      </div>
      <div class="form-group">
        <label>Standard-Anmeldefrist (Stunden vor Rennstart)</label>
        <input type="number" name="discord_signup_hours" class="form-control"
               value="<?= h(getSetting('discord_signup_hours','2')) ?>" min="0" max="72" style="max-width:120px"/>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">💾 Speichern & config.json schreiben</button>
    </form>
  </div>
</div>

<!-- Discord -->
<div class="card mb-4" id="discord">
  <div class="card-header">
    <h3>🎮 Discord Integration</h3>
    <span class="badge <?= getSetting('discord_webhook_url')?'badge-success':'badge-muted' ?>"><?= getSetting('discord_webhook_url')?'Aktiv':'Nicht konfiguriert' ?></span>
  </div>
  <div class="card-body">
    <div class="notice notice-info mb-3">
      So erhältst du einen Webhook-URL:<br/>
      Discord Server → Einstellungen → Integrationen → Webhooks → Neuer Webhook → URL kopieren
    </div>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="discord"/>
      <div class="form-group">
        <label>Webhook URL</label>
        <input type="url" name="discord_webhook_url" class="form-control" value="<?= h(getSetting('discord_webhook_url')) ?>" placeholder="https://discord.com/api/webhooks/..."/>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:.9rem;margin-bottom:10px">
          <input type="checkbox" name="discord_notify_results" <?= getSetting('discord_notify_results','1')==='1'?'checked':'' ?>/>
          <span>📊 Bei neuen Rennergebnissen benachrichtigen</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:.9rem">
          <input type="checkbox" name="discord_notify_news" <?= getSetting('discord_notify_news','0')==='1'?'checked':'' ?>/>
          <span>📰 Bei neuen News-Veröffentlichungen benachrichtigen</span>
        </label>
      </div>
      <div class="flex gap-2 mt-2">
        <button type="submit" class="btn btn-primary">💾 Speichern</button>
        <button type="submit" form="discord-test-form" class="btn btn-secondary">🧪 Test senden</button>
      </div>
    </form>
    <form method="post" id="discord-test-form">
      <?= csrfField() ?><input type="hidden" name="action" value="discord_test"/>
    </form>
    <?php if (getSetting('discord_webhook_url')): ?>
    <div class="notice notice-success mt-3">
      ✅ Webhook konfiguriert. Wenn du ein Ergebnis hochlädst, wird automatisch eine Nachricht in deinen Discord-Channel gepostet.
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- System Settings -->
<div class="card mb-4" id="system">
  <div class="card-header"><h3>⚙️ System-Optionen</h3></div>
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="system"/>
      <div style="display:flex;flex-direction:column;gap:14px">
        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">
          <input type="checkbox" name="maintenance_mode" <?= getSetting('maintenance_mode','0')==='1'?'checked':'' ?>/>
          <div><div style="font-weight:600">🔧 Wartungsmodus</div><div class="text-muted" style="font-size:.82rem">Website für Besucher sperren (nur Admins haben Zugang)</div></div>
        </label>
        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">
          <input type="checkbox" name="qualifying_enabled" <?= getSetting('qualifying_enabled','1')==='1'?'checked':'' ?>/>
          <div><div style="font-weight:600">⏱ Qualifying aktiviert</div><div class="text-muted" style="font-size:.82rem">Qualifying-Tab in Admin-Menü anzeigen</div></div>
        </label>
        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">
          <input type="checkbox" name="penalties_enabled" <?= getSetting('penalties_enabled','1')==='1'?'checked':'' ?>/>
          <div><div style="font-weight:600">⚠️ Strafen-System aktiviert</div><div class="text-muted" style="font-size:.82rem">Penalties-Tab in Admin-Menü anzeigen</div></div>
        </label>
        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">
          <input type="checkbox" name="bonus_points_pole" <?= getSetting('bonus_points_pole','1')==='1'?'checked':'' ?>/>
          <div><div style="font-weight:600">🏆 +1 Punkt für Pole Position</div><div class="text-muted" style="font-size:.82rem">Automatisch beim Import aus Qualifying</div></div>
        </label>
        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">
          <input type="checkbox" name="bonus_points_fl" <?= getSetting('bonus_points_fl','1')==='1'?'checked':'' ?>/>
          <div><div style="font-weight:600">⚡ +1 Punkt für Schnellste Runde</div><div class="text-muted" style="font-size:.82rem">Wird beim Ergebnis-Upload angewendet</div></div>
        </label>

        <div style="height:1px;background:var(--border);margin:4px 0"></div>
        <div style="font-family:var(--font-display);font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text2);padding:4px 0">Reservefahrer-Punkte</div>

        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">
          <input type="checkbox" name="reserve_scores_driver" <?= getSetting('reserve_scores_driver','1')==='1'?'checked':'' ?>/>
          <div>
            <div style="font-weight:600">🏎️ Reservefahrer in Fahrerwertung</div>
            <div class="text-muted" style="font-size:.82rem">Punkte von Reservefahrern erscheinen in der Fahrerwertung</div>
          </div>
        </label>
        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">
          <input type="checkbox" name="reserve_scores_team" <?= getSetting('reserve_scores_team','0')==='1'?'checked':'' ?>/>
          <div>
            <div style="font-weight:600">🏭 Reservefahrer-Punkte in Teamwertung</div>
            <div class="text-muted" style="font-size:.82rem">Punkte von Reservefahrern zählen auch für das Team</div>
          </div>
        </label>

        <div style="height:1px;background:var(--border);margin:4px 0"></div>
        <div style="font-family:var(--font-display);font-size:.72rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text2);padding:4px 0">Bonus-Punkte Regeln</div>

        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">
          <input type="checkbox" name="fl_only_if_finished" <?= getSetting('fl_only_if_finished','1')==='1'?'checked':'' ?>/>
          <div>
            <div style="font-weight:600">⚡ Schnellste Runde nur bei Zieleinfahrt</div>
            <div class="text-muted" style="font-size:.82rem">FL-Bonuspunkt wird nur vergeben wenn der Fahrer nicht DNF/DSQ ist</div>
          </div>
        </label>
        <label style="display:flex;align-items:center;gap:12px;cursor:pointer;padding:12px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">
          <input type="checkbox" name="pole_only_if_finished" <?= getSetting('pole_only_if_finished','0')==='1'?'checked':'' ?>/>
          <div>
            <div style="font-weight:600">🏆 Pole-Punkt nur bei Zieleinfahrt</div>
            <div class="text-muted" style="font-size:.82rem">Pole-Bonuspunkt wird nur vergeben wenn der Fahrer das Rennen beendet hat (nicht DNF/DSQ)</div>
          </div>
        </label>
      </div>
      <div class="form-group mt-3">
        <label>Google Analytics ID (optional)</label>
        <input type="text" name="google_analytics" class="form-control" value="<?= h(getSetting('google_analytics')) ?>" placeholder="G-XXXXXXXXXX"/>
        <div class="input-hint">Nur die Measurement ID eintragen</div>
      </div>
      <button type="submit" class="btn btn-primary mt-2">💾 Einstellungen speichern</button>
    </form>
  </div>
</div>

<!-- Audit Log -->
<div class="card">
  <div class="card-header"><h3>📋 Audit-Log (letzte 30 Einträge)</h3></div>
  <div class="card-body" style="padding:0;max-height:400px;overflow-y:auto">
    <?php
    try {
        $log = $db->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 30")->fetchAll();
        if ($log): foreach($log as $l): ?>
        <div class="activity-item" style="padding:8px 16px">
          <div class="activity-icon">📝</div>
          <div class="flex-1">
            <div class="activity-text"><strong><?= h($l['username']) ?></strong>: <?= h($l['action']) ?><?= $l['target_table']?' → '.h($l['target_table']):'' ?><?= $l['details']?' – '.h(mb_substr($l['details'],0,60)):'' ?></div>
            <div class="activity-time"><?= date('d.m.Y H:i:s',strtotime($l['created_at'])) ?> · IP: <?= h($l['ip']??'–') ?></div>
          </div>
        </div>
        <?php endforeach; else: ?><div style="padding:18px" class="text-muted">Noch keine Einträge.</div><?php endif;
    } catch (Exception $e) { ?><div style="padding:18px" class="text-muted">Audit-Log-Tabelle noch nicht erstellt. Führe die install.sql erneut aus.</div><?php } ?>
  </div>
</div>

<script>
function toggleSmtp(on) {
    document.getElementById('smtp-settings').style.display = on ? 'block' : 'none';
}
</script>
<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
