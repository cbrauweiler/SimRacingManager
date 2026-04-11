<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
startSecureSession();
session_destroy();
header('Location: ' . SITE_URL . '/admin/login.php');
exit;
