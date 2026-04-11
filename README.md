# SimRace Liga Manager

Ein vollständiges Liga-Management-System für Simracing-Ligen, gebaut mit PHP 8+ und MySQL. Enthält öffentliche Website, Admin-Backend, Grafik-Export, Discord-Integration und mehr.

---

## Voraussetzungen

| Komponente | Version |
|---|---|
| PHP | 8.1 oder höher |
| MySQL / MariaDB | 5.7 / 10.4 oder höher |
| Webserver | Apache mit mod_rewrite und mod_headers |
| wkhtmltoimage | 0.12.6 (für Grafik-Export) |

---

## Installation

### 1. Dateien hochladen

Alle Dateien in das Webroot des Servers hochladen (z.B. `/var/www/html/` oder ein Subdomain-Verzeichnis).

### 2. Datenbank anlegen

In phpMyAdmin oder per CLI eine neue MySQL-Datenbank anlegen und anschließend die `install.sql` importieren:

```bash
mysql -u BENUTZER -p DATENBANKNAME < install.sql
```

### 3. Konfiguration anpassen

In `/includes/config.php` die folgenden Werte setzen:

```php
// Pflichtfeld – vollständige URL ohne abschließenden Slash
define('SITE_URL', 'https://deine-domain.de');

// Datenbankverbindung
define('DB_HOST', 'localhost');
define('DB_NAME', 'datenbankname');
define('DB_USER', 'datenbankbenutzer');
define('DB_PASS', 'datenbankpasswort');
```

### 4. Erster Login

Standardmäßig wird beim SQL-Import ein Admin-Benutzer angelegt:

- **Benutzer:** `admin`
- **Passwort:** `admin123`

**Passwort sofort nach dem ersten Login ändern** unter `Admin → Benutzer`.

### 5. Upload-Verzeichnis

Das Verzeichnis `/uploads/` muss vom Webserver beschreibbar sein:

```bash
chmod 755 uploads/
chmod 755 uploads/photos/
chmod 755 uploads/tracks/
```

---

## Dateistruktur

```
/
├── index.php               Startseite (Countdown, Twitch, Wertungsvorschau)
├── calendar.php            Rennkalender
├── standings.php           Fahrer- und Team-WM
├── results.php             Rennergebnisse (Übersicht + Detail)
├── teams.php               Teams & Fahrer
├── driver.php              Fahrerprofil
├── track.php               Streckenprofil
├── news.php                Neuigkeiten
├── season.php              Saison-Übersicht
├── info.php                Liga-Info-Seite
├── install.sql             Datenbankschema + Standarddaten
├── .htaccess               Cache, Security, Kompression
│
├── includes/
│   ├── config.php          Datenbankverbindung, Hilfsfunktionen, Mail, Discord
│   ├── header.php          Navigation, CSS-Variablen, Custom CSS
│   └── footer.php          Footer, JS
│
├── assets/
│   ├── css/
│   │   ├── main.css        Öffentliches Frontend
│   │   └── admin.css       Admin-Backend
│   └── js/
│       ├── main.js         Frontend JS (Mobile Nav, Tabs)
│       └── admin.js        Admin JS
│
├── exports/
│   └── generate.php        Grafik-Export via wkhtmltoimage
│
├── uploads/
│   ├── photos/             Fahrer-Fotos
│   └── tracks/             Streckenbilder
│
└── admin/
    ├── index.php           Dashboard
    ├── login.php           Login
    ├── forgot_password.php Passwort zurücksetzen (Anfrage)
    ├── reset_password.php  Passwort zurücksetzen (Token)
    ├── settings.php        Liga-Einstellungen (Name, Logo, Slogan)
    ├── design.php          Farbpalette + Custom CSS
    ├── info.php            Liga-Info-Seite bearbeiten
    ├── social.php          Social Links (Twitch, Discord, etc.)
    ├── news.php            News verwalten
    ├── seasons.php         Saisons verwalten
    ├── calendar.php        Rennkalender bearbeiten
    ├── tracks.php          Strecken verwalten
    ├── teams.php           Teams verwalten
    ├── drivers.php         Fahrer (global) verwalten
    ├── lineup.php          Saison-Lineup (Fahrer ↔ Teams)
    ├── results.php         Ergebnisübersicht
    ├── result_edit.php     Rennergebnis manuell bearbeiten
    ├── import_lmu.php      Le Mans Ultimate XML-Import
    ├── qualifying.php      Qualifying-Ergebnisse
    ├── penalties.php       Strafensystem
    ├── points.php          Punktesystem konfigurieren
    ├── export.php          Grafik-Export (Social Media)
    ├── users.php           Admin-Benutzer verwalten
    ├── advanced.php        E-Mail, Discord Webhook, System
    └── includes/
        ├── layout.php      Admin-Sidebar + Topbar
        └── layout_end.php  Schließende Tags
```

---

## Datenbankschema

| Tabelle | Beschreibung |
|---|---|
| `settings` | Alle Konfigurationswerte (Key-Value) |
| `admin_users` | Admin-Benutzer mit Rollen und Reset-Token |
| `news` | Neuigkeiten/Blogbeiträge |
| `seasons` | Saisons mit Aktivierungs-Flag |
| `drivers` | Globale Fahrerdatenbank |
| `teams` | Teams pro Saison |
| `season_entries` | Fahrer-Team-Zuordnung pro Saison |
| `tracks` | Strecken mit Metadaten |
| `races` | Rennen im Kalender |
| `results` | Rennergebnis-Kopfdaten |
| `result_entries` | Einzelne Fahrerergebnisse |
| `qualifying_results` | Qualifying-Ergebnisse |
| `penalties` | Strafen (Punkte, Zeit, DSQ, etc.) |
| `audit_log` | Alle Admin-Aktionen protokolliert |

---

## Features

### Öffentliche Website
- **Startseite** mit Countdown zum nächsten Rennen, Twitch Live-Embed, Discord-Button, Fahrerwertungs-Chart und Saison-Statistiken
- **Fahrer- und Team-WM** mit Verlaufs-Chart
- **Rennergebnisse** mit Qualifying-Tab, Strafenliste und Fastest-Lap-Badge
- **Fahrerprofil** mit Karriere-Statistiken, Rennergebnis-Historie und Qualifying-Ergebnissen
- **Rennkalender** mit Status (Vergangen / Nächstes Rennen / Geplant)
- **Teams & Fahrer** mit Stammfahrern, Reservefahrern und Team-Punkten
- **Streckenprofil** mit Streckeninfos und Ergebnishistorie
- Dynamisches Farbschema – alle Farben über Admin konfigurierbar

### Admin-Backend
- **Dashboard** mit Schnellübersicht, nächstem Rennen und letzten Aktivitäten
- **Saisons** mit Aktivierungs-Flag (nur eine Saison gleichzeitig aktiv)
- **Saison-Lineup** – Fahrer den Teams zuweisen, Startnummern, Reserve-Flag
- **LMU XML-Import** – Le Mans Ultimate Ergebnis-XML automatisch einlesen mit exaktem und Fuzzy-Matching auf registrierte Fahrer (≥2 übereinstimmende Namensteile)
- **Ergebnis-Editor** – alle Felder inline bearbeiten (Position, Zeiten, FL, DNF/DSQ, Punkte), Punkte per Knopfdruck neu berechnen, Fahrer hinzufügen/entfernen
- **Punktesystem** – frei konfigurierbar (z.B. `25,18,15,12,10,8,6,4,2,1`)
- **Bonus-Punkte** für Pole Position und Fastest Lap, jeweils separat aktivierbar
- **Strafensystem** – Punkteabzug (live in Wertung), Zeitstrafe, DSQ, Startplatz, Verwarnung; aktivieren/deaktivieren ohne Löschen
- **Grafik-Export** – WEC-Style Grafiken (1200px WEC + 1080×1080px Instagram) für Kalender, Lineup, Qualifying, Rennen, Wertung, Top 10 Podium, Fahrer-Champion, Team-Champion

### Integrationen
- **Discord Webhook** – automatische Benachrichtigung bei neuen Ergebnissen und News, manuell erneut auslösbar
- **Twitch Embed** – Live-Player auf der Startseite, automatischer ONLINE/OFFLINE-Status, Kanal aus hinterlegtem Social Link
- **E-Mail** – Willkommensmail bei Benutzeranlage, Passwort-Reset; unterstützt PHP `mail()` und SMTP (Gmail, IONOS, Outlook) mit TLS/SSL

### Design & Customization
- **5 Farbwerte** frei konfigurierbar (Primär, Sekundär, Tertiär, Hintergrund, Text)
- **4 Farbpresets** (Dark, Blau, Grün, Hell)
- **Custom CSS** – eigenes CSS wird nach dem Standard-CSS geladen, kein `<style>`-Tag nötig
- Alle Farbableitungen (`--primary-subtle`, `--primary-hover`, `--primary-faint`, `--primary-glow`) werden dynamisch aus der Primärfarbe berechnet

---

## Grafik-Export (wkhtmltoimage)

Für den Grafik-Export muss `wkhtmltoimage` installiert sein:

```bash
# Ubuntu 22.04 (Jammy)
wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-2/wkhtmltox_0.12.6.1-2.jammy_amd64.deb
dpkg -i wkhtmltox_0.12.6.1-2.jammy_amd64.deb
apt-get install -f
```

**Plesk:** Unter PHP-Einstellungen → `open_basedir` muss `/usr/bin` freigegeben sein.

Die generierten Grafiken werden nie gecacht (`generate.php` liefert immer frische PNG-Ausgabe).

---

## Twitch Embed

Damit das Twitch-Embed im Browser funktioniert, muss die Domain in der [Twitch Developer Console](https://dev.twitch.tv) als erlaubte Domain eingetragen sein:

`Deine App → Settings → Allowed Origins → Domain hinzufügen`

Der Kanalname wird automatisch aus dem hinterlegten Twitch-Social-Link extrahiert.

---

## Rollen & Berechtigungen

| Rolle | Rechte |
|---|---|
| `editor` | News, Kalender verwalten |
| `admin` | Alles außer Benutzerverwaltung |
| `superadmin` | Vollzugriff inkl. Benutzerverwaltung |

---

## Sicherheit

- CSRF-Schutz auf allen POST-Formularen
- Passwörter mit bcrypt (cost 12) gehasht
- Session-Härtung (httponly, SameSite=Strict, strict mode)
- `config.php` via `.htaccess` vor direktem Zugriff geschützt
- Alle Ausgaben via `h()` (htmlspecialchars) escaped
- Audit-Log für alle Admin-Aktionen
- Passwort-Reset-Token mit 1 Stunde Ablaufzeit, einmalig verwendbar
- Directory Listing deaktiviert (`Options -Indexes`)

---

## Changelog (Kurzfassung)

| Version | Änderungen |
|---|---|
| v0.9 | Initiales Release: Website, Admin, LMU-Import, Wertung |
| v0.9.1 | Grafik-Export (WEC-Style + Instagram), Discord Webhook |
| v0.9.2 | Strafensystem, Ergebnis-Editor, Qualifying |
| v0.9.3 | E-Mail/SMTP, Passwort-Reset, Custom CSS, Twitch Embed, Farbschema-Fix |

---

## Lizenz

Privates Projekt – alle Rechte vorbehalten.
