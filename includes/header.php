<?php
// includes/header.php
if (!defined('IN_APP')) die('Direct access forbidden');
$s = getAllSettings();
$primaryColor    = $s['color_primary']   ?? '#e8333a';
$secondaryColor  = $s['color_secondary'] ?? '#f5a623';
$tertiaryColor   = $s['color_tertiary']  ?? '#1a9fff';
$bgColor         = $s['color_bg']        ?? '#0a0a0f';
$textColor       = $s['color_text']      ?? '#f0f0f5';
$leagueName      = $s['league_name']     ?? 'SimRace Liga';
$leagueAbbr      = $s['league_abbr']     ?? 'SRL';
$leagueSub       = $s['league_sub']      ?? '';
$leagueDesc      = $s['league_desc']     ?? '';
$leagueLogo      = $s['league_logo']     ?? '';
$socialLinks     = json_decode($s['social_links'] ?? '[]', true) ?: [];
$currentPage     = $currentPage ?? '';

function adjustHex(string $hex, int $amt): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = max(0, min(255, hexdec(substr($hex,0,2)) + $amt));
    $g = max(0, min(255, hexdec(substr($hex,2,2)) + $amt));
    $b = max(0, min(255, hexdec(substr($hex,4,2)) + $amt));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
$bg2 = adjustHex($bgColor, 12);
$bg3 = adjustHex($bgColor, 22);
$border = adjustHex($bgColor, 32);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title><?= h($pageTitle ?? $leagueName) ?></title>
<?php if ($leagueLogo): ?><link rel="icon" href="<?= h($leagueLogo) ?>"/><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;900&family=Barlow:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/main.css"/>
<style>
:root {
  --primary:       <?= h($primaryColor)   ?>;
  --secondary:     <?= h($secondaryColor) ?>;
  --tertiary:      <?= h($tertiaryColor)  ?>;
  --bg:            <?= h($bgColor)        ?>;
  --bg2:           <?= h($bg2)            ?>;
  --bg3:           <?= h($bg3)            ?>;
  --border:        <?= h($border)         ?>;
  --text:          <?= h($textColor)      ?>;
  /* Primärfarbe-Ableitungen für dynamisches Farbschema */
  --primary-subtle: <?= h($primaryColor) ?>1a;
  --primary-hover:  <?= h($primaryColor) ?>28;
  --primary-faint:  <?= h($primaryColor) ?>0a;
  --primary-glow:   <?= h($primaryColor) ?>38;
}
</style>
<?php $customCss = getSetting('custom_css',''); if($customCss): ?>
<style id="custom-css"><?= $customCss ?></style>
<?php endif; ?>
</head>
<body>

<header id="top-nav">
  <a class="brand" href="<?= SITE_URL ?>/">
    <?php if ($leagueLogo): ?>
      <img src="<?= h($leagueLogo) ?>" alt="Logo" class="brand-logo"/>
    <?php else: ?>
      <div class="brand-icon"><?= h(mb_substr($leagueAbbr, 0, 2)) ?></div>
    <?php endif; ?>
    <div class="brand-text">
      <span class="brand-name"><?= h($leagueName) ?></span>
      <?php if ($leagueSub): ?><span class="brand-sub"><?= h($leagueSub) ?></span><?php endif; ?>
    </div>
  </a>
  <nav class="main-nav">
    <a href="<?= SITE_URL ?>/" class="<?= $currentPage==='home'?'active':'' ?>">Home</a>
    <a href="<?= SITE_URL ?>/news.php" class="<?= $currentPage==='news'?'active':'' ?>">News</a>
    <a href="<?= SITE_URL ?>/season.php" class="<?= $currentPage==='season'?'active':'' ?>">Saison</a>
    <a href="<?= SITE_URL ?>/calendar.php" class="<?= $currentPage==='calendar'?'active':'' ?>">Kalender</a>
    <a href="<?= SITE_URL ?>/results.php" class="<?= $currentPage==='results'?'active':'' ?>">Ergebnisse</a>
    <a href="<?= SITE_URL ?>/standings.php" class="<?= $currentPage==='standings'?'active':'' ?>">Wertung</a>
    <a href="<?= SITE_URL ?>/teams.php" class="<?= $currentPage==='teams'?'active':'' ?>">Teams</a>
    <a href="<?= SITE_URL ?>/info.php" class="<?= $currentPage==='info'?'active':'' ?>">Liga Info</a>
  </nav>
  <div class="nav-right">
    <a href="<?= SITE_URL ?>/admin/" class="btn btn-primary btn-sm nav-admin-btn">⚙ Admin</a>
    <button class="nav-burger" id="nav-burger-btn" onclick="toggleMobileNav()" aria-label="Menü öffnen" aria-expanded="false">
      <span class="burger-bar"></span>
      <span class="burger-bar"></span>
      <span class="burger-bar"></span>
    </button>
  </div>
</header>

<div id="mobile-nav" class="mobile-nav" aria-hidden="true">
  <a href="<?= SITE_URL ?>/" class="<?= $currentPage==='home'?'active':'' ?>">
    <span class="mnav-icon">🏠</span> Home
  </a>
  <a href="<?= SITE_URL ?>/news.php" class="<?= $currentPage==='news'?'active':'' ?>">
    <span class="mnav-icon">📰</span> News
  </a>
  <a href="<?= SITE_URL ?>/season.php" class="<?= $currentPage==='season'?'active':'' ?>">
    <span class="mnav-icon">🏆</span> Saison
  </a>
  <a href="<?= SITE_URL ?>/calendar.php" class="<?= $currentPage==='calendar'?'active':'' ?>">
    <span class="mnav-icon">📅</span> Kalender
  </a>
  <a href="<?= SITE_URL ?>/results.php" class="<?= $currentPage==='results'?'active':'' ?>">
    <span class="mnav-icon">🏁</span> Ergebnisse
  </a>
  <a href="<?= SITE_URL ?>/standings.php" class="<?= $currentPage==='standings'?'active':'' ?>">
    <span class="mnav-icon">📊</span> Wertung
  </a>
  <a href="<?= SITE_URL ?>/teams.php" class="<?= $currentPage==='teams'?'active':'' ?>">
    <span class="mnav-icon">👥</span> Teams
  </a>
  <a href="<?= SITE_URL ?>/info.php" class="<?= $currentPage==='info'?'active':'' ?>">
    <span class="mnav-icon">ℹ️</span> Liga Info
  </a>
  <div class="mnav-divider"></div>
  <a href="<?= SITE_URL ?>/admin/" class="mnav-admin">
    <span class="mnav-icon">⚙️</span> Admin
  </a>
</div>
<div id="mobile-nav-overlay" class="mobile-nav-overlay" onclick="closeMobileNav()"></div>

<main id="app">
