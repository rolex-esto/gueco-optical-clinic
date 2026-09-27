<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Sales Reports';
$breadcrumb = ['Admin', 'Sales Reports'];
$db = getDB();
ensureJobOrderSchema($db);
ensureAppointmentsSchema($db);

// Date filter
$filterFrom = $_GET['from'] ?? date('Y-m-01');
$filterTo   = $_GET['to']   ?? date('Y-m-d');

// Automatically fix backwards dates
if (strtotime($filterFrom) > strtotime($filterTo)) {
    $temp = $filterFrom;
    $filterFrom = $filterTo;
    $filterTo = $temp;
}
$groupBy    = $_GET['group'] ?? 'day';

// Summary totals
$summary = $db->prepare("
    SELECT COUNT(*) as total_tx, COALESCE(SUM(total),0) as total_sales, COALESCE(SUM(discount),0) as total_discount
    FROM sales 
    WHERE DATE(created_at) BETWEEN ? AND ? 
      AND (status = 'completed' OR status IS NULL OR status = '' OR status NOT IN ('voided', 'refunded', 'cancelled'))
");
$summary->execute([$filterFrom, $filterTo]); $summary = $summary->fetch();

// Chart data by day
$chartLabels = []; $chartData = [];
$d = new DateTime($filterFrom);
$end = new DateTime($filterTo);
while ($d <= $end) {
    $date = $d->format('Y-m-d');
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total),0) as t 
        FROM sales 
        WHERE DATE(created_at) = ? 
          AND (status = 'completed' OR status IS NULL OR status = '' OR status NOT IN ('voided', 'refunded', 'cancelled'))
    ");
    $stmt->execute([$date]); $chartData[] = round($stmt->fetch()['t'],2);
    $chartLabels[] = $d->format('M d');
    $d->modify('+1 day');
}

// Payment method breakdown
$payBreak = $db->prepare("
    SELECT payment_method, COUNT(*) as count, COALESCE(SUM(total),0) as total
    FROM sales 
    WHERE DATE(created_at) BETWEEN ? AND ? 
      AND (status = 'completed' OR status IS NULL OR status = '' OR status NOT IN ('voided', 'refunded', 'cancelled'))
    GROUP BY payment_method ORDER BY total DESC
");
$payBreak->execute([$filterFrom, $filterTo]);
$payBreak = $payBreak->fetchAll();

// Top selling products (accounting for discount proportions and custom Rx/services)
$topProds = $db->prepare("
    SELECT COALESCE(p.name, si.item_name, 'Other Item') as name, 
           COALESCE(p.product_code, '—') as product_code, 
           SUM(si.quantity) as units_sold,
           SUM(CASE WHEN s.subtotal > 0 THEN (si.total_price * (s.total / s.subtotal)) ELSE si.total_price END) as revenue
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    LEFT JOIN products p ON p.id = si.product_id
    WHERE DATE(s.created_at) BETWEEN ? AND ? 
      AND (s.status = 'completed' OR s.status IS NULL OR s.status = '' OR s.status NOT IN ('voided', 'refunded', 'cancelled'))
    GROUP BY name, product_code ORDER BY units_sold DESC LIMIT 5
");
$topProds->execute([$filterFrom, $filterTo]);
$topProds = $topProds->fetchAll();

// Total transactions count for pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$total = $db->prepare("SELECT COUNT(*) as c FROM sales WHERE DATE(created_at) BETWEEN ? AND ?");
$total->execute([$filterFrom, $filterTo]);
$total = $total->fetch()['c'];
$pg = paginate($total, $perPage, $page);

$limit = (int)$perPage;
$offset = (int)$pg['offset'];
$sales = $db->prepare("
    SELECT s.*, p.full_name as patient_name, u.full_name as cashier_name
    FROM sales s LEFT JOIN patients p ON p.id=s.patient_id JOIN users u ON u.id=s.cashier_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    ORDER BY s.created_at DESC LIMIT $limit OFFSET $offset
");
$sales->execute([$filterFrom, $filterTo]);
$sales = $sales->fetchAll() ?: [];

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/sales_reports.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>

<!-- Filter -->
<div class="card sales-filter-card mb-4">
  <div class="card-body p-3 p-md-4">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-12 col-sm-6 col-md-3">
        <label class="form-label mb-1 fw-bold text-xs text-uppercase" style="letter-spacing:0.05em; color:var(--text-muted);"><i class="far fa-calendar-alt me-1 text-primary"></i> Date From</label>
        <input type="date" name="from" class="form-control" value="<?= $filterFrom ?>">
      </div>
      <div class="col-12 col-sm-6 col-md-3">
        <label class="form-label mb-1 fw-bold text-xs text-uppercase" style="letter-spacing:0.05em; color:var(--text-muted);"><i class="far fa-calendar-check me-1 text-primary"></i> Date To</label>
        <input type="date" name="to" class="form-control" value="<?= $filterTo ?>">
      </div>
      <div class="col-12 col-md-6 d-flex flex-wrap gap-2 align-items-center justify-content-md-end mt-3 mt-md-0">
        <button type="submit" class="btn btn-primary px-3 shadow-sm">
          <i class="fas fa-filter me-1"></i> Apply
        </button>
        <?php 
          $isThisMonth = ($filterFrom === date('Y-m-01') && $filterTo === date('Y-m-d'));
          $isToday     = ($filterFrom === date('Y-m-d') && $filterTo === date('Y-m-d'));
        ?>
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-this-month px-3 <?= $isThisMonth ? 'active' : '' ?>">
          <i class="far fa-calendar-alt me-1"></i> This Month
        </a>
        <a href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-today px-3 <?= $isToday ? 'active' : '' ?>">
          <i class="fas fa-sun me-1"></i> Today
        </a>
        <a href="print_sales_report.php?from=<?= $filterFrom ?>&to=<?= $filterTo ?>" target="_blank" class="btn btn-danger px-3 shadow-sm">
          <i class="fas fa-file-pdf me-1"></i> Export PDF
        </a>
      </div>
    </form>
  </div>
</div>


<!-- Summary Cards (Modern Bento Grid) -->
<div class="row g-3 mb-4">
  <!-- Card 1: Total Revenue -->
  <div class="col-lg-4 col-md-6 col-sm-12">
    <div class="bento-stat" style="--stat-color:#10B981; --stat-rgb:16, 185, 129;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Total Revenue</div>
        <div class="bento-value text-success"><?= formatCurrency($summary['total_sales']) ?></div>
        <div class="bento-badge green">
          <i class="fas fa-arrow-trend-up"></i> Net Completed Sales
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-peso-sign"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 2: Total Transactions -->
  <div class="col-lg-4 col-md-6 col-sm-12">
    <div class="bento-stat" style="--stat-color:#00ADEF; --stat-rgb:0, 173, 239;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Total Transactions</div>
        <div class="bento-value"><?= number_format($summary['total_tx']) ?></div>
        <div class="bento-badge blue">
          <i class="fas fa-receipt"></i> Recorded Invoices
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-file-invoice"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 3: Total Discounts Given -->
  <div class="col-lg-4 col-md-12 col-sm-12">
    <div class="bento-stat" style="--stat-color:#A855F7; --stat-rgb:168, 85, 247;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Total Discounts Given</div>
        <div class="bento-value" style="color:#C084FC;"><?= formatCurrency($summary['total_discount']) ?></div>
        <div class="bento-badge" style="background:rgba(168,85,247,0.14);color:#C084FC;border:1px solid rgba(168,85,247,0.35);">
          <i class="fas fa-tags"></i> Customer Deductions
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-percent"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4">
  <div class="col-xl-8 col-lg-7 col-12">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">
          <i class="fas fa-chart-area me-2" style="color:var(--clr-primary-light);"></i>Daily Sales Revenue
        </h6>
        <span class="badge bg-primary-soft font-monospace">
          <?= formatDate($filterFrom) ?> — <?= formatDate($filterTo) ?>
        </span>
      </div>
      <div class="card-body">
        <div id="salesChartContainer" style="min-height:280px; margin-top:5px;"></div>
      </div>
    </div>
  </div>
  <div class="col-xl-4 col-lg-5 col-12">
    <div class="card h-100">
      <div class="card-header">
        <h6 class="mb-0 fw-bold">
          <i class="fas fa-wallet me-2" style="color:var(--clr-primary-light);"></i>Payment Breakdown
        </h6>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <?php if (empty($payBreak)): ?>
          <div style="height:250px; display:flex; flex-direction:column; justify-content:center; align-items:center; color:var(--text-muted); opacity:0.6;">
            <i class="fas fa-chart-pie" style="font-size:3rem; margin-bottom:15px;"></i>
            <p style="font-size:0.95rem; margin:0; font-weight:500;">No transactions yet</p>
          </div>
        <?php else: ?>
          <div id="payChartContainer" style="width:100%; min-height:260px;"></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Top Products + Sales Table -->
<div class="row g-3 mb-4">
  <div class="col-xl-4 col-lg-5 col-12">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fas fa-fire me-2" style="color:#F59E0B;"></i>Top Selling Products</h6>
        <span class="badge bg-warning-soft">By Units</span>
      </div>
      <div class="card-body p-3">
        <?php if (empty($topProds)): ?>
        <div class="sales-34c8a2">No product sales recorded for this period</div>
        <?php else: ?>
        <div class="d-flex flex-column gap-2">
        <?php foreach ($topProds as $i => $tp): 
          $rankBadge = match($i) {
            0 => '<span class="sales-rank-badge rank-1"><i class="fas fa-trophy"></i></span>',
            1 => '<span class="sales-rank-badge rank-2"><i class="fas fa-medal"></i></span>',
            2 => '<span class="sales-rank-badge rank-3"><i class="fas fa-award"></i></span>',
            default => '<span class="sales-rank-badge rank-other">' . ($i+1) . '</span>'
          };
        ?>
        <div class="sales-product-item">
          <?= $rankBadge ?>
          <div class="sales-8b56c7">
            <div class="sales-262d15" title="<?= sanitize($tp['name']) ?>"><?= sanitize($tp['name']) ?></div>
            <div class="sales-46d9fd"><i class="fas fa-boxes-stacked me-1 opacity-75"></i><?= $tp['units_sold'] ?> unit<?= $tp['units_sold'] != 1 ? 's' : '' ?> sold</div>
          </div>
          <div class="sales-c58223"><?= formatCurrency($tp['revenue']) ?></div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-8 col-lg-7 col-12">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fas fa-receipt me-2" style="color:var(--clr-primary-light);"></i>All Transactions</h6>
        <span class="badge bg-primary-soft font-monospace"><?= number_format($total) ?> Total</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Patient</th>
              <th>Cashier</th>
              <th>Total</th>
              <th>Discount</th>
              <th>Method</th>
              <th>Date</th>
              <th>Status</th>
              <th style="text-align:right;">Receipt</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($sales)): ?>
            <tr><td colspan="9"><div class="empty-state sales-35a11d"><div class="empty-icon"><i class="fas fa-receipt"></i></div><h6>No transactions recorded</h6></div></td></tr>
            <?php else: ?>
            <?php foreach ($sales as $s): ?>
            <tr>
              <td><span class="sales-invoice-pill"><?= sanitize($s['invoice_no']) ?></span></td>
              <td class="sales-0de4e7">
                <i class="far fa-user-circle me-1 text-muted"></i><?= sanitize($s['patient_name'] ?? 'Walk-in') ?>
              </td>
              <td class="sales-67fd48"><?= sanitize($s['cashier_name']) ?></td>
              <td class="sales-c0f652"><?= formatCurrency($s['total']) ?></td>
              <td class="sales-26dfd7"><?= $s['discount']>0?formatCurrency($s['discount']):'—' ?></td>
              <td>
                <?php
                  $method = strtoupper($s['payment_method']);
                  $methodClass = match($method) {
                    'CASH' => 'badge-cash',
                    'GCASH' => 'badge-gcash',
                    'CARD' => 'badge-card',
                    default => 'bg-info'
                  };
                ?>
                <span class="badge <?= $methodClass ?>"><?= $method ?></span>
              </td>
              <td class="sales-d44d31"><?= formatDateTime($s['created_at']) ?></td>
              <td><?= statusBadge($s['status']) ?></td>
              <td style="text-align:right;">
                <a href="../saleslady/receipt.php?id=<?= $s['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm py-1 px-2" title="Print Receipt">
                  <i class="fas fa-print me-1"></i> Receipt
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pg['total_pages'] > 1): ?>
      <div class="sales-4174a0">
        <div class="pagination">
          <?php if ($pg['has_prev']): ?><a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
          <?php for ($p=1;$p<=$pg['total_pages'];$p++): ?>
          <a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($pg['has_next']): ?><a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$payLabels = array_map(fn($p) => strtoupper($p['payment_method']), $payBreak);
$payData   = array_map(fn($p) => (float)$p['total'], $payBreak);
$extraScripts = '<script>
const themeMode = document.documentElement.getAttribute("data-theme") === "dark" ? "dark" : "light";
  
const salesOptions = {
    series: [{ name: "Sales (₱)", data: ' . json_encode($chartData) . ' }],
    chart: { 
        type: "area", 
        height: 300, 
        toolbar: { show: false }, 
        fontFamily: "Poppins, sans-serif", 
        background: "transparent",
        dropShadow: { enabled: true, top: 2, left: 0, blur: 5, color: "#00ADEF", opacity: 0.3 }
    },
    theme: { mode: themeMode },
    colors: ["#00ADEF"],
    fill: { type: "gradient", gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05, stops: [0, 90, 100] } },
    dataLabels: { enabled: false },
    stroke: { curve: "smooth", width: 3 },
    xaxis: { 
        categories: ' . json_encode($chartLabels) . ', 
        tickAmount: 6,
        labels: { 
            rotate: 0,
            trim: true,
            style: { colors: "var(--text-muted)", fontSize: "12px", cssClass: "apexcharts-xaxis-label" } 
        },
        axisBorder: { show: false }, 
        axisTicks: { show: false },
        tooltip: { enabled: false }
    },
    yaxis: { 
        min: 0,
        forceNiceScale: true,
        decimalsInFloat: 0,
        labels: { 
            formatter: (value) => { return "₱" + Math.round(value).toLocaleString() },
            style: { colors: "var(--text-muted)", fontSize: "12px" }
        } 
    },
    grid: { borderColor: "rgba(150, 150, 150, 0.12)", strokeDashArray: 4, padding: { left: 15, right: 15, bottom: 5, top: 10 } },
    tooltip: { theme: themeMode, style: { fontSize: "13px" }, y: { formatter: function (val) { return "₱" + val.toLocaleString() } } }
};
if (document.querySelector("#salesChartContainer")) {
  new ApexCharts(document.querySelector("#salesChartContainer"), salesOptions).render();
}

const payOptions = {
  series: ' . json_encode($payData) . ',
  chart: { type: "donut", height: 270, fontFamily: "Poppins, sans-serif", background: "transparent" },
  labels: ' . json_encode($payLabels) . ',
  theme: { mode: themeMode },
  colors: ["#00ADEF", "#10B981", "#A855F7", "#F59E0B"],
  plotOptions: { pie: { donut: { size: "72%" } } },
  dataLabels: { enabled: false },
  stroke: { show: true, colors: [themeMode === "dark" ? "#1A2642" : "#FFFFFF"], width: 2 },
  legend: { position: "bottom", fontSize: "13px", labels: { colors: "var(--text-muted)" } },
  tooltip: { theme: themeMode, style: { fontSize: "13px" } }
};
if (document.querySelector("#payChartContainer")) {
  new ApexCharts(document.querySelector("#payChartContainer"), payOptions).render();
}
</script>';
include __DIR__ . '/../includes/footer.php';
?>