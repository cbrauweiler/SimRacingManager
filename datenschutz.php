<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = '';
$pageTitle   = 'Datenschutzerklärung – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';

$leagueName = getSetting('league_name','SimRace Liga');
$siteUrl    = SITE_URL;
$email      = getSetting('imprint_email','');
$name       = getSetting('imprint_name','');
$fontsLocal = getSetting('fonts_local','0') === '1';
?>
<div class="container section" style="max-width:720px">
  <div class="section-title mb-4">Datenschutzerklärung</div>

  <?php
  $sections = [
    ['Verantwortlicher', '
      <p>Verantwortlich für die Datenverarbeitung auf dieser Website ist:</p>
      <p><strong>'.h($name ?: $leagueName).'</strong>'.($email ? '<br/>'.h($email) : '').'</p>
    '],
    ['1. Welche Daten wir verarbeiten', '
      <p>Diese Website erhebt und verarbeitet nur Daten, die für den Betrieb der Liga-Plattform
      erforderlich sind:</p>
      <ul style="margin-left:1.2em;margin-top:8px;line-height:2">
        <li><strong>Server-Logfiles:</strong> IP-Adresse, Datum/Uhrzeit, aufgerufene Seite,
            übertragene Datenmenge, HTTP-Statuscode – gespeichert durch den Webserver,
            automatisch nach spätestens 7 Tagen gelöscht.</li>
        <li><strong>Session-Cookie:</strong> Technisch notwendiges Cookie für eingeloggte
            Administratoren. Kein Tracking, kein Profiling.</li>
        <li><strong>Discord-Anmeldungen:</strong> Discord-Nutzername und Nutzername für die
            Rennanmeldung-Funktion. Keine Weitergabe an Dritte.</li>
      </ul>
    '],
    ['2. Externe Dienste', '
      '.(!$fontsLocal ? '
      <p><strong>Google Fonts:</strong> Diese Website lädt Schriftarten von Google-Servern
      (fonts.googleapis.com / fonts.gstatic.com). Dabei wird Ihre IP-Adresse an Google
      übertragen. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an
      einheitlicher Darstellung). Weitere Informationen:
      <a href="https://policies.google.com/privacy" target="_blank" rel="noopener"
         style="color:var(--primary)">Google Privacy Policy</a>.</p>
      ' : '
      <p>Diese Website lädt alle Schriften und Bibliotheken lokal vom eigenen Server.
      Es werden keine externen Dienste für die Darstellung der Website genutzt.</p>
      ').'
      <p><strong>Discord:</strong> Wenn Sie den Discord-Bot für Rennanmeldungen nutzen,
      werden Ihre Discord-Nutzerdaten (Name, Server-Nickname) gemäß den
      <a href="https://discord.com/privacy" target="_blank" rel="noopener"
         style="color:var(--primary)">Discord-Datenschutzbestimmungen</a> verarbeitet.</p>
    '],
    ['3. Keine Cookies außer Session', '
      <p>Wir setzen ausschließlich technisch notwendige Session-Cookies ein, die nach dem
      Schließen des Browsers gelöscht werden. Es werden keine Tracking- oder
      Marketing-Cookies verwendet. Eine Cookie-Banner-Zustimmung ist daher nicht erforderlich.</p>
    '],
    ['4. Ihre Rechte', '
      <p>Sie haben gemäß DSGVO folgende Rechte:</p>
      <ul style="margin-left:1.2em;margin-top:8px;line-height:2">
        <li>Auskunft über gespeicherte Daten (Art. 15 DSGVO)</li>
        <li>Berichtigung unrichtiger Daten (Art. 16 DSGVO)</li>
        <li>Löschung Ihrer Daten (Art. 17 DSGVO)</li>
        <li>Einschränkung der Verarbeitung (Art. 18 DSGVO)</li>
        <li>Widerspruch gegen die Verarbeitung (Art. 21 DSGVO)</li>
        <li>Beschwerde bei einer Aufsichtsbehörde (Art. 77 DSGVO)</li>
      </ul>
      '.($email ? '<p style="margin-top:12px">Für Anfragen wenden Sie sich an:
      <a href="mailto:'.h($email).'" style="color:var(--primary)">'.h($email).'</a></p>' : '').'
    '],
    ['5. Datensicherheit', '
      <p>Diese Website verwendet HTTPS zur verschlüsselten Übertragung aller Daten.
      Passwörter werden ausschließlich gehasht (bcrypt) gespeichert und nie im Klartext
      übertragen oder gespeichert.</p>
    '],
    ['6. Aktualität', '
      <p>Diese Datenschutzerklärung ist aktuell gültig. Durch die Weiterentwicklung der
      Website oder gesetzliche Änderungen kann eine Anpassung notwendig werden.</p>
      <p style="margin-top:8px;font-size:.85rem;color:var(--text2)">
        Stand: '.date('d.m.Y').'
      </p>
    '],
  ];
  foreach ($sections as $s):
  ?>
  <div class="card mb-3">
    <div class="card-body">
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:10px"><?= h($s[0]) ?></h3>
      <div style="font-size:.88rem;color:var(--text2);line-height:1.8"><?= $s[1] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
