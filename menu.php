<?php
// Public menu: food, entrance/swimming fees and activities from the existing menu tables.
require __DIR__ . '/includes/bootstrap.php';

$rows = db_all("SELECT ot.name AS type_name, c.id AS cat_id, c.name AS category, o.name AS item, o.description,
                       pv.variant_label, pv.price
                FROM offerings o
                JOIN categories c ON c.id = o.category_id
                JOIN offering_types ot ON ot.id = c.offering_type_id
                LEFT JOIN price_variants pv ON pv.offering_id = o.id
                WHERE ot.name IN ('Food', 'Activities')
                ORDER BY ot.id, c.id, o.name, pv.price");
$menu = [];
foreach ($rows as $r) {
    $menu[$r['type_name']][$r['category']][$r['item']]['description'] = $r['description'];
    $menu[$r['type_name']][$r['category']][$r['item']]['prices'][] = $r;
}

$pageTitle = 'Food & activities';
$activeNav = 'menu';
include __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div class="container">
    <h1>Food &amp; activities</h1>
    <p>Cooked in the farm kitchen. You can pre-order when you book, so your food is ready when you arrive.</p>
  </div>
</section>
<div class="container pb-5">
  <?php if (!empty($menu['Activities'])): ?>
    <div class="d-flex flex-column flex-md-row gap-3 align-items-md-center mb-4 pb-4 border-bottom">
      <h2 class="h4 mb-0 flex-shrink-0" style="min-width:9rem">Activities</h2>
      <ul class="activity-list">
        <?php foreach ($menu['Activities'] as $cat => $items) foreach ($items as $name => $info): ?>
          <li><?= e($name) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="menu-columns">
    <?php foreach ($menu['Food'] ?? [] as $cat => $items): ?>
      <section class="menu-cat">
        <h3><?= e($cat) ?></h3>
        <?php foreach ($items as $name => $info): ?>
          <div class="menu-item">
            <span class="mi-name"><?= e($name) ?><?= $info['description'] ? '<span class="mi-desc">' . e($info['description']) . '</span>' : '' ?></span>
            <span class="mi-price">
              <?php foreach ($info['prices'] as $p) if ($p['price'] !== null): ?>
                <?= $p['variant_label'] !== 'default' ? '<span>' . e($p['variant_label']) . '</span> ' : '' ?><?= money($p['price']) ?><br>
              <?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php';
