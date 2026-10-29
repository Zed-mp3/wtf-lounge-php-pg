<?php
require_once __DIR__ . '/../includes/auth.php';
configureSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

$stepLabels = ['meal' => 'Meals', 'rice' => 'Rice', 'drink' => 'Drinks', 'addon' => 'Add-ons (optional)'];
$stepHints = [
    'meal'  => 'Step 1 — customer must pick one (e.g. products from your Meals category). Extra charge 0 = included in the budget meal price.',
    'rice'  => 'Step 2 — customer must pick one. Extra charge 0 = included in the budget meal price.',
    'drink' => 'Step 3 — optional, customers also see a "No drink" choice. Extra charge 0 = included in the budget meal price.',
    'addon' => 'Step 4 — optional, customer can pick any number. The extra charge is added to the meal price.',
];

// ---- Handle changes (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_option') {
            $step = (string) ($_POST['step'] ?? '');
            $productId = (int) ($_POST['product_id'] ?? 0);
            $extra = round(min(99999.99, max(0, (float) ($_POST['extra_price'] ?? 0))), 2);

            if (!isset($stepLabels[$step]) || $productId <= 0) {
                flashSet('Choose a step and a product.');
            } elseif (!getProduct($pdo, $productId)) {
                flashSet('That product was not found or is inactive.');
            } elseif (getBudgetMeal($pdo, $productId)) {
                flashSet('A budget meal cannot be used as a choice inside another budget meal.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO meal_options (step, product_id, extra_price)
                     VALUES (?, ?, ?)
                     ON CONFLICT (step, product_id) DO NOTHING'
                );
                $stmt->execute([$step, $productId, $extra]);
                flashSet($stmt->rowCount() > 0 ? 'Option added to ' . $stepLabels[$step] . '.' : 'That product is already in this step.');
            }
        } elseif ($action === 'update_option') {
            $optionId = (int) ($_POST['option_id'] ?? 0);
            $extra = round(min(99999.99, max(0, (float) ($_POST['extra_price'] ?? 0))), 2);
            $isActive = isset($_POST['is_active']) ? 'true' : 'false';
            if ($optionId > 0) {
                $stmt = $pdo->prepare('UPDATE meal_options SET extra_price = ?, is_active = ? WHERE option_id = ?');
                $stmt->execute([$extra, $isActive, $optionId]);
                flashSet('Option updated.');
            }
        } elseif ($action === 'delete_option') {
            $optionId = (int) ($_POST['option_id'] ?? 0);
            if ($optionId > 0) {
                $stmt = $pdo->prepare('DELETE FROM meal_options WHERE option_id = ?');
                $stmt->execute([$optionId]);
                flashSet('Option removed.');
            }
        }
    } catch (PDOException $e) {
        flashSet('Could not save: ' . $e->getMessage());
    }

    header('Location: meal_options.php');
    exit;
}

// ---- Load data ----
$grouped = ['meal' => [], 'rice' => [], 'drink' => [], 'addon' => []];
$setupNeeded = false;
try {
    $rows = $pdo->query(
        'SELECT mo.option_id, mo.step, mo.extra_price, mo.sort_order, mo.is_active,
                p.product_id, p.productname, p.price, p.stock, p.is_active AS product_active,
                c.categoryname
         FROM meal_options mo
         JOIN products p ON p.product_id = mo.product_id
         LEFT JOIN categories c ON c.category_id = p.category_id
         ORDER BY mo.sort_order, mo.option_id'
    )->fetchAll();
    foreach ($rows as $r) {
        $grouped[$r['step']][] = $r;
    }
} catch (PDOException $e) {
    $setupNeeded = true; // meal_options table (or its product_id column) is missing
}

