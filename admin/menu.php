<?php
require_once __DIR__ . '/../includes/auth.php';
configureSession();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin();

/**
 * Validates an uploaded product image and returns ['mime' => ..., 'tmp' => ...],
 * or null when no file was chosen. Nothing is written to disk: the image is
 * stored in the database (product_images table) so it survives Render restarts.
 */
function readProductImageUpload(): ?array
{
    if (!isset($_FILES['image_upload']) || $_FILES['image_upload']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $upload = $_FILES['image_upload'];
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (file may be too large). Please try a smaller image.');
    }

    if ($upload['size'] > 2 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Choose a valid image that is no larger than 2 MB.');
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    if (!in_array($mimeType, $allowed, true)) {
        throw new RuntimeException('Upload a JPG, PNG, GIF, or WebP image.');
    }

    return ['mime' => $mimeType, 'tmp' => $upload['tmp_name']];
}

/** Inserts or replaces the image stored in the database for a product. */
function saveProductImage(PDO $pdo, int $productId, array $img): void
{
    $fp = fopen($img['tmp'], 'rb');
    if ($fp === false) {
        throw new RuntimeException('Could not read the uploaded image.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO product_images (product_id, mime_type, data, updated_at)
         VALUES (:id, :mime, :data, CURRENT_TIMESTAMP)
         ON CONFLICT (product_id) DO UPDATE
           SET mime_type = EXCLUDED.mime_type,
               data = EXCLUDED.data,
               updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
    $stmt->bindValue(':mime', $img['mime']);
    $stmt->bindParam(':data', $fp, PDO::PARAM_LOB);
    $stmt->execute();
    fclose($fp);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_product') {
        $productname = trim($_POST['productname'] ?? '');
        $category_id = (int) ($_POST['category_id'] ?? 0);
        $price = (float) ($_POST['price'] ?? 0);
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        $image = trim($_POST['image'] ?? '');
        $default_meta = trim($_POST['default_meta'] ?? '');
        $is_active = isset($_POST['is_active']) ? 'true' : 'false';

        if ($productname && $category_id > 0 && $price > 0) {
            try {
                $img = readProductImageUpload();
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO products (productname, category_id, price, image, stock, default_meta, is_active) 
                     VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING product_id'
                );
                $stmt->execute([$productname, $category_id, $price, $image, $stock, $default_meta, $is_active]);
                $newId = (int) $stmt->fetchColumn();
                if ($img) {
                    saveProductImage($pdo, $newId, $img);
                }
                $pdo->commit();
                flashSet('Product "' . $productname . '" added successfully!');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flashSet('Error adding product: ' . $e->getMessage());
            }
        } else {
            flashSet('Please fill in all required fields.');
        }
        header('Location: menu.php');
        exit;
    }

    if ($action === 'edit_product') {
        $product_id = (int) ($_POST['product_id'] ?? 0);
        $productname = trim($_POST['productname'] ?? '');
        $category_id = (int) ($_POST['category_id'] ?? 0);
        $price = (float) ($_POST['price'] ?? 0);
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        $image = trim($_POST['image'] ?? '');
        $default_meta = trim($_POST['default_meta'] ?? '');
        $is_active = isset($_POST['is_active']) ? 'true' : 'false';

        if ($product_id > 0 && $productname && $category_id > 0 && $price > 0) {
            try {
                $img = readProductImageUpload();
                $removeImage = isset($_POST['remove_uploaded_image']);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'UPDATE products 
                     SET productname = ?, category_id = ?, price = ?, image = ?, stock = ?, default_meta = ?, is_active = ?
                     WHERE product_id = ?'
                );
                $stmt->execute([$productname, $category_id, $price, $image, $stock, $default_meta, $is_active, $product_id]);
                if ($img) {
                    saveProductImage($pdo, $product_id, $img);
                } elseif ($removeImage) {
                    $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$product_id]);
                }
                $pdo->commit();
                flashSet('Product updated successfully!');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flashSet('Error updating product: ' . $e->getMessage());
            }
        } else {
            flashSet('Please fill in all required fields.');
        }
        header('Location: menu.php');
        exit;
    }

    if ($action === 'delete_product') {
        $product_id = (int) ($_POST['product_id'] ?? 0);
        if ($product_id > 0) {
            try {
                $stmt = $pdo->prepare('DELETE FROM products WHERE product_id = ?');
                $stmt->execute([$product_id]);
                flashSet('Product deleted successfully.');
            } catch (Exception $e) {
                flashSet('Error deleting product: ' . $e->getMessage());
            }
        }
        header('Location: menu.php');
        exit;
    }

    if ($action === 'add_category') {
        $categoryname = trim($_POST['categoryname'] ?? '');
        if ($categoryname) {
            try {
                $stmt = $pdo->prepare('INSERT INTO categories (categoryname) VALUES (?)');
                $stmt->execute([$categoryname]);
                flashSet('Category "' . $categoryname . '" added successfully!');
            } catch (Exception $e) {
                flashSet('Error adding category: ' . $e->getMessage());
            }
        } else {
            flashSet('Category name is required.');
        }
        header('Location: menu.php');
        exit;
    }

    if ($action === 'delete_category') {
        $category_id = (int) ($_POST['category_id'] ?? 0);
        if ($category_id > 0) {
            try {
                // Check if category has products
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
                $stmt->execute([$category_id]);
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    flashSet('Cannot delete category with existing products. Move or delete products first.');
                } else {
                    $stmt = $pdo->prepare('DELETE FROM categories WHERE category_id = ?');
                    $stmt->execute([$category_id]);
                    flashSet('Category deleted successfully.');
                }
            } catch (Exception $e) {
                flashSet('Error deleting category: ' . $e->getMessage());
            }
        }
        header('Location: menu.php');
        exit;
    }
}

// Get data
$categories = getCategories($pdo);
$products = getProducts($pdo, null, null);
$editProduct = null;
$editProductID = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

if ($editProductID > 0) {
    $stmt = $pdo->prepare(
        'SELECT p.*,
                EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.product_id) AS has_image
         FROM products p WHERE p.product_id = ?'
    );
    $stmt->execute([$editProductID]);
    $editProduct = $stmt->fetch();
}

$pageTitle = 'Manage Menu — WTF Lounge Admin';
$activeAdminNav = 'menu';
require __DIR__ . '/includes/admin_header.php';
?>

<h1 class="headline-lg">Manage Menu</h1>
<p class="body-md">Add, edit, or remove products and categories.</p>

<!-- Add/Edit Product Form -->
<div class="admin-welcome" style="flex-direction:column; align-items:stretch;">
    <h2 style="font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:18px; margin:0 0 12px;">
        <?= $editProduct ? 'Edit Product' : 'Add New Product' ?>
    </h2>
    <form method="post" action="menu.php" enctype="multipart/form-data" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <?= csrfField() ?>
        <?php if ($editProduct): ?>
            <input type="hidden" name="action" value="edit_product">
            <input type="hidden" name="product_id" value="<?= (int) $editProduct['product_id'] ?>">
        <?php else: ?>
            <input type="hidden" name="action" value="add_product">
        <?php endif; ?>
        
        <div class="field" style="margin:0;">
            <label>Product Name</label>
            <input type="text" name="productname" required 
                   value="<?= $editProduct ? h($editProduct['productname']) : '' ?>">
        </div>
        
        <div class="field" style="margin:0;">
            <label>Category</label>
            <select name="category_id" required>
                <option value="">Select Category</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['category_id'] ?>" 
                        <?= $editProduct && $editProduct['category_id'] == $cat['category_id'] ? 'selected' : '' ?>>
                        <?= h($cat['categoryname']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="field" style="margin:0;">
            <label>Price (₱)</label>
            <input type="number" step="0.01" name="price" required 
                   value="<?= $editProduct ? $editProduct['price'] : '' ?>">
        </div>
        
        <div class="field" style="margin:0;">
            <label>Stock on Hand</label>
            <input type="number" step="1" min="0" name="stock" required
                   value="<?= $editProduct ? (int) $editProduct['stock'] : 0 ?>">
        </div>

        <div class="field" style="margin:0;">
            <label>Image URL</label>
            <input type="url" name="image" 
                   value="<?= $editProduct ? h($editProduct['image']) : '' ?>"
                   placeholder="https://images.unsplash.com/...">
        </div>

        <div class="field" style="margin:0;">
            <label>Or upload image from your computer</label>
            <input type="file" name="image_upload" accept="image/jpeg,image/png,image/gif,image/webp">
            <small>JPG, PNG, GIF, or WebP; max 2 MB. Saved in the database. An uploaded image takes priority over the URL.</small>
            <?php if ($editProduct && !empty($editProduct['has_image'])): ?>
                <label style="margin:6px 0 0; display:flex; align-items:center; gap:6px; cursor:pointer; font-size:13px;">
                    <input type="checkbox" name="remove_uploaded_image" value="1">
                    Remove the uploaded image (use the URL instead)
                </label>
            <?php endif; ?>
        </div>
        
        <div class="field" style="margin:0; grid-column: span 2;">
            <label>Default Meta (e.g., Iced, Oat Milk)</label>
            <input type="text" name="default_meta" 
                   value="<?= $editProduct ? h($editProduct['default_meta']) : '' ?>">
        </div>
        
        <div class="field" style="margin:0; grid-column: span 2; display:flex; align-items:center; gap:10px;">
            <label style="margin:0; display:flex; align-items:center; gap:6px; cursor:pointer;">
                <input type="checkbox" name="is_active" value="1" 
                       <?= $editProduct && $editProduct['is_active'] ? 'checked' : (!$editProduct ? 'checked' : '') ?>>
                Active (visible on menu)
            </label>
        </div>
        
        <div style="grid-column: span 2; display:flex; gap:10px;">
            <button type="submit" class="btn btn--primary">
                <?= $editProduct ? 'Update Product' : 'Add Product' ?>
            </button>
            <?php if ($editProduct): ?>
                <a href="menu.php" class="btn btn--secondary">Cancel Edit</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Add Category -->
<div class="admin-welcome" style="flex-direction:column; align-items:stretch; margin-top:16px;">
    <h2 style="font-family:'Plus Jakarta Sans',sans-serif; font-weight:700; font-size:18px; margin:0 0 12px;">Add Category</h2>
    <form method="post" action="menu.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_category">
        <input type="text" name="categoryname" placeholder="New category name" required style="flex:1; min-width:200px; padding:10px 16px; border:2px solid var(--outline-variant); border-radius:var(--radius-full); background:var(--surface-container-lowest);">
        <button type="submit" class="btn btn--secondary">Add Category</button>
    </form>
</div>

<!-- Products Table -->
<h2 class="panel__title" style="margin-top:24px; margin-bottom:16px;">All Products</h2>
<div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Product</th>
                <th>Category</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Meta</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= (int) $p['product_id'] ?></td>
                    <td>
                        <strong><?= h($p['productname']) ?></strong>
                        <?php if ($p['image']): ?>
                            <br><small><a href="<?= h($p['image']) ?>" target="_blank">View image</a></small>
                        <?php endif; ?>
                    </td>
                    <td><?= h($p['categoryname'] ?? 'Uncategorized') ?></td>
                    <td><strong><?= formatPrice($p['price']) ?></strong></td>
                    <td>
                        <?php $st = stockStatus((int) $p['stock']); ?>
                        <span class="status-badge <?= $st === 'out' ? 'status-badge--cancelled' : ($st === 'low' ? 'status-badge--active' : 'status-badge--completed') ?>">
                            <?= (int) $p['stock'] ?><?= $st === 'out' ? ' — Out' : ($st === 'low' ? ' — Low' : '') ?>
                        </span>
                    </td>
                    <td><small><?= h($p['default_meta']) ?></small></td>
                    <td>
                        <span class="status-badge <?= $p['is_active'] ? 'status-badge--completed' : 'status-badge--cancelled' ?>">
                            <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <div class="admin-actions">
                            <a href="menu.php?edit=<?= (int) $p['product_id'] ?>" class="btn btn--secondary" style="padding:4px 12px; font-size:11px;">Edit</a>
                            <form method="post" action="menu.php" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
                                <button type="submit" onclick="return confirm('Delete this product?')" style="padding:4px 12px; border:1px solid var(--error); border-radius:var(--radius-sm); background:var(--error-container); color:var(--error); cursor:pointer; font-size:11px; font-weight:600;">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($products)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;">No products found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Categories Table -->
<h2 class="panel__title" style="margin-top:24px; margin-bottom:16px;">Categories</h2>
<div class="table-scroll">
    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Category Name</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><?= (int) $cat['category_id'] ?></td>
                    <td><strong><?= h($cat['categoryname']) ?></strong></td>
                    <td>
                        <form method="post" action="menu.php" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="category_id" value="<?= (int) $cat['category_id'] ?>">
                            <button type="submit" onclick="return confirm('Delete this category?')" style="padding:4px 12px; border:1px solid var(--error); border-radius:var(--radius-sm); background:var(--error-container); color:var(--error); cursor:pointer; font-size:11px; font-weight:600;">
                                Delete
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($categories)): ?>
                <tr><td colspan="3" style="text-align:center;padding:40px;">No categories found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>