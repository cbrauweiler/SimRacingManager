# SimRace Manager – Die Liga-Plattform für Simracing-Communities

**Version 1.8.2 · Open Source · PHP / MySQL**

---

Simracing-Ligen sind längst mehr als ein Hobby für Einzelne. Wer eine Liga betreibt, jongliert mit Rennkalendern, Ergebnissen, Fahrerregistrierungen, Discord-Koordination und nicht zuletzt dem Wunsch, das alles professionell nach außen zu präsentieren. Genau hier setzt der **SimRace Manager** an – eine selbstgehostete Webplattform, die alles unter einem Dach vereint.

---

## Was ist der SimRace Manager?

Der SimRace Manager ist ein Open-Source-Ligaverwaltungssystem auf Basis von PHP und MySQL. Er richtet sich an Betreiber privater Simracing-Ligen jeder Größe – ob fünf Fahrer unter Freunden oder eine mehrspurige Community mit Dutzenden Teilnehmern und komplexen Regelwerken.

Die Plattform besteht aus zwei Teilen: einer öffentlichen Website für Fahrer, Zuschauer und Sponsoren, sowie einem vollständigen Administrations-Backend für Ligaorganisatoren.

---

## Die öffentliche Seite

Besucher der Liga-Website sehen auf den ersten Blick alles Wichtige: aktuelle Neuigkeiten, den Saisonkalender, Ergebnisse und die Meisterschaftswertung. Das Design ist dunkel, modern und auf Simracing-Ästhetik ausgelegt – mit konfigurierbaren Primärfarben, eigenem Logo und lokaler Schrifteinbindung für vollständige DSGVO-Konformität.

**Fahrerprofil** – Jeder Fahrer bekommt eine eigene Profilseite mit Karrierestatistiken, Saisonwertungen, Rundenrekorden und dem RPCE-Rating-System. Dieses bewertet Fahrer in vier Dimensionen: Racecraft (Positionsgewinne), Pace (Qualifying- und Rundenzeiten), Consistency (Straffreiheit, Finishquote) und Experience (Starterfahrung). Das Ergebnis erscheint als Radar-Diagramm mit Vorjahresvergleich.

**Teamseite** – Teams werden mit Farbe, Fahrzeug und Fahreraufgebot präsentiert. Das RPCE-Badge der besten Teamfahrer macht Leistungsunterschiede auf einen Blick sichtbar.

**Streckenprofile** – Jede Strecke hat eine eigene Seite mit Streckenlänge, Kurvenanzahl, Rundenrekord und Beschreibung. Koordinaten ermöglichen eine automatische Wettervorschau für anstehende Rennen.

**Hall of Fame** – Abgeschlossene Saisonen werden mit ihren Champions archiviert. Punktgleichheit wird nach dem WEC-Tiebreaker-System aufgelöst: verglichene Positionen vom ersten bis zum letzten Platz.

---

## Das Admin-Backend

Das Backend ist rollenbasiert aufgebaut. Drei Rollen – Editor, Admin und Superadmin – steuern den Zugriff auf einzelne Funktionen. Alle Änderungen werden in einem Audit-Log protokolliert.

**Saison- und Kalenderverwaltung** – Saisons, Rennwochenenden und Streckenzuweisungen lassen sich vollständig im Browser verwalten. Der Kalender zeigt vergangene und zukünftige Rennen in einer Übersicht.

**Ergebniserfassung** – Ergebnisse können manuell eingegeben, per XML-Import aus Le Mans Ultimate (LMU) oder per JSON-Import aus Racing League Tools (RLT) übernommen werden. Qualifying-Ergebnisse, schnellste Runden und DSQ-Markierungen werden separat behandelt.

**Strafensystem** – Zeit-, Punkte-, Gitterstrafen, Verwarnungen und Disqualifikationen lassen sich pro Rennen und Fahrer verhängen. Sie fließen automatisch in die Wertung ein.

