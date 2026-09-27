<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Dashboard';
$breadcrumb = ['Admin'];
$activeNav  = 'dashboard';

$db = getDB();
ensureJobOrderSchema($db);
ensureAppointmentsSchema($db);

$stats = getDashboardStats();

// Selected month for Sales and Category metrics
$selectedMonth = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$isCurrentMonth = ($selectedMonth === date('Y-m'));
$monthName = date('F Y', strtotime($selectedMonth . '-01'));

$monthlySalesTotal = 0.0;
$monthSalesCount   = 0;
$sales7            = [];
$labels7           = [];
$catSales          = [];
$prodSales         = [];
$appointments      = [];
$lowStockItems     = [];
$recentSales       = [];

// 1. Monthly Sales for the selected month
try {
    $monthSalesStmt = $db->prepare("
        SELECT COALESCE(SUM(total),0) as total, COUNT(*) as count 
        FROM sales 
        WHERE DATE_FORMAT(created_at, '%Y-%m') = ? 
          AND (status = 'completed' OR status IS NULL OR status = '' OR status NOT IN ('voided', 'refunded', 'cancelled'))
    ");
    $monthSalesStmt->execute([$selectedMonth]);
    $monthSalesData = $monthSalesStmt->fetch();
    $monthlySalesTotal = (float)($monthSalesData['total'] ?? 0);
    $monthSalesCount   = (int)($monthSalesData['count'] ?? 0);
} catch (Exception $e) {
    error_log("Dashboard monthly sales error: " . $e->getMessage());
}

// 2. Sales chart data — last 7 days
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels7[] = date('M d', strtotime($date));
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(total),0) as total 
            FROM sales 
            WHERE DATE(created_at) = ? 
              AND (status = 'completed' OR status IS NULL OR status = '' OR status NOT IN ('voided', 'refunded', 'cancelled'))
        ");
        $stmt->execute([$date]);
        $sales7[] = round((float)($stmt->fetch()['total'] ?? 0), 2);
    } catch (Exception $e) {
        $sales7[] = 0.0;
    }
}

