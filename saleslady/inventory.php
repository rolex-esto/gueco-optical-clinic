<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');

$pageTitle  = 'Inventory';
$breadcrumb = ['Saleslady', 'Inventory'];
$db = getDB();
ensureProductVariantSchema($db);
$msg = ''; $msgType = 'success';

// Stock-in / out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    if ($action === 'stock_in' || $action === 'stock_out') {
        $id     = (int)$_POST['id'];
        $qty    = (int)$_POST['qty'];
        $reason = sanitize(trim($_POST['reason'] ?? ''));
        if ($qty <= 0) { $msg = 'Quantity must be greater than 0.'; $msgType = 'danger'; }
        else {
            $prod = $db->prepare("SELECT name, stock_quantity FROM products WHERE id=?"); $prod->execute([$id]); $prodData = $prod->fetch();
            $prev = (int)($prodData['stock_quantity'] ?? 0);
            $prodName = $prodData['name'] ?? ('Product #' . $id);
            $new = $action === 'stock_in' ? $prev + $qty : max(0, $prev - $qty);
            $db->prepare("UPDATE products SET stock_quantity=? WHERE id=?")->execute([$new, $id]);
            $db->prepare("INSERT INTO inventory_logs (product_id,type,quantity,previous_stock,new_stock,reason,user_id) VALUES (?,?,?,?,?,?,?)")
               ->execute([$id,$action,$qty,$prev,$new,$reason,$_SESSION['user_id']]);
            $msg = "Stock " . ($action==='stock_in'?'added':'deducted') . ". New stock: $new";
            logActivity(($action === 'stock_in' ? "Stock In: +$qty" : "Stock Out: -$qty") . " for \"$prodName\" (Reason: " . ($reason ?: 'None') . ", Stock: $prev → $new)", "Inventory", $_SESSION['user_id'], 'staff');
        }
    }
}

$search = sanitize($_GET['search'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$tierFilter = sanitize($_GET['tier'] ?? '');
$stockFilter = $_GET['stock'] ?? '';
$where = ['p.status = "active"']; $params = [];
if ($search) { $where[] = '(p.name LIKE ? OR p.product_code LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $where[] = 'p.category_id=?'; $params[] = $catFilter; }
if (in_array($tierFilter, ['budget', 'mid', 'high'])) { $where[] = 'p.tier=?'; $params[] = $tierFilter; }
if ($stockFilter === 'low') $where[] = 'p.stock_quantity <= p.low_stock_alert';
if ($stockFilter === 'out') $where[] = 'p.stock_quantity = 0';
$whereStr = implode(' AND ', $where);

$products = $db->prepare("
    SELECT p.*, c.name as cat_name
    FROM products p JOIN categories c ON c.id=p.category_id
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

// Recent inventory logs
$logs = $db->query("
    SELECT il.*, p.name as product_name, u.full_name as user_name
    FROM inventory_logs il JOIN products p ON p.id=il.product_id JOIN users u ON u.id=il.user_id
    ORDER BY il.created_at DESC LIMIT 15
")->fetchAll();

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

<div class="row" style="margin-bottom:16px;">
  <div class="col-8">
    <div class="section-header">
      <h5><i class="fas fa-warehouse me-2" style="color:var(--clr-primary)"></i>Stock Management (<?= count($groupedProducts) ?><?= count($groupedProducts) !== count($products) ? ' Models, ' . count($products) . ' SKUs' : '' ?>)</h5>
      <div style="display:flex;gap:8px;">
        <a href="?stock=low" class="btn btn-warning btn-sm"><i class="fas fa-exclamation-triangle"></i> Low Stock</a>
        <a href="?stock=out" class="btn btn-danger btn-sm"><i class="fas fa-times-circle"></i> Out of Stock</a>
      </div>
    </div>
    <!-- Filters -->
    <div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
      <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="text" name="search" class="form-control" placeholder="Search product..." value="<?= htmlspecialchars($search) ?>" style="width:170px;">
        <select name="cat" class="form-select" style="width:140px;">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $catFilter==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="tier" class="form-select" style="width:140px;">
          <option value="">All Tiers</option>
          <option value="budget" <?= $tierFilter==='budget'?'selected':'' ?>>⚪ Budget</option>
          <option value="mid" <?= $tierFilter==='mid'?'selected':'' ?>>🔵 Mid</option>
          <option value="high" <?= $tierFilter==='high'?'selected':'' ?>>🟣 High</option>
        </select>
        <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
        <a href="inventory.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a>
      </form>
    </div>

    <div class="table-wrapper">
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Product</th><th>Tier</th><th>Category</th><th>Price</th><th>Stock</th><th>Alert At</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if (empty($groupedProducts)): ?>
            <tr><td colspan="7"><div class="empty-state"><div class="empty-icon"><i class="fas fa-boxes"></i></div><h6>No products found</h6></div></td></tr>
            <?php else: ?>
            <?php foreach ($groupedProducts as $item): ?>
            <?php 
              $p = $item['primary'];
              $isLow = $p['stock_quantity'] <= $p['low_stock_alert']; 
              $isOut = $p['stock_quantity'] == 0; 
              $totalModelStock = array_sum(array_column($item['variants'], 'stock_quantity'));
            ?>
            <tr id="row-prod-<?= $p['id'] ?>">
              <td>
                <div style="font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:6px;">
                  <?= sanitize($item['base_model']) ?>
                  <?php if ($item['has_variants']): ?>
                    <span class="badge bg-primary" style="font-size:.65rem; padding:2px 6px;"><?= count($item['variants']) ?> Variants</span>
                  <?php endif; ?>
                </div>
                <div class="cell-code" style="font-family:monospace; color:var(--text-muted); font-size:0.75rem; margin-top:2px;">
                  <?= sanitize($p['product_code'] ?: '') ?>
                </div>
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
                              data-code="<?= htmlspecialchars($v['product_code'] ?: '') ?>"
                              data-name="<?= htmlspecialchars($v['name']) ?>"
                              data-variant="<?= htmlspecialchars($v['variant_name'] ?: $v['name']) ?>"
                              data-price="<?= number_format($v['price'], 2) ?>"
                              data-stock="<?= (int)$v['stock_quantity'] ?>"
                              data-low-alert="<?= (int)$v['low_stock_alert'] ?>">
                        <?= htmlspecialchars($v['variant_name'] ?: $v['name']) ?> (Stock: <?= (int)$v['stock_quantity'] ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php endif; ?>
              </td>
              <td><?= tierBadge($p['tier'] ?? 'budget') ?></td>
              <td><span class="badge bg-secondary"><?= sanitize($p['cat_name']) ?></span></td>
              <td class="cell-price" style="font-weight:700;color:var(--clr-success)"><?= formatCurrency($p['price']) ?></td>
              <td class="cell-stock">
                <span class="stock-qty-val" style="font-weight:800;font-size:.95rem;color:<?= $isOut?'#DC2626':($isLow?'#EF4444':'var(--text-primary)') ?>">
                  <?= $p['stock_quantity'] ?>
                </span>
                <span class="stock-badge-val">
                  <?php if ($isOut): ?><span class="badge badge-out-alert ms-1" style="font-size:.65rem; padding:2px 6px;">OUT</span>
                  <?php elseif ($isLow): ?><span class="badge badge-low-alert ms-1" style="font-size:.65rem; padding:2px 6px; font-weight:700;">LOW</span><?php endif; ?>
                </span>
                <?php if ($item['has_variants']): ?>
                  <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;" title="Combined stock for all variants of this model">
                    Model Total: <strong class="model-total-val"><?= $totalModelStock ?></strong>
                  </div>
                <?php endif; ?>
              </td>
              <td class="cell-alert" style="font-size:.8rem;color:var(--text-muted)"><?= $p['low_stock_alert'] ?></td>
              <td>
                <button class="btn btn-sm btn-success btn-icon btn-action-in" title="Stock In" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', 'stock_in')"><i class="fas fa-plus"></i></button>
                <button class="btn btn-sm btn-warning btn-icon btn-action-out" title="Stock Out" onclick="openStockModal(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', 'stock_out')"><i class="fas fa-minus"></i></button>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Recent Logs -->
  <div class="col-4">
    <div class="card">
      <div class="card-header"><h6><i class="fas fa-history me-2" style="color:var(--clr-info)"></i>Recent Activity</h6></div>
      <div style="overflow-y:auto;max-height:520px;">
        <?php if (empty($logs)): ?>
        <div class="empty-state" style="padding:30px"><div class="empty-icon"><i class="fas fa-history"></i></div><h6>No logs yet</h6></div>
        <?php else: ?>
        <?php foreach ($logs as $log): ?>
        <div style="padding:10px 16px;border-bottom:1px solid var(--border-light);">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <div style="width:24px;height:24px;border-radius:50%;background:<?= $log['type']==='stock_in'?'rgba(5,150,105,.15)':'rgba(220,38,38,.15)' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <i class="fas fa-<?= $log['type']==='stock_in'?'arrow-up':'arrow-down' ?>" style="font-size:.55rem;color:<?= $log['type']==='stock_in'?'var(--clr-success)':'var(--clr-danger)' ?>"></i>
            </div>
            <div style="font-weight:600;font-size:.8rem;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize($log['product_name']) ?></div>
            <span style="font-weight:700;font-size:.8rem;color:<?= $log['type']==='stock_in'?'var(--clr-success)':'var(--clr-danger)' ?>">
              <?= $log['type']==='stock_in'?'+':'-' ?><?= $log['quantity'] ?>
            </span>
          </div>
          <div style="font-size:.7rem;color:var(--text-muted);padding-left:32px">
            <?= $log['previous_stock'] ?> → <?= $log['new_stock'] ?> &middot; <?= sanitize($log['reason']??'—') ?> &middot; <?= formatDateTime($log['created_at']) ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Stock Modal -->
<div class="modal-overlay" id="stockModal">
  <div class="modal-box" style="max-width:380px;">
    <div class="modal-header"><h5 id="stockTitle">Stock In</h5><button class="modal-close" onclick="closeModal('stockModal')"><i class="fas fa-times"></i></button></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
      <div class="modal-body">
        <input type="hidden" name="id" id="sId"><input type="hidden" name="action" id="sAction">
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:12px;">Product: <strong id="sProdName"></strong></p>
        <div class="form-group"><label class="form-label">Quantity *</label><input type="number" name="qty" class="form-control" min="1" required></div>
        <div class="form-group"><label class="form-label">Reason / Note</label><input type="text" name="reason" class="form-control" placeholder="e.g. Supplier delivery, Return..."></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('stockModal')">Cancel</button><button type="submit" id="sBtn" class="btn btn-success">Confirm</button></div>
    </form>
  </div>
</div>
<script>
function openStockModal(id, name, action) {
  document.getElementById('sId').value = id;
  document.getElementById('sAction').value = action;
  document.getElementById('sProdName').textContent = name;
  const isIn = action === 'stock_in';
  document.getElementById('stockTitle').innerHTML = `<i class="fas fa-${isIn?'plus':'minus'} me-2" style="color:var(--clr-${isIn?'success':'warning'})"></i>${isIn?'Stock In':'Stock Out'}`;
  document.getElementById('sBtn').className = `btn btn-${isIn?'success':'warning'}`;
  openModal('stockModal');
}

function onVariantChange(selectEl) {
  const opt = selectEl.options[selectEl.selectedIndex];
  const row = selectEl.closest('tr');
  if (!row || !opt) return;

  // 1. Update SKU / Code
  const codeCell = row.querySelector('.cell-code');
  if (codeCell) codeCell.textContent = opt.dataset.code || '';

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
      if (isOut) badgeVal.innerHTML = '<span class="badge badge-out-alert ms-1" style="font-size:.65rem; padding:2px 6px;">OUT</span>';
      else if (isLow) badgeVal.innerHTML = '<span class="badge badge-low-alert ms-1" style="font-size:.65rem; padding:2px 6px; font-weight:700;">LOW</span>';
      else badgeVal.innerHTML = '';
    }
  }

  // 4. Update Alert At
  const alertCell = row.querySelector('.cell-alert');
  if (alertCell) alertCell.textContent = opt.dataset.lowAlert || '5';

  // 5. Update Actions (Stock In / Stock Out)
  const btnIn = row.querySelector('.btn-action-in');
  const btnOut = row.querySelector('.btn-action-out');
  const prodId = opt.value;
  const prodName = opt.dataset.name;

  if (btnIn) btnIn.setAttribute('onclick', `openStockModal(${prodId}, '${prodName.replace(/'/g, "\\'")}', 'stock_in')`);
  if (btnOut) btnOut.setAttribute('onclick', `openStockModal(${prodId}, '${prodName.replace(/'/g, "\\'")}', 'stock_out')`);
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
