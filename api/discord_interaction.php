<?php
/**
 * API-Endpoint: Empfängt Button-Klicks vom Discord Bot
 * POST /api/discord_interaction.php
 */
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';

header('Content-Type: application/json');

// Einfacher Token-Check: Bot sendet X-Bot-Secret Header
$secret = getSetting('discord_bot_token', '');
$incomingSecret = $_SERVER['HTTP_X_BOT_SECRET'] ?? '';
if (!$secret || $incomingSecret !== substr(hash('sha256', $secret), 0, 32)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

$action        = $body['action']           ?? '';
$eventId       = (int)($body['event_id']   ?? 0);
$discordUserId = $body['discord_user_id']  ?? '';
$discordName   = $body['discord_username'] ?? '';
$status        = $body['status']           ?? ''; // accepted|declined|maybe

$db = getDB();

// ---- Aktion: Anmeldung speichern ----
if ($action === 'signup') {
    if (!$eventId || !$discordUserId || !in_array($status, ['accepted','declined','maybe'])) {
        http_response_code(400); echo json_encode(['error' => 'Missing params']); exit;
    }

    // Event prüfen + Frist
    $event = $db->prepare("SELECT * FROM discord_events WHERE id=?");
    $event->execute([$eventId]); $event = $event->fetch();
    if (!$event || $event['is_closed']) {
        echo json_encode(['error' => 'Event closed', 'closed' => true]); exit;
    }
    if ($event['deadline'] && strtotime($event['deadline']) < time()) {
        // Frist abgelaufen → Event schließen
        $db->prepare("UPDATE discord_events SET is_closed=1 WHERE id=?")->execute([$eventId]);
        echo json_encode(['error' => 'Deadline passed', 'closed' => true]); exit;
    }

    // Alte Anmeldung für Log
    $old = $db->prepare("SELECT status FROM race_signups WHERE event_id=? AND discord_user_id=?");
    $old->execute([$eventId, $discordUserId]); $oldRow = $old->fetch();

    // Upsert
    $db->prepare("INSERT INTO race_signups (event_id,race_id,discord_user_id,discord_username,status)
                  VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE discord_username=VALUES(discord_username), status=VALUES(status), changed_at=NOW()")
       ->execute([$eventId, $event['race_id'], $discordUserId, $discordName, $status]);

    // Aktuelle Teilnehmerliste zurückgeben
    $signups = $db->prepare("SELECT * FROM race_signups WHERE event_id=? ORDER BY changed_at ASC");
    $signups->execute([$eventId]); $signups = $signups->fetchAll();

    $lists = ['accepted'=>[], 'declined'=>[], 'maybe'=>[]];
    foreach ($signups as $s) $lists[$s['status']][] = $s['discord_username'];

    echo json_encode([
        'success'    => true,
        'old_status' => $oldRow ? $oldRow['status'] : null,
        'new_status' => $status,
        'username'   => $discordName,
        'lists'      => $lists,
    ]);
    exit;
}

// ---- Aktion: Frist-Check (Bot fragt alle X Sekunden) ----
if ($action === 'check_deadlines') {
    $expired = $db->query("
        SELECT de.*, rc.track_name, rc.round
        FROM discord_events de
        JOIN races rc ON rc.id = de.race_id
        WHERE de.is_closed = 0
          AND de.deadline IS NOT NULL
          AND de.deadline <= NOW()
    ")->fetchAll();

    $toClose = [];
    foreach ($expired as $e) {
        $db->prepare("UPDATE discord_events SET is_closed=1 WHERE id=?")->execute([$e['id']]);
        $toClose[] = [
            'event_id'   => $e['id'],
            'message_id' => $e['message_id'],
            'channel_id' => $e['channel_id'],
            'thread_id'  => $e['thread_id'],
            'track_name' => $e['track_name'],
            'round'      => $e['round'],
        ];
    }
    echo json_encode(['to_close' => $toClose]);
    exit;
}

// ---- Aktion: Event-Details holen (für Bot-Neustart) ----
if ($action === 'get_open_events') {
    $events = $db->query("
        SELECT de.*, rc.track_name, rc.round, rc.race_date, rc.race_time,
               rc.location, s.name AS season_name
        FROM discord_events de
        JOIN races rc ON rc.id = de.race_id
        JOIN seasons s ON s.id = rc.season_id
        WHERE de.is_closed = 0 AND de.message_id IS NOT NULL
    ")->fetchAll();

    $result = [];
    foreach ($events as $ev) {
        $rawPayload = $ev['event_payload'];
        $payload = ($rawPayload && $rawPayload !== '{}' && $rawPayload !== 'null')
            ? json_decode($rawPayload, true)
            : [];
        if (!is_array($payload)) $payload = [];

        // wx_* sicherstellen dass es Arrays sind (nicht Objekte)
        foreach (['wx_training','wx_quali','wx_race'] as $wxKey) {
            if (isset($payload[$wxKey]) && !array_is_list((array)$payload[$wxKey])) {
                $payload[$wxKey] = array_values((array)$payload[$wxKey]);
            }
        }

        // Pflichtfelder immer aus DB überschreiben
        $payload['event_id']   = (int)$ev['id'];
        $payload['message_id'] = $ev['message_id'];
        $payload['channel_id'] = $ev['channel_id'];
        $payload['thread_id']  = $ev['thread_id'];
        $payload['deadline']   = $ev['deadline'];
        if (empty($payload['track_name']))  $payload['track_name']  = $ev['track_name'];
        if (empty($payload['round']))       $payload['round']       = $ev['round'];
        if (empty($payload['race_date']))   $payload['race_date']   = $ev['race_date'];
        if (empty($payload['season_name'])) $payload['season_name'] = $ev['season_name'];
        if (empty($payload['location']))    $payload['location']    = $ev['location'] ?? '';

        // Debug: payload_debug_info mitschicken
        $payload['_debug'] = [
            'raw_payload_length' => strlen($rawPayload ?? ''),
            'has_wx_training'    => isset($payload['wx_training']),
            'has_time_training'  => isset($payload['time_training']),
        ];

        $result[] = $payload;
    }
    echo json_encode(['events' => $result]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);

// ---- Aktion: Bot-Commands Daten ----
if ($action === 'get_command_data') {
    $cmd = $body['command'] ?? '';
    $db  = getDB();

    if ($cmd === 'calendar') {
        $season = $db->query("SELECT * FROM seasons WHERE is_active=1 LIMIT 1")->fetch();
        if (!$season) { echo json_encode(['error'=>'Keine aktive Saison']); exit; }
        $races = $db->prepare("SELECT * FROM races WHERE season_id=? ORDER BY round ASC");
        $races->execute([$season['id']]); $races = $races->fetchAll();
        echo json_encode(['season'=>$season, 'races'=>$races]); exit;
    }

    if ($cmd === 'result') {
        $row = $db->query("
            SELECT r.id AS result_id, rc.track_name, rc.round, rc.race_date, s.name AS season_name
            FROM results r
            JOIN races rc ON rc.id=r.race_id
            JOIN seasons s ON s.id=rc.season_id
            ORDER BY rc.race_date DESC, r.id DESC LIMIT 1
        ")->fetch();
        if (!$row) { echo json_encode(['error'=>'Keine Ergebnisse']); exit; }
        $top3 = $db->prepare("
            SELECT re.position, re.points, re.is_fastest_lap, re.dnf,
                   d.name AS driver_name, t.name AS team_name, t.color
            FROM result_entries re
            LEFT JOIN drivers d ON d.id=re.driver_id
            LEFT JOIN teams t ON t.id=re.team_id
            WHERE re.result_id=? AND re.position<=3 AND re.dnf=0 AND re.dsq=0
            ORDER BY re.position ASC
        ");
        $top3->execute([$row['result_id']]); $top3=$top3->fetchAll();
        echo json_encode(['result'=>$row,'top3'=>$top3]); exit;
    }

    if ($cmd === 'next') {
        $next = $db->query("
            SELECT rc.*, s.name AS season_name
            FROM races rc
            JOIN seasons s ON s.id=rc.season_id
            WHERE s.is_active=1 AND rc.race_date >= CURDATE()
              AND rc.id NOT IN (SELECT race_id FROM results)
            ORDER BY rc.race_date ASC, rc.round ASC LIMIT 1
        ")->fetch();
        echo json_encode(['race'=>$next ?: null]); exit;
    }

    echo json_encode(['error'=>'Unknown command']); exit;
}
