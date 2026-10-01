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
    return '$' . number_format($n, 2);
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
    $sql = 'SELECT p.*, c.categoryname
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
    return $stmt->fetchAll();
}

function getProduct(PDO $pdo, int $product_id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE product_id = ? AND is_active');
    $stmt->execute([$product_id]);
    $row = $stmt->fetch();
    return $row ?: null;
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
}

function cartCount(): int
{
    return array_sum(cartData());
}

/**
 * Resolves the session cart against live product rows.
 * Silently drops lines whose product no longer exists/active.
 */
function cartLines(PDO $pdo): array
{
    $cart = cartData();
    if (empty($cart)) {
        return [];
    }
    $ids = array_map('intval', array_keys($cart));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id IN ($placeholders) AND is_active");
    $stmt->execute($ids);
    $products = $stmt->fetchAll();

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
        'SELECT od.*, p.image
         FROM orderdetails od
         LEFT JOIN products p ON p.product_id = od.product_id
         WHERE od.order_id = ?
         ORDER BY od.order_detail_id'
    );
    $stmt->execute([$order['order_id']]);
    $order['items'] = $stmt->fetchAll();
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
        "SELECT od.*, p.image
         FROM orderdetails od
         LEFT JOIN products p ON p.product_id = od.product_id
         WHERE od.order_id IN ($placeholders)
         ORDER BY od.order_detail_id"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

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
        SELECT p.*, c.categoryname 
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
    return $stmt->fetchAll();
}
