<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';

// Allow saleslady, doctor, and admin
requireRole('saleslady', 'doctor', 'admin');

$db = getDB();
ensureJobOrderSchema($db);
$saleId = (int)($_GET['id'] ?? 0);

if ($saleId <= 0) {
    die("Invalid transaction ID.");
}

$sale = $db->prepare("
    SELECT s.*, 
           p.full_name as patient_name, 
           p.phone as patient_phone, 
           p.email as patient_email, 
           p.address as patient_address,
           u.full_name as cashier_name
    FROM sales s
    LEFT JOIN patients p ON p.id = s.patient_id
    JOIN users u ON u.id = s.cashier_id
    WHERE s.id = ?
");
$sale->execute([$saleId]);
$sale = $sale->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    die("Transaction record not found.");
}

// Fetch items
$items = $db->prepare("
    SELECT si.*, COALESCE(p.name, si.item_name) as product_name, c.name as category_name
    FROM sale_items si
    LEFT JOIN products p ON p.id = si.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");
$items->execute([$saleId]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// Fetch linked or latest prescription
$rx = null;
if (!empty($sale['prescription_id'])) {
    $rxStmt = $db->prepare("
        SELECT rx.*, u.full_name as doctor_name 
        FROM prescriptions rx 
        LEFT JOIN users u ON u.id = rx.doctor_id 
        WHERE rx.id = ?
    ");
    $rxStmt->execute([$sale['prescription_id']]);
    $rx = $rxStmt->fetch(PDO::FETCH_ASSOC);
} elseif (!empty($sale['patient_id'])) {
    $rxStmt = $db->prepare("
        SELECT rx.*, u.full_name as doctor_name 
        FROM prescriptions rx 
        LEFT JOIN users u ON u.id = rx.doctor_id 
        WHERE rx.patient_id = ? 
        ORDER BY rx.created_at DESC LIMIT 1
    ");
    $rxStmt->execute([$sale['patient_id']]);
    $rx = $rxStmt->fetch(PDO::FETCH_ASSOC);
}

$jobOrderNo = !empty($sale['job_order_no']) ? $sale['job_order_no'] : ('JO-' . date('Ymd', strtotime($sale['created_at'])) . '-' . str_pad($sale['id'], 4, '0', STR_PAD_LEFT));
$pickupDate = !empty($sale['target_pickup_date']) ? date('M d, Y (l)', strtotime($sale['target_pickup_date'])) : 'Ready Upon Mounting';
$autoPrint  = isset($_GET['auto_print']) && $_GET['auto_print'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Optical Job Slip - <?= sanitize($jobOrderNo) ?> - Gueco Optical Clinic</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --clr-primary: #1e3a8a;
      --clr-accent: #2563eb;
      --clr-warning: #b45309;
      --clr-danger: #dc2626;
      --clr-success: #15803d;
      --text-main: #0f172a;
      --text-muted: #475569;
      --border-color: #cbd5e1;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      background-color: #525659;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: var(--text-main);
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 100vh;
      padding-bottom: 40px;
    }

    .pdf-toolbar {
      width: 100%;
      background: #1e293b;
      color: #f1f5f9;
      padding: 10px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 10px rgba(0,0,0,0.3);
      position: sticky;
      top: 0;
      z-index: 100;
    }

    .pdf-btn {
      background: #334155;
      color: #fff;
      border: 1px solid #475569;
      border-radius: 6px;
      padding: 6px 14px;
      font-size: 0.85rem;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 500;
      transition: background 0.15s;
    }

    .pdf-btn:hover { background: #475569; color: #fff; }
    .pdf-btn-primary { background: #2563eb; border-color: #1d4ed8; }
    .pdf-btn-primary:hover { background: #1d4ed8; }

    .slip-canvas {
      width: 210mm;
      min-height: 297mm;
      background: #fff;
      margin: 20px auto;
      padding: 24px 28px;
      box-shadow: 0 6px 24px rgba(0,0,0,0.35);
      border-radius: 4px;
    }

    .slip-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      border-bottom: 2px solid var(--clr-primary);
      padding-bottom: 14px;
      margin-bottom: 16px;
    }

    .clinic-brand h2 {
      font-size: 1.4rem;
      font-weight: 800;
      color: var(--clr-primary);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .clinic-brand p {
      font-size: 0.75rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .job-badge-block {
      text-align: right;
    }
    .job-badge-title {
      font-size: 0.95rem;
      font-weight: 800;
      color: #fff;
      background: var(--clr-primary);
      padding: 4px 10px;
      border-radius: 4px;
      display: inline-block;
      letter-spacing: 0.05em;
    }
    .job-no-str {
      font-family: monospace;
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--clr-accent);
      margin-top: 4px;
    }

    .meta-box-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 16px;
    }

    .info-card {
      border: 1px solid var(--border-color);
      border-radius: 6px;
      padding: 10px 12px;
      background: #f8fafc;
      font-size: 0.8rem;
    }
    .info-card h6 {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      font-weight: 700;
      margin-bottom: 6px;
      border-bottom: 1px solid var(--border-color);
      padding-bottom: 4px;
    }
    .info-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 4px;
    }
    .info-label { color: var(--text-muted); }
    .info-val { font-weight: 600; color: var(--text-main); }

    .section-title {
      font-size: 0.82rem;
      font-weight: 800;
      color: var(--clr-primary);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin: 12px 0 6px 0;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    /* Optical Rx Table */
    .rx-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 14px;
      font-size: 0.82rem;
    }
    .rx-table th, .rx-table td {
      border: 1px solid #94a3b8;
      padding: 6px 8px;
      text-align: center;
    }
    .rx-table th {
      background: #e2e8f0;
      color: #1e293b;
      font-weight: 700;
      font-size: 0.75rem;
    }
    .rx-table .eye-row-title {
      background: #f1f5f9;
      font-weight: 800;
      color: var(--clr-primary);
    }
    .rx-table .val-highlight {
      font-weight: 700;
      color: #0f172a;
    }

    /* Lab Items Table */
    .lab-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.8rem;
      margin-bottom: 14px;
    }
    .lab-table th, .lab-table td {
      border: 1px solid #cbd5e1;
      padding: 6px 8px;
    }
    .lab-table th {
      background: #f1f5f9;
      text-align: left;
      font-size: 0.72rem;
      text-transform: uppercase;
    }

    /* Financials & Balance Box */
    .payment-summary-card {
      border: 2px solid var(--clr-primary);
      border-radius: 6px;
      padding: 10px 14px;
      background: #eff6ff;
      margin-bottom: 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .pay-item { text-align: center; }
    .pay-item-label { font-size: 0.72rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
    .pay-item-val { font-size: 1.05rem; font-weight: 800; margin-top: 2px; }

    /* Lab Checklist */
    .checklist-box {
      border: 1px dashed #94a3b8;
      border-radius: 6px;
      padding: 10px 14px;
      background: #fafafa;
      font-size: 0.78rem;
      margin-bottom: 16px;
    }
    .check-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px 14px;
      margin-top: 6px;
    }
    .check-item { display: flex; align-items: center; gap: 8px; }
    .check-square {
      width: 14px;
      height: 14px;
      border: 1.5px solid #475569;
      display: inline-block;
    }

    .sign-row {
      display: flex;
      justify-content: space-between;
      margin-top: 26px;
      font-size: 0.75rem;
    }
    .sign-col {
      width: 200px;
      text-align: center;
      border-top: 1px solid #475569;
      padding-top: 4px;
      font-weight: 600;
    }

    @media print {
      @page {
        size: A4 portrait;
        margin: 8mm 12mm;
      }
      body {
        background: #fff !important;
        padding: 0 !important;
      }
      .pdf-toolbar {
        display: none !important;
      }
      .slip-canvas {
        box-shadow: none !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
      }
    }
  </style>
</head>
<body>

  <!-- Top PDF Toolbar -->
  <div class="pdf-toolbar">
    <div style="font-weight:700;">
      <i class="fas fa-tools text-primary me-2"></i>Optical Lab Mounting Job Slip &middot; <?= sanitize($jobOrderNo) ?>
    </div>
    <div style="display:flex; gap:10px;">
      <button type="button" onclick="window.print()" class="pdf-btn pdf-btn-primary">
        <i class="fas fa-print"></i> Print Job Slip
      </button>
      <a href="receipt.php?id=<?= $sale['id'] ?>" class="pdf-btn">
        <i class="fas fa-file-invoice"></i> View Sales Receipt
      </a>
      <a href="pos.php" class="pdf-btn">
        <i class="fas fa-shopping-cart"></i> POS
      </a>
      <button type="button" onclick="window.close()" class="pdf-btn">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>

  <div class="slip-canvas">
    
    <!-- Header -->
    <div class="slip-header">
      <div class="clinic-brand">
        <h2>Gueco Optical Clinic</h2>
        <p>Optical Laboratory & Lens Mounting Job Slip &middot; Angeles City, Pampanga</p>
        <p style="font-size:0.7rem; color:var(--text-muted); margin-top:1px;">Contact: 0917-123-4567 | Email: guecooptical@gmail.com</p>
      </div>
      <div class="job-badge-block">
        <span class="job-badge-title"><i class="fas fa-clipboard-list me-1"></i> LAB MOUNTING ORDER</span>
        <div class="job-no-str"><?= sanitize($jobOrderNo) ?></div>
        <div style="font-size:0.72rem; color:var(--text-muted);">Invoice: #<?= sanitize($sale['invoice_no']) ?></div>
      </div>
    </div>

    <!-- Metadata Grid -->
    <div class="meta-box-grid">
      <div class="info-card">
        <h6>Patient & Notification Contact</h6>
        <div class="info-row">
          <span class="info-label">Patient Name:</span>
          <span class="info-val"><?= sanitize($sale['patient_name'] ?? 'Walk-in Customer') ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Contact Mobile:</span>
          <span class="info-val" style="color:var(--clr-accent);"><?= sanitize($sale['patient_phone'] ?: 'N/A') ?></span>
        </div>
        <?php if (!empty($sale['patient_address'])): ?>
        <div class="info-row">
          <span class="info-label">Address:</span>
          <span class="info-val"><?= sanitize($sale['patient_address']) ?></span>
        </div>
        <?php endif; ?>
      </div>

      <div class="info-card">
        <h6>Order & Schedule Information</h6>
        <div class="info-row">
          <span class="info-label">Order Date:</span>
          <span class="info-val"><?= date('M d, Y &middot; h:i A', strtotime($sale['created_at'])) ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Target Pickup Date:</span>
          <span class="info-val" style="color:var(--clr-warning); font-weight:800; font-size:0.85rem;"><?= $pickupDate ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Attending Cashier:</span>
          <span class="info-val"><?= sanitize($sale['cashier_name']) ?></span>
        </div>
        <div class="info-row">
          <span class="info-label">Examining Optometrist:</span>
          <span class="info-val"><?= sanitize($rx['doctor_name'] ?? 'Clinic Optometrist') ?></span>
        </div>
      </div>
    </div>

    <!-- Optical Refraction Specifications -->
    <div class="section-title"><i class="fas fa-glasses"></i> Refraction & Lens Parameters</div>
    <table class="rx-table">
      <thead>
        <tr>
          <th style="width: 70px;">EYE</th>
          <th>SPHERE (SPH)</th>
          <th>CYLINDER (CYL)</th>
          <th>AXIS</th>
          <th>ADD POWER</th>
          <th>V.A.</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="eye-row-title">OD (Right)</td>
          <td class="val-highlight"><?= !empty($rx['od_sphere']) ? sanitize($rx['od_sphere']) : 'Plano' ?></td>
          <td class="val-highlight"><?= !empty($rx['od_cylinder']) ? sanitize($rx['od_cylinder']) : '—' ?></td>
          <td class="val-highlight"><?= !empty($rx['od_axis']) ? sanitize($rx['od_axis']) . '°' : '—' ?></td>
          <td class="val-highlight"><?= !empty($rx['od_add']) ? sanitize($rx['od_add']) : (!empty($rx['add_power']) ? sanitize($rx['add_power']) : '—') ?></td>
          <td><?= !empty($rx['od_va']) ? sanitize($rx['od_va']) : '20/20' ?></td>
        </tr>
        <tr>
          <td class="eye-row-title">OS (Left)</td>
          <td class="val-highlight"><?= !empty($rx['os_sphere']) ? sanitize($rx['os_sphere']) : 'Plano' ?></td>
          <td class="val-highlight"><?= !empty($rx['os_cylinder']) ? sanitize($rx['os_cylinder']) : '—' ?></td>
          <td class="val-highlight"><?= !empty($rx['os_axis']) ? sanitize($rx['os_axis']) . '°' : '—' ?></td>
          <td class="val-highlight"><?= !empty($rx['os_add']) ? sanitize($rx['os_add']) : (!empty($rx['add_power']) ? sanitize($rx['add_power']) : '—') ?></td>
          <td><?= !empty($rx['os_va']) ? sanitize($rx['os_va']) : '20/20' ?></td>
        </tr>
      </tbody>
    </table>

    <div style="display:flex; justify-content:space-between; font-size:0.8rem; margin-bottom:14px; background:#f8fafc; border:1px solid #e2e8f0; padding:8px 12px; border-radius:6px;">
      <div>
        <strong>Pupillary Distance (PD):</strong> 
        <span style="font-weight:700; color:var(--clr-accent);">
          <?= !empty($rx['pd']) ? $rx['pd'] . ' mm' : (!empty($rx['pd_right']) ? 'R: ' . $rx['pd_right'] . ' | L: ' . ($rx['pd_left']??'') . ' mm' : 'Standard (62 mm)') ?>
        </span>
      </div>
      <div>
        <strong>Lens Type / Coating:</strong> 
        <span style="font-weight:700;">
          <?= !empty($rx['lens_type']) ? sanitize($rx['lens_type']) : 'Single Vision Multicoated' ?>
        </span>
      </div>
      <?php if (!empty($rx['notes'])): ?>
      <div style="max-width:35%;">
        <strong>Clinical Instructions:</strong> 
        <span style="font-style:italic;"><?= sanitize($rx['notes']) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Items to Fabricate / Mount -->
    <div class="section-title"><i class="fas fa-cubes"></i> Order Items & Hardware Details</div>
    <table class="lab-table">
      <thead>
        <tr>
          <th style="width:30px; text-align:center;">#</th>
          <th>Item / Lens Description</th>
          <th style="width:120px;">Category</th>
          <th style="width:50px; text-align:center;">Qty</th>
          <th>Mounting Notes / Parameters</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $item): ?>
        <tr>
          <td style="text-align:center; color:var(--text-muted);"><?= $i+1 ?></td>
          <td style="font-weight:600;"><?= sanitize($item['product_name']) ?></td>
          <td style="color:var(--text-muted);"><?= sanitize($item['category_name'] ?: 'Optical') ?></td>
          <td style="text-align:center; font-weight:700;"><?= $item['quantity'] ?></td>
          <td style="font-size:0.75rem; color:#334155;"><?= sanitize($item['notes'] ?? 'Mount to specification') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Payment & Deposit Status Card -->
    <?php
      $isDownpayment = ($sale['payment_type'] ?? '') === 'downpayment';
      $totalAmt   = (float)$sale['total'];
      $depositAmt = (float)($sale['deposit_amount'] > 0 ? $sale['deposit_amount'] : ($isDownpayment ? $sale['amount_paid'] : $totalAmt));
      $balanceDue = max(0, (float)($sale['balance_due'] > 0 ? $sale['balance_due'] : ($totalAmt - $depositAmt)));
    ?>
    <div class="payment-summary-card">
      <div class="pay-item">
        <div class="pay-item-label">Total Job Amount</div>
        <div class="pay-item-val" style="color:var(--clr-primary);"><?= formatCurrency($totalAmt) ?></div>
      </div>
      <div class="pay-item">
        <div class="pay-item-label">Payment Type</div>
        <div class="pay-item-val" style="color:<?= $isDownpayment ? 'var(--clr-warning)' : 'var(--clr-success)' ?>;">
          <?= $isDownpayment ? 'Downpayment Deposit' : 'Full Payment Paid' ?>
        </div>
      </div>
      <div class="pay-item">
        <div class="pay-item-label">Amount Paid / Deposit</div>
        <div class="pay-item-val" style="color:var(--clr-success);"><?= formatCurrency($depositAmt) ?></div>
      </div>
      <div class="pay-item">
        <div class="pay-item-label">Balance Due Upon Pickup</div>
        <div class="pay-item-val" style="color:<?= $balanceDue > 0 ? 'var(--clr-danger)' : 'var(--clr-success)' ?>;">
          <?= formatCurrency($balanceDue) ?>
        </div>
      </div>
    </div>

    <!-- Laboratory Quality Checklist -->
    <div class="checklist-box">
      <strong style="color:var(--clr-primary); font-size:0.78rem; text-transform:uppercase;">
        <i class="fas fa-check-square me-1"></i> Optical Mounting Quality Control Checklist
      </strong>
      <div class="check-grid">
        <div class="check-item"><span class="check-square"></span> Power verified via Lensometer</div>
        <div class="check-item"><span class="check-square"></span> Optical Axis and Centers Aligned</div>
        <div class="check-item"><span class="check-square"></span> Lens Bevel & Edging Flawless</div>
        <div class="check-item"><span class="check-square"></span> Frame Screws & Temple Tension Checked</div>
        <div class="check-item"><span class="check-square"></span> Ultrasonic Cleaning & Scratch Inspection</div>
        <div class="check-item"><span class="check-square"></span> Patient Pickup Notification Sent (SMS/Call)</div>
      </div>
    </div>

    <!-- Sign-off Block -->
    <div class="sign-row">
      <div class="sign-col">
        Optometrist / Attending Staff<br>
        <span style="font-weight:400; color:var(--text-muted); font-size:0.7rem;"><?= sanitize($sale['cashier_name']) ?></span>
      </div>
      <div class="sign-col">
        Mounting Lab Technician<br>
        <span style="font-weight:400; color:var(--text-muted); font-size:0.7rem;">Date Completed & Initial</span>
      </div>
      <div class="sign-col">
        Customer Claim Confirmation<br>
        <span style="font-weight:400; color:var(--text-muted); font-size:0.7rem;">Signature upon receiving eyeglass</span>
      </div>
    </div>

  </div>

  <?php if ($autoPrint): ?>
  <script>
    window.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => { window.print(); }, 400);
    });
  </script>
  <?php endif; ?>

</body>
</html>
