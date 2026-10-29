<?php
/**
 * Shared helpers: session cart, formatting, and DB queries used
 * across every page. Requires config/db.php ($pdo), includes/auth.php,
 * and configureSession() to already have run.
 */

const DELIVERY_FEE = 3.00;
const LOW_STOCK_THRESHOLD = 10;

/** Small helper for menu/cart badges: 'out' | 'low' | 'ok'. */
function stockStatus(int $stock): string
{
    if ($stock <= 0) {
        return 'out';
    }
    if ($stock <= LOW_STOCK_THRESHOLD) {
        return 'low';
    }
    return 'ok';
}

/* ---------- formatting ---------- */

function formatPrice(float $n): string
{
    return '₱' . number_format($n, 2);
}

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * The recurring "hanging vine" motif from DESIGN.md, reused on
 * every product card as the brand's signature decorative element.
 */
function vineSvg(): string
{
    return '<svg viewBox="0 0 46 60" xmlns="http://www.w3.org/2000/svg" fill="none">'
         . '<path d="M23 2C23 2 4 10 6 26C7.6 39 23 34 23 34" stroke="#a03b56" stroke-width="2.5" stroke-linecap="round"/>'
         . '<circle cx="6" cy="26" r="4.5" fill="#ff85a1"/>'
         . '<circle cx="14" cy="12" r="3" fill="#ffb1c0"/>'
         . '<circle cx="23" cy="34" r="3.5" fill="#ff85a1"/>'
         . '</svg>';
}

/* ---------- product images (stored in the product_images table) ----------
 * Uploaded images live in the database so they survive Render restarts.
 * Queries add has_image + image_ver, then attachProductImages() swaps the
 * 'image' field for the product_image.php URL, so pages keep using
 * $p['image'] exactly as before. Products without an upload keep their URL.
 */

/** SQL snippet: pass the products table alias/column, e.g. 'p.product_id'. */
function imageColumnsSql(string $productIdColumn): string
{
    return "EXISTS (SELECT 1 FROM product_images pim WHERE pim.product_id = $productIdColumn) AS has_image,
            (SELECT EXTRACT(EPOCH FROM pim.updated_at)::bigint FROM product_images pim WHERE pim.product_id = $productIdColumn) AS image_ver";
}

/** Relative URL to product_image.php (it lives in the same folder as the public pages). */
function productImageUrl(int $product_id, $version = null): string
{
    $dir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = ($dir === 'admin') ? '../' : '';
    return $base . 'product_image.php?id=' . $product_id . ($version ? '&v=' . urlencode((string) $version) : '');
}

/** Replaces 'image' with the database-image URL on rows that have an uploaded image. */
function attachProductImages(array $rows): array
{
    foreach ($rows as &$row) {
        if (!empty($row['has_image']) && isset($row['product_id'])) {
            $row['image'] = productImageUrl((int) $row['product_id'], $row['image_ver'] ?? null);
        }
    }
    unset($row);
    return $rows;
}

/* ---------- catalogue ---------- */

function getCategories(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM categories ORDER BY category_id')->fetchAll();
}

/**
 * Products list, optionally filtered by category and/or a free-text
 * search term matched against the product name.
 */
function getProducts(PDO $pdo, ?string $categoryName = null, ?string $search = null): array
{
    $sql = 'SELECT p.*, c.categoryname, ' . imageColumnsSql('p.product_id') . '
            FROM products p
            LEFT JOIN categories c ON c.category_id = p.category_id
            WHERE p.is_active';
    $params = [];

    if ($categoryName && $categoryName !== 'All') {
        $sql .= ' AND c.categoryname = ?';
        $params[] = $categoryName;
    }

    $search = trim((string) $search);
    if ($search !== '') {
        $sql .= ' AND p.productname ILIKE ?';
        $params[] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY p.product_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return attachProductImages($stmt->fetchAll());
}

function getProduct(PDO $pdo, int $product_id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, ' . imageColumnsSql('p.product_id') . '
         FROM products p WHERE p.product_id = ? AND p.is_active'
    );
    $stmt->execute([$product_id]);
    $row = $stmt->fetch();
    return $row ? attachProductImages([$row])[0] : null;
}

/* ---------- session cart ----------
 * Cart lives in $_SESSION['cart'] as [product_id => qty, ...].
 * Prices are always re-read from the DB, never trusted from the client.
 */

function cartData(): array
{
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    return $_SESSION['cart'];
}

function cartAdd(int $product_id, int $qty = 1): void
{
    $cart = cartData();
    $cart[$product_id] = ($cart[$product_id] ?? 0) + $qty;
    if ($cart[$product_id] < 1) {
        unset($cart[$product_id]);
    }
    $_SESSION['cart'] = $cart;
}

