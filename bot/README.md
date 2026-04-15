# SimRace Liga Manager – Discord Bot

## Installation

```bash
cd /pfad/zu/simracing/bot
npm install
```

## Konfiguration

Die Konfiguration erfolgt im SimRace Manager Admin unter **Erweitert → Discord Bot**.
Danach `config.json` einmalig generieren lassen oder manuell anlegen:

```json
{
  "token":        "DEIN_BOT_TOKEN",
  "port":         3001,
  "callback_url": "https://deine-domain.de/api/discord_interaction.php",
  "bot_secret":   "wird_automatisch_aus_token_generiert"
}
```

## Bot als Dienst (systemd)

```bash
# /etc/systemd/system/simracing-bot.service
[Unit]
Description=SimRace Liga Discord Bot
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/pfad/zu/simracing/bot
ExecStart=/usr/bin/node bot.js
Restart=always
RestartSec=10
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable simracing-bot
systemctl start simracing-bot
systemctl status simracing-bot
```

## Discord Application erstellen

1. https://discord.com/developers/applications → "New Application"
2. Bot → "Add Bot" → Token kopieren
3. Bot → Privileged Gateway Intents: **Server Members Intent** aktivieren
4. OAuth2 → URL Generator → Scopes: `bot` → Bot Permissions: `Send Messages (Nachrichten senden)`, `Read Messages/View Channels (Nachrichtenverlauf anzeigen/Kanäle ansehen)`, `Create Public Threads (Öffentliche Threads erstellen)`, `Send Messages in Threads (Nachrichten in Threads senden)`, `Manage Messages (Nachrichten verwalten)`
5. Generierten Link aufrufen → Bot in Server einladen
