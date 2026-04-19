<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';
$currentPage = '';
$pageTitle   = 'Impressum – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';

$name    = getSetting('imprint_name','');
$email   = getSetting('imprint_email','');
$address = getSetting('imprint_address','');
$city    = getSetting('imprint_city','');
$phone   = getSetting('imprint_phone','');
$ust     = getSetting('imprint_ust','');
$extra   = getSetting('imprint_extra','');
?>
<div class="container section" style="max-width:720px">
  <div class="section-title mb-4">Impressum</div>

  <?php if (!$name && !$email): ?>
  <div class="card">
    <div class="card-body">
      <p class="text-muted">Das Impressum wurde noch nicht konfiguriert.</p>
      <?php if (isLoggedIn()): ?>
      <a href="<?= SITE_URL ?>/admin/settings.php" class="btn btn-secondary btn-sm">→ Jetzt einrichten</a>
      <?php endif; ?>
    </div>
  </div>
  <?php else: ?>

  <div class="card mb-3">
    <div class="card-body" style="line-height:1.9">

      <h3 style="font-size:1rem;font-weight:700;margin-bottom:12px">Angaben gemäß § 5 TMG</h3>

      <?php if ($name): ?>
      <div><strong><?= h($name) ?></strong></div>
      <?php endif; ?>
      <?php if ($address): ?>
      <div><?= h($address) ?></div>
      <?php endif; ?>
      <?php if ($city): ?>
      <div><?= h($city) ?></div>
      <?php endif; ?>

      <?php if ($phone || $email): ?>
      <div style="margin-top:16px">
        <?php if ($phone): ?><div>📞 <?= h($phone) ?></div><?php endif; ?>
        <?php if ($email): ?><div>✉️ <a href="mailto:<?= h($email) ?>" style="color:var(--primary)"><?= h($email) ?></a></div><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($ust): ?>
      <div style="margin-top:16px">
        <strong>Umsatzsteuer-Identifikationsnummer</strong><br/>
        <?= h($ust) ?>
      </div>
      <?php endif; ?>

      <?php if ($extra): ?>
      <div style="margin-top:16px"><?= nl2br(h($extra)) ?></div>
      <?php endif; ?>

    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:8px">Haftung für Inhalte</h3>
      <p style="font-size:.88rem;color:var(--text2);line-height:1.7">
        Als Diensteanbieter sind wir gemäß § 7 Abs. 1 TMG für eigene Inhalte auf diesen Seiten
        nach den allgemeinen Gesetzen verantwortlich. Nach §§ 8 bis 10 TMG sind wir als
        Diensteanbieter jedoch nicht verpflichtet, übermittelte oder gespeicherte fremde
        Informationen zu überwachen oder nach Umständen zu forschen, die auf eine rechtswidrige
        Tätigkeit hinweisen.
      </p>
      <h3 style="font-size:1rem;font-weight:700;margin:16px 0 8px">Haftung für Links</h3>
      <p style="font-size:.88rem;color:var(--text2);line-height:1.7">
        Unser Angebot enthält Links zu externen Webseiten Dritter, auf deren Inhalte wir keinen
        Einfluss haben. Deshalb können wir für diese fremden Inhalte auch keine Gewähr übernehmen.
        Für die Inhalte der verlinkten Seiten ist stets der jeweilige Anbieter oder Betreiber der
        Seiten verantwortlich.
      </p>
    </div>
  </div>

  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
