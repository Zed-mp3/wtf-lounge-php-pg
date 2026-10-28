<?php
/**
 * Budget Meal builder:  Budget meal -> Rice -> Drink -> Add-ons (optional) -> Cart.
 * The choices are products picked by the admin (admin/meal_options.php).
 * ?partial=1 returns just the builder form, used by the modal on menu.php.
 */
require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$partial = isset($_GET['partial']);
$productId = (int) ($_GET['id'] ?? $_POST['product_id'] ?? 0);
$product = $productId > 0 ? getBudgetMeal($pdo, $productId) : null;
if (!$product) {
    if ($partial) {
        http_response_code(404);
        exit;
    }
    flashSet('That budget meal is not available.');
    header('Location: menu.php');
    exit;
}
$backUrl = 'menu.php?category=' . urlencode($product['categoryname']);
$stock = (int) $product['stock'];
if ($stock <= 0) {
    if ($partial) {
        http_response_code(404);
        exit;
    }
    flashSet($product['productname'] . ' is out of stock.');
    header('Location: ' . $backUrl);
    exit;
}

$options = getMealOptions($pdo);
$maxQty = min($stock, 10);
$errors = [];
$sel = ['rice' => 0, 'drink' => 0, 'addons' => [], 'qty' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $sel['rice']   = (int) ($_POST['rice'] ?? 0);
    $sel['drink']  = (int) ($_POST['drink'] ?? 0);
    $sel['addons'] = array_values(array_unique(array_map('intval', (array) ($_POST['addons'] ?? []))));
    $sel['qty']    = max(1, min((int) ($_POST['qty'] ?? 1), $maxQty));

    // A step is only required when the admin has set up options for it.
    if (!empty($options['rice']) && !isset($options['rice'][$sel['rice']])) {
        $errors[] = 'Please choose your rice.';
    }
    // Drink is optional: 0 = "No drink".
    if ($sel['drink'] !== 0 && !isset($options['drink'][$sel['drink']])) {
        $errors[] = 'That drink is no longer available.';
    }
    foreach ($sel['addons'] as $a) {
        if (!isset($options['addon'][$a])) {
            $errors[] = 'One of the add-ons you picked is no longer available.';
            break;
        }
    }

    if (empty($errors)) {
        // Never take more than the meal OR any chosen option has in stock.
        $chosen = [];
        if (!empty($options['rice'])) {
            $chosen[] = $options['rice'][$sel['rice']];
        }
        if ($sel['drink'] !== 0) {
            $chosen[] = $options['drink'][$sel['drink']];
        }
        foreach ($sel['addons'] as $a) {
            $chosen[] = $options['addon'][$a];
        }
        $cap = $stock;
        foreach ($chosen as $o) {
            $cap = min($cap, (int) $o['stock']);
        }

        comboAdd(
            (int) $product['product_id'],
            empty($options['rice']) ? 0 : $sel['rice'],
            $sel['drink'],
            $sel['addons'],
            max(1, min($sel['qty'], $cap)),
            $cap
        );
        flashSet($product['productname'] . ' meal added to cart.');
        header('Location: ' . $backUrl);
        exit;
    }
}

/** Renders one group of option cards. */
function renderMealOptions(array $group, string $name, string $type, array $selectedIds): void
{
    foreach ($group as $id => $o) {
        $checked = in_array($id, $selectedIds, true) ? 'checked' : '';
        $extra = (float) $o['extra_price'];
        ?>
        <label class="meal-opt">
          <input type="<?= $type ?>" name="<?= h($name) ?>" value="<?= (int) $id ?>"
                 data-price="<?= h(number_format($extra, 2, '.', '')) ?>" data-label="<?= h($o['name']) ?>" <?= $checked ?>>
          <span class="meal-opt__name"><?= h($o['name']) ?></span>
          <span class="meal-opt__price"><?= $extra > 0 ? '+' . formatPrice($extra) : 'Included' ?></span>
        </label>
        <?php
    }
}

$steps = [];
if (!empty($options['rice']))  { $steps[] = 'Rice'; }
if (!empty($options['drink'])) { $steps[] = 'Drink (optional)'; }
if (!empty($options['addon'])) { $steps[] = 'Add-ons (optional)'; }
$steps[] = 'Review';

