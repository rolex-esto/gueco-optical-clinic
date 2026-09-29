<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Inventory Management';
$breadcrumb = ['Admin', 'Inventory'];
$db = getDB();
ensureProductVariantSchema($db);
$msg = ''; $msgType = 'success';
$reopenData = null;

// Add/Edit product
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $productCode = sanitize(trim($_POST['product_code'] ?? ''));
        $name        = sanitize(trim($_POST['name'] ?? ''));
        $baseModel   = sanitize(trim($_POST['base_model'] ?? '')) ?: null;
        $variantName = sanitize(trim($_POST['variant_name'] ?? '')) ?: null;
        $catId       = (int)($_POST['category_id'] ?? 0);
        $tier        = sanitize(trim($_POST['tier'] ?? 'budget'));
        if (!in_array($tier, ['budget', 'mid', 'high'])) $tier = 'budget';
        $suppId      = (int)($_POST['supplier_id'] ?? 0) ?: null;
        $price       = (float)($_POST['price'] ?? 0);
        $stock       = (int)($_POST['stock_quantity'] ?? 0);
        $alert       = (int)($_POST['low_stock_alert'] ?? 5);
        $desc        = sanitize(trim($_POST['description'] ?? ''));
        $stat        = $_POST['status'] ?? 'active';

        if (!$productCode || !$name || !$catId || $price < 0) { $msg = 'Product Code, Name, category, and a valid price are required.'; $msgType = 'danger'; }
        else {
            try {
                // Secure Image Upload Logic
                $imagePath = null;
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    $fileTmp = $_FILES['image']['tmp_name'];
                    $fileExt = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    $imgInfo = @getimagesize($fileTmp);
                    $fileMime = $imgInfo['mime'] ?? '';

                    if (in_array($fileExt, $allowedExts) && in_array($fileMime, $allowedMimes) && $_FILES['image']['size'] <= 5 * 1024 * 1024) {
                        $imagePath = 'prod_' . bin2hex(random_bytes(10)) . '.' . $fileExt;
                        move_uploaded_file($fileTmp, __DIR__ . '/../assets/images/products/' . $imagePath);
                    } else {
                        $msg = 'Invalid image file. Only JPG, PNG, WEBP, or GIF up to 5MB are allowed.';
                        $msgType = 'danger';
                    }
                }

                if ($action === 'add') {
                    $db->prepare("INSERT INTO products (product_code,name,base_model,variant_name,category_id,tier,supplier_id,price,stock_quantity,low_stock_alert,description,status,image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$productCode,$name,$baseModel,$variantName,$catId,$tier,$suppId,$price,$stock,$alert,$desc,$stat,$imagePath]);
                    // Log inventory
                    $newId = $db->lastInsertId();
                    if ($stock > 0) {
                        $db->prepare("INSERT INTO inventory_logs (product_id,type,quantity,previous_stock,new_stock,reason,user_id) VALUES (?,?,?,?,?,?,?)")
                           ->execute([$newId,'stock_in',$stock,0,$stock,'Initial stock',$_SESSION['user_id']]);
                    }
                    $msg = "Product \"$name\" added.";
                    logActivity("Added new product \"$name\" ($productCode, Tier: " . tierLabel($tier) . ", Price: " . formatCurrency($price) . ", Stock: $stock)", "Inventory", $_SESSION['user_id'], 'staff');
                } else {
                    $id = (int)$_POST['id'];
                    $stmt = $db->prepare("SELECT product_code, name, base_model, variant_name, category_id, tier, supplier_id, price, low_stock_alert, description, status FROM products WHERE id=?");
                    $stmt->execute([$id]);
                    $old = $stmt->fetch();
                    
                    if ($old && $old['product_code'] === $productCode && $old['name'] === $name && ($old['base_model'] ?? null) === $baseModel && ($old['variant_name'] ?? null) === $variantName && (int)$old['category_id'] === $catId && ($old['tier'] ?? 'budget') === $tier && (int)$old['supplier_id'] === $suppId && (float)$old['price'] === $price && (int)$old['low_stock_alert'] === $alert && $old['description'] === $desc && $old['status'] === $stat && !$imagePath) {
                        $msg = "No changes were made. Product is already up to date!";
                        $msgType = "info";
                        $reopenData = ['id' => $id, 'name' => $name, 'base_model' => $baseModel, 'variant_name' => $variantName, 'category_id' => $catId, 'tier' => $tier, 'supplier_id' => $suppId, 'price' => $price, 'low_stock_alert' => $alert, 'description' => $desc, 'status' => $stat];
                    } else {
                        if ($imagePath) {
                            $db->prepare("UPDATE products SET product_code=?,name=?,base_model=?,variant_name=?,category_id=?,tier=?,supplier_id=?,price=?,low_stock_alert=?,description=?,status=?,image=? WHERE id=?")
                               ->execute([$productCode,$name,$baseModel,$variantName,$catId,$tier,$suppId,$price,$alert,$desc,$stat,$imagePath,$id]);
                        } else {
                            $db->prepare("UPDATE products SET product_code=?,name=?,base_model=?,variant_name=?,category_id=?,tier=?,supplier_id=?,price=?,low_stock_alert=?,description=?,status=? WHERE id=?")
                               ->execute([$productCode,$name,$baseModel,$variantName,$catId,$tier,$suppId,$price,$alert,$desc,$stat,$id]);
                        }
                        $msg = "Product updated successfully!";
                        logActivity("Updated product \"$name\" ($productCode, Tier: " . tierLabel($tier) . ", Price: " . formatCurrency($price) . ", Status: " . strtoupper($stat) . ")", "Inventory", $_SESSION['user_id'], 'staff');
                    }
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $msg = "Error: A product with this name or product code already exists.";
                    $msgType = "danger";
                } else {
                    $msg = "An error occurred: " . $e->getMessage();
                    $msgType = "danger";
                }
            }
        }
    } elseif ($action === 'stock_in' || $action === 'stock_out') {
        $id  = (int)$_POST['id'];
        $qty = (int)$_POST['qty'];
        $reason = sanitize(trim($_POST['reason'] ?? ''));
        if ($qty <= 0) { $msg = 'Quantity must be greater than 0.'; $msgType = 'danger'; }
        else {
            $prod = $db->prepare("SELECT name, stock_quantity FROM products WHERE id=?"); $prod->execute([$id]); $prod = $prod->fetch();
            $prevStock = (int)$prod['stock_quantity'];
            $prodName = $prod['name'] ?? ('Product #' . $id);
            $newStock = $action === 'stock_in' ? $prevStock + $qty : max(0, $prevStock - $qty);
            $db->prepare("UPDATE products SET stock_quantity=? WHERE id=?")->execute([$newStock, $id]);
            $db->prepare("INSERT INTO inventory_logs (product_id,type,quantity,previous_stock,new_stock,reason,user_id) VALUES (?,?,?,?,?,?,?)")
               ->execute([$id,$action,$qty,$prevStock,$newStock,$reason,$_SESSION['user_id']]);
            $msg = "Stock " . ($action==='stock_in'?'added':'deducted') . " successfully. New stock: $newStock";
            logActivity(($action === 'stock_in' ? "Stock In: +$qty" : "Stock Out: -$qty") . " for \"$prodName\" (Reason: " . ($reason ?: 'None') . ", Stock: $prevStock → $newStock)", "Inventory", $_SESSION['user_id'], 'staff');
        }
    }
}

// Filters
$search = sanitize($_GET['search'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$tierFilter = sanitize($_GET['tier'] ?? '');
$stockFilter = $_GET['stock'] ?? '';
$highlightId = (int)($_GET['highlight'] ?? 0);
$where = ['1=1']; $params = [];
if ($search) { $where[] = '(p.name LIKE ? OR p.product_code LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $where[] = 'p.category_id=?'; $params[] = $catFilter; }
if (in_array($tierFilter, ['budget', 'mid', 'high'])) { $where[] = 'p.tier=?'; $params[] = $tierFilter; }
if ($stockFilter === 'low') { $where[] = 'p.stock_quantity <= p.low_stock_alert'; }
if ($stockFilter === 'out') { $where[] = 'p.stock_quantity = 0'; }
$whereStr = implode(' AND ', $where);

$products = $db->prepare("
    SELECT p.*, c.name as cat_name, s.company_name as supplier_name
    FROM products p
    JOIN categories c ON c.id=p.category_id
    LEFT JOIN suppliers s ON s.id=p.supplier_id
    WHERE $whereStr ORDER BY c.name, COALESCE(p.base_model, p.name) ASC, p.name ASC
");
$products->execute($params); $products = $products->fetchAll();

// Group products by base_model if identical base model exists
$groupedProducts = [];
foreach ($products as $p) {
    $groupKey = !empty($p['base_model']) ? ('m_' . $p['category_id'] . '_' . strtolower(trim($p['base_model']))) : ('p_' . $p['id']);
    if (!isset($groupedProducts[$groupKey])) {
        $groupedProducts[$groupKey] = [
            'base_model'   => $p['base_model'] ?: $p['name'],
            'has_variants' => false,
            'primary'      => $p,
            'variants'     => []
        ];
    }
    $groupedProducts[$groupKey]['variants'][] = $p;
    if (count($groupedProducts[$groupKey]['variants']) > 1) {
        $groupedProducts[$groupKey]['has_variants'] = true;
    }
}

$categories = $db->query("SELECT * FROM categories WHERE status='active' ORDER BY name")->fetchAll();
$suppliers  = $db->query("SELECT * FROM suppliers WHERE status='active' ORDER BY company_name")->fetchAll();

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/inventory.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        title: '<?= $msgType === "success" ? "Success!" : ($msgType === "info" ? "Notice" : "Error") ?>',
        text: '<?= addslashes($msg) ?>',
        icon: '<?= $msgType === "success" ? "success" : ($msgType === "info" ? "info" : "error") ?>',
        confirmButtonColor: 'var(--clr-primary)',
        background: 'var(--bg-card)',
        color: 'var(--text-primary)',
        timer: 3000,
        timerProgressBar: true
    });
});
</script>
<?php endif; ?>

