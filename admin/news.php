<?php
define('IN_APP', true);
require_once dirname(__DIR__) . '/includes/config.php';
$adminTitle = 'News'; $adminPage = 'news';
$db = getDB(); $user = currentUser();

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole('editor');
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $cat     = trim($_POST['category'] ?? 'News');
        $excerpt = trim($_POST['excerpt'] ?? '');
        $content = $_POST['content'] ?? '';
        $pub     = isset($_POST['published']) ? 1 : 0;
        $sl      = slug($title);
        // Unique slug
        if ($id) {
            $existing = $db->prepare("SELECT id FROM news WHERE slug = ? AND id != ?")->execute([$sl, $id]);
        } else {
            $check = $db->prepare("SELECT COUNT(*) FROM news WHERE slug = ?"); $check->execute([$sl]);
            if ($check->fetchColumn()) $sl .= '-' . time();
        }
        // Image
        $imgPath = $_POST['image_path_current'] ?? '';
        if (!empty($_FILES['image_file']['name'])) {
            $up = uploadFile($_FILES['image_file'], 'news', ['image/jpeg','image/png','image/gif','image/webp']);
            if ($up) $imgPath = $up;
        }
        if ($id) {
            $db->prepare("UPDATE news SET title=?,slug=?,category=?,excerpt=?,content=?,image_path=?,published=?,updated_at=NOW() WHERE id=?")
               ->execute([$title,$sl,$cat,$excerpt,$content,$imgPath,$pub,$id]);
        } else {
            $db->prepare("INSERT INTO news (title,slug,category,excerpt,content,image_path,published,author_id) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$title,$sl,$cat,$excerpt,$content,$imgPath,$pub,$user['id']]);
        }
        // Discord Webhook bei neuer veröffentlichter News
        if ($pub && !$id && getSetting('discord_notify_news','1') === '1') {
            $newId = (int)$db->lastInsertId();
            $newsRow = ['id'=>$newId,'slug'=>$sl,'title'=>$title,'content'=>$content,
                        'excerpt'=>$excerpt,'category'=>$cat,'image_path'=>$imgPath,
                        'author_name'=>$user['user']??'Admin'];
            discordNotify('', discordNewsEmbed($newsRow));
        }
        auditLog('news_save','news',$id?:(int)$db->lastInsertId(),$title);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ News gespeichert!'];
        header('Location: ' . SITE_URL . '/admin/news.php'); exit;
    }
    if ($action === 'discord_news') {
        $newsId = (int)$_POST['news_id'];
        $n = $db->prepare("SELECT n.*,u.username AS author_name FROM news n LEFT JOIN admin_users u ON u.id=n.author_id WHERE n.id=?");
        $n->execute([$newsId]); $n = $n->fetch();
        if ($n) {
            $ok = discordNotify('', discordNewsEmbed($n));
            $_SESSION['flash'] = $ok
                ? ['type'=>'success','msg'=>'✅ Discord Webhook für News ausgelöst!']
                : ['type'=>'error','msg'=>'❌ Discord Webhook fehlgeschlagen.'];
        }
        header('Location: ' . SITE_URL . '/admin/news.php'); exit;
    }
    if ($action === 'delete') {
        $db->prepare("DELETE FROM news WHERE id=?")->execute([(int)$_POST['id']]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'🗑 News gelöscht.'];
        header('Location: ' . SITE_URL . '/admin/news.php'); exit;
    }
    if ($action === 'toggle') {
        $db->prepare("UPDATE news SET published = NOT published WHERE id=?")->execute([(int)$_POST['id']]);
        header('Location: ' . SITE_URL . '/admin/news.php'); exit;
    }
}

// Edit mode
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM news WHERE id=?"); $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}

$newsList = $db->query("SELECT n.*, u.username AS author FROM news n LEFT JOIN admin_users u ON u.id=n.author_id ORDER BY n.created_at DESC")->fetchAll();
$categories = ['News','Rennbericht','Ankündigung','Regelwerk','Ergebnis','Sonstiges'];
require_once __DIR__ . '/includes/layout.php';
?>
<div class="admin-page-title">News <span style="color:var(--primary)">verwalten</span></div>
<div class="admin-page-sub">News veröffentlichen, bearbeiten und löschen</div>

<!-- Form -->
<div class="card mb-4">
  <div class="card-header">
    <h3><?= $editing ? '✏️ News bearbeiten' : '➕ Neue News' ?></h3>
    <?php if ($editing): ?><a href="<?= SITE_URL ?>/admin/news.php" class="btn btn-secondary btn-sm">Abbrechen</a><?php endif; ?>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="save"/>
      <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>"/>
      <input type="hidden" name="image_path_current" value="<?= h($editing['image_path'] ?? '') ?>"/>
      <div class="form-row cols-2">
        <div class="form-group">
          <label>Titel *</label>
          <input type="text" name="title" class="form-control" value="<?= h($editing['title'] ?? '') ?>" required/>
        </div>
        <div class="form-group">
          <label>Kategorie</label>
          <select name="category" class="form-control">
            <?php foreach ($categories as $c): ?>
              <option value="<?= $c ?>" <?= ($editing['category']??'News')===$c?'selected':'' ?>><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Kurzbeschreibung (Vorschau)</label>
        <input type="text" name="excerpt" class="form-control" value="<?= h($editing['excerpt'] ?? '') ?>" placeholder="Erscheint in der News-Liste..."/>
      </div>
      <div class="form-group">
        <label>Inhalt (HTML erlaubt)</label>
        <textarea name="content" class="form-control" style="min-height:220px"><?= h($editing['content'] ?? '') ?></textarea>
      </div>
      <div class="form-row cols-2">
        <div class="form-group">
          <label>Bild</label>
          <?php if (!empty($editing['image_path'])): ?>
            <img src="<?= h($editing['image_path']) ?>" style="max-height:100px;margin-bottom:8px;border-radius:4px"/>
          <?php endif; ?>
          <input type="file" name="image_file" class="form-control" accept="image/*"/>
          <div class="input-hint">Oder URL unten eintragen (wird ignoriert wenn Datei gewählt)</div>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;gap:16px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;font-size:.88rem;text-transform:none;letter-spacing:0">
            <input type="checkbox" name="published" <?= ($editing['published']??1)?'checked':'' ?>/>
            <span>Veröffentlicht</span>
          </label>
        </div>
      </div>
      <button type="submit" class="btn btn-primary"><?= $editing ? '💾 Aktualisieren' : '🚀 Veröffentlichen' ?></button>
    </form>
  </div>
</div>

<!-- List -->
<div class="card">
  <div class="card-header"><h3>Alle News (<?= count($newsList) ?>)</h3></div>
  <div class="card-body" style="padding:0">
    <?php if ($newsList): ?>
    <div class="overflow-x">
    <table class="admin-table">
      <thead><tr><th>Titel</th><th>Kategorie</th><th>Autor</th><th>Datum</th><th>Status</th><th style="text-align:right">Aktionen</th></tr></thead>
      <tbody>
        <?php foreach ($newsList as $n): ?>
        <tr>
          <td><strong><?= h($n['title']) ?></strong></td>
          <td><span class="badge badge-info"><?= h($n['category']) ?></span></td>
          <td class="text-muted"><?= h($n['author'] ?? '–') ?></td>
          <td class="text-muted"><?= date('d.m.Y', strtotime($n['created_at'])) ?></td>
          <td><span class="badge <?= $n['published']?'badge-success':'badge-muted' ?>"><?= $n['published']?'Live':'Entwurf' ?></span></td>
          <td>
            <div class="actions">
              <a href="<?= SITE_URL ?>/news.php?slug=<?= h($n['slug']) ?>" class="btn btn-secondary btn-sm" target="_blank">👁</a>
              <a href="?edit=<?= $n['id'] ?>" class="btn btn-secondary btn-sm">✏️</a>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle"/>
                <input type="hidden" name="id" value="<?= $n['id'] ?>"/>
                <button class="btn btn-secondary btn-sm"><?= $n['published']?'⏸':'▶' ?></button>
              </form>
              <?php if(getSetting('discord_webhook_url') && $n['published']): ?>
              <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="discord_news"/>
                <input type="hidden" name="news_id" value="<?= $n['id'] ?>"/>
                <button class="btn btn-secondary btn-sm" title="Discord Notification senden" style="color:#5865F2;border-color:#5865F2">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>
                </button>
              </form>
              <?php endif; ?>
              <form method="post" style="display:inline" onsubmit="return confirm('News löschen?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="id" value="<?= $n['id'] ?>"/>
                <button class="btn btn-danger btn-sm">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php else: ?><div style="padding:18px" class="text-muted">Noch keine News vorhanden.</div><?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/layout_end.php'; ?>
