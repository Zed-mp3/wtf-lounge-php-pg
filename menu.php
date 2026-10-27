<?php
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$selectedCategory = isset($_GET['category']) ? trim($_GET['category']) : 'All';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$categories = getCategories($pdo);
$products = getProducts($pdo, $selectedCategory === 'All' ? null : $selectedCategory, $search);

$pageTitle = 'WTF Lounge — Where To Find the Best Sips';
$activeNav = 'menu';
require __DIR__ . '/includes/header.php';
?>

    <section class="hero">
      <div class="hero__content">
        <p class="tagline-cursive hero__eyebrow">Your daily dose of floral whimsy.</p>
        <h1 class="display-bubbly">Where To Find the Best Sips</h1>
        <a href="#menu" class="btn btn--primary btn--lg">Browse Menu</a>
      </div>
    </section>

    <div id="menu">
      <form method="get" action="menu.php" class="search-bar">
        <?php if ($selectedCategory !== 'All'): ?>
          <input type="hidden" name="category" value="<?= h($selectedCategory) ?>">
        <?php endif; ?>
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search the menu (e.g. matcha, croissant)...">
        <button type="submit">Search</button>
      </form>

      <div class="chips">
        <a href="menu.php?category=All<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="chip <?= $selectedCategory === 'All' ? 'is-selected' : '' ?>">All</a>
        <?php foreach ($categories as $cat): ?>
          <a href="menu.php?category=<?= urlencode($cat['categoryname']) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
             class="chip <?= $selectedCategory === $cat['categoryname'] ? 'is-selected' : '' ?>">
            <?= h($cat['categoryname']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="product-grid">
      <?php if (empty($products)): ?>
        <div class="empty-state" style="grid-column:1/-1;">
          <p><?= $search !== '' ? 'No items match "' . h($search) . '".' : 'No items in this category yet.' ?></p>
        </div>
      <?php endif; ?>

      <?php foreach ($products as $p): ?>
        <div class="product-card">
          <div class="product-card__media">
            <div class="product-card__vine"><?= vineSvg() ?></div>
            <img src="<?= h($p['image']) ?>" alt="<?= h($p['productname']) ?>" loading="lazy">
          </div>
          <div class="product-card__body">
            <h3 class="product-card__name"><?= h($p['productname']) ?></h3>
            <div class="product-card__meta">
              <span class="star">★</span> <?= number_format($p['rating'], 1) ?>
              &nbsp;·&nbsp; <?= number_format($p['sold_count']) ?> sold
            </div>
            <?php $stockStatus = stockStatus((int) $p['stock']); ?>
            <?php if ($stockStatus === 'out'): ?>
              <p class="stock-badge stock-badge--out">Out of stock</p>
            <?php elseif ($stockStatus === 'low'): ?>
              <p class="stock-badge stock-badge--low">Only <?= (int) $p['stock'] ?> left</p>
            <?php endif; ?>
            <div class="product-card__footer">
              <span class="product-card__price"><?= formatPrice($p['price']) ?></span>
              <?php if ($stockStatus === 'out'): ?>
                <button type="button" class="icon-btn" disabled style="opacity:.4;cursor:not-allowed;" aria-label="<?= h($p['productname']) ?> is out of stock">+</button>
              <?php elseif (isBudgetCategoryName($p['categoryname'] ?? '')): ?>
                <a href="budget_meal.php?id=<?= (int) $p['product_id'] ?>" class="icon-btn js-meal-open" aria-label="Build your <?= h($p['productname']) ?> meal" title="Choose rice, drink &amp; add-ons">+</a>
              <?php else: ?>
                <form method="post" action="cart.php" class="inline-form">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
                  <input type="hidden" name="redirect" value="menu.php?category=<?= urlencode($selectedCategory) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
                  <button type="submit" class="icon-btn" aria-label="Add <?= h($p['productname']) ?> to cart">+</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <dialog id="mealModal" class="meal-modal" aria-label="Build your meal">
      <div class="meal-modal__content"></div>
    </dialog>
    <script src="js/budget-meal.js" defer></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