**Grafik-Export** – Ergebnisse lassen sich als WEC-Stil-Grafiken exportieren, direkt für Social Media oder Discord geeignet.

**Import / Export** – Teams und Strecken können per CSV importiert und exportiert werden. Vorgefertigte Templates für die Formel-1-Saison 2026 sind bereits enthalten.

---

## Discord-Integration

Die tiefste Integration bietet der eingebaute **Discord Bot** – ein Node.js-Prozess der eng mit dem PHP-Backend kommuniziert.

**Race Signup** – Der Admin postet über das Backend ein strukturiertes Anmeldeformular direkt in einen Discord-Kanal. Fahrer reagieren mit Buttons: Zusagen, Absagen oder Vielleicht. Das Embed aktualisiert sich bei jeder Reaktion in Echtzeit und zeigt Zeitplan, Wetterverlauf und die aktuelle Teilnehmerliste. Solange die Anmeldung offen ist, erscheint der Embed-Streifen grün; nach dem Schließen rot.

**Zwei Fristen** – Eine Rückmeldefrist gibt an, bis wann Fahrer geantwortet haben sollten. Eine separate Anmeldeschluss-Frist sperrt danach weitere Änderungen. Nach Ablauf der Rückmeldefrist postet der Bot automatisch im zugehörigen Thread, welche Stammfahrer noch nicht geantwortet haben – basierend auf dem hinterlegten Discord-Account-Namen im Fahrerprofil.

**Wettervorschau** – Im Formular kann die Wetterlage für Training, Qualifying und Rennen aus fünf Zeitslots (10:00 bis 20:00 Uhr) gewählt werden. Eine integrierte Vorschau via Open-Meteo – kostenlos, kein API-Key, DSGVO-konform – zeigt die echte Wettervorhersage für den Streckenstandort an und überträgt die Werte mit einem Klick ins Formular.

**Chat-Befehle** – Mit dem Präfix `?` können Ligamitglieder im Discord schnelle Informationen abrufen: `?next` zeigt das nächste Rennen, `?result` die Top 3 des letzten Rennens, `?calendar` den Saisonkalender und `?hp` den Link zur Liga-Website.

---

## Technische Grundlage

Der SimRace Manager läuft auf einem Standard-LAMP-Stack (PHP 8.1+, MySQL 5.7+ oder MariaDB). Es sind keine externen Abhängigkeiten zur Laufzeit erforderlich – alle JavaScript-Bibliotheken (Chart.js, SortableJS) können lokal gehostet werden, ebenso die Barlow-Schriftfamilie. Der eingebaute Installer richtet die Datenbank in einem Schritt ein.

Für erhöhte Sicherheit unterstützt das Admin-Backend **TOTP-basierte Multi-Faktor-Authentifizierung** ohne externe Bibliothek, Passwort-Reset per E-Mail und eine vollständige Audit-Protokollierung aller administrativen Aktionen.

Rechtliche Anforderungen werden direkt aus dem Backend bedient: Impressum und Datenschutzerklärung werden aus den hinterlegten Ligadaten automatisch generiert. Die Datenschutzerklärung passt sich dynamisch an – je nachdem ob Schriften lokal oder via Google Fonts geladen werden.

---

## Fazit

Der SimRace Manager schließt eine Lücke, die viele Ligaorganisatoren kennen: die Flickendecke aus Discord-Bots, Google Sheets, manuellen Grafiken und improvisierten Websites. Er bringt alles in ein System – professionell nach außen, effizient im Backend, vollständig unter eigener Kontrolle.

Wer seine Liga nicht auf einer generischen Plattform betreiben, sondern eine eigene digitale Heimat schaffen möchte, findet im SimRace Manager eine solide, erweiterbare Grundlage.

---

*SimRace Manager ist Open Source und verfügbar auf [GitHub](https://github.com/cbrauweiler/SimRacingManager).*