function cartSetQty(int $product_id, int $qty): void
{
    $cart = cartData();
    if ($qty <= 0) {
        unset($cart[$product_id]);
    } else {
        $cart[$product_id] = $qty;
    }
    $_SESSION['cart'] = $cart;
}

function cartRemove(int $product_id): void
{
    $cart = cartData();
    unset($cart[$product_id]);
    $_SESSION['cart'] = $cart;
}

function cartClear(): void
{
    $_SESSION['cart'] = [];
    comboClear();
}

function cartCount(): int
{
    return array_sum(cartData()) + array_sum(array_column(comboData(), 'qty'));
}

/**
 * Resolves the session cart against live product rows.
 * Silently drops lines whose product no longer exists/active.
 */
function plainCartLines(PDO $pdo): array
{
    $cart = cartData();
    if (empty($cart)) {
        return [];
    }
    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'SELECT p.*, ' . imageColumnsSql('p.product_id') . "
         FROM products p WHERE p.product_id IN ($placeholders) AND p.is_active"
    );
    $stmt->execute($ids);
    $products = attachProductImages($stmt->fetchAll());

    $lines = [];
    foreach ($products as $p) {
        $qty = $cart[$p['product_id']] ?? 0;
        if ($qty > 0) {
            $p['qty'] = $qty;
            $p['line_total'] = $p['price'] * $qty;
            $lines[] = $p;
        }
    }
    return $lines;
}

function cartSubtotal(PDO $pdo): float
{
    $subtotal = 0.0;
    foreach (cartLines($pdo) as $line) {
        $subtotal += $line['line_total'];
    }
    return $subtotal;
}

/* ---------- orders ---------- */

function generateOrderCode(PDO $pdo): string
{
    do {
        $code = 'WTF-' . random_int(1000, 9999);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_code = ?');
        $stmt->execute([$code]);
    } while ((int) $stmt->fetchColumn() > 0);
    return $code;
}

function getOrderByCode(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_code = ?');
    $stmt->execute([$code]);
    $order = $stmt->fetch();
    if (!$order) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT od.*, p.image, ' . imageColumnsSql('od.product_id') . '
         FROM orderdetails od
         LEFT JOIN products p ON p.product_id = od.product_id
         WHERE od.order_id = ?
         ORDER BY od.order_detail_id'
    );
    $stmt->execute([$order['order_id']]);
    $order['items'] = attachProductImages($stmt->fetchAll());
    return $order;
}

/** Order history for one logged-in customer — used by orders.php. */
function getOrdersForCustomer(PDO $pdo, int $customer_id): array
{
    $stmt = $pdo->prepare(
        'SELECT o.*, cu.fullname, cu.phone
         FROM orders o
         LEFT JOIN customers cu ON cu.customer_id = o.customer_id
         WHERE o.customer_id = ?
         ORDER BY o.order_date DESC, o.order_id DESC'
    );
    $stmt->execute([$customer_id]);
    return $stmt->fetchAll();
}

/** Admin-only: every order, optionally filtered by phone/code and/or status. */
function getAllOrders(PDO $pdo, ?string $search = null, ?string $status = null, int $limit = 100): array
{
    $sql = 'SELECT o.*, cu.fullname, cu.phone
            FROM orders o
            LEFT JOIN customers cu ON cu.customer_id = o.customer_id
            WHERE 1=1';
    $params = [];

    $search = trim((string) $search);
    if ($search !== '') {
        $sql .= ' AND (o.order_code ILIKE ? OR cu.phone ILIKE ? OR cu.fullname ILIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($status && $status !== 'All') {
        $sql .= ' AND o.status = ?';
        $params[] = $status;
    }

    $sql .= ' ORDER BY o.order_date DESC, o.order_id DESC LIMIT ' . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

const ORDER_STATUSES = ['Pending', 'Preparing', 'Out for Delivery', 'Ready for Pickup', 'Completed', 'Cancelled'];

function updateOrderStatus(PDO $pdo, int $order_id, string $status): bool
{
    if (!in_array($status, ORDER_STATUSES, true)) {
        return false;
    }
    $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?');
    return $stmt->execute([$status, $order_id]);
}

function getOrderItemsFor(PDO $pdo, array $orderIDs): array
{
    if (empty($orderIDs)) {
        return [];
    }
    $ids = array_map('intval', $orderIDs);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'SELECT od.*, p.image, ' . imageColumnsSql('od.product_id') . "
         FROM orderdetails od
         LEFT JOIN products p ON p.product_id = od.product_id
         WHERE od.order_id IN ($placeholders)
         ORDER BY od.order_detail_id"
    );
    $stmt->execute($ids);
    $rows = attachProductImages($stmt->fetchAll());

    $byOrder = [];
    foreach ($rows as $row) {
        $byOrder[$row['order_id']][] = $row;
    }
    return $byOrder;
}

