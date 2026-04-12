<?php
// ============================================================
// includes/config.inc.php | Gruundeinstellung (SITE URL + DB)
// ============================================================

// Pflichtfeld – vollständige URL ohne abschließenden Slash
define('SITE_URL', 'https://deine-domain.de');

// Datenbankverbindung
define('DB_HOST', 'localhost');
define('DB_NAME', 'datenbankname');
define('DB_USER', 'datenbankbenutzer');
define('DB_PASS', 'datenbankpasswort');
define('DB_CHARSET', 'utf8mb4');

define('SITE_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', SITE_ROOT . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

?>