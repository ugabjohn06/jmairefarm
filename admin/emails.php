<?php
// Shows every email the system sent (or would send, when real email isn't set up on XAMPP).
require __DIR__ . '/../includes/bootstrap.php';
require_staff();

$view = get('id') ? db_one('SELECT * FROM email_log WHERE id = ?', [(int) get('id')]) : null;
[$limit, $offset, $page] = paginate(30);
$total = (int) db_value('SELECT COUNT(*) FROM email_log');
$emails = db_all("SELECT e.id, e.to_email, e.subject, e.status, e.created_at, e.booking_id, b.booking_code
                  FROM email_log e LEFT JOIN bookings b ON b.id = e.booking_id ORDER BY e.id DESC LIMIT $limit OFFSET $offset");

$pageTitle = 'Email log';
$activeAdmin = 'emails';
include __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="mb-1">Email log</h1>
<p class="text-muted small">
  <?= MAIL_ENABLED ? 'Emails are being sent with PHP mail().' : 'Real sending is off (MAIL_ENABLED = false in config.php), so emails are saved here instead. Turn it on once SMTP is set up.' ?>
</p>
<div class="row g-4">
  <div class="col-lg-<?= $view ? '5' : '12' ?>">
    <div class="card"><div class="list-group list-group-flush">
      <?php foreach ($emails as $em): ?>
        <a href="?id=<?= (int) $em['id'] ?>&page=<?= $page ?>" class="list-group-item list-group-item-action <?= $view && (int) $view['id'] === (int) $em['id'] ? 'active' : '' ?>">
          <div class="d-flex justify-content-between"><strong class="small"><?= e($em['subject']) ?></strong><span class="small"><?= e(fmt_datetime($em['created_at'])) ?></span></div>
          <div class="small">To: <?= e($em['to_email']) ?> &middot; <?= e($em['status']) ?></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$emails): ?><div class="list-group-item text-muted">No emails yet.</div><?php endif; ?>
    </div></div>
    <div class="mt-3"><?= pagination_links($total, $limit, $page) ?></div>
  </div>
  <?php if ($view): ?>
    <div class="col-lg-7">
      <div class="card p-3">
        <p class="small mb-1"><strong>To:</strong> <?= e($view['to_email']) ?></p>
        <p class="small mb-1"><strong>Subject:</strong> <?= e($view['subject']) ?></p>
        <p class="small mb-3"><strong>Date:</strong> <?= e(fmt_datetime($view['created_at'])) ?></p>
        <!-- The body is our own generated HTML; shown in a sandboxed frame -->
        <iframe sandbox="" style="width:100%;height:480px;border:1px solid #ddd;border-radius:8px" srcdoc="<?= e(email_layout($view['subject'], $view['body'])) ?>"></iframe>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php';
