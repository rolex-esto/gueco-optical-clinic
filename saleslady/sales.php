<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');
$pageTitle  = 'Sales History';
$breadcrumb = ['Saleslady', 'Sales'];
$db = getDB();
ensureJobOrderSchema($db);
ensureAppointmentsSchema($db);

$filterFrom = $_GET['from'] ?? date('Y-m-d');
$filterTo   = $_GET['to']   ?? date('Y-m-d');
$page       = max(1,(int)($_GET['page']??1)); $perPage = 15;

$total = $db->prepare("SELECT COUNT(*) as c FROM sales WHERE DATE(created_at) BETWEEN ? AND ?");
$total->execute([$filterFrom,$filterTo]); $total = $total->fetch()['c'];
$pg = paginate($total,$perPage,$page);

$todaySales = $db->prepare("SELECT COALESCE(SUM(total),0) as t, COUNT(*) as c FROM sales WHERE DATE(created_at)=? AND (status='completed' OR status IS NULL OR status = '' OR status NOT IN ('voided','refunded','cancelled'))");
$todaySales->execute([date('Y-m-d')]); $todaySales = $todaySales->fetch();

$limit = (int)$perPage;
$offset = (int)$pg['offset'];
$sales = $db->prepare("
    SELECT s.*, p.full_name as patient_name, u.full_name as cashier_name
    FROM sales s LEFT JOIN patients p ON p.id=s.patient_id LEFT JOIN users u ON u.id=s.cashier_id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    ORDER BY s.created_at DESC LIMIT $limit OFFSET $offset
");
$sales->execute([$filterFrom,$filterTo]); $sales = $sales->fetchAll() ?: [];

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/dashboard.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>
<!-- Summary -->
<div class="row g-3 mb-4">
  <!-- Today's Revenue -->
  <div class="col-lg-4 col-md-6">
    <div class="bento-stat" style="--stat-color:#10B981; --stat-rgb:16, 185, 129;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Today's Revenue</div>
        <div class="bento-value"><?= formatCurrency($todaySales['t']) ?></div>
        <div class="bento-badge green">
          <i class="fas fa-coins"></i> Today's earnings
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-peso-sign"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Transactions Today -->
  <div class="col-lg-4 col-md-6">
    <div class="bento-stat" style="--stat-color:#0EA5E9; --stat-rgb:14, 165, 233;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Transactions Today</div>
        <div class="bento-value"><?= $todaySales['c'] ?></div>
        <div class="bento-badge blue">
          <i class="fas fa-receipt"></i> Processed today
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-cash-register"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- In Selected Range -->
  <div class="col-lg-4 col-md-6">
    <div class="bento-stat" style="--stat-color:#8B5CF6; --stat-rgb:139, 92, 246;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">In Selected Range</div>
        <div class="bento-value"><?= $total ?></div>
        <div class="bento-badge purple">
          <i class="fas fa-calendar-check"></i> Filtered results
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-chart-bar"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-body" style="padding:14px 20px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div style="flex:1;min-width:130px;"><label class="form-label" style="margin-bottom:5px">From</label><input type="date" name="from" class="form-control" value="<?= $filterFrom ?>"></div>
      <div style="flex:1;min-width:130px;"><label class="form-label" style="margin-bottom:5px">To</label><input type="date" name="to" class="form-control" value="<?= $filterTo ?>"></div>
      <div style="display:flex;gap:6px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
        <a href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary">Today</a>
        <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary">This Month</a>
      </div>
    </form>
  </div>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Invoice</th><th>Patient</th><th>Cashier</th><th>Subtotal</th><th>Discount</th><th>Total</th><th>Method</th><th>Date/Time</th><th>Status</th><th style="text-align:right;">Receipt</th></tr></thead>
      <tbody>
        <?php if (empty($sales)): ?>
        <tr><td colspan="10"><div class="empty-state"><div class="empty-icon"><i class="fas fa-receipt"></i></div><h6>No sales in this period</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($sales as $s): ?>
        <tr>
          <td><span style="font-family:monospace;font-size:.78rem;color:var(--clr-primary);font-weight:700"><?= sanitize($s['invoice_no']) ?></span></td>
          <td style="font-size:.82rem"><?= sanitize($s['patient_name']??'Walk-in') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= sanitize($s['cashier_name']) ?></td>
          <td style="font-size:.83rem"><?= formatCurrency($s['subtotal']) ?></td>
          <td style="font-size:.8rem;color:var(--clr-warning)"><?= $s['discount']>0?formatCurrency($s['discount']):'—' ?></td>
          <td style="font-weight:800;color:var(--clr-success)"><?= formatCurrency($s['total']) ?></td>
          <td><span class="badge bg-info"><?= strtoupper($s['payment_method']) ?></span></td>
          <td style="font-size:.75rem;color:var(--text-muted)"><?= formatDateTime($s['created_at']) ?></td>
          <td><?= statusBadge($s['status']) ?></td>
          <td style="text-align:right;">
            <a href="receipt.php?id=<?= $s['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm py-1 px-2" title="Print Receipt">
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
  <div style="padding:14px 20px;border-top:1px solid var(--border-light);">
    <div class="pagination">
      <?php if ($pg['has_prev']): ?><a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p=1;$p<=$pg['total_pages'];$p++): ?><a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($pg['has_next']): ?><a href="?from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
