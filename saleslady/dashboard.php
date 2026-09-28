<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');

$pageTitle  = 'Dashboard';
$breadcrumb = ['Saleslady'];
$activeNav  = 'dashboard';

$db    = getDB();
ensureJobOrderSchema($db);
ensureAppointmentsSchema($db);
$today = date('Y-m-d');

// Stats
$todayAppts    = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date=? AND status NOT IN ('cancelled','no_show')"); $todayAppts->execute([$today]); $todayAppts = $todayAppts->fetch()['c'];
$todaySales    = $db->prepare("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE(created_at)=? AND (status='completed' OR status IS NULL OR status = '' OR status NOT IN ('voided','refunded','cancelled'))"); $todaySales->execute([$today]); $todaySales = $todaySales->fetch()['t'];
$todayTxCount  = $db->prepare("SELECT COUNT(*) as c FROM sales WHERE DATE(created_at)=? AND (status='completed' OR status IS NULL OR status = '' OR status NOT IN ('voided','refunded','cancelled'))"); $todayTxCount->execute([$today]); $todayTxCount = $todayTxCount->fetch()['c'];
$lowStock      = $db->query("SELECT COUNT(*) as c FROM products WHERE stock_quantity<=low_stock_alert AND status='active'")->fetch()['c'];

// Today's appointment list (all for modal, first 10 for dashboard queue card)
$todayAllApptsStmt = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.phone
    FROM appointments a JOIN patients p ON p.id=a.patient_id
    WHERE a.appointment_date=?
    ORDER BY a.appointment_time ASC
");
$todayAllApptsStmt->execute([$today]);
$todayAllAppts = $todayAllApptsStmt->fetchAll(PDO::FETCH_ASSOC);
$appts = array_slice($todayAllAppts, 0, 10);

// Today's all transactions for the modal & dashboard recent sales
$todayAllSalesStmt = $db->prepare("
    SELECT s.*, 
           p.full_name as patient_name, 
           p.phone as patient_phone,
           u.full_name as cashier_name,
           (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) as item_count,
           (SELECT GROUP_CONCAT(CONCAT(si.item_name, ' (x', si.quantity, ')') SEPARATOR ', ') FROM sale_items si WHERE si.sale_id = s.id) as item_summary
    FROM sales s
    LEFT JOIN patients p ON p.id = s.patient_id
    LEFT JOIN users u ON u.id = s.cashier_id
    WHERE DATE(s.created_at) = ? AND (s.status = 'completed' OR s.status IS NULL OR s.status = '' OR s.status NOT IN ('voided','refunded','cancelled'))
    ORDER BY s.created_at DESC
");
$todayAllSalesStmt->execute([$today]);
$todayAllSales = $todayAllSalesStmt->fetchAll(PDO::FETCH_ASSOC);
$recentSales   = array_slice($todayAllSales, 0, 6);

// Payment breakdown for today
$todayCashTotal = 0; $todayCashCount = 0;
$todayDigitalTotal = 0; $todayDigitalCount = 0;
foreach ($todayAllSales as $s) {
    if (strtolower($s['payment_method'] ?? '') === 'cash') {
        $todayCashTotal += (float)$s['total'];
        $todayCashCount++;
    } else {
        $todayDigitalTotal += (float)$s['total'];
        $todayDigitalCount++;
    }
}

// Hourly sales data for today
$hourlyLabels = []; $hourlyData = [];
for ($h = 9; $h <= 17; $h++) {
    $hourlyLabels[] = date('g A', mktime($h,0,0));
    $stmt = $db->prepare("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE DATE(created_at)=? AND HOUR(created_at)=? AND (status='completed' OR status IS NULL OR status = '' OR status NOT IN ('voided','refunded','cancelled'))");
    $stmt->execute([$today, $h]);
    $hourlyData[] = round($stmt->fetch()['t'], 2);
}

// Low stock items (all for modal, first 5 for dashboard card)
$allLowStockItems = $db->query("
    SELECT p.id, p.name, p.stock_quantity, p.low_stock_alert, p.price, c.name as cat
    FROM products p JOIN categories c ON c.id=p.category_id
    WHERE p.stock_quantity<=p.low_stock_alert AND p.status='active'
    ORDER BY p.stock_quantity ASC
")->fetchAll(PDO::FETCH_ASSOC);
$lowItems = array_slice($allLowStockItems, 0, 5);

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/dashboard.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>

<!-- Quick Action Buttons -->
<div class="dash-quick-actions">
  <a href="../saleslady/pos.php?mode=retail" class="dash-action-btn primary-action">
    <i class="fas fa-bolt"></i>
    <span>Quick Sale / Walk-in</span>
  </a>
  <a href="../saleslady/pos.php" class="dash-action-btn action-primary">
    <i class="fas fa-cash-register"></i>
    <span>POS Terminal</span>
  </a>
  <a href="../saleslady/appointments.php" class="dash-action-btn">
    <i class="fas fa-calendar-check"></i>
    <span>Today's Queue</span>
  </a>
  <a href="../saleslady/inventory.php" class="dash-action-btn">
    <i class="fas fa-warehouse"></i>
    <span>Inventory Stock</span>
  </a>
  <a href="../saleslady/patients.php" class="dash-action-btn">
    <i class="fas fa-users"></i>
    <span>Patient Info</span>
  </a>
</div>

<!-- ─── Bento Top Metric Cards Row (4 Responsive Clickable Cards) ─── -->
<div class="row g-2 g-sm-3 mb-4">
  <!-- Card 1: Today's Revenue -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat clickable" data-bs-toggle="modal" data-bs-target="#todayTransactionsModal" role="button" tabindex="0" title="Click to view today's transactions &amp; revenue" style="--stat-color:#10B981; --stat-rgb:16, 185, 129;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Today's Revenue</div>
        <div class="bento-value"><?= formatCurrency($todaySales) ?></div>
        <div class="bento-badge <?= $todayTxCount > 0 ? 'up' : 'neutral' ?>">
          <i class="fas fa-receipt"></i> <?= $todayTxCount ?> transaction<?= $todayTxCount !== 1 ? 's' : '' ?>
        </div>
        <span class="bento-stat-hint"><i class="fas fa-expand me-1"></i> View Details</span>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-peso-sign"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 2: Transactions Today -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat clickable" data-bs-toggle="modal" data-bs-target="#todayTransactionsModal" role="button" tabindex="0" title="Click to view today's transactions log" style="--stat-color:#0EA5E9; --stat-rgb:14, 165, 233;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Transactions Today</div>
        <div class="bento-value"><?= number_format($todayTxCount) ?></div>
        <div class="bento-badge blue">
          <i class="fas fa-cash-register"></i> Processed today
        </div>
        <span class="bento-stat-hint"><i class="fas fa-receipt me-1"></i> View Transactions</span>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-receipt"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 3: Appointments Today -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat clickable" data-bs-toggle="modal" data-bs-target="#todayAppointmentsModal" role="button" tabindex="0" title="Click to view today's scheduled queue" style="--stat-color:#8B5CF6; --stat-rgb:139, 92, 246;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Appointments Today</div>
        <div class="bento-value"><?= number_format($todayAppts) ?></div>
        <div class="bento-badge purple">
          <i class="fas fa-calendar-day"></i> Scheduled
        </div>
        <span class="bento-stat-hint"><i class="fas fa-calendar-check me-1"></i> View Queue</span>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-calendar-check"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 4: Low Stock Alerts -->
  <?php
  $isLowStock = ($lowStock > 0);
  $stockColor = $isLowStock ? '#EF4444' : '#E09A67';
  $stockRgb   = $isLowStock ? '239, 68, 68' : '224, 154, 103';
  ?>
  <div class="col-6 col-lg-3">
    <div class="bento-stat clickable" data-bs-toggle="modal" data-bs-target="#lowStockModal" role="button" tabindex="0" title="Click to view low stock items" style="--stat-color:<?= $stockColor ?>; --stat-rgb:<?= $stockRgb ?>;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Low Stock Alerts</div>
        <div class="bento-value"><?= number_format($lowStock) ?></div>
        <div class="bento-badge <?= $isLowStock ? 'danger' : 'neutral' ?>">
          <i class="fas fa-<?= $isLowStock ? 'triangle-exclamation' : 'boxes-stacked' ?>"></i>
          <?= $isLowStock ? 'Needs attention' : 'All good' ?>
        </div>
        <span class="bento-stat-hint"><i class="fas fa-boxes me-1"></i> View Stock</span>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-boxes"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts + Appointments -->
<div class="row g-3 mb-4">
  <!-- Hourly Sales Chart -->
  <div class="col-12 col-lg-8">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-chart-area me-2" style="color:var(--clr-success)"></i>Today's Sales by Hour</h6>
        <span style="font-size:.75rem;color:var(--text-muted)"><?= date('F d, Y') ?></span>
      </div>
      <div class="card-body">
        <div class="chart-container" style="height:220px;">
          <canvas id="hourlyChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Today's Appointment Mini List -->
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-calendar-day me-2" style="color:var(--clr-info)"></i>Today's Queue</h6>
      </div>
      <div style="overflow-y:auto;max-height:260px;padding:12px;">
        <?php if (empty($appts)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:.82rem;">
          <i class="fas fa-calendar" style="font-size:2rem;margin-bottom:10px;display:block;opacity:.4"></i>
          No appointments today
        </div>
        <?php else: ?>
        <?php foreach ($appts as $appt): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:10px;margin-bottom:6px;background:var(--bg-hover);">
          <div>
            <div style="font-weight:600;font-size:.82rem"><?= sanitize($appt['patient_name']) ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)"><?= formatTime($appt['appointment_time']) ?> &mdash; <?= ucwords(str_replace('_',' ',$appt['purpose'])) ?></div>
          </div>
          <?= statusBadge($appt['status']) ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Today's Sales + Low Stock -->
<div class="row g-3 mb-4">
  <!-- Today's transactions -->
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-receipt me-2" style="color:var(--clr-success)"></i>Today's Transactions</h6>
        <a href="../saleslady/sales.php" class="btn btn-sm btn-outline-primary">All Sales</a>
      </div>
      <?php if (empty($recentSales)): ?>
      <div class="empty-state" style="padding:40px">
        <div class="empty-icon"><i class="fas fa-receipt"></i></div>
        <h6>No sales yet today</h6>
        <p>Start by processing a sale at the POS</p>
        <a href="../saleslady/pos.php" class="btn btn-primary btn-sm mt-2"><i class="fas fa-plus"></i> New Sale</a>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Invoice</th><th>Patient</th><th>Total</th><th>Method</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recentSales as $s): ?>
          <tr>
            <td><span style="font-family:monospace;font-size:.78rem;color:var(--clr-primary);font-weight:600"><?= sanitize($s['invoice_no']) ?></span></td>
            <td style="font-size:.83rem"><?= sanitize($s['patient_name'] ?? 'Walk-in') ?></td>
            <td style="font-weight:700;color:var(--clr-success)"><?= formatCurrency($s['total']) ?></td>
            <td><span class="badge bg-info"><?= strtoupper($s['payment_method']) ?></span></td>
            <td><?= statusBadge($s['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Low stock items -->
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-exclamation-triangle me-2" style="color:var(--clr-warning)"></i>Low Stock Items</h6>
        <a href="../saleslady/inventory.php" class="btn btn-sm btn-outline-primary">Manage Stock</a>
      </div>
      <?php if (empty($lowItems)): ?>
      <div class="empty-state" style="padding:40px">
        <div class="empty-icon" style="color:var(--clr-success)"><i class="fas fa-check-circle"></i></div>
        <h6 style="color:var(--clr-success)">All stock levels are fine!</h6>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Product</th><th>Category</th><th>Stock</th><th>Alert</th></tr></thead>
          <tbody>
          <?php foreach ($lowItems as $item): ?>
          <tr>
            <td style="font-weight:600;font-size:.83rem"><?= sanitize($item['name']) ?></td>
            <td style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($item['cat']) ?></td>
            <td>
              <span style="font-weight:700;color:<?= $item['stock_quantity']==0?'var(--clr-danger)':'var(--clr-warning)' ?>">
                <?= $item['stock_quantity'] ?> left
              </span>
            </td>
            <td style="font-size:.8rem;color:var(--text-muted)">Alert at <?= $item['low_stock_alert'] ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ─── TODAY'S TRANSACTIONS MODAL ──────────────────────────────────────── -->
<div class="modal fade dash-modal" id="todayTransactionsModal" tabindex="-1" aria-labelledby="todayTxModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl" style="max-width: 1100px;">
    <div class="modal-content">
      <!-- Modal Header -->
      <div class="modal-header dash-modal-header-blue d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
          <div style="width:44px;height:44px;border-radius:14px;background:rgba(255,255,255,0.22);display:flex;align-items:center;justify-content:center;font-size:1.25rem;color:#fff;">
            <i class="fas fa-receipt"></i>
          </div>
          <div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <h5 class="modal-title fw-bold mb-0 text-white" id="todayTxModalLabel">Today's Transactions</h5>
              <span class="badge bg-white text-primary fw-bold" style="font-size:0.75rem;"><i class="fas fa-calendar-day me-1"></i><?= date('M d, Y') ?></span>
              <span class="badge bg-success text-white fw-bold" style="font-size:0.75rem;"><i class="fas fa-coins me-1"></i><?= formatCurrency($todaySales) ?></span>
            </div>
            <small class="text-white" style="opacity:0.85;">Current day sales ledger &bull; Gueco Optical Clinic</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Modal Body -->
      <div class="modal-body">
        <!-- Summary Cards Row -->
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3">
            <div class="dash-summary-pill">
              <span class="dash-summary-pill-label"><i class="fas fa-chart-line text-success me-1"></i> Total Revenue</span>
              <span class="dash-summary-pill-val text-success"><?= formatCurrency($todaySales) ?></span>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="dash-summary-pill">
              <span class="dash-summary-pill-label"><i class="fas fa-receipt text-info me-1"></i> Completed Sales</span>
              <span class="dash-summary-pill-val text-info"><?= count($todayAllSales) ?></span>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="dash-summary-pill">
              <span class="dash-summary-pill-label"><i class="fas fa-money-bill-wave text-primary me-1"></i> Cash Payments</span>
              <span class="dash-summary-pill-val" style="font-size:1.05rem;"><?= formatCurrency($todayCashTotal) ?> <small class="text-muted fw-normal" style="font-size:0.72rem;">(<?= $todayCashCount ?>)</small></span>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="dash-summary-pill">
              <span class="dash-summary-pill-label"><i class="fas fa-qrcode text-warning me-1"></i> GCash / Digital</span>
              <span class="dash-summary-pill-val" style="font-size:1.05rem;"><?= formatCurrency($todayDigitalTotal) ?> <small class="text-muted fw-normal" style="font-size:0.72rem;">(<?= $todayDigitalCount ?>)</small></span>
            </div>
          </div>
        </div>

        <!-- Search Bar -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div class="input-group" style="max-width: 420px;">
            <span class="input-group-text bg-transparent border-end-0 text-muted"><i class="fas fa-search"></i></span>
            <input type="text" id="todayTxSearchInput" class="form-control border-start-0 ps-0" placeholder="Filter by invoice #, customer name, items, or payment..." oninput="filterTodayTx(this.value)">
          </div>
          <div class="text-muted small">
            Showing <strong id="todayTxCountDisplay"><?= count($todayAllSales) ?></strong> transaction(s) today
          </div>
        </div>

        <!-- Table Container -->
        <?php if (empty($todayAllSales)): ?>
        <div class="text-center py-5 my-3" style="background:var(--bg-hover); border-radius:16px; border:1px dashed var(--border-color);">
          <div style="width:60px;height:60px;border-radius:50%;background:rgba(14,165,233,0.1);color:#0ea5e9;display:inline-flex;align-items:center;justify-content:center;font-size:1.75rem;margin-bottom:12px;">
            <i class="fas fa-receipt"></i>
          </div>
          <h5 class="fw-bold mb-1">No Transactions Recorded Yet Today</h5>
          <p class="text-muted small mb-3">Sales processed at the POS Terminal will automatically appear in this transaction ledger.</p>
          <a href="../saleslady/pos.php" class="btn btn-primary btn-sm px-3 shadow-sm">
            <i class="fas fa-cash-register me-1"></i> Open POS Terminal
          </a>
        </div>
        <?php else: ?>
        <div class="table-responsive" style="border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden;">
          <table class="table table-hover mb-0 dash-tx-table" id="todayTxTable">
            <thead>
              <tr>
                <th style="width: 140px;">Invoice #</th>
                <th style="width: 100px;">Time</th>
                <th style="min-width: 170px;">Customer / Patient</th>
                <th style="min-width: 220px;">Items / Dispensed</th>
                <th style="width: 120px;">Payment</th>
                <th style="width: 110px;" class="text-end">Total</th>
                <th style="width: 90px;" class="text-center">Receipt</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($todayAllSales as $tx): 
                $payMethod = strtolower($tx['payment_method'] ?? 'cash');
                $methodBadge = 'bg-secondary';
                if ($payMethod === 'cash') $methodBadge = 'bg-success';
                elseif ($payMethod === 'gcash') $methodBadge = 'bg-primary';
                elseif ($payMethod === 'card') $methodBadge = 'bg-info text-dark';
                
                $searchStr = strtolower(($tx['invoice_no'] ?? '') . ' ' . ($tx['patient_name'] ?? 'walk-in') . ' ' . ($tx['payment_method'] ?? '') . ' ' . ($tx['item_summary'] ?? '') . ' ' . ($tx['job_order_no'] ?? ''));
              ?>
              <tr class="tx-row" data-search="<?= htmlspecialchars($searchStr, ENT_QUOTES, 'UTF-8') ?>">
                <td>
                  <span class="badge bg-primary-subtle text-primary fw-bold font-monospace px-2 py-1" style="font-size:0.8rem;">
                    <?= sanitize($tx['invoice_no']) ?>
                  </span>
                  <?php if (!empty($tx['job_order_no'])): ?>
                    <br><span class="badge bg-warning-subtle text-dark border border-warning-subtle mt-1" style="font-size:0.68rem;" title="Optical Job Order">
                      <i class="fas fa-glasses me-1"></i>JO #<?= sanitize($tx['job_order_no']) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="text-muted small">
                    <i class="fas fa-clock me-1 text-primary" style="font-size:0.7rem;"></i><?= date('h:i A', strtotime($tx['created_at'])) ?>
                  </span>
                </td>
                <td>
                  <div class="fw-semibold text-dark-emphasis" style="font-size:0.88rem;">
                    <?= sanitize($tx['patient_name'] ?? 'Walk-in Customer') ?>
                  </div>
                  <?php if (!empty($tx['patient_phone'])): ?>
                    <small class="text-muted"><i class="fas fa-phone-alt me-1" style="font-size:0.65rem;"></i><?= sanitize($tx['patient_phone']) ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge bg-secondary-subtle text-secondary mb-1">
                    <i class="fas fa-box me-1"></i><?= (int)$tx['item_count'] ?> item<?= (int)$tx['item_count'] !== 1 ? 's' : '' ?>
                  </span>
                  <div class="small text-muted text-truncate" style="max-width: 260px;" title="<?= sanitize($tx['item_summary'] ?? 'POS Products') ?>">
                    <?= sanitize($tx['item_summary'] ?? 'POS Products') ?>
                  </div>
                </td>
                <td>
                  <span class="badge <?= $methodBadge ?> text-uppercase px-2 py-1" style="font-size:0.72rem;">
                    <?= sanitize($tx['payment_method']) ?>
                  </span>
                  <?php if (($tx['payment_type'] ?? 'full') === 'downpayment'): ?>
                    <br><span class="badge bg-warning-subtle text-warning-emphasis border mt-1" style="font-size:0.66rem;">
                      Downpayment (Bal: <?= formatCurrency($tx['balance_due']) ?>)
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="fw-bold text-success" style="font-size:0.95rem;">
                    <?= formatCurrency($tx['total']) ?>
                  </div>
                </td>
                <td class="text-center">
                  <button type="button" class="btn btn-sm btn-outline-primary px-2 py-1 shadow-sm" onclick="openReceiptFromModal(<?= (int)$tx['id'] ?>)" title="View Receipt">
                    <i class="fas fa-file-invoice me-1"></i> Receipt
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Modal Footer -->
      <div class="modal-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
        <a href="../saleslady/pos.php" class="btn btn-primary px-3 shadow-sm">
          <i class="fas fa-plus me-1"></i> New POS Sale
        </a>
        <div class="d-flex gap-2">
          <a href="../saleslady/sales.php" class="btn btn-outline-secondary px-3">
            <i class="fas fa-history me-1"></i> All Sales Records
          </a>
          <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ─── TODAY'S APPOINTMENTS MODAL ─────────────────────────────────────── -->
<div class="modal fade dash-modal" id="todayAppointmentsModal" tabindex="-1" aria-labelledby="todayApptModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header dash-modal-header-purple d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
          <div style="width:42px;height:42px;border-radius:12px;background:rgba(255,255,255,0.22);display:flex;align-items:center;justify-content:center;font-size:1.25rem;color:#fff;">
            <i class="fas fa-calendar-check"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-white" id="todayApptModalLabel">Today's Appointment Queue</h5>
            <small class="text-white-50"><i class="fas fa-calendar-day me-1"></i> <?= date('F d, Y') ?> &bull; <?= count($todayAllAppts) ?> scheduled patient(s)</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if (empty($todayAllAppts)): ?>
        <div class="text-center py-5" style="background:var(--bg-hover); border-radius:14px; border:1px dashed var(--border-color);">
          <i class="fas fa-calendar fa-2x text-muted mb-2" style="opacity:0.4;"></i>
          <h6 class="fw-bold mb-1">No Appointments Scheduled Today</h6>
          <p class="text-muted small">Any new bookings or walk-ins registered today will appear here.</p>
        </div>
        <?php else: ?>
        <div class="d-flex flex-column gap-2">
          <?php foreach ($todayAllAppts as $apt): ?>
          <div class="d-flex justify-content-between align-items-center p-3 rounded-3" style="background:var(--bg-hover); border:1px solid var(--border-color);">
            <div>
              <div class="fw-bold" style="font-size:0.92rem;"><?= sanitize($apt['patient_name']) ?></div>
              <div class="small text-muted">
                <i class="fas fa-clock text-primary me-1"></i><?= formatTime($apt['appointment_time']) ?> &bull; 
                <span class="text-primary fw-medium"><?= ucwords(str_replace('_',' ',$apt['purpose'])) ?></span>
                <?php if (!empty($apt['phone'])): ?> &bull; <i class="fas fa-phone-alt ms-1 me-1"></i><?= sanitize($apt['phone']) ?><?php endif; ?>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <?= statusBadge($apt['status']) ?>
              <a href="../saleslady/appointments.php" class="btn btn-sm btn-outline-primary px-2 py-1" title="View in Appointments Calendar">
                <i class="fas fa-arrow-right"></i>
              </a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <a href="../saleslady/appointments.php" class="btn btn-primary px-3">
          <i class="fas fa-calendar-alt me-1"></i> Open Appointments Calendar
        </a>
        <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ─── LOW STOCK ALERTS MODAL ─────────────────────────────────────────── -->
<div class="modal fade dash-modal" id="lowStockModal" tabindex="-1" aria-labelledby="lowStockModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header dash-modal-header-red d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
          <div style="width:42px;height:42px;border-radius:12px;background:rgba(255,255,255,0.22);display:flex;align-items:center;justify-content:center;font-size:1.25rem;color:#fff;">
            <i class="fas fa-triangle-exclamation"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-white" id="lowStockModalLabel">Low Stock Inventory Alerts</h5>
            <small class="text-white-50"><?= count($allLowStockItems) ?> product(s) at or below critical threshold</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if (empty($allLowStockItems)): ?>
        <div class="text-center py-5" style="background:var(--bg-hover); border-radius:14px; border:1px dashed var(--border-color);">
          <div style="width:50px;height:50px;border-radius:50%;background:rgba(16,185,129,0.15);color:#10b981;display:inline-flex;align-items:center;justify-content:center;font-size:1.5rem;margin-bottom:10px;">
            <i class="fas fa-check-circle"></i>
          </div>
          <h6 class="fw-bold text-success mb-1">All Stock Levels are Healthy</h6>
          <p class="text-muted small">No products are currently at or below their reorder alert threshold.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive" style="border:1px solid var(--border-color); border-radius:14px; overflow:hidden;">
          <table class="table table-hover mb-0 dash-tx-table">
            <thead>
              <tr>
                <th>Product Name</th>
                <th>Category</th>
                <th class="text-center">Available Stock</th>
                <th class="text-center">Alert Level</th>
                <th class="text-end">Price</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allLowStockItems as $item): ?>
              <tr>
                <td class="fw-semibold"><?= sanitize($item['name']) ?></td>
                <td><span class="badge bg-secondary-subtle text-secondary"><?= sanitize($item['cat']) ?></span></td>
                <td class="text-center">
                  <span class="badge <?= $item['stock_quantity'] == 0 ? 'bg-danger' : 'bg-warning text-dark' ?> px-2 py-1">
                    <?= $item['stock_quantity'] ?> left
                  </span>
                </td>
                <td class="text-center text-muted small">Alert &le; <?= (int)$item['low_stock_alert'] ?></td>
                <td class="text-end fw-bold"><?= formatCurrency($item['price']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <a href="../saleslady/inventory.php" class="btn btn-danger px-3">
          <i class="fas fa-boxes-stacked me-1"></i> Manage Inventory Stock
        </a>
        <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ─── RECEIPT PREVIEW MODAL ─────────────────────────────────────────── -->
<div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-hidden="true" style="z-index: 1090;">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content shadow-lg border-0" style="background:var(--bg-card); border-radius:18px; overflow:hidden;">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary)); color:#fff; padding:14px 20px;">
        <div class="d-flex align-items-center gap-2">
          <i class="fas fa-file-invoice fa-lg"></i>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-white" style="font-size:1.05rem;" id="receiptPreviewModalTitle">Official Sales Receipt</h5>
            <small style="opacity:0.85; color: #fff;">Gueco Optical Clinic</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0" style="background:#525659;">
        <iframe id="receiptPreviewIframe" src="" style="width:100%; height:540px; border:none; display:block; background:#525659;"></iframe>
      </div>
      <div class="modal-footer" style="background:var(--bg-hover); padding:12px 20px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <button type="button" onclick="printReceiptPreviewIframe()" class="btn btn-primary px-3">
          <i class="fas fa-print me-1"></i> Print Receipt
        </button>
        <div class="d-flex gap-2">
          <a href="#" id="btnOpenReceiptTabLink" target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-external-link-alt me-1"></i> Open in New Tab
          </a>
          <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script>
const hCtx = document.getElementById("hourlyChart");
if(hCtx){
  new Chart(hCtx,{
    type:"bar",
    data:{
      labels:'.json_encode($hourlyLabels).',
      datasets:[{
        label:"Sales (₱)",
        data:'.json_encode($hourlyData).',
        backgroundColor:"rgba(5,150,105,.65)",
        borderRadius:8,borderSkipped:false
      }]
    },
    options:{
      responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false}},
      scales:{
        y:{beginAtZero:true,ticks:{callback:v=>"₱"+v.toLocaleString(),font:{family:"Poppins",size:10}},grid:{color:"rgba(0,0,0,.04)"}},
        x:{ticks:{font:{family:"Poppins",size:10}},grid:{display:false}}
      }
    }
  });
}

// Live filter today transactions table
function filterTodayTx(query) {
  const q = (query || "").toLowerCase().trim();
  const rows = document.querySelectorAll("#todayTxTable tbody tr.tx-row");
  let visibleCount = 0;
  rows.forEach(r => {
    const text = r.dataset.search || "";
    if (!q || text.includes(q)) {
      r.style.display = "";
      visibleCount++;
    } else {
      r.style.display = "none";
    }
  });
  const display = document.getElementById("todayTxCountDisplay");
  if (display) display.textContent = visibleCount;
}

// Receipt preview modal helper
function openReceiptFromModal(saleId) {
  const iframe = document.getElementById("receiptPreviewIframe");
  const link   = document.getElementById("btnOpenReceiptTabLink");
  if (iframe) iframe.src = "receipt.php?id=" + saleId;
  if (link) link.href = "receipt.php?id=" + saleId;

  // Temporarily hide transactions modal to view receipt cleanly
  const txModalEl = document.getElementById("todayTransactionsModal");
  if (txModalEl) {
    const txModal = bootstrap.Modal.getInstance(txModalEl);
    if (txModal) txModal.hide();
  }

  const rcModal = new bootstrap.Modal(document.getElementById("receiptPreviewModal"));
  rcModal.show();
}

function printReceiptPreviewIframe() {
  const iframe = document.getElementById("receiptPreviewIframe");
  if (iframe && iframe.contentWindow) {
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
  }
}
</script>';
include __DIR__ . '/../includes/footer.php';