$categories = getCategories($pdo);
// Products the admin can pick from: active ones, excluding the budget meals themselves.
$pickable = $pdo->query(
    'SELECT p.product_id, p.productname, p.price, p.stock, p.category_id, c.categoryname
     FROM products p
     LEFT JOIN categories c ON c.category_id = p.category_id
     WHERE p.is_active AND (c.categoryname IS NULL OR LOWER(TRIM(c.categoryname)) <> \'budget meals\')
     ORDER BY c.categoryname, p.productname'
)->fetchAll();

$pickJson = [];
foreach ($pickable as $p) {
    $pickJson[] = [
        'id'    => (int) $p['product_id'],
        'name'  => $p['productname'],
        'cat'   => (int) $p['category_id'],
        'price' => (float) $p['price'],
        'stock' => (int) $p['stock'],
    ];
}

$pageTitle = 'Meal Options — WTF Lounge Admin';
$activeAdminNav = 'meal_options';
require __DIR__ . '/includes/admin_header.php';
?>

<h1 class="headline-lg">Budget Meal Options</h1>
<p class="body-md">Choose which products customers can pick for each budget meal: Rice, then Drink, then optional Add-ons. Products come from <a href="menu.php">Manage Menu</a>, so names, stock and availability stay in sync.</p>

<?php if ($setupNeeded): ?>
    <div class="admin-welcome" style="flex-direction:column; align-items:stretch;">
        <h2 style="font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:18px; margin:0 0 8px;">One-time setup needed</h2>
        <p style="margin:0;">Run <code>sql/budget_meals.sql</code> in the Supabase SQL editor (or pgAdmin), then reload this page.</p>
    </div>
<?php else: ?>

<!-- Add option -->
<div class="admin-welcome" style="flex-direction:column; align-items:stretch;">
    <h2 style="font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:18px; margin:0 0 12px;">Add an option</h2>
    <form method="post" action="meal_options.php" id="addForm" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_option">

        <div class="field" style="margin:0;">
            <label for="addStep">Step</label>
            <select name="step" id="addStep" required>
                <?php foreach ($stepLabels as $key => $label): ?>
                    <option value="<?= h($key) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="margin:0;">
            <label for="addCat">Category</label>
            <select id="addCat">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                    <?php if (strtolower(trim($cat['categoryname'])) === BUDGET_CATEGORY) { continue; } ?>
                    <option value="<?= (int) $cat['category_id'] ?>"><?= h($cat['categoryname']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="margin:0; grid-column: span 2;">
            <label for="addProduct">Product</label>
            <select name="product_id" id="addProduct" required></select>
            <small id="addInfo"></small>
        </div>

        <div class="field" style="margin:0; grid-column: span 2;">
            <label for="addPrice">Extra charge (₱)</label>
            <input type="number" step="0.01" min="0" name="extra_price" id="addPrice" value="0">
        </div>

        <div style="grid-column: span 2;">
            <button type="submit" class="btn btn--primary">Add option</button>
        </div>
    </form>
</div>

<?php foreach ($stepLabels as $key => $label): ?>
    <h2 class="panel__title" style="margin-top:24px; margin-bottom:4px;"><?= h($label) ?></h2>
    <p class="body-md" style="margin:0 0 12px;"><?= h($stepHints[$key]) ?></p>
    <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Regular price</th>
                    <th>Stock</th>
                    <th>Extra charge (₱)</th>
                    <th>Show</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped[$key] as $r): ?>
                    <?php
                    $fid = 'opt' . (int) $r['option_id'];
                    if (!$r['is_active']) {
                        [$statusClass, $statusText] = ['status-badge--cancelled', 'Off'];
                    } elseif (!$r['product_active']) {
                        [$statusClass, $statusText] = ['status-badge--cancelled', 'Hidden — product inactive'];
                    } elseif ((int) $r['stock'] <= 0) {
                        [$statusClass, $statusText] = ['status-badge--cancelled', 'Hidden — out of stock'];
                    } else {
                        [$statusClass, $statusText] = ['status-badge--completed', 'Live'];
                    }
                    ?>
                    <tr>
                        <td>
                            <form method="post" action="meal_options.php" id="<?= $fid ?>">
                                <?= csrfField() ?>
                                <input type="hidden" name="option_id" value="<?= (int) $r['option_id'] ?>">
                            </form>
                            <strong><?= h($r['productname']) ?></strong><br>
                            <small><?= h($r['categoryname'] ?? 'Uncategorized') ?></small>
                        </td>
                        <td><?= formatPrice((float) $r['price']) ?></td>
                        <td><?= (int) $r['stock'] ?></td>
                        <td><input form="<?= $fid ?>" type="number" step="0.01" min="0" name="extra_price" value="<?= h(number_format((float) $r['extra_price'], 2, '.', '')) ?>" style="width:90px;"></td>
                        <td><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= $r['is_active'] ? 'checked' : '' ?> aria-label="Show to customers"></td>
                        <td><span class="status-badge <?= $statusClass ?>"><?= h($statusText) ?></span></td>
                        <td>
                            <div class="admin-actions">
                                <button form="<?= $fid ?>" type="submit" name="action" value="update_option" class="btn btn--secondary" style="padding:4px 12px; font-size:11px;">Save</button>
                                <button form="<?= $fid ?>" type="submit" name="action" value="delete_option" onclick="return confirm('Remove this option?')" style="padding:4px 12px; border:1px solid var(--error); border-radius:var(--radius-sm); background:var(--error-container); color:var(--error); cursor:pointer; font-size:11px; font-weight:600;">Remove</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($grouped[$key])): ?>
                    <tr><td colspan="7" style="text-align:center;padding:24px;">Nothing here yet. Customers skip this step until you add an option.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>

<script>
(function () {
    var products = <?= json_encode($pickJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var stepSel = document.getElementById('addStep');
    var catSel = document.getElementById('addCat');
    var prodSel = document.getElementById('addProduct');
    var priceInp = document.getElementById('addPrice');
    var info = document.getElementById('addInfo');
    var priceTouched = false;

    function money(n) { return '\u20B1' + Number(n).toFixed(2); }

    function fillProducts() {
        var cat = catSel.value;
        prodSel.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = 'Select a product';
        prodSel.appendChild(ph);
        products.forEach(function (p) {
            if (cat && String(p.cat) !== cat) { return; }
            var o = document.createElement('option');
            o.value = p.id;
            o.textContent = p.name + ' (' + money(p.price) + ')';
            prodSel.appendChild(o);
        });
        onProductChange();
    }

    function currentProduct() {
        var id = parseInt(prodSel.value, 10);
        return products.filter(function (p) { return p.id === id; })[0] || null;
    }

    // Add-ons usually charge the product's own price; rice/drinks are usually included (0).
    function suggestPrice() {
        if (priceTouched) { return; }
        var p = currentProduct();
        priceInp.value = (stepSel.value === 'addon' && p) ? Number(p.price).toFixed(2) : '0.00';
    }

    function onProductChange() {
        var p = currentProduct();
        info.textContent = p ? 'Regular price ' + money(p.price) + ' · ' + p.stock + ' in stock' : '';
        suggestPrice();
    }

    priceInp.addEventListener('input', function () { priceTouched = true; });
    // Jump to the matching category (Meals / Drinks / Add ons) when the step changes.
    function preselectCategory() {
        var wanted = { meal: 'meals', drink: 'drinks', addon: 'add ons' }[stepSel.value];
        if (!wanted) { return; }
        for (var i = 0; i < catSel.options.length; i++) {
            if (catSel.options[i].textContent.trim().toLowerCase() === wanted) {
                catSel.value = catSel.options[i].value;
                fillProducts();
                return;
            }
        }
    }

    stepSel.addEventListener('change', function () { priceTouched = false; preselectCategory(); suggestPrice(); });
    catSel.addEventListener('change', fillProducts);
    prodSel.addEventListener('change', function () { priceTouched = false; onProductChange(); });
    fillProducts();
    preselectCategory();
})();
</script>

<?php endif; ?>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
