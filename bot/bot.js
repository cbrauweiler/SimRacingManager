/**
 * SimRace Liga Manager – Discord Bot
 * Postet Race-Anmeldenachrichten mit Buttons und verwaltet Anmeldungen
 *
 * Setup:
 *   1. npm install
 *   2. Einstellungen in SimRace Manager: Admin → Erweitert → Discord Bot
 *   3. node bot.js
 */

const { Client, GatewayIntentBits, ButtonBuilder, ButtonStyle,
        ActionRowBuilder, EmbedBuilder, ThreadAutoArchiveDuration } = require('discord.js');
const express = require('express');
const axios   = require('axios');

// ============================================================
// Konfiguration (wird aus Umgebungsvariablen oder config.json geladen)
// ============================================================
let config = {};
try {
    config = require('./config.json');
} catch(e) {
    config = {
        token:        process.env.BOT_TOKEN        || '',
        port:         parseInt(process.env.BOT_PORT || '3001'),
        callback_url: process.env.CALLBACK_URL     || '',
        bot_secret:   process.env.BOT_SECRET       || '',
    };
}

// ============================================================
// Wetter-Mapping
// ============================================================
const WEATHER_EMOJIS = {
    Clear:             '☀️',
    LightClouds:       '🌤️',
    PartiallyCloudy:   '⛅',
    MostlyCloudy:      '🌥️',
    Overcast:          '☁️',
    CloudyDrizzle:     '🌦️',
    CloudyLightRain:   '🌧️',
    OvercastLightRain: '🌨️',
    OvercastRain:      '🌧️',
    OvercastHeavyRain: '💧',
    OvercastStorm:     '⛈️',
    Night:             '🌙',
    Random:            '🎲',
};

// ============================================================
// Hilfsfunktionen
// ============================================================
function formatDate(dateStr) {
    if (!dateStr) return '–';
    const d = new Date(dateStr);
    return d.toLocaleDateString('de-DE', { weekday:'long', day:'2-digit', month:'2-digit', year:'numeric' });
}

function formatTime(timeStr) {
    if (!timeStr) return '–';
    return timeStr.slice(0,5) + ' Uhr';
}

