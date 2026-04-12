<?php
// admin/includes/layout.php  v1.1
if (!defined('IN_APP')) die('Direct access forbidden');
requireLogin();
$s = getAllSettings();
$pc=$s['color_primary']??'#e8333a';$sc=$s['color_secondary']??'#f5a623';$tc=$s['color_tertiary']??'#1a9fff';$bg=$s['color_bg']??'#0a0a0f';$tx=$s['color_text']??'#f0f0f5';
function adjH(string $h,int $a):string{$h=ltrim($h,'#');if(strlen($h)===3)$h=$h[0].$h[0].$h[1].$h[1].$h[2].$h[2];return sprintf('#%02x%02x%02x',max(0,min(255,hexdec(substr($h,0,2))+$a)),max(0,min(255,hexdec(substr($h,2,2))+$a)),max(0,min(255,hexdec(substr($h,4,2))+$a)));}
$bg2=adjH($bg,12);$bg3=adjH($bg,22);$brd=adjH($bg,32);
$cu=currentUser();$ap=$adminPage??'';
$qe=getSetting('qualifying_enabled','1')==='1';$pe=getSetting('penalties_enabled','1')==='1';$ga=getSetting('google_analytics','');
$isEditor      = hasRole('editor');
$isAdmin       = hasRole('admin');
$isSuperAdmin  = hasRole('superadmin');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?= h($adminTitle??'Admin') ?> – <?= h($s['league_name']??'Liga') ?> Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;900&family=Barlow:wght@300;400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css"/>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/admin.css"/>
<?php if($ga): ?><script async src="https://www.googletagmanager.com/gtag/js?id=<?= h($ga) ?>"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= h($ga) ?>');</script><?php endif; ?>
<style>:root{--primary:<?= h($pc)?>;--secondary:<?= h($sc)?>;--tertiary:<?= h($tc)?>;--bg:<?= h($bg)?>;--bg2:<?= h($bg2)?>;--bg3:<?= h($bg3)?>;--border:<?= h($brd)?>;--text:<?= h($tx)?>}</style>
</head>
<body class="admin-body">
<header class="admin-topbar">
  <a class="admin-brand" href="<?= SITE_URL ?>/admin/"><div class="brand-icon"><?= h(mb_substr($s['league_abbr']??'SR',0,2)) ?></div><div class="admin-brand-text"><div class="admin-brand-name"><?= h($s['league_name']??'Liga') ?></div><div class="admin-brand-sub">Admin v<?= APP_VERSION ?></div></div></a>
  <?php if(getSetting('maintenance_mode','0')==='1'): ?><div style="background:rgba(245,166,35,.15);border:1px solid var(--secondary);border-radius:4px;padding:4px 12px;margin:0 16px;font-size:.8rem;color:var(--secondary)">🔧 Wartungsmodus</div><?php endif; ?>
  <div class="admin-topbar-right">
    <span class="admin-user-badge">👤 <strong><?= h($cu['user']) ?></strong> <span class="badge badge-muted" style="font-size:.6rem"><?= h($cu['role']) ?></span></span>
    <a href="<?= SITE_URL ?>/admin/mfa_setup.php" class="btn btn-secondary btn-sm" title="Zwei-Faktor-Authentifizierung">🔐<?php if(!empty($cu['id']) && $db=getDB() && ($mfaRow=$db->prepare("SELECT mfa_enabled FROM admin_users WHERE id=?")) && $mfaRow->execute([$cu['id']]) && ($mfaEnabled=$mfaRow->fetchColumn())): ?> <span style="color:#4cffb0;font-size:.6rem">ON</span><?php else: ?> <span style="color:#8888a0;font-size:.6rem">OFF</span><?php endif; ?></a>
    <a href="<?= SITE_URL ?>/" class="btn btn-secondary btn-sm" target="_blank">🌐 Website</a>
    <a href="<?= SITE_URL ?>/admin/logout.php" class="btn btn-danger btn-sm">Logout</a>
  </div>
</header>
<div class="admin-wrapper">
<aside class="admin-sidebar">
  <div class="admin-menu-group">Liga</div>
  <a href="<?= SITE_URL ?>/admin/index.php" class="admin-menu-item <?= $ap==='dashboard'?'active':'' ?>"><span class="menu-icon">📊</span><span>Dashboard</span></a>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/settings.php" class="admin-menu-item <?= $ap==='settings'?'active':'' ?>"><span class="menu-icon">⚙️</span><span>Liga Einstellungen</span></a><?php endif; ?>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/design.php" class="admin-menu-item <?= $ap==='design'?'active':'' ?>"><span class="menu-icon">🎨</span><span>Design</span></a><?php endif; ?>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/info.php" class="admin-menu-item <?= $ap==='info'?'active':'' ?>"><span class="menu-icon">ℹ️</span><span>Liga Info</span></a><?php endif; ?>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/social.php" class="admin-menu-item <?= $ap==='social'?'active':'' ?>"><span class="menu-icon">🔗</span><span>Social Links</span></a><?php endif; ?>
  <div class="admin-menu-group">Inhalte</div>
  <a href="<?= SITE_URL ?>/admin/news.php" class="admin-menu-item <?= $ap==='news'?'active':'' ?>"><span class="menu-icon">📰</span><span>News</span></a>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/seasons.php" class="admin-menu-item <?= $ap==='seasons'?'active':'' ?>"><span class="menu-icon">🏆</span><span>Saisons</span></a><?php endif; ?>
  <a href="<?= SITE_URL ?>/admin/calendar.php" class="admin-menu-item <?= $ap==='calendar'?'active':'' ?>"><span class="menu-icon">📅</span><span>Kalender</span></a>
  <a href="<?= SITE_URL ?>/admin/tracks.php" class="admin-menu-item <?= $ap==='tracks'?'active':'' ?>"><span class="menu-icon">🗺️</span><span>Strecken</span></a>
  <div class="admin-menu-group">Teilnehmer</div>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/teams.php" class="admin-menu-item <?= $ap==='teams'?'active':'' ?>"><span class="menu-icon">🚗</span><span>Teams</span></a><?php endif; ?>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/drivers.php" class="admin-menu-item <?= $ap==='drivers'?'active':'' ?>"><span class="menu-icon">🏎️</span><span>Fahrer (Global)</span></a><?php endif; ?>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/lineup.php" class="admin-menu-item <?= $ap==='lineup'?'active':'' ?>"><span class="menu-icon">📋</span><span>Saison-Lineup</span></a><?php endif; ?>
  <div class="admin-menu-group">Rennen</div>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/results.php" class="admin-menu-item <?= $ap==='results'?'active':'' ?>"><span class="menu-icon">🏁</span><span>Race Results</span></a><?php endif; ?>
  <?php if($qe): ?><a href="<?= SITE_URL ?>/admin/qualifying.php" class="admin-menu-item <?= $ap==='qualifying'?'active':'' ?>"><span class="menu-icon">⏱️</span><span>Qualifying Results</span></a><?php endif; ?>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/import_lmu.php" class="admin-menu-item <?= $ap==='import_lmu'?'active':'' ?>"><span class="menu-icon">🏎</span><span>LMU XML Import</span></a><?php endif; ?>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/export.php" class="admin-menu-item <?= $ap==='export'?'active':'' ?>"><span class="menu-icon">🖼</span><span>Grafik Export</span></a><?php endif; ?>
  <?php if($pe): ?><a href="<?= SITE_URL ?>/admin/penalties.php" class="admin-menu-item <?= $ap==='penalties'?'active':'' ?>"><span class="menu-icon">⚠️</span><span>Strafen</span></a><?php endif; ?>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/points.php" class="admin-menu-item <?= $ap==='points'?'active':'' ?>"><span class="menu-icon">🏅</span><span>Punktesystem</span></a><?php endif; ?>
  <div class="admin-menu-group">System</div>
<?php if(hasRole('superadmin')): ?>  <a href="<?= SITE_URL ?>/admin/users.php" class="admin-menu-item <?= $ap==='users'?'active':'' ?>"><span class="menu-icon">👤</span><span>Benutzer</span></a><?php endif; ?>
<?php if(hasRole('admin')): ?>  <a href="<?= SITE_URL ?>/admin/advanced.php" class="admin-menu-item <?= $ap==='advanced'?'active':'' ?>"><span class="menu-icon">🔧</span><span>Erweitert</span></a><?php endif; ?>
</aside>
<main class="admin-content">
<?php if(isset($_SESSION['flash'])): ?><div class="notice notice-<?= h($_SESSION['flash']['type']) ?> flash-message"><?= h($_SESSION['flash']['msg']) ?></div><?php unset($_SESSION['flash']); endif; ?>