// 3. Category sales breakdown for the selected month (net revenue after discounts)
try {
    $catStmt = $db->prepare("
        SELECT COALESCE(c.name, CASE WHEN si.item_type = 'service' OR si.product_id IS NULL THEN 'Prescription & Services' ELSE 'Uncategorized' END) AS name, 
               ROUND(COALESCE(SUM(
                   CASE 
                       WHEN s.subtotal > 0 THEN (si.total_price * (s.total / s.subtotal)) 
                       ELSE si.total_price 
                   END
               ), 0), 2) as total
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id 
          AND (s.status = 'completed' OR s.status IS NULL OR s.status = '' OR s.status NOT IN ('voided', 'refunded', 'cancelled')) 
          AND DATE_FORMAT(s.created_at, '%Y-%m') = ?
        LEFT JOIN products p ON p.id = si.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        GROUP BY name
        HAVING total > 0
        ORDER BY total DESC
        LIMIT 8
    ");
    $catStmt->execute([$selectedMonth]);
    $catSales = $catStmt->fetchAll() ?: [];

    // Fallback: If item rows not found but monthly total exists, show General Sales
    if (empty($catSales) && $monthlySalesTotal > 0) {
        $catSales = [
            ['name' => 'General Sales', 'total' => $monthlySalesTotal]
        ];
    }
} catch (Exception $e) {
    error_log("Dashboard category sales error: " . $e->getMessage());
    if ($monthlySalesTotal > 0) {
        $catSales = [['name' => 'General Sales', 'total' => $monthlySalesTotal]];
    }
}

// 4. Product sales breakdown for the selected month (net revenue after discounts)
try {
    $prodStmt = $db->prepare("
        SELECT COALESCE(p.name, si.item_name, 'Other Item') AS name, 
               SUM(si.quantity) as units_sold,
               ROUND(COALESCE(SUM(
                   CASE 
                       WHEN s.subtotal > 0 THEN (si.total_price * (s.total / s.subtotal)) 
                       ELSE si.total_price 
                   END
               ), 0), 2) as total
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id 
          AND (s.status = 'completed' OR s.status IS NULL OR s.status = '' OR s.status NOT IN ('voided', 'refunded', 'cancelled')) 
          AND DATE_FORMAT(s.created_at, '%Y-%m') = ?
        LEFT JOIN products p ON p.id = si.product_id
        GROUP BY name
        HAVING total > 0
        ORDER BY total DESC
        LIMIT 8
    ");
    $prodStmt->execute([$selectedMonth]);
    $prodSales = $prodStmt->fetchAll() ?: [];

    // Fallback: If item rows not found but monthly total exists, show General Transactions
    if (empty($prodSales) && $monthlySalesTotal > 0) {
        $prodSales = [
            ['name' => 'General Transactions', 'units_sold' => $monthSalesCount, 'total' => $monthlySalesTotal]
        ];
    }
} catch (Exception $e) {
    error_log("Dashboard product sales error: " . $e->getMessage());
    if ($monthlySalesTotal > 0) {
        $prodSales = [['name' => 'General Transactions', 'units_sold' => $monthSalesCount, 'total' => $monthlySalesTotal]];
    }
}

// 5. Today's appointments
try {
    $todayAppts = $db->prepare("
        SELECT a.*, p.full_name as patient_name, p.phone
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        WHERE a.appointment_date = CURDATE()
        ORDER BY a.appointment_time ASC
        LIMIT 6
    ");
    $todayAppts->execute();
    $appointments = $todayAppts->fetchAll() ?: [];
} catch (Exception $e) {
    error_log("Dashboard appointments error: " . $e->getMessage());
}

// 6. Low stock products
try {
    $lowStockQuery = $db->query("
        SELECT p.name, p.stock_quantity, p.low_stock_alert, c.name as category
        FROM products p
        JOIN categories c ON c.id = p.category_id
        WHERE p.stock_quantity <= p.low_stock_alert AND p.status = 'active'
        ORDER BY p.stock_quantity ASC
        LIMIT 5
    ");
    $lowStockItems = $lowStockQuery ? ($lowStockQuery->fetchAll() ?: []) : [];
} catch (Exception $e) {
    error_log("Dashboard low stock error: " . $e->getMessage());
}

// 7. Recent sales
try {
    $recentSalesQuery = $db->query("
        SELECT s.*, p.full_name as patient_name, u.full_name as cashier_name
        FROM sales s
        LEFT JOIN patients p ON p.id = s.patient_id
        LEFT JOIN users u ON u.id = s.cashier_id
        ORDER BY s.created_at DESC
        LIMIT 5
    ");
    $recentSales = $recentSalesQuery ? ($recentSalesQuery->fetchAll() ?: []) : [];
} catch (Exception $e) {
    error_log("Dashboard recent sales error: " . $e->getMessage());
}

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/dashboard.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>

<!-- ─── Bento Top Metric Cards Row (4 Responsive Cards) ─── -->
<div class="row g-2 g-sm-3 mb-4">
  <!-- Card 1: Sales this month (Clickable Month Filter) -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat" style="--stat-color:#235EAE; --stat-rgb:35, 94, 174; cursor: pointer;" onclick="openDashMonthPicker()" title="Click to change month">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label d-flex align-items-center gap-1">
          <span><?= $isCurrentMonth ? 'Sales this month' : 'Sales (' . $monthName . ')' ?></span>
          <i class="fas fa-chevron-down fa-2xs opacity-75"></i>
        </div>
        <div class="bento-value"><?= formatCurrency($monthlySalesTotal) ?></div>
        <div class="bento-badge <?= $monthlySalesTotal > 0 ? 'up' : 'neutral' ?>">
          <?php if ($isCurrentMonth): ?>
            <i class="fas fa-arrow-trend-up"></i> <?= formatCurrency($stats['todaySales']) ?> today
          <?php else: ?>
            <i class="fas fa-receipt"></i> <?= $monthSalesCount ?> transaction<?= $monthSalesCount !== 1 ? 's' : '' ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon" title="Choose Month">
          <i class="fas fa-calendar-alt"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 2: Total Patients -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat" style="--stat-color:#0EA5E9; --stat-rgb:14, 165, 233;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Total Patients</div>
        <div class="bento-value"><?= number_format($stats['totalPatients']) ?></div>
        <div class="bento-badge blue">
          <i class="fas fa-user-check"></i> Active records
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-user-group"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 3: Today's Appointments -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat" style="--stat-color:#10B981; --stat-rgb:16, 185, 129;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Today's Appointments</div>
        <div class="bento-value"><?= number_format($stats['todayAppointments']) ?></div>
        <div class="bento-badge <?= $stats['pendingAppts'] > 0 ? 'warning' : 'green' ?>">
          <i class="fas fa-clock"></i> <?= number_format($stats['pendingAppts']) ?> pending
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-calendar-check"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 4: Low Stock Items -->
  <?php
  $isLowStock = ($stats['lowStock'] > 0);
  $stockColor = $isLowStock ? '#EF4444' : '#8B5CF6';
  $stockRgb   = $isLowStock ? '239, 68, 68' : '139, 92, 246';
  ?>
  <div class="col-6 col-lg-3">
    <div class="bento-stat" style="--stat-color:<?= $stockColor ?>; --stat-rgb:<?= $stockRgb ?>;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Low Stock Items</div>
        <div class="bento-value"><?= number_format($stats['lowStock']) ?></div>
        <div class="bento-badge <?= $isLowStock ? 'danger' : 'purple' ?>">
          <i class="fas fa-<?= $isLowStock ? 'triangle-exclamation' : 'boxes-stacked' ?>"></i>
          <?= $isLowStock ? 'Needs restocking' : 'Stock levels optimal' ?>
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-box-open"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ─── Modern Glassmorphic Month Picker Modal ───────────────── -->
<div id="dashMonthPickerOverlay" class="dash-mp-overlay" onclick="handleDashMpOverlayClick(event)">
  <div class="dash-mp-card" role="dialog" aria-modal="true" aria-labelledby="dashMpTitle">
    <!-- Header -->
    <div class="dash-mp-header">
      <div class="dash-mp-title-group">
        <div class="dash-mp-icon">
          <i class="fas fa-calendar-days"></i>
        </div>
        <div>
          <h5 class="dash-mp-heading" id="dashMpTitle">Select Sales Month</h5>
          <p class="dash-mp-subheading">Choose a month to view sales &amp; category analytics</p>
        </div>
      </div>
      <button type="button" class="dash-mp-close" onclick="closeDashMonthPicker()" title="Close picker" aria-label="Close">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- Body -->
    <div class="dash-mp-body">
      <!-- Year Stepper Bar -->
      <div class="dash-mp-year-bar">
        <button type="button" class="dash-mp-year-btn" id="dashMpPrevYear" onclick="changeDashMpYear(-1)" title="Previous Year">
          <i class="fas fa-chevron-left"></i>
        </button>
        <div class="dash-mp-year-display">
          <span id="dashMpYearText"><?= date('Y', strtotime($selectedMonth . '-01')) ?></span>
        </div>
        <button type="button" class="dash-mp-year-btn" id="dashMpNextYear" onclick="changeDashMpYear(1)" title="Next Year">
          <i class="fas fa-chevron-right"></i>
        </button>
      </div>

      <!-- 12-Month Interactive Grid -->
      <div class="dash-mp-grid" id="dashMpGrid">
        <!-- Rendered dynamically by JavaScript -->
      </div>
    </div>

    <!-- Footer Shortcuts -->
    <div class="dash-mp-footer">
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="dash-mp-quick-btn primary" onclick="goToDashMonth('<?= date('Y-m') ?>')" title="Jump to Current Month">
          <i class="fas fa-sparkles"></i> This Month
        </button>
        <?php
        $prevMonthVal = date('Y-m', strtotime('first day of last month'));
        $prevMonthName = date('M Y', strtotime('first day of last month'));
        ?>
        <button type="button" class="dash-mp-quick-btn" onclick="goToDashMonth('<?= $prevMonthVal ?>')" title="Jump to <?= $prevMonthName ?>">
          <i class="fas fa-arrow-rotate-left"></i> Last Month
        </button>
      </div>
      <button type="button" class="dash-mp-quick-btn" onclick="closeDashMonthPicker()">
        Cancel
      </button>
    </div>
  </div>
</div>

<!-- ─── Middle Charts Row (Bento Grid) ────────────────────── -->
<div class="row g-3 mb-4">
  <!-- Main Sales Trend Area Chart -->
  <div class="col-12 col-lg-7 col-xl-8">
    <div class="card h-100">
      <div class="card-header">
        <div>
          <h6><i class="fas fa-chart-line me-2" style="color:var(--clr-bronze)"></i>Sales Overview — Last 7 Days</h6>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="../admin/sales_reports.php" class="btn btn-sm btn-outline-primary">
            <span>View Report</span> <i class="fas fa-arrow-right fa-xs"></i>
          </a>
        </div>
      </div>
      <div class="card-body">
        <div id="dashSalesApex" style="min-height: 280px; width: 100%;"></div>
      </div>
    </div>
  </div>

  <!-- Sales Breakdown Donut Chart (Category & Product) -->
  <div class="col-12 col-lg-5 col-xl-4">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <h6 class="mb-0" id="breakdownHeading"><i class="fas fa-chart-pie me-2" style="color:var(--clr-gold)"></i>Sales by <span id="breakdownModeText">Category</span></h6>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div class="segmented-control" role="tablist" aria-label="Sales breakdown filter">
            <button type="button" class="segmented-btn active" id="btnBreakdownCat" role="tab" aria-selected="true" title="View Sales by Category">Category</button>
            <button type="button" class="segmented-btn" id="btnBreakdownProd" role="tab" aria-selected="false" title="View Sales by Product">Product</button>
          </div>
        </div>
      </div>
      <div class="card-body d-flex flex-column align-items-center justify-content-center p-3">
        <div id="dashCatApex" style="width: 100%; min-height: 280px;"></div>
      </div>
    </div>
  </div>
</div>

<!-- ─── Lower Row: Appointments & Low Stock ───────────────── -->
<div class="row g-3 mb-4">
  <!-- Today's Appointments -->
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-calendar-day me-2" style="color:var(--clr-success)"></i>Today's Appointments</h6>
        <a href="../admin/appointments.php" class="btn btn-sm btn-outline-primary">Manage</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Patient</th>
              <th>Time</th>
              <th>Purpose</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($appointments)): ?>
            <tr><td colspan="4" class="text-center py-4 text-muted">
              <i class="fas fa-calendar-check fa-2x mb-2 d-block opacity-50"></i>
              No appointments scheduled for today
            </td></tr>
            <?php else: ?>
            <?php foreach ($appointments as $appt): ?>
            <tr>
              <td>
                <div class="fw-bold"><?= sanitize($appt['patient_name']) ?></div>
                <small class="text-muted"><?= sanitize($appt['phone']) ?></small>
              </td>
              <td><span class="badge bg-secondary"><?= formatTime($appt['appointment_time']) ?></span></td>
              <td><?= ucwords(str_replace('_',' ',$appt['purpose'])) ?></td>
              <td><?= statusBadge($appt['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Low Stock Alerts -->
  <div class="col-12 col-xl-6">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-triangle-exclamation me-2" style="color:var(--clr-warning)"></i>Low Stock Alerts</h6>
        <a href="../admin/inventory.php" class="btn btn-sm btn-outline-primary">View Inventory</a>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th>Stock</th>
              <th>Alert At</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($lowStockItems)): ?>
            <tr><td colspan="4" class="text-center py-4 text-muted">
              <i class="fas fa-circle-check fa-2x mb-2 d-block text-success opacity-75"></i>
              All product stock levels are optimal!
            </td></tr>
            <?php else: ?>
            <?php foreach ($lowStockItems as $item): ?>
            <tr>
              <td class="fw-bold"><?= sanitize($item['name']) ?></td>
              <td><span class="badge bg-secondary"><?= sanitize($item['category']) ?></span></td>
              <td>
                <span class="badge <?= $item['stock_quantity'] == 0 ? 'badge-out-alert' : 'badge-low-alert' ?>">
                  <i class="fas fa-<?= $item['stock_quantity'] == 0 ? 'circle-xmark' : 'triangle-exclamation' ?> me-1"></i><?= $item['stock_quantity'] ?> left
                </span>
              </td>
              <td class="text-muted"><?= $item['low_stock_alert'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ─── Recent Transactions ───────────────────────────────── -->
<div class="card mb-4">
  <div class="card-header">
    <h6><i class="fas fa-receipt me-2" style="color:var(--clr-bronze)"></i>Recent Transactions</h6>
    <a href="../admin/sales_reports.php" class="btn btn-sm btn-outline-primary">All Sales</a>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Invoice #</th>
          <th>Patient</th>
          <th>Cashier</th>
          <th>Total</th>
          <th>Payment</th>
          <th>Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recentSales)): ?>
        <tr><td colspan="7" class="text-center py-4 text-muted">
          <i class="fas fa-receipt fa-2x mb-2 d-block opacity-50"></i>
          No sales transactions recorded yet
        </td></tr>
        <?php else: ?>
        <?php foreach ($recentSales as $sale): ?>
        <tr>
          <td><span class="fw-bold" style="color:var(--clr-bronze)"><?= sanitize($sale['invoice_no']) ?></span></td>
          <td><?= sanitize($sale['patient_name'] ?? 'Walk-in') ?></td>
          <td class="text-muted"><?= sanitize($sale['cashier_name']) ?></td>
          <td class="fw-bold text-success"><?= formatCurrency($sale['total']) ?></td>
          <td><span class="badge bg-info"><?= strtoupper($sale['payment_method']) ?></span></td>
          <td class="text-muted"><?= formatDateTime($sale['created_at']) ?></td>
          <td><?= statusBadge($sale['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$catNames   = array_column($catSales, 'name');
$catTotals  = array_map('floatval', array_column($catSales, 'total'));
$prodNames  = array_column($prodSales, 'name');
$prodTotals = array_map('floatval', array_column($prodSales, 'total'));
$prodUnits  = array_map('intval', array_column($prodSales, 'units_sold'));

$sales7Json    = json_encode($sales7);
$labels7Json   = json_encode($labels7);
$catNamesJson  = json_encode($catNames);
$catTotalsJson = json_encode($catTotals);
$prodNamesJson = json_encode($prodNames);
$prodTotalsJson= json_encode($prodTotals);
$prodUnitsJson = json_encode($prodUnits);
$currRealMonthJson = json_encode(date('Y-m'));
$activeMonthJson   = json_encode($selectedMonth);

$extraScripts = <<<HTML
<script>
document.addEventListener("DOMContentLoaded", function() {
  function getThemeColors() {
    const isDark = document.documentElement.getAttribute("data-theme") === "dark";
    return {
      isDark: isDark,
      mode: isDark ? "dark" : "light",
      bgCard: isDark ? "#1A2642" : "#FFFFFF",
      textPrimary: isDark ? "#FFFFFF" : "#18181B",
      textSecondary: isDark ? "#F1F5F9" : "#374151",
      textMuted: isDark ? "#CBD5E1" : "#64748B",
      borderColor: isDark ? "rgba(255, 255, 255, 0.14)" : "rgba(0, 0, 0, 0.06)",
      valColor: isDark ? "#FFFFFF" : "#18181B"
    };
  }

  let tc = getThemeColors();

  // 1. Sales Line / Area Chart
  const salesOptions = {
    series: [{
      name: "Sales (₱)",
      data: {$sales7Json}
    }],
    chart: {
      type: "area",
      height: 280,
      toolbar: { show: false },
      fontFamily: "Plus Jakarta Sans, Poppins, sans-serif",
      background: "transparent",
      dropShadow: { enabled: true, top: 3, left: 0, blur: 5, color: "#235EAE", opacity: 0.25 }
    },
    theme: { mode: tc.mode },
    colors: ["#235EAE"],
    fill: {
      type: "gradient",
      gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 90, 100] }
    },
    dataLabels: { enabled: false },
    stroke: { curve: "smooth", width: 3 },
    xaxis: {
      categories: {$labels7Json},
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { style: { colors: tc.textMuted, fontSize: "12px", fontWeight: 500 } }
    },
    yaxis: {
      min: 0,
      forceNiceScale: true,
      labels: {
        formatter: (val) => "₱" + Math.round(val).toLocaleString(),
        style: { colors: tc.textMuted, fontSize: "12px", fontWeight: 500 }
      }
    },
    grid: {
      borderColor: tc.borderColor,
      strokeDashArray: 4,
      padding: { left: 10, right: 10, top: 0, bottom: 0 }
    },
    tooltip: {
      theme: tc.mode,
      style: { fontSize: "12px" },
      y: { formatter: (val) => "₱" + val.toLocaleString() }
    }
  };

  const salesEl = document.querySelector("#dashSalesApex");
  let salesChart = null;
  if (salesEl) {
    salesChart = new ApexCharts(salesEl, salesOptions);
    salesChart.render();
  }

  // 2. Sales Breakdown Donut Chart (Category / Product)
  let currentBreakdown = "category";
  const catNames = {$catNamesJson};
  const catTotals = {$catTotalsJson};
  const prodNames = {$prodNamesJson};
  const prodTotals = {$prodTotalsJson};
  const prodUnits = {$prodUnitsJson};

  // Vibrant modern multi-color palette for categories and products
  const modernPalette = [
    "#3B82F6", // Royal Blue
    "#10B981", // Emerald Green
    "#F59E0B", // Radiant Amber Gold
    "#8B5CF6", // Electric Purple
    "#F43F5E", // Rose Coral
    "#06B6D4", // Luminous Cyan
    "#E09A67", // Warm Bronze
    "#EC4899"  // Pink
  ];

  function getBreakdownConfig(mode) {
    const isCat = (mode === "category");
    const names = isCat ? catNames : prodNames;
    const totals = isCat ? catTotals : prodTotals;
    const hasData = totals.some(t => t > 0);
    const labelTitle = isCat ? "Category Total" : "Product Total";

    return {
      hasData: hasData,
      series: hasData ? totals : [1],
      labels: hasData ? names : ["No Sales Recorded"],
      colors: hasData ? modernPalette.slice(0, Math.max(names.length, 1)) : [tc.isDark ? "#27272A" : "#E2E8F0"],
      totalLabel: labelTitle
    };
  }

  let currentConfig = getBreakdownConfig(currentBreakdown);

  const catOptions = {
    series: currentConfig.series,
    labels: currentConfig.labels,
    chart: {
      type: "donut",
      height: 280,
      fontFamily: "Plus Jakarta Sans, Poppins, sans-serif",
      background: "transparent",
      toolbar: { show: false },
      animations: {
        enabled: true,
        easing: "easeinout",
        speed: 400,
        dynamicAnimation: { enabled: true, speed: 350 }
      }
    },
    theme: { mode: tc.mode },
    colors: currentConfig.colors,
    stroke: {
      show: true,
      width: 3,
      colors: [tc.bgCard]
    },
    plotOptions: {
      pie: {
        donut: {
          size: "74%",
          labels: {
            show: true,
            name: {
              show: true,
              fontSize: "13px",
              fontWeight: 600,
              color: tc.textPrimary,
              offsetY: -4
            },
            value: {
              show: true,
              fontSize: "20px",
              fontWeight: 800,
              color: tc.valColor,
              offsetY: 6,
              formatter: (val) => currentConfig.hasData ? "₱" + parseFloat(val).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) : "₱0.00"
            },
            total: {
              show: true,
              label: currentConfig.totalLabel,
              fontSize: "12px",
              fontWeight: 600,
              color: tc.textMuted,
              formatter: (w) => {
                if (!currentConfig.hasData) return "₱0.00";
                const total = w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                return "₱" + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
              }
            }
          }
        }
      }
    },
    dataLabels: { enabled: false },
    legend: {
      position: "bottom",
      fontSize: "12px",
      fontWeight: 600,
      itemMargin: { horizontal: 8, vertical: 4 },
      labels: { colors: tc.textSecondary },
      markers: { width: 10, height: 10, radius: 10 }
    },
    tooltip: {
      theme: tc.mode,
      style: { fontSize: "12px", fontFamily: "inherit" },
      y: {
        formatter: (val, opt) => {
          if (!currentConfig.hasData) return "₱0.00";
          let formatted = "₱" + parseFloat(val).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
          if (currentBreakdown === "product" && opt && typeof opt.dataPointIndex === "number" && prodUnits[opt.dataPointIndex] !== undefined) {
            formatted += " (" + prodUnits[opt.dataPointIndex] + " sold)";
          }
          return formatted;
        }
      }
    }
  };

  const catEl = document.querySelector("#dashCatApex");
  let catChart = null;
  if (catEl) {
    catChart = new ApexCharts(catEl, catOptions);
    catChart.render();
  }

  function switchBreakdown(mode) {
    currentBreakdown = mode;
    currentConfig = getBreakdownConfig(mode);

    const btnCat = document.getElementById("btnBreakdownCat");
    const btnProd = document.getElementById("btnBreakdownProd");
    const modeText = document.getElementById("breakdownModeText");

    if (mode === "category") {
      btnCat.classList.add("active");
      btnCat.setAttribute("aria-selected", "true");
      btnProd.classList.remove("active");
      btnProd.setAttribute("aria-selected", "false");
      if (modeText) modeText.textContent = "Category";
    } else {
      btnProd.classList.add("active");
      btnProd.setAttribute("aria-selected", "true");
      btnCat.classList.remove("active");
      btnCat.setAttribute("aria-selected", "false");
      if (modeText) modeText.textContent = "Product";
    }

    if (catChart) {
      catChart.updateOptions({
        series: currentConfig.series,
        labels: currentConfig.labels,
        colors: currentConfig.colors,
        plotOptions: {
          pie: {
            donut: {
              labels: {
                total: {
                  label: currentConfig.totalLabel
                }
              }
            }
          }
        }
      });
    }
  }

  const btnCat = document.getElementById("btnBreakdownCat");
  const btnProd = document.getElementById("btnBreakdownProd");
  if (btnCat) btnCat.addEventListener("click", () => switchBreakdown("category"));
  if (btnProd) btnProd.addEventListener("click", () => switchBreakdown("product"));

  // Dynamic Theme Switcher synchronization for charts
  window.addEventListener("themeChanged", (e) => {
    tc = getThemeColors();
    currentConfig = getBreakdownConfig(currentBreakdown);

    if (salesChart) {
      salesChart.updateOptions({
        theme: { mode: tc.mode },
        xaxis: { labels: { style: { colors: tc.textMuted } } },
        yaxis: { labels: { style: { colors: tc.textMuted } } },
        grid: { borderColor: tc.borderColor },
        tooltip: { theme: tc.mode }
      });
    }

    if (catChart) {
      catChart.updateOptions({
        theme: { mode: tc.mode },
        colors: currentConfig.colors,
        stroke: { colors: [tc.bgCard] },
        legend: { labels: { colors: tc.textSecondary } },
        tooltip: { theme: tc.mode },
        plotOptions: {
          pie: {
            donut: {
              labels: {
                name: { color: tc.textPrimary },
                value: { color: tc.valColor },
                total: { 
                  color: tc.textMuted,
                  label: currentConfig.totalLabel
                }
              }
            }
          }
        }
      });
    }
  });

  // ─── Modern Luxury Month Picker Controller ────────────────
  const currentRealMonth = {$currRealMonthJson};
  const activeSelectedMonth = {$activeMonthJson};
  let displayedMpYear = parseInt(activeSelectedMonth.split('-')[0], 10) || new Date().getFullYear();

  const mpMonthList = [
    { code: 'Jan', full: 'January', num: '01' },
    { code: 'Feb', full: 'February', num: '02' },
    { code: 'Mar', full: 'March', num: '03' },
    { code: 'Apr', full: 'April', num: '04' },
    { code: 'May', full: 'May', num: '05' },
    { code: 'Jun', full: 'June', num: '06' },
    { code: 'Jul', full: 'July', num: '07' },
    { code: 'Aug', full: 'August', num: '08' },
    { code: 'Sep', full: 'September', num: '09' },
    { code: 'Oct', full: 'October', num: '10' },
    { code: 'Nov', full: 'November', num: '11' },
    { code: 'Dec', full: 'December', num: '12' }
  ];

  function renderDashMonthPicker() {
    const yearText = document.getElementById('dashMpYearText');
    const grid = document.getElementById('dashMpGrid');
    if (!yearText || !grid) return;

    yearText.textContent = displayedMpYear;

    let html = '';
    mpMonthList.forEach(function(m) {
      const monthKey = displayedMpYear + '-' + m.num;
      const isSelected = (monthKey === activeSelectedMonth);
      const isCurrent = (monthKey === currentRealMonth);

      let classes = 'dash-mp-month-btn' + (isSelected ? ' is-selected' : '');
      let currentTag = isCurrent ? '<span class="dash-mp-current-tag" title="Current Month"></span>' : '';

      html += '<button type="button" class="' + classes + '" onclick="goToDashMonth(\'' + monthKey + '\')" title="' + m.full + ' ' + displayedMpYear + '">' +
                currentTag +
                '<span class="dash-mp-mcode">' + m.code + '</span>' +
                '<span class="dash-mp-mname">' + m.full + '</span>' +
              '</button>';
    });
    grid.innerHTML = html;
  }

  window.openDashMonthPicker = function() {
    displayedMpYear = parseInt(activeSelectedMonth.split('-')[0], 10) || new Date().getFullYear();
    renderDashMonthPicker();
    const overlay = document.getElementById('dashMonthPickerOverlay');
    if (overlay) {
      overlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    }
  };

  window.closeDashMonthPicker = function() {
    const overlay = document.getElementById('dashMonthPickerOverlay');
    if (overlay) {
      overlay.classList.remove('active');
      document.body.style.overflow = '';
    }
  };

  window.handleDashMpOverlayClick = function(e) {
    if (e.target && e.target.id === 'dashMonthPickerOverlay') {
      closeDashMonthPicker();
    }
  };

  window.changeDashMpYear = function(delta) {
    displayedMpYear += delta;
    renderDashMonthPicker();
  };

  window.goToDashMonth = function(monthKey) {
    if (monthKey === activeSelectedMonth) {
      closeDashMonthPicker();
      return;
    }
    window.location.href = 'dashboard.php?month=' + encodeURIComponent(monthKey);
  };

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeDashMonthPicker();
    }
  });

  // Initial render
  renderDashMonthPicker();
});
</script>
HTML;

include __DIR__ . '/../includes/footer.php';