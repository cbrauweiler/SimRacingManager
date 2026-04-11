# SimRace Liga Manager v1.0
## Vollständiges PHP/MySQL Liga-Management-System

---

## 📦 Installation

### Voraussetzungen
- PHP 8.1+ mit PDO, PDO_MySQL
- MySQL 5.7+ / MariaDB 10.4+
- Apache/Nginx mit mod_rewrite
- Schreibrechte für `/uploads/` Verzeichnis

---

### 1. Dateien hochladen
Lade den gesamten Ordner auf deinen Webserver hoch (z.B. nach `/var/www/html/simracing/` oder in dein Domain-Root).

---

### 2. Datenbank anlegen
```sql
-- In phpMyAdmin oder MySQL-CLI:
mysql -u root -p < install.sql
```
Oder kopiere den Inhalt von `install.sql` in phpMyAdmin → SQL Tab.

---

### 3. Konfiguration anpassen
Öffne `includes/config.php` und passe folgendes an:

```php
define('DB_HOST', 'localhost');    // Dein DB-Host
define('DB_NAME', 'simracing_liga'); // Dein DB-Name
define('DB_USER', 'root');         // Dein DB-Benutzer
define('DB_PASS', '');             // Dein DB-Passwort

define('SITE_URL', '');            // Leer lassen wenn Root, sonst z.B. '/simracing'
```

---

### 4. Upload-Verzeichnis schreibbar machen
```bash
chmod 755 uploads/
chmod 755 uploads/logos/
chmod 755 uploads/news/
chmod 755 uploads/results/
```

---

### 5. Admin-Login
Rufe `/admin/` auf und logge dich ein mit:
- **Benutzername:** `admin`
- **Passwort:** `admin123`

⚠️ **WICHTIG:** Passwort sofort unter Admin → Benutzer ändern!

---

## 📁 Dateistruktur

```
simracing/
├── index.php              # Homepage
├── news.php               # News (Liste + Einzelartikel)
├── season.php             # Saison-Übersicht
├── calendar.php           # Rennkalender
├── results.php            # Rennergebnisse
├── standings.php          # WM-Wertung (Fahrer + Teams)
├── teams.php              # Teams & Fahrer
├── info.php               # Liga Info
├── install.sql            # Datenbankschema
│
├── includes/
│   ├── config.php         # Datenbank + Konfiguration
│   ├── header.php         # Öffentlicher Header
│   └── footer.php         # Öffentlicher Footer
│
├── admin/
│   ├── index.php          # Dashboard
│   ├── login.php          # Login
│   ├── logout.php         # Logout
│   ├── settings.php       # Liga Einstellungen
│   ├── design.php         # Farbdesign
│   ├── info.php           # Liga Info bearbeiten
│   ├── social.php         # Social Links
│   ├── news.php           # News verwalten
│   ├── seasons.php        # Saisons verwalten
│   ├── calendar.php       # Rennkalender
│   ├── teams.php          # Teams verwalten
│   ├── drivers.php        # Fahrer verwalten
│   ├── upload.php         # Ergebnis Upload (CSV/JSON)
│   ├── results.php        # Ergebnisse verwalten
│   ├── points.php         # Punktesystem
│   ├── users.php          # Benutzer verwalten
│   └── includes/
│       ├── layout.php     # Admin Header + Sidebar
│       └── layout_end.php # Admin Footer
│
├── assets/
│   ├── css/
│   │   ├── main.css       # Public CSS
│   │   └── admin.css      # Admin CSS
│   └── js/
│       ├── main.js        # Public JS
│       └── admin.js       # Admin JS (Parser, Uploader)
│
└── uploads/
    ├── logos/             # Team/Liga Logos
    ├── news/              # News Bilder
    └── results/           # Ergebnis-Dateien
```

---

## 🎮 Unterstützte Ergebnis-Formate

### CSV (Universal)
```
Position,Fahrername,Teamname,Runden,Gesamtzeit,Abstand
1,Max Mustermann,Team Alpha,30,1:32:14.567,
2,John Doe,Team Beta,30,1:32:18.123,+3.556
```

### iRacing JSON
Direkte SessionResults-Datei (.json) aus iRacing Session Export.

### Assetto Corsa Competizione JSON
Result-Datei aus `…/ACC/Saved/Results/`.

### Manuelle Eingabe
Über das Admin-Panel direkt im Browser eingeben.

---

## 🏁 Schnellstart Workflow

1. **Saison anlegen** → Admin → Saisons → Neue Saison
2. **Teams erstellen** → Admin → Teams → Neues Team
3. **Fahrer zuordnen** → Admin → Fahrer → Fahrer anlegen (max. 2 Stammfahrer/Team)
4. **Rennkalender** → Admin → Kalender → Rennen hinzufügen
5. **Ergebnis hochladen** → Admin → Upload → CSV/JSON hochladen → Prüfen → Speichern
6. **WM-Wertung** wird automatisch berechnet!

---

## 🎨 Design anpassen
Admin → Design → Farben auswählen
- **Primärfarbe** – Hauptakzent (Buttons, Hover)
- **Sekundärfarbe** – Highlights (Punkte-Badges)
- **Tertiärfarbe** – Info-Elemente
- **Hintergrund** – Seitenhintergrund
- **Textfarbe** – Haupttext

---

## 🔒 Sicherheitshinweise
- Standard-Passwort **sofort** ändern
- Uploads-Ordner: PHP-Ausführung deaktivieren (`.htaccess` empfohlen)
- `config.php` außerhalb des Web-Roots ablegen für max. Sicherheit
- HTTPS verwenden (Let's Encrypt)

---

## 💡 Passwort-Hash neu generieren
```php
echo password_hash('MeinNeuesPasswort', PASSWORD_BCRYPT, ['cost'=>12]);
```
Dann in der DB: `UPDATE admin_users SET password_hash='...' WHERE username='admin';`
