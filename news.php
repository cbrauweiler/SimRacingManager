<?php
define('IN_APP', true);
require_once __DIR__ . '/includes/config.php';

$currentPage = 'news';
$db = getDB();

// Single news view
$slug = $_GET['slug'] ?? '';
if ($slug) {
    $stmt = $db->prepare("SELECT n.*, u.username AS author FROM news n LEFT JOIN admin_users u ON u.id = n.author_id WHERE n.slug = ? AND n.published = 1");
    $stmt->execute([$slug]);
    $article = $stmt->fetch();
    if (!$article) { header('Location: ' . SITE_URL . '/news.php'); exit; }
    $pageTitle = $article['title'] . ' – ' . getSetting('league_name');
    require_once __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <a href="<?= SITE_URL ?>/news.php" class="btn btn-secondary btn-sm mb-3">← Zurück zur Übersicht</a>
  <article style="max-width:800px;margin:0 auto">
    <div class="mb-2">
      <span class="badge badge-primary"><?= h($article['category']) ?></span>
      <span class="text-muted" style="font-size:.82rem;margin-left:10px"><?= date('d.m.Y H:i', strtotime($article['created_at'])) ?> Uhr</span>
    </div>
    <h1 style="font-family:var(--font-display);font-size:clamp(1.8rem,5vw,3rem);font-weight:900;line-height:1.05;margin-bottom:16px"><?= h($article['title']) ?></h1>
    <?php if ($article['image_path']): ?>
      <img src="<?= h($article['image_path']) ?>" alt="" style="width:100%;border-radius:6px;max-height:460px;object-fit:cover;margin-bottom:24px"/>
    <?php endif; ?>
    <div style="line-height:1.85;font-size:1.02rem;max-width:740px"><?= $article['content'] ?></div>
  </article>
</div>
<?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// List view
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 9;
$offset   = ($page - 1) * $perPage;
$category = $_GET['cat'] ?? '';

$where = "published = 1";
$params = [];
if ($category) { $where .= " AND category = ?"; $params[] = $category; }

$total = $db->prepare("SELECT COUNT(*) FROM news WHERE $where");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

$stmt = $db->prepare("SELECT * FROM news WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$newsList = $stmt->fetchAll();

$categories = $db->query("SELECT DISTINCT category FROM news WHERE published = 1 ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'News – ' . getSetting('league_name');
require_once __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="flex flex-center justify-between mb-3" style="flex-wrap:wrap;gap:12px">
    <div>
      <div class="section-title">Liga <span>News</span></div>
      <div class="section-sub">Alle Neuigkeiten auf einen Blick</div>
    </div>
    <div class="flex gap-1 flex-wrap">
      <a href="<?= SITE_URL ?>/news.php" class="btn btn-sm <?= !$category?'btn-primary':'btn-secondary' ?>">Alle</a>
      <?php foreach ($categories as $cat): ?>
        <a href="<?= SITE_URL ?>/news.php?cat=<?= urlencode($cat) ?>" class="btn btn-sm <?= $category===$cat?'btn-primary':'btn-secondary' ?>"><?= h($cat) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($newsList): ?>
  <div class="grid-3">
    <?php foreach ($newsList as $n): ?>
    <a href="<?= SITE_URL ?>/news.php?slug=<?= h($n['slug']) ?>" style="text-decoration:none">
    <div class="card news-card">
      <div class="news-card-img">
        <?php if ($n['image_path']): ?><img src="<?= h($n['image_path']) ?>" alt=""/><?php else: ?>📰<?php endif; ?>
        <span class="news-tag"><?= h($n['category']) ?></span>
      </div>
      <div class="news-card-body">
        <div class="news-date"><?= date('d.m.Y', strtotime($n['created_at'])) ?></div>
        <div class="news-card-title"><?= h($n['title']) ?></div>
        <div class="news-excerpt"><?= h(mb_substr($n['excerpt'] ?: strip_tags($n['content']), 0, 120)) ?>…</div>
        <div class="news-read-more mt-1">Weiterlesen →</div>
      </div>
    </div></a>
    <?php endforeach; ?>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <?php if ($p === $page): ?>
        <span class="current"><?= $p ?></span>
      <?php else: ?>
        <a href="?page=<?= $p ?><?= $category ? '&cat='.urlencode($category) : '' ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <?php else: ?>
    <div class="card"><div class="card-body text-muted">Noch keine News vorhanden.</div></div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