<div class="section-header">
  <h5><i class="fas fa-boxes me-2 inv-b6b6a8"></i>Inventory — <?= count($groupedProducts) ?> <?= count($groupedProducts) !== count($products) ? 'Models (' . count($products) . ' SKUs / Variants)' : 'Products' ?></h5>
  <div class="inv-96b971">
    <button id="viewToggleBtn" class="btn btn-view-toggle btn-sm" onclick="toggleView()"><i class="fas fa-th-large"></i> Grid View</button>
    <a href="inventory_logs.php" class="btn btn-info btn-sm text-white"><i class="fas fa-history"></i> Stock History</a>
    <a href="?stock=low" class="btn btn-warning btn-sm"><i class="fas fa-exclamation-triangle"></i> Low Stock</a>
    <a href="?stock=out" class="btn btn-danger btn-sm"><i class="fas fa-times-circle"></i> Out of Stock</a>
    <button class="btn btn-primary btn-sm" onclick="openModal('addProductModal')"><i class="fas fa-plus"></i> Add Product</button>
  </div>
</div>

<!-- Filter bar -->
<div class="card inv-684111">
  <div class="card-body inv-140fb6">
    <form method="GET" class="inv-7cdce4">
      <div class="inv-ce6b9e"><label class="form-label inv-7c8fee">Search</label>
        <input type="text" name="search" class="form-control" placeholder="Product name or SKU..." value="<?= htmlspecialchars($search) ?>"></div>
      <div class="inv-398dad"><label class="form-label inv-7c8fee">Category</label>
        <select name="cat" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $catFilter==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="inv-398dad"><label class="form-label inv-7c8fee">Product Tier</label>
        <select name="tier" class="form-select">
          <option value="">All Tiers</option>
          <option value="budget" <?= $tierFilter==='budget'?'selected':'' ?>>⚪ Budget Product</option>
          <option value="mid" <?= $tierFilter==='mid'?'selected':'' ?>>🔵 Mid Product</option>
          <option value="high" <?= $tierFilter==='high'?'selected':'' ?>>🟣 High Product</option>
        </select></div>
      <div><button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i> Search</button></div>
      <div><a href="inventory.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a></div>
    </form>
  </div>
</div>

<div class="table-wrapper" id="tableView">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>CODE</th><th>Product</th><th>Tier</th><th>Category</th><th>Supplier</th><th>Price</th><th>Stock</th><th>Alert</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($groupedProducts)): ?>
        <tr><td colspan="11"><div class="empty-state"><div class="empty-icon"><i class="fas fa-boxes"></i></div><h6>No products found</h6></div></td></tr>
        <?php else: ?>
        <?php $rowCounter = 0; ?>
        <?php foreach ($groupedProducts as $item): ?>
        <?php 
          $rowCounter++;
          $p = $item['primary'];
          $isLow = $p['stock_quantity'] <= $p['low_stock_alert']; 
          $isOut = $p['stock_quantity'] == 0; 
          $rowClass = $isOut ? 'table-row-out' : ($isLow ? 'table-row-low' : '');
          $totalModelStock = array_sum(array_column($item['variants'], 'stock_quantity'));
          $isHighlighted = ($highlightId > 0 && ($highlightId === (int)$p['id'] || in_array($highlightId, array_column($item['variants'], 'id'))));
          if ($isHighlighted) {
              $rowClass .= ' appt-highlight-pulse';
          }
        ?>
        <tr class="<?= trim($rowClass) ?>" id="row-prod-<?= $p['id'] ?>" data-variant-ids="<?= implode(',', array_column($item['variants'], 'id')) ?>">
          <td class="inv-67fd48"><?= $rowCounter ?></td>
          <td class="cell-code" style="font-family:monospace; color:var(--text-primary); font-size:0.85rem; font-weight:600;"><?= sanitize($p['product_code'] ?: '—') ?></td>
          <td>
            <div style="display:flex; align-items:flex-start; gap:10px;">
              <?php if($p['image']): ?>
                <img src="<?= BASE_URL ?>assets/images/products/<?= $p['image'] ?>" alt="Product" style="width:40px; height:40px; object-fit:cover; border-radius:5px; margin-top:2px;">
              <?php else: ?>
                <div style="width:40px; height:40px; background:var(--bg-card); border-radius:5px; display:flex; align-items:center; justify-content:center; color:var(--text-muted); margin-top:2px;"><i class="fas fa-glasses"></i></div>
              <?php endif; ?>
              <div>
                <div class="inv-bac3c9" style="font-weight:700; font-size:.92rem; display:flex; align-items:center; gap:6px;">
                  <?= sanitize($item['base_model']) ?>
                  <?php if ($item['has_variants']): ?>
                    <span class="badge bg-primary" style="font-size:.65rem; padding:3px 7px;"><?= count($item['variants']) ?> Variants</span>
                  <?php endif; ?>
                </div>
                <div class="inv-26a4f5"><?= sanitize($p['description'] ?: ($item['has_variants'] ? ($item['base_model'] . ' Series') : '—')) ?></div>
                
                <?php if ($item['has_variants']): ?>
                <!-- Variant Selector Dropdown -->
                <div style="margin-top:6px; display:inline-flex; align-items:center; gap:6px; background:var(--bg-hover); padding:3px 8px; border-radius:6px; border:1px solid var(--border-color);">
                  <label style="font-size:0.72rem; font-weight:700; color:var(--clr-primary); margin:0; white-space:nowrap;">
                    <i class="fas fa-palette me-1"></i>Color / Variant:
                  </label>
                  <select class="form-select form-select-sm variant-picker-select" 
                          style="font-size:0.78rem; padding:2px 24px 2px 8px; height:auto; width:auto; font-weight:600; cursor:pointer;"
                          onchange="onVariantChange(this)">
                    <?php foreach ($item['variants'] as $v): ?>
                      <option value="<?= $v['id'] ?>"
                              <?= ($highlightId === (int)$v['id']) ? 'selected' : '' ?>
                              data-code="<?= htmlspecialchars($v['product_code'] ?: '—') ?>"
                              data-name="<?= htmlspecialchars($v['name']) ?>"
                              data-variant="<?= htmlspecialchars($v['variant_name'] ?: $v['name']) ?>"
                              data-price="<?= number_format($v['price'], 2) ?>"
                              data-price-raw="<?= $v['price'] ?>"
                              data-stock="<?= (int)$v['stock_quantity'] ?>"
                              data-low-alert="<?= (int)$v['low_stock_alert'] ?>"
                              data-obj='<?= htmlspecialchars(json_encode($v), ENT_QUOTES, "UTF-8") ?>'>
                        <?= htmlspecialchars($v['variant_name'] ?: $v['name']) ?> (Stock: <?= (int)$v['stock_quantity'] ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td><?= tierBadge($p['tier'] ?? 'budget') ?></td>
          <td><span class="badge bg-secondary"><?= sanitize($p['cat_name']) ?></span></td>
          <td class="inv-67fd48"><?= sanitize($p['supplier_name'] ?? '—') ?></td>
          <td class="inv-c0f652 cell-price" style="font-weight:700; color:var(--clr-success);"><?= formatCurrency($p['price']) ?></td>
          <td class="cell-stock">
            <span class="stock-qty-val" style="font-weight:700;font-size:.95rem;color:<?= $isOut?'#DC2626':($isLow?'#EF4444':'var(--text-primary)') ?>">
              <?= $p['stock_quantity'] ?>
            </span>
            <span class="stock-badge-val">
              <?php if ($isOut): ?><span class="badge badge-out-alert inv-c1ae5c">OUT</span>
              <?php elseif ($isLow): ?><span class="badge badge-low-alert inv-c1ae5c">LOW</span><?php endif; ?>
            </span>
            <?php if ($item['has_variants']): ?>
              <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;" title="Combined stock for all variants of this model">
                Model Total: <strong class="model-total-val"><?= $totalModelStock ?></strong>
              </div>
            <?php endif; ?>
          </td>
          <td class="inv-00a7ed cell-alert"><?= $p['low_stock_alert'] ?></td>
          <td class="cell-status"><?= statusBadge($p['status']) ?></td>
          <td>
            <div class="inv-152c49 cell-actions">
              <button class="btn btn-sm btn-success btn-icon btn-action-in" title="Stock In" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', 'stock_in')"><i class="fas fa-plus"></i></button>
              <button class="btn btn-sm btn-warning btn-icon btn-action-out" title="Stock Out" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', 'stock_out')"><i class="fas fa-minus"></i></button>
              <button class="btn btn-sm btn-outline-primary btn-icon btn-action-edit" title="Edit" onclick='openEditProduct(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8") ?>)'><i class="fas fa-edit"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="gridView" style="display:none; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:20px; margin-bottom:20px;">
  <?php foreach ($groupedProducts as $item): ?>
    <?php 
    $p = $item['primary'];
    $isLow = $p['stock_quantity'] <= $p['low_stock_alert'];
    $isOut = $p['stock_quantity'] == 0; 
    $cardClass = $isOut ? 'product-card card-out-stock' : ($isLow ? 'product-card card-low-stock' : 'product-card');
    $cardBorder = $isOut 
      ? 'border:2px solid #DC2626; box-shadow:0 0 14px rgba(220,38,38,0.28);' 
      : ($isLow 
        ? 'border:2px solid #EF4444; box-shadow:0 0 14px rgba(239,68,68,0.25);' 
        : 'border:1px solid var(--border-color);');
    ?>
    <div class="<?= $cardClass ?>" style="background:var(--bg-card); <?= $cardBorder ?> border-radius:12px; padding:15px; position:relative; display:flex; flex-direction:column;">
      <div style="text-align:center; margin-bottom:12px; flex-grow:0;">
        <?php if($p['image']): ?>
          <img src="<?= BASE_URL ?>assets/images/products/<?= $p['image'] ?>" alt="Product" style="width:100%; height:160px; object-fit:cover; border-radius:8px;">
        <?php else: ?>
          <div style="width:100%; height:160px; background:var(--bg-hover); border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:2rem;">
            <i class="fas fa-glasses"></i>
          </div>
        <?php endif; ?>
      </div>
      
      <div style="flex-grow:1;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
          <span class="grid-code" style="font-family:monospace; color:var(--text-primary); font-size:0.85rem; font-weight:600;"><?= sanitize($p['product_code'] ?: '—') ?></span>
          <?= tierBadge($p['tier'] ?? 'budget') ?>
        </div>
        <div style="font-weight:700; font-size:1.05rem; line-height:1.2; margin-bottom:5px; color:var(--text-primary);"><?= sanitize($item['base_model']) ?></div>
        <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:10px;"><?= sanitize($p['cat_name']) ?></div>

        <?php if ($item['has_variants']): ?>
        <div style="margin-bottom:12px;">
          <label style="font-size:0.72rem; font-weight:700; color:var(--clr-primary); margin-bottom:2px; display:block;">
            <i class="fas fa-palette me-1"></i>Color / Variant:
          </label>
          <select class="form-select form-select-sm grid-variant-picker" 
                  style="font-size:0.78rem; font-weight:600;"
                  onchange="onGridVariantChange(this)">
            <?php foreach ($item['variants'] as $v): ?>
              <option value="<?= $v['id'] ?>"
                      data-code="<?= htmlspecialchars($v['product_code'] ?: '—') ?>"
                      data-name="<?= htmlspecialchars($v['name']) ?>"
                      data-variant="<?= htmlspecialchars($v['variant_name'] ?: $v['name']) ?>"
                      data-price="<?= number_format($v['price'], 2) ?>"
                      data-stock="<?= (int)$v['stock_quantity'] ?>"
                      data-low-alert="<?= (int)$v['low_stock_alert'] ?>"
                      data-obj='<?= htmlspecialchars(json_encode($v), ENT_QUOTES, "UTF-8") ?>'>
                <?= htmlspecialchars($v['variant_name'] ?: $v['name']) ?> (<?= (int)$v['stock_quantity'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>
      
      <div style="display:flex; justify-content:space-between; align-items:end; margin-bottom:15px; flex-grow:0;">
        <div class="grid-price" style="font-weight:800; font-size:1.15rem; color:var(--clr-success);">₱<?= number_format($p['price'], 2) ?></div>
        
        <div class="grid-stock-badge" style="text-align:right;">
          <?php if($isOut): ?>
            <span class="badge badge-out-alert"><i class="fas fa-times-circle me-1"></i>Out of Stock</span>
          <?php elseif($isLow): ?>
            <span class="badge badge-low-alert"><i class="fas fa-exclamation-triangle me-1"></i>Low: <?= $p['stock_quantity'] ?></span>
          <?php else: ?>
            <span class="badge bg-success" style="font-weight:600; font-size:0.8rem; padding:4px 10px; border-radius:20px;"><?= $p['stock_quantity'] ?> in stock</span>
          <?php endif; ?>
        </div>
      </div>
      
      <div style="display:flex; gap:5px; flex-grow:0;">
        <button class="btn btn-sm btn-outline-success grid-btn-in" style="flex:1;" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes(sanitize($p['name'])) ?>', 'stock_in')" title="Stock In"><i class="fas fa-plus"></i></button>
        <button class="btn btn-sm btn-outline-warning grid-btn-out" style="flex:1;" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes(sanitize($p['name'])) ?>', 'stock_out')" title="Stock Out"><i class="fas fa-minus"></i></button>
        <button class="btn btn-sm btn-outline-primary grid-btn-edit" style="flex:1;" onclick='openEditProduct(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, "UTF-8") ?>)' title="Edit"><i class="fas fa-edit"></i></button>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Add Product Modal -->
<div class="modal-overlay" id="addProductModal">
  <div  class="modal-box inv-c9726f">
    <div class="modal-header"><h5><i class="fas fa-plus me-2"></i>Add New Product</h5><button class="modal-close" onclick="closeModal('addProductModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group"><label class="form-label">Product Code / SKU *</label><input type="text" name="product_code" class="form-control" placeholder="Enter Product Code" required></div>
        <div class="form-group"><label class="form-label">Product Name *</label><input type="text" name="name" class="form-control" required></div>
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label">Base Model / Series (Optional)</label>
            <input type="text" name="base_model" class="form-control" placeholder="e.g. METAL 6631, PLASTIC SUNCARI">
          </div>
          <div class="col-md-6">
            <label class="form-label">Variant / Color (Optional)</label>
            <input type="text" name="variant_name" class="form-control" placeholder="e.g. Gold/Pink, Black, 8022 Grey">
          </div>
        </div>
        <div class="inv-b1eb0f">
          <div  class="form-group inv-da5cd6"><label class="form-label">Category *</label>
            <select name="category_id" class="form-select" required><option value="">Select</option>
              <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Product Tier *</label>
            <select name="tier" class="form-select" required>
              <option value="budget" selected>⚪ Budget Product</option>
              <option value="mid">🔵 Mid Product</option>
              <option value="high">🟣 High Product</option>
            </select></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-select"><option value="">None</option>
              <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= sanitize($s['company_name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="inv-b1eb0f">
          <div  class="form-group inv-da5cd6"><label class="form-label">Price (₱) *</label><input type="number" name="price" class="form-control" step="0.01" min="0" required></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Initial Stock</label><input type="number" name="stock_quantity" class="form-control" value="0" min="0"></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Low Stock Alert</label><input type="number" name="low_stock_alert" class="form-control" value="5" min="1"></div>
        </div>
        <div class="form-group"><label class="form-label">Product Image</label><input type="file" name="image" class="form-control" accept="image/*"></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addProductModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Product</button></div>
    </form>
  </div>
</div>

<!-- Edit Product Modal -->
<div class="modal-overlay" id="editProductModal">
  <div  class="modal-box inv-c9726f">
    <div class="modal-header"><h5><i class="fas fa-edit me-2"></i>Edit Product</h5><button class="modal-close" onclick="closeModal('editProductModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST" enctype="multipart/form-data" onsubmit="return confirmEdit(event, this)">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="epId">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group"><label class="form-label">Product Code / SKU *</label><input type="text" name="product_code" id="epCode" class="form-control" placeholder="Enter Product Code" required></div>
        <div class="form-group"><label class="form-label">Product Name *</label><input type="text" name="name" id="epName" class="form-control" required></div>
        <div class="row g-2 mb-3">
          <div class="col-md-6">
            <label class="form-label">Base Model / Series (Optional)</label>
            <input type="text" name="base_model" id="epBaseModel" class="form-control" placeholder="e.g. METAL 6631, PLASTIC SUNCARI">
          </div>
          <div class="col-md-6">
            <label class="form-label">Variant / Color (Optional)</label>
            <input type="text" name="variant_name" id="epVariantName" class="form-control" placeholder="e.g. Gold/Pink, Black, 8022 Grey">
          </div>
        </div>
        <div class="inv-b1eb0f">
          <div  class="form-group inv-da5cd6"><label class="form-label">Category *</label>
            <select name="category_id" id="epCat" class="form-select" required><option value="">Select</option>
              <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Product Tier *</label>
            <select name="tier" id="epTier" class="form-select" required>
              <option value="budget">⚪ Budget Product</option>
              <option value="mid">🔵 Mid Product</option>
              <option value="high">🟣 High Product</option>
            </select></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Supplier</label>
            <select name="supplier_id" id="epSupp" class="form-select"><option value="">None</option>
              <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= sanitize($s['company_name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div class="inv-b1eb0f">
          <div  class="form-group inv-da5cd6"><label class="form-label">Price (₱) *</label><input type="number" name="price" id="epPrice" class="form-control" step="0.01" min="0" required></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Low Stock Alert</label><input type="number" name="low_stock_alert" id="epAlert" class="form-control" min="1"></div>
          <div  class="form-group inv-da5cd6"><label class="form-label">Status</label>
            <select name="status" id="epStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        </div>
        <div class="form-group"><label class="form-label">Product Image (Leave empty to keep current)</label><input type="file" name="image" id="epImage" class="form-control" accept="image/*"></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" id="epDesc" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editProductModal')">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button></div>
    </form>
  </div>
</div>

<!-- Stock In/Out Modal -->
<div class="modal-overlay" id="stockModal">
  <div  class="modal-box inv-a833a4">
    <div class="modal-header"><h5 id="stockModalTitle">Stock In</h5><button class="modal-close" onclick="closeModal('stockModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="id" id="stockProdId">
        <input type="hidden" name="action" id="stockAction">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <p class="inv-8c7aac">Product: <strong id="stockProdName"></strong></p>
        <div class="form-group"><label class="form-label">Quantity *</label><input type="number" name="qty" class="form-control" min="1" required></div>
        <div class="form-group"><label class="form-label">Reason / Note</label><input type="text" name="reason" class="form-control" placeholder="e.g. Supplier delivery, Sold..."></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('stockModal')">Cancel</button><button type="submit" class="btn btn-primary" id="stockSubmitBtn">Confirm</button></div>
    </form>
  </div>
</div>

<script>
function openStockModal(id, name, action) {
  document.getElementById('stockProdId').value = id;
  document.getElementById('stockAction').value = action;
  document.getElementById('stockProdName').textContent = name;
  const isIn = action === 'stock_in';
  document.getElementById('stockModalTitle').innerHTML = `<i class="fas fa-${isIn?'plus':'minus'} me-2" style="color:var(--clr-${isIn?'success':'warning'})"></i>${isIn?'Stock In':'Stock Out'}`;
  document.getElementById('stockSubmitBtn').className = `btn btn-${isIn?'success':'warning'}`;
  document.getElementById('stockSubmitBtn').innerHTML = `<i class="fas fa-${isIn?'plus':'minus'}"></i> ${isIn?'Add Stock':'Deduct Stock'}`;
  openModal('stockModal');
}
let currentEditProduct = null;

function confirmEdit(e, form) {
  e.preventDefault();

  // Check if anything actually changed
  const p = currentEditProduct;
  if (p) {
      const code = document.getElementById('epCode').value;
      const name = document.getElementById('epName').value;
      const cat = document.getElementById('epCat').value;
      const tier = document.getElementById('epTier').value;
      const supp = document.getElementById('epSupp').value || null;
      const price = parseFloat(document.getElementById('epPrice').value);
      const alert = parseInt(document.getElementById('epAlert').value);
      const desc = document.getElementById('epDesc').value || null;
      const stat = document.getElementById('epStatus').value;
      const hasImage = document.getElementById('epImage').files.length > 0;

      const oldSupp = p.supplier_id ? String(p.supplier_id) : null;
      const oldDesc = p.description ? p.description : null;

      if (
          !hasImage &&
          code === (p.product_code || '') &&
          name === p.name &&
          cat === String(p.category_id) &&
          tier === (p.tier || 'budget') &&
          supp === oldSupp &&
          price === parseFloat(p.price) &&
          alert === parseInt(p.low_stock_alert) &&
          desc === oldDesc &&
          stat === p.status
      ) {
          Swal.fire({
              title: 'Notice',
              text: 'No changes were made. Product is already up to date!',
              icon: 'info',
              background: 'var(--bg-card)',
              color: 'var(--text-primary)',
              confirmButtonColor: 'var(--clr-primary)'
          });
          return;
      }
  }

  Swal.fire({
      title: 'Save Changes?',
      text: 'Are you sure you want to update this product?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: 'var(--clr-primary)',
      cancelButtonColor: 'var(--clr-danger)',
      confirmButtonText: 'Yes, update it!',
      background: 'var(--bg-card)',
      color: 'var(--text-primary)'
  }).then((result) => {
      if (result.isConfirmed) {
          form.submit();
      }
  });
}

function onVariantChange(selectEl) {
  const opt = selectEl.options[selectEl.selectedIndex];
  const row = selectEl.closest('tr');
  if (!row || !opt) return;

  // 1. Update SKU / CODE
  const codeCell = row.querySelector('.cell-code');
  if (codeCell) codeCell.textContent = opt.dataset.code || '—';

  // 2. Update Price
  const priceCell = row.querySelector('.cell-price');
  if (priceCell) priceCell.textContent = '₱' + opt.dataset.price;

  // 3. Update Stock & Alert Badge
  const stockCell = row.querySelector('.cell-stock');
  if (stockCell) {
    const qtyVal = stockCell.querySelector('.stock-qty-val');
    const badgeVal = stockCell.querySelector('.stock-badge-val');
    const stock = parseInt(opt.dataset.stock) || 0;
    const alertAt = parseInt(opt.dataset.lowAlert) || 5;
    const isOut = stock === 0;
    const isLow = stock <= alertAt && !isOut;

    if (qtyVal) {
      qtyVal.textContent = stock;
      qtyVal.style.color = isOut ? '#DC2626' : (isLow ? '#EF4444' : 'var(--text-primary)');
    }
    if (badgeVal) {
      if (isOut) badgeVal.innerHTML = '<span class="badge badge-out-alert inv-c1ae5c">OUT</span>';
      else if (isLow) badgeVal.innerHTML = '<span class="badge badge-low-alert inv-c1ae5c">LOW</span>';
      else badgeVal.innerHTML = '';
    }
  }

  // 4. Update Alert At
  const alertCell = row.querySelector('.cell-alert');
  if (alertCell) alertCell.textContent = opt.dataset.lowAlert || '5';

  // 5. Update Actions (Stock In / Stock Out / Edit)
  const btnIn = row.querySelector('.btn-action-in');
  const btnOut = row.querySelector('.btn-action-out');
  const btnEdit = row.querySelector('.btn-action-edit');
  const prodId = opt.value;
  const prodName = opt.dataset.name;

  if (btnIn) btnIn.setAttribute('onclick', `openStockModal(${prodId}, '${prodName.replace(/'/g, "\\'")}', 'stock_in')`);
  if (btnOut) btnOut.setAttribute('onclick', `openStockModal(${prodId}, '${prodName.replace(/'/g, "\\'")}', 'stock_out')`);
  if (btnEdit && opt.dataset.obj) btnEdit.setAttribute('onclick', `openEditProduct(${opt.dataset.obj})`);
}

function onGridVariantChange(selectEl) {
  const opt = selectEl.options[selectEl.selectedIndex];
  const card = selectEl.closest('.product-card');
  if (!card || !opt) return;

  const codeEl = card.querySelector('.grid-code');
  if (codeEl) codeEl.textContent = opt.dataset.code || '—';

  const priceEl = card.querySelector('.grid-price');
  if (priceEl) priceEl.textContent = '₱' + opt.dataset.price;

  const stockEl = card.querySelector('.grid-stock-badge');
  const stock = parseInt(opt.dataset.stock) || 0;
  const alertAt = parseInt(opt.dataset.lowAlert) || 5;
  const isOut = stock === 0;
  const isLow = stock <= alertAt && !isOut;

  if (stockEl) {
    if (isOut) {
      stockEl.innerHTML = '<span class="badge badge-out-alert"><i class="fas fa-times-circle me-1"></i>Out of Stock</span>';
    } else if (isLow) {
      stockEl.innerHTML = `<span class="badge badge-low-alert"><i class="fas fa-exclamation-triangle me-1"></i>Low: ${stock}</span>`;
    } else {
      stockEl.innerHTML = `<span class="badge bg-success" style="font-weight:600; font-size:0.8rem; padding:4px 10px; border-radius:20px;">${stock} in stock</span>`;
    }
  }

  const btnIn = card.querySelector('.grid-btn-in');
  const btnOut = card.querySelector('.grid-btn-out');
  const btnEdit = card.querySelector('.grid-btn-edit');
  const prodId = opt.value;
  const prodName = opt.dataset.name;

  if (btnIn) btnIn.setAttribute('onclick', `openStockModal(${prodId}, '${prodName.replace(/'/g, "\\'")}', 'stock_in')`);
  if (btnOut) btnOut.setAttribute('onclick', `openStockModal(${prodId}, '${prodName.replace(/'/g, "\\'")}', 'stock_out')`);
  if (btnEdit && opt.dataset.obj) btnEdit.setAttribute('onclick', `openEditProduct(${opt.dataset.obj})`);
}

function openEditProduct(p) {
  currentEditProduct = p;
  document.getElementById('epId').value = p.id;
  document.getElementById('epCode').value = p.product_code || '';
  document.getElementById('epName').value = p.name;
  document.getElementById('epBaseModel').value = p.base_model || '';
  document.getElementById('epVariantName').value = p.variant_name || '';
  document.getElementById('epCat').value = p.category_id;
  document.getElementById('epTier').value = p.tier || 'budget';
  document.getElementById('epSupp').value = p.supplier_id || '';
  document.getElementById('epPrice').value = p.price;
  document.getElementById('epAlert').value = p.low_stock_alert;
  document.getElementById('epDesc').value = p.description || '';
  document.getElementById('epStatus').value = p.status;
  openModal('editProductModal');
}

<?php if ($reopenData): ?>
// Re-open the modal automatically if there were no changes
document.addEventListener("DOMContentLoaded", function() {
    openEditProduct(<?= json_encode($reopenData) ?>);
});
<?php endif; ?>

function toggleView() {
    const isGrid = document.getElementById('gridView').style.display !== 'none';
    if (isGrid) {
        document.getElementById('gridView').style.display = 'none';
        document.getElementById('tableView').style.display = 'block';
        document.getElementById('viewToggleBtn').innerHTML = '<i class="fas fa-th-large"></i> Grid View';
        localStorage.setItem('inventoryViewPref', 'list');
    } else {
        document.getElementById('gridView').style.display = 'grid';
        document.getElementById('tableView').style.display = 'none';
        document.getElementById('viewToggleBtn').innerHTML = '<i class="fas fa-list"></i> List View';
        localStorage.setItem('inventoryViewPref', 'grid');
    }
}

document.addEventListener("DOMContentLoaded", function() {
    if (localStorage.getItem('inventoryViewPref') === 'grid') {
        // execute toggle to switch to grid initially without toggling the preference
        document.getElementById('gridView').style.display = 'grid';
        document.getElementById('tableView').style.display = 'none';
        document.getElementById('viewToggleBtn').innerHTML = '<i class="fas fa-list"></i> List View';
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