/* ---------- customers / admin user management ---------- */

function getCustomerById(PDO $pdo, int $customer_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE customer_id = ?');
    $stmt->execute([$customer_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Admin-only: list customers, optionally filtered by name/phone. */
function getAllCustomers(PDO $pdo, ?string $search = null, int $limit = 200): array
{
    $sql = 'SELECT customer_id, fullname, phone, address, is_admin, created_at FROM customers WHERE 1=1';
    $params = [];
    $search = trim((string) $search);
    if ($search !== '') {
        $sql .= ' AND (fullname ILIKE ? OR phone ILIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function setCustomerAdmin(PDO $pdo, int $customer_id, bool $isAdmin): bool
{
    $stmt = $pdo->prepare('UPDATE customers SET is_admin = ? WHERE customer_id = ?');
    return $stmt->execute([$isAdmin ? 'true' : 'false', $customer_id]);
}

/** Admin action: set/reset a customer's password (e.g. for legacy no-password accounts). */
function setCustomerPassword(PDO $pdo, int $customer_id, string $plainPassword): bool
{
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE customers SET password_hash = ? WHERE customer_id = ?');
    return $stmt->execute([$hash, $customer_id]);
}

/* ---------- flash messages (simple session-based) ---------- */

function flashSet(string $message): void
{
    $_SESSION['flash'] = $message;
}

function flashGet(): ?string
{
    if (!empty($_SESSION['flash'])) {
        $msg = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $msg;
    }
    return null;
}
/**
 * Verify CSRF token for AJAX requests
 */
function csrfVerifyAjax($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
/**
 * Get order statuses constant if not defined
 */
if (!defined('ORDER_STATUSES')) {
    define('ORDER_STATUSES', ['Pending', 'Preparing', 'Out for Delivery', 'Ready for Pickup', 'Completed', 'Cancelled']);
}

/**
 * Get products with category name
 */
function getProductsWithCategory($pdo, $search = null) {
    $sql = "
        SELECT p.*, c.categoryname, " . imageColumnsSql('p.product_id') . "
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.category_id 
        WHERE 1=1
    ";
    $params = [];
    
    if ($search) {
        $sql .= " AND (p.productname ILIKE ? OR c.categoryname ILIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    
    $sql .= " ORDER BY p.product_id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return attachProductImages($stmt->fetchAll());
}


/* ---------- Budget meals (customised "combo" cart lines) ----------
 * A budget meal is a product in the "BUDGET MEALS" category. Customers pick
 * rice -> drink -> optional add-ons (budget_meal.php). Each distinct choice
 * set is its own cart line, stored in $_SESSION['combos'] keyed by a hash:
 *   [key => ['product_id' => int, 'rice' => id, 'drink' => id, 'addons' => [ids], 'qty' => int]]
 * Option prices are re-read from the meal_options table, never from the client.
 */

const BUDGET_CATEGORY = 'budget meals';

function isBudgetCategoryName(?string $name): bool
{
    return strtolower(trim((string) $name)) === BUDGET_CATEGORY;
}

/** The product (plus 'categoryname') if it is an active budget meal, else null. */
function getBudgetMeal(PDO $pdo, int $product_id): ?array
{
    $product = getProduct($pdo, $product_id);
    if (!$product) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT c.categoryname FROM products p
         LEFT JOIN categories c ON c.category_id = p.category_id
         WHERE p.product_id = ?'
    );
    $stmt->execute([$product_id]);
    $name = $stmt->fetchColumn();
    if (!isBudgetCategoryName($name)) {
        return null;
    }
    $product['categoryname'] = $name;
    return $product;
}

/**
 * Options the customer can pick, grouped by step:
 * ['rice' => [option_id => row], 'drink' => [...], 'addon' => [...]].
 * Each option is a product chosen by the admin (meal_options.product_id);
 * 'name' and 'stock' come from that product. Inactive / sold-out products are left out.
 */
function getMealOptions(PDO $pdo): array
{
    $groups = ['meal' => [], 'rice' => [], 'drink' => [], 'addon' => []];
    $rows = $pdo->query(
        'SELECT mo.option_id, mo.step, mo.product_id, mo.extra_price, mo.sort_order,
                p.productname AS name, p.stock
         FROM meal_options mo
         JOIN products p ON p.product_id = mo.product_id
         WHERE mo.is_active AND p.is_active AND p.stock > 0
         ORDER BY mo.sort_order, mo.option_id'
    )->fetchAll();
    foreach ($rows as $row) {
        $groups[$row['step']][(int) $row['option_id']] = $row;
    }
    return $groups;
}

function comboData(): array
{
    if (!isset($_SESSION['combos']) || !is_array($_SESSION['combos'])) {
        $_SESSION['combos'] = [];
    }
    return $_SESSION['combos'];
}

function comboClear(): void
{
    $_SESSION['combos'] = [];
}

function comboKey(int $product_id, int $rice, int $drink, array $addons, int $meal = 0): string
{
    sort($addons);
    $raw = $product_id . '|' . $rice . '|' . $drink . '|' . implode(',', $addons);
    if ($meal > 0) {
        $raw .= '|m' . $meal; // carts made before the "Meals" step keep their old keys
    }
    return substr(md5($raw), 0, 12);
}

/** Adds one customised meal (same choices merge into one line). Returns the line key. */
function comboAdd(int $product_id, int $rice, int $drink, array $addons, int $qty, int $maxQty, int $meal = 0): string
{
    $addons = array_values(array_unique(array_map('intval', $addons)));
    sort($addons);
    $key = comboKey($product_id, $rice, $drink, $addons, $meal);
    $combos = comboData();
    $current = $combos[$key]['qty'] ?? 0;
    $combos[$key] = [
        'product_id' => $product_id,
        'meal'       => $meal,
        'rice'       => $rice,
        'drink'      => $drink,
        'addons'     => $addons,
        'qty'        => max(1, min($current + $qty, max(1, $maxQty))),
    ];
    $_SESSION['combos'] = $combos;
    return $key;
}

function comboSetQty(string $key, int $qty): void
{
    $combos = comboData();
    if (!isset($combos[$key])) {
        return;
    }
    if ($qty <= 0) {
        unset($combos[$key]);
    } else {
        $combos[$key]['qty'] = $qty;
    }
    $_SESSION['combos'] = $combos;
}

function comboRemove(string $key): void
{
    comboSetQty($key, 0);
}

/** Combo lines shaped like plain cart lines (+ line_key), so cart/checkout can treat them alike. */
function comboLines(PDO $pdo): array
{
    $combos = comboData();
    if (empty($combos)) {
        return [];
    }
    $ids = [];
    foreach ($combos as $c) {
        $ids[] = (int) ($c['meal'] ?? 0);
        $ids[] = (int) $c['rice'];
        $ids[] = (int) $c['drink'];
        foreach ($c['addons'] as $a) {
            $ids[] = (int) $a;
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $opts = [];
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT mo.option_id, mo.step, mo.product_id, mo.extra_price, p.productname AS name
             FROM meal_options mo
             JOIN products p ON p.product_id = mo.product_id
             WHERE mo.option_id IN ($placeholders) AND mo.is_active AND p.is_active"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $o) {
            $opts[(int) $o['option_id']] = $o;
        }
    }

    $lines = [];
    foreach ($combos as $key => $c) {
        $p = getProduct($pdo, (int) $c['product_id']);
        if (!$p) {
            continue;
        }
        $unit = (float) $p['price'];
        $parts = [];
        $optProducts = [];
        $broken = false;
        foreach (['meal', 'rice', 'drink'] as $slot) {
            $id = (int) ($c[$slot] ?? 0);
            if ($id === 0) {
                continue;
            }
            if (!isset($opts[$id])) {
                $broken = true;
                break;
            }
            $unit += (float) $opts[$id]['extra_price'];
            $parts[] = $opts[$id]['name'];
            $optProducts[] = ['product_id' => (int) $opts[$id]['product_id'], 'name' => $opts[$id]['name'], 'role' => $slot];
        }
        $addonNames = [];
        foreach ($c['addons'] as $a) {
            if (!isset($opts[(int) $a])) {
                $broken = true;
                break;
            }
            $unit += (float) $opts[(int) $a]['extra_price'];
            $addonNames[] = $opts[(int) $a]['name'];
            $optProducts[] = ['product_id' => (int) $opts[(int) $a]['product_id'], 'name' => $opts[(int) $a]['name'], 'role' => 'addon'];
        }
        if ($broken) {
            continue; // an option was removed/disabled since it was added
        }
        if ($addonNames) {
            $parts[] = '+ ' . implode(', ', $addonNames);
        }

        $qty = (int) $c['qty'];
        $p['price'] = $unit;
        $p['qty'] = $qty;
        $p['line_total'] = $unit * $qty;
        $p['line_key'] = (string) $key;
        $p['default_meta'] = implode(' • ', $parts);
        $p['option_products'] = $optProducts;
        $lines[] = $p;
    }
    return $lines;
}

/** Cart lines = normal items + customised budget meals. */
function cartLines(PDO $pdo): array
{
    return array_merge(plainCartLines($pdo), comboLines($pdo));
}

/** Hidden input that identifies a cart line: line_key for budget meals, product_id otherwise. */
function cartLineIdField(array $line): string
{
    if (!empty($line['line_key'])) {
        return '<input type="hidden" name="line_key" value="' . h($line['line_key']) . '">';
    }
    return '<input type="hidden" name="product_id" value="' . (int) $line['product_id'] . '">';
}