function buildEventMessage(data, lists) {
    const accepted = lists.accepted || [];
    const declined = lists.declined || [];
    const maybe    = lists.maybe    || [];

    const deadline = data.deadline
        ? new Date(data.deadline).toLocaleString('de-DE', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' }) + ' Uhr'
        : null;

    // Zeitplan
    let zeitplan = '';
    if (data.time_training) zeitplan += `\`${data.time_training}\` Training\n`;
    if (data.time_briefing) zeitplan += `\`${data.time_briefing}\` Briefing\n`;
    if (data.time_race)     zeitplan += `\`${data.time_race}\` Rennen\n`;

    // Wetter
    // wx_* sind Arrays mit 5 Slots → Emojis zusammensetzen
    const wxSlots = (arr) => {
        if (!Array.isArray(arr)) return arr?.emoji || '';
        const filled = arr.filter(s => s.key !== '' && s.key != null);
        return filled.map(s => s.emoji || WEATHER_EMOJIS[s.key] || '❓').join(' ');
    };
    const wxTrain = wxSlots(data.wx_training);
    const wxQuali = wxSlots(data.wx_quali);
    const wxRace  = wxSlots(data.wx_race);

    // Teilnehmerlisten
    const yesStr   = accepted.length ? accepted.map(n=>`👤 ${n}`).join('\n') : '*Noch keine Zusagen*';
    const noStr    = declined.length ? declined.map(n=>`👤 ${n}`).join('\n') : '*Noch keine Absagen*';
    const maybeStr = maybe.length    ? maybe.map(n=>`👤 ${n}`).join('\n')    : '*Noch keine Rückmeldung*';

    // Beschreibung: Datum + optionale Extra-Info
    const descParts = [`**${data.season_name}** · ${formatDate(data.race_date)}`];
    if (data.extra_info && data.extra_info.trim()) {
        descParts.push('');
        descParts.push(data.extra_info.trim());
    }

    const embed = new EmbedBuilder()
        .setColor(0xe8333a)
        .setTitle(`🏁 Runde ${data.round} · ${data.track_name}${data.location ? ` (${data.location})` : ''}`)
        .setDescription(descParts.join('\n'))
        .addFields(
            { name: '⏰ Zeitplan', value: zeitplan || '–', inline: false },
            ...(wxTrain ? [{ name: '🌤️ Wetter Training',  value: wxTrain, inline: false }] : []),
            ...(wxQuali ? [{ name: '🌤️ Wetter Qualifying', value: wxQuali, inline: false }] : []),
            ...(wxRace  ? [{ name: '🌤️ Wetter Rennen',     value: wxRace,  inline: false }] : []),
            { name: `✅ Zusagen (${accepted.length})`, value: yesStr,   inline: false },
            { name: `❌ Absagen (${declined.length})`, value: noStr,    inline: false },
            { name: `❓ Vielleicht (${maybe.length})`, value: maybeStr, inline: false },
        );

    if (deadline) {
        embed.setFooter({ text: `⏳ Anmeldeschluss: ${deadline}` });
    }

    return embed;
}

function buildButtons(closed = false) {
    return new ActionRowBuilder().addComponents(
        new ButtonBuilder()
            .setCustomId('signup_accepted')
            .setLabel('Zusagen')
            .setEmoji('✅')
            .setStyle(ButtonStyle.Success)
            .setDisabled(closed),
        new ButtonBuilder()
            .setCustomId('signup_declined')
            .setLabel('Absagen')
            .setEmoji('❌')
            .setStyle(ButtonStyle.Danger)
            .setDisabled(closed),
        new ButtonBuilder()
            .setCustomId('signup_maybe')
            .setLabel('Vielleicht')
            .setEmoji('❓')
            .setStyle(ButtonStyle.Secondary)
            .setDisabled(closed),
    );
}

// ============================================================
// Discord Client
// ============================================================
const client = new Client({ intents: [GatewayIntentBits.Guilds] });
const openEvents = new Map(); // eventId → { messageId, channelId, threadId, data }

client.once('ready', async () => {
    console.log(`✅ Bot eingeloggt als ${client.user.tag}`);

    // Offene Events vom PHP-Backend laden (nach Neustart)
    if (config.callback_url) {
        try {
            const res = await axios.post(config.callback_url, {
                action:     'get_open_events',
                bot_secret: config.bot_secret,
            }, { timeout: 5000, headers: { 'X-Bot-Secret': config.bot_secret } });

            if (res.data?.events) {
                for (const ev of res.data.events) {
                    if (ev.message_id) {
                        openEvents.set(ev.id, {
                            messageId: ev.message_id,
                            channelId: ev.channel_id,
                            threadId:  ev.thread_id,
                            deadline:  ev.deadline,
                        });
                    }
                }
                console.log(`📋 ${openEvents.size} offene Event(s) wiederhergestellt`);
            }
        } catch(e) {
            console.warn('⚠️ Konnte offene Events nicht laden:', e.message);
        }
    }
});

// ============================================================
// Button-Interactions
// ============================================================
client.on('interactionCreate', async interaction => {
    if (!interaction.isButton()) return;

    const customId = interaction.customId;
    if (!customId.startsWith('signup_')) return;

    const statusMap = {
        signup_accepted: 'accepted',
        signup_declined: 'declined',
        signup_maybe:    'maybe',
    };
    const status = statusMap[customId];
    if (!status) return;

    // Event-ID aus der Nachricht ermitteln
    let eventId = null;
    for (const [id, ev] of openEvents.entries()) {
        if (ev.messageId === interaction.message.id) { eventId = id; break; }
    }
    if (!eventId) {
        await interaction.reply({ content: '❌ Event nicht gefunden.', ephemeral: true });
        return;
    }

    await interaction.deferUpdate();

    // An PHP-Backend melden
    try {
        const res = await axios.post(config.callback_url, {
            action:           'signup',
            event_id:         eventId,
            discord_user_id:  interaction.user.id,
            discord_username: interaction.user.username,
            status:           status,
            bot_secret:       config.bot_secret,
        }, { timeout: 8000, headers: { 'X-Bot-Secret': config.bot_secret } });

        const data = res.data;

        if (data.closed) {
            await interaction.followUp({ content: '⏳ Die Anmeldefrist ist abgelaufen.', ephemeral: true });
            return;
        }

        if (data.success) {
            const ev = openEvents.get(eventId);
            const channel = await client.channels.fetch(ev.channelId);
            const msg = await channel.messages.fetch(ev.messageId);

            // Embed aktualisieren
            const storedData = ev.eventData || {};
            const embed = buildEventMessage(storedData, data.lists);
            await msg.edit({ embeds: [embed], components: [buildButtons(false)] });

            // Thread-Log
            if (ev.threadId) {
                try {
                    const thread = await client.channels.fetch(ev.threadId);
                    const statusLabels = { accepted:'✅ zugesagt', declined:'❌ abgesagt', maybe:'❓ Vielleicht' };
                    let logMsg = `**${interaction.user.username}** hat ${statusLabels[status]}`;
                    if (data.old_status && data.old_status !== status) {
                        const oldLabels = { accepted:'Zusage', declined:'Absage', maybe:'Vielleicht' };
                        logMsg = `**${interaction.user.username}** hat geändert: ${oldLabels[data.old_status]} → ${statusLabels[status]}`;
                    }
                    await thread.send(`\`${new Date().toLocaleTimeString('de-DE')}\` ${logMsg}`);
                } catch(e) { /* Thread nicht verfügbar */ }
            }

            // Ephemeral-Bestätigung
            const confirmLabels = { accepted:'✅ Du hast **zugesagt**!', declined:'❌ Du hast **abgesagt**.', maybe:'❓ Du hast mit **Vielleicht** geantwortet.' };
            await interaction.followUp({ content: confirmLabels[status], ephemeral: true });
        }
    } catch(e) {
        console.error('Interaction-Fehler:', e.message);
        await interaction.followUp({ content: '❌ Fehler beim Speichern. Bitte erneut versuchen.', ephemeral: true });
    }
});

// ============================================================
// HTTP-Server (empfängt Befehle vom PHP-Backend)
// ============================================================
const app = express();
app.use(express.json());

// Middleware: Secret prüfen (außer /health)
app.use((req, res, next) => {
    if (req.path === '/health') return next();
    if (req.headers['x-bot-secret'] !== config.bot_secret && req.body?.bot_secret !== config.bot_secret) {
        return res.status(403).json({ error: 'Unauthorized' });
    }
    next();
});

// POST /post-event – Neue Anmelde-Nachricht posten
app.post('/post-event', async (req, res) => {
    const data = req.body;
    try {
        const channel = await client.channels.fetch(data.channel_id);
        if (!channel) return res.status(404).json({ error: 'Channel nicht gefunden' });

        const embed   = buildEventMessage(data, { accepted:[], declined:[], maybe:[] });
        const buttons = buildButtons(false);
        // Rolle markieren (als separater Content vor dem Embed)
        const mentionContent = data.mention_role ? `<@&${data.mention_role}>` : undefined;
        const msg = await channel.send({
            content:    mentionContent,
            embeds:     [embed],
            components: [buttons],
        });

        // Thread für Log erstellen
        let thread = null;
        try {
            thread = await msg.startThread({
                name:                `R${data.round} · ${data.track_name} – Anmeldungen`,
                autoArchiveDuration: ThreadAutoArchiveDuration.OneWeek,
            });
            await thread.send(`📋 **Anmeldungs-Log** · R${data.round} ${data.track_name}\n*Alle Reaktionen werden hier protokolliert.*`);
        } catch(e) {
            console.warn('Thread konnte nicht erstellt werden:', e.message);
        }

        // Im Speicher halten
        openEvents.set(data.event_id, {
            messageId: msg.id,
            channelId: data.channel_id,
            threadId:  thread?.id || null,
            deadline:  data.deadline,
            eventData: data,
        });

        res.json({ success: true, message_id: msg.id, thread_id: thread?.id || null });
    } catch(e) {
        console.error('post-event Fehler:', e.message);
        res.status(500).json({ error: e.message });
    }
});

// POST /close-event – Buttons deaktivieren, Thread abschließen
app.post('/close-event', async (req, res) => {
    const { event_id, message_id, channel_id } = req.body;
    try {
        const channel = await client.channels.fetch(channel_id);
        const msg     = await channel.messages.fetch(message_id);
        const embeds  = msg.embeds;

        // Letztes Embed holen und Footer aktualisieren
        const updatedEmbed = EmbedBuilder.from(embeds[0])
            .setFooter({ text: '🔒 Anmeldung geschlossen' })
            .setColor(0x555555);
        await msg.edit({ embeds: [updatedEmbed], components: [buildButtons(true)] });

        // Thread-Log
        const ev = openEvents.get(event_id);
        if (ev?.threadId) {
            try {
                const thread = await client.channels.fetch(ev.threadId);
                await thread.send(`\`${new Date().toLocaleTimeString('de-DE')}\` ⏹ **Anmeldung manuell geschlossen.**`);
            } catch(e) {}
        }

        openEvents.delete(event_id);
        res.json({ success: true });
    } catch(e) {
        res.status(500).json({ error: e.message });
    }
});

// POST /delete-event – Nachricht + Thread löschen
app.post('/delete-event', async (req, res) => {
    const { event_id, message_id, channel_id, thread_id } = req.body;
    try {
        // Thread zuerst löschen (bevor die Elternnachricht weg ist)
        if (thread_id) {
            try {
                const thread = await client.channels.fetch(thread_id);
                await thread.delete('Anmeldung entfernt');
            } catch(e) {
                console.warn(`Thread ${thread_id} konnte nicht gelöscht werden:`, e.message);
            }
        }
        // Nachricht löschen
        const channel = await client.channels.fetch(channel_id);
        const msg     = await channel.messages.fetch(message_id);
        await msg.delete();
        openEvents.delete(event_id);
        res.json({ success: true });
    } catch(e) {
        res.status(500).json({ error: e.message });
    }
});

// GET /health – Status-Check vom Admin
app.get('/health', (req, res) => {
    res.json({
        status:      'ok',
        bot_tag:     client.user?.tag || 'nicht eingeloggt',
        open_events: openEvents.size,
        uptime:      Math.floor(process.uptime()),
    });
});

// ============================================================
// Deadline-Checker (jede Minute)
// ============================================================
setInterval(async () => {
    if (!config.callback_url) return;
    try {
        const res = await axios.post(config.callback_url, {
            action:     'check_deadlines',
            bot_secret: config.bot_secret,
        }, { timeout: 5000, headers: { 'X-Bot-Secret': config.bot_secret } });

        const toClose = res.data?.to_close || [];
        for (const ev of toClose) {
            try {
                const channel = await client.channels.fetch(ev.channel_id);
                const msg     = await channel.messages.fetch(ev.message_id);
                const updatedEmbed = EmbedBuilder.from(msg.embeds[0])
                    .setFooter({ text: '⏳ Anmeldeschluss erreicht – Anmeldung geschlossen' })
                    .setColor(0x555555);
                await msg.edit({ embeds: [updatedEmbed], components: [buildButtons(true)] });

                if (ev.thread_id) {
                    try {
                        const thread = await client.channels.fetch(ev.thread_id);
                        await thread.send(`\`${new Date().toLocaleTimeString('de-DE')}\` ⏳ **Anmeldeschluss erreicht. Anmeldung automatisch geschlossen.**`);
                    } catch(e) {}
                }

                openEvents.delete(parseInt(ev.event_id));
                console.log(`⏰ Event ${ev.event_id} (R${ev.round} ${ev.track_name}) automatisch geschlossen`);
            } catch(e) {
                console.warn(`Fehler beim Schließen von Event ${ev.event_id}:`, e.message);
            }
        }
    } catch(e) { /* Silence */ }
}, 60000);

// ============================================================
// Starten
// ============================================================
app.listen(config.port, '0.0.0.0', () => {
    console.log(`🌐 HTTP-Server auf Port ${config.port} (0.0.0.0)`);
});

client.login(config.token).catch(e => {
    console.error('❌ Login fehlgeschlagen:', e.message);
    process.exit(1);
});