if (!$partial) {
    $pageTitle = 'Build your meal — WTF Lounge';
    $activeNav = 'menu';
    require __DIR__ . '/includes/header.php';
}
?>

<?php if ($partial): ?>
    <div class="meal-modal__head">
      <div>
        <h2 class="display-bubbly">Build your meal</h2>
        <p class="body-md"><?= h($product['productname']) ?> · <?= formatPrice((float) $product['price']) ?></p>
      </div>
      <button type="button" class="meal-modal__close" aria-label="Close">&times;</button>
    </div>
<?php else: ?>
    <div class="page-header">
      <h1 class="display-bubbly" style="font-size:32px;">Build your meal</h1>
      <p class="body-md"><?= h($product['productname']) ?> · <?= formatPrice((float) $product['price']) ?></p>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="container">
        <div class="form-errors">
          Please fix the following:
          <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
        </div>
      </div>
    <?php endif; ?>
<?php endif; ?>

    <form method="post" action="budget_meal.php?id=<?= (int) $product['product_id'] ?>" id="mealForm" class="meal-flow"
          data-base="<?= h(number_format((float) $product['price'], 2, '.', '')) ?>">
      <?= csrfField() ?>
      <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">

      <ol class="meal-steps">
        <?php foreach ($steps as $i => $label): ?>
          <li class="<?= $i === 0 ? 'is-active' : '' ?>"><span><?= $i + 1 ?></span><?= h($label) ?></li>
        <?php endforeach; ?>
      </ol>

      <?php if (!empty($options['rice'])): ?>
        <section class="panel meal-step" data-step="rice" data-required="1">
          <h2 class="panel__title">Choose your rice</h2>
          <div class="meal-opts"><?php renderMealOptions($options['rice'], 'rice', 'radio', [$sel['rice']]); ?></div>
        </section>
      <?php endif; ?>

      <?php if (!empty($options['drink'])): ?>
        <section class="panel meal-step" data-step="drink">
          <h2 class="panel__title">Choose your drink <small style="font-weight:400;">(optional)</small></h2>
          <div class="meal-opts">
            <label class="meal-opt">
              <input type="radio" name="drink" value="0" data-price="0.00" data-label="No drink" <?= $sel['drink'] === 0 ? 'checked' : '' ?>>
              <span class="meal-opt__name">No drink</span>
              <span class="meal-opt__price">Skip the drink</span>
            </label>
            <?php renderMealOptions($options['drink'], 'drink', 'radio', [$sel['drink']]); ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if (!empty($options['addon'])): ?>
        <section class="panel meal-step" data-step="addon">
          <h2 class="panel__title">Add-ons <small style="font-weight:400;">(optional)</small></h2>
          <div class="meal-opts"><?php renderMealOptions($options['addon'], 'addons[]', 'checkbox', $sel['addons']); ?></div>
        </section>
      <?php endif; ?>

      <section class="panel meal-step" data-step="review">
        <h2 class="panel__title">Review your meal</h2>
        <ul class="meal-review" id="mealReview"></ul>
        <div class="field" style="margin-top:16px;">
          <label for="mealQty">Quantity</label>
          <input id="mealQty" name="qty" type="number" min="1" max="<?= (int) $maxQty ?>" value="<?= (int) $sel['qty'] ?>">
        </div>
        <div class="summary-total">
          <span class="summary-total__label">Total</span>
          <span class="summary-total__value" id="mealTotal"><?= formatPrice((float) $product['price']) ?></span>
        </div>
      </section>

      <div class="meal-nav">
        <a href="<?= h($backUrl) ?>" class="btn btn--outline" id="mealCancel">Cancel</a>
        <button type="button" class="btn btn--secondary" id="mealBack" hidden>Back</button>
        <button type="button" class="btn btn--primary" id="mealNext" hidden>Next</button>
        <button type="submit" class="btn btn--primary" id="mealSubmit">Add to cart</button>
      </div>
    </form>

<?php if (!$partial): ?>
    <script src="js/budget-meal.js" defer></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>