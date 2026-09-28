<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');
$pageTitle  = 'Products';
$breadcrumb = ['Saleslady', 'Products'];
$db = getDB();
ensureProductVariantSchema($db);

$search    = sanitize($_GET['search'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$tierFilter = sanitize($_GET['tier'] ?? '');
$where = ["p.status='active'"]; $params = [];
if ($search)    { $where[] = '(p.name LIKE ? OR p.product_code LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($catFilter) { $where[] = 'p.category_id=?'; $params[] = $catFilter; }
if (in_array($tierFilter, ['budget', 'mid', 'high'])) { $where[] = 'p.tier=?'; $params[] = $tierFilter; }
$whereStr = implode(' AND ', $where);

$products = $db->prepare("SELECT p.*, c.name as cat_name FROM products p JOIN categories c ON c.id=p.category_id WHERE $whereStr ORDER BY c.name, COALESCE(p.base_model, p.name) ASC, p.name ASC");
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

include __DIR__ . '/../includes/header.php';
?>
<div class="section-header">
  <h5><i class="fas fa-glasses me-2" style="color:var(--clr-primary)"></i>Products Catalog (<?= count($groupedProducts) ?><?= count($groupedProducts) !== count($products) ? ' Models, ' . count($products) . ' SKUs' : '' ?>)</h5>
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
    <?php if ($search || $catFilter || $tierFilter): ?><a href="products.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
  </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;">
  <?php if (empty($groupedProducts)): ?>
  <div style="grid-column:1/-1"><div class="empty-state"><div class="empty-icon"><i class="fas fa-glasses"></i></div><h6>No products found</h6></div></div>
  <?php else: ?>
  <?php foreach ($groupedProducts as $item): ?>
  <?php 
    $p = $item['primary'];
    $isLow = $p['stock_quantity'] <= $p['low_stock_alert']; 
    $isOut = $p['stock_quantity'] == 0; 
    $cardClass = $isOut ? 'card-out-stock' : ($isLow ? 'card-low-stock' : '');
    $borderStyle = $isOut 
      ? 'border:2px solid #DC2626;box-shadow:0 0 12px rgba(220,38,38,0.22);' 
      : ($isLow ? 'border:2px solid #EF4444;box-shadow:0 0 12px rgba(239,68,68,0.22);' : 'border:1px solid var(--border-color);');
  ?>
  <div class="<?= $cardClass ?> product-card" style="background:var(--bg-card);<?= $borderStyle ?>border-radius:14px;padding:18px;transition:all .2s;display:flex;flex-direction:column;"
       onmouseover="this.style.boxShadow='<?= $isLow || $isOut ? '0 0 18px rgba(239,68,68,0.35)' : 'var(--shadow-md)' ?>';this.style.borderColor='<?= $isOut ? '#DC2626' : ($isLow ? '#EF4444' : 'var(--clr-primary)') ?>'"
       onmouseout="this.style.boxShadow='<?= $isOut ? '0 0 12px rgba(220,38,38,0.22)' : ($isLow ? '0 0 12px rgba(239,68,68,0.22)' : 'none') ?>';this.style.borderColor='<?= $isOut ? '#DC2626' : ($isLow ? '#EF4444' : 'var(--border-color)') ?>'">
    <?php if($p['image']): ?>
      <img src="<?= BASE_URL ?>assets/images/products/<?= $p['image'] ?>" alt="Product" style="width:40px;height:40px;object-fit:cover;border-radius:12px;margin-bottom:12px;">
    <?php else: ?>
      <div style="width:40px;height:40px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:12px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;">
        <i class="fas fa-glasses" style="color:#fff;font-size:.9rem;"></i>
      </div>
    <?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
      <span class="card-code" style="font-family:monospace; color:var(--text-primary); font-size:0.85rem; font-weight:600;"><?= sanitize($p['product_code'] ?: '') ?></span>
      <?= tierBadge($p['tier'] ?? 'budget') ?>
    </div>
    <div style="font-weight:700;font-size:.88rem;margin-bottom:4px;line-height:1.3;display:flex;align-items:center;gap:6px;">
      <?= sanitize($item['base_model']) ?>
      <?php if ($item['has_variants']): ?>
        <span class="badge bg-primary" style="font-size:.62rem; padding:2px 5px;"><?= count($item['variants']) ?> Options</span>
      <?php endif; ?>
    </div>
    <div style="margin-bottom:8px;"><span class="badge bg-secondary" style="font-size:.65rem"><?= sanitize($p['cat_name']) ?></span></div>
    
    <?php if ($item['has_variants']): ?>
    <!-- Variant Selector Dropdown -->
    <div style="margin-bottom:10px;">
      <label style="font-size:0.7rem; font-weight:700; color:var(--clr-primary); margin-bottom:2px; display:block;">
        <i class="fas fa-palette me-1"></i>Color / Variant:
      </label>
      <select class="form-select form-select-sm" 
              style="font-size:0.75rem; padding:3px 20px 3px 6px; font-weight:600;"
              onchange="onCardVariantChange(this)">
        <?php foreach ($item['variants'] as $v): ?>
          <option value="<?= $v['id'] ?>"
                  data-code="<?= htmlspecialchars($v['product_code'] ?: '') ?>"
                  data-name="<?= htmlspecialchars($v['name']) ?>"
                  data-price="<?= number_format($v['price'], 2) ?>"
                  data-stock="<?= (int)$v['stock_quantity'] ?>"
                  data-low-alert="<?= (int)$v['low_stock_alert'] ?>">
            <?= htmlspecialchars($v['variant_name'] ?: $v['name']) ?> (Stock: <?= (int)$v['stock_quantity'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:auto;margin-bottom:8px;">
      <span class="card-price" style="font-weight:800;font-size:1.05rem;color:var(--clr-success)"><?= formatCurrency($p['price']) ?></span>
    </div>
    <div style="display:flex;align-items:center;gap:6px;">
      <div style="height:4px;flex:1;background:var(--bg-hover);border-radius:2px;overflow:hidden;">
        <?php $pct = min(100, ($p['stock_quantity'] / max(1,$p['low_stock_alert']*2)) * 100); ?>
        <div class="card-stock-bar" style="height:100%;width:<?= $pct ?>%;background:<?= $isOut?'#DC2626':($isLow?'#EF4444':'var(--clr-success)') ?>;border-radius:2px;"></div>
      </div>
      <span class="card-stock-text" style="font-size:.72rem;font-weight:700;color:<?= $isOut?'#DC2626':($isLow?'#EF4444':'var(--text-muted)') ?>;white-space:nowrap">
        <?= $isOut ? 'OUT' : ($isLow ? "LOW: {$p['stock_quantity']}" : "{$p['stock_quantity']} in stock") ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
function onCardVariantChange(selectEl) {
  const opt = selectEl.options[selectEl.selectedIndex];
  const card = selectEl.closest('.product-card');
  if (!card || !opt) return;

  const codeEl = card.querySelector('.card-code');
  if (codeEl) codeEl.textContent = opt.dataset.code || '';

  const priceEl = card.querySelector('.card-price');
  if (priceEl) priceEl.textContent = '₱' + opt.dataset.price;

  const stock = parseInt(opt.dataset.stock) || 0;
  const alertAt = parseInt(opt.dataset.lowAlert) || 5;
  const isOut = stock === 0;
  const isLow = stock <= alertAt && !isOut;

  const stockBar = card.querySelector('.card-stock-bar');
  if (stockBar) {
    const pct = Math.min(100, (stock / Math.max(1, alertAt * 2)) * 100);
    stockBar.style.width = pct + '%';
    stockBar.style.background = isOut ? '#DC2626' : (isLow ? '#EF4444' : 'var(--clr-success)');
  }

  const stockText = card.querySelector('.card-stock-text');
  if (stockText) {
    stockText.style.color = isOut ? '#DC2626' : (isLow ? '#EF4444' : 'var(--text-muted)');
    stockText.textContent = isOut ? 'OUT' : (isLow ? `LOW: ${stock}` : `${stock} in stock`);
  }
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
