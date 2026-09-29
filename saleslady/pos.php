<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');

$pageTitle  = 'Point of Sale';
$breadcrumb = ['Saleslady', 'POS'];
$db = getDB();
ensureJobOrderSchema($db);
ensureAppointmentsSchema($db);
ensureFinancialComplianceSchema($db);

// Process sale submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_sale') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Security validation failed (CSRF). Please refresh the page.']);
        exit;
    }
    $patientId        = (int)($_POST['patient_id'] ?? 0) ?: null;
    $items            = json_decode($_POST['items'] ?? '[]', true);
    $payMethod        = $_POST['payment_method'] ?? 'cash';
    $discount         = (float)($_POST['discount'] ?? 0);
    $amountPaid       = (float)($_POST['amount_paid'] ?? 0);
    $generateJobOrder = (int)($_POST['generate_job_order'] ?? 0);
    $paymentType      = in_array($_POST['payment_type'] ?? '', ['full', 'downpayment'], true) ? $_POST['payment_type'] : 'full';
    $depositAmount    = (float)($_POST['deposit_amount'] ?? 0);
    $targetPickupDate = !empty($_POST['target_pickup_date']) ? sanitize($_POST['target_pickup_date']) : null;
    $prescriptionId   = (int)($_POST['prescription_id'] ?? 0) ?: null;
    $patientPhone     = sanitize($_POST['patient_phone'] ?? '');
    $appointmentId    = (int)($_POST['appointment_id'] ?? 0) ?: null;

    // If appointment_id wasn't directly passed, find most recent active or uncompleted appointment for this patient
    if ($appointmentId === null && $patientId > 0) {
        $findAppt = $db->prepare("
            SELECT id FROM appointments 
            WHERE patient_id = ? 
            ORDER BY (status IN ('confirmed','in_progress','pending')) DESC, id DESC 
            LIMIT 1
        ");
        $findAppt->execute([$patientId]);
        $appointmentId = $findAppt->fetchColumn() ?: null;
    }

    // If generating a job order and prescription_id wasn't directly sent, link latest prescription silently
    if ($generateJobOrder === 1 && $prescriptionId === null && $patientId > 0) {
        $latestRxStmt = $db->prepare("SELECT id FROM prescriptions WHERE patient_id=? ORDER BY created_at DESC LIMIT 1");
        $latestRxStmt->execute([$patientId]);
        $prescriptionId = $latestRxStmt->fetchColumn() ?: null;
    }

    if (empty($items)) {
        echo json_encode(['success'=>false,'error'=>'Cart is empty. Please add items to proceed.']);
        exit;
    }

    // Calculate subtotal and build item details
    $subtotal = 0;
    $itemsDetailed = [];
    $hasRxItem = ($prescriptionId !== null) || ($generateJobOrder === 1);

    foreach ($items as $rawItem) {
        $rawId = (string)($rawItem['id'] ?? '');
        $isRx = !empty($rawItem['is_rx']) || str_starts_with($rawId, 'rx_');
        
        if ($isRx) {
            $hasRxItem = true;
            $itemPrice = (float)($rawItem['price'] ?? 1500);
            $itemQty   = max(1, (int)($rawItem['qty'] ?? 1));
            $itemTotal = (float)($itemPrice * $itemQty);
            $subtotal += $itemTotal;

            $itemsDetailed[] = [
                'id'         => null,
                'product_id' => null,
                'name'       => sanitize($rawItem['name'] ?? 'Prescription Lenses'),
                'qty'        => $itemQty,
                'price'      => $itemPrice,
                'total'      => $itemTotal,
                'is_rx'      => true,
                'notes'      => sanitize($rawItem['notes'] ?? 'Prescription Lenses Fabricated to Rx')
            ];
        } else {
            $prodId = (int)$rawId;
            $prod = $db->prepare("SELECT * FROM products WHERE id=? AND status='active'");
            $prod->execute([$prodId]); 
            $prod = $prod->fetch();
            if (!$prod) { 
                echo json_encode(['success'=>false,'error'=>'Product not found or inactive: ID #'.$prodId]); 
                exit; 
            }
            if ($prod['stock_quantity'] < (int)$rawItem['qty']) {
                echo json_encode(['success'=>false,'error'=>"Insufficient stock for: {$prod['name']}. Available stock: {$prod['stock_quantity']}"]); 
                exit; 
            }
            $itemPrice = (float)$prod['price'];
            $itemQty   = (int)$rawItem['qty'];
            $itemTotal = (float)($itemPrice * $itemQty);
            $subtotal += $itemTotal;

            $itemsDetailed[] = [
                'id'         => $prodId,
                'product_id' => $prodId,
                'name'       => $prod['name'],
                'qty'        => $itemQty,
                'price'      => $itemPrice,
                'total'      => $itemTotal,
                'is_rx'      => false,
                'notes'      => null
            ];
        }
    }

    $discountType     = in_array($_POST['discount_type'] ?? '', ['none', 'flat', 'senior_pwd'], true) ? $_POST['discount_type'] : 'none';
    $seniorId         = sanitize($_POST['senior_id'] ?? '');

    // VAT & Discount Computation
    $vatRate = 0.12;
    $vatableSales = 0;
    $vatAmount = 0;
    $vatExemptSales = 0;
    
    if ($discountType === 'senior_pwd') {
        // SC/PWD: Subtotal / 1.12 = VAT Exempt Sales
        $vatExemptSales = round($subtotal / (1 + $vatRate), 2);
        // 20% discount on VAT Exempt Sales
        $discount = round($vatExemptSales * 0.20, 2);
        $total = max(0, $vatExemptSales - $discount);
        $vatableSales = 0;
        $vatAmount = 0;
    } else {
        // Flat discount or None
        if ($discountType === 'none') {
            $discount = 0;
        }
        $total = max(0, $subtotal - $discount);
        // Compute VAT backwards from Total
        $vatableSales = round($total / (1 + $vatRate), 2);
        $vatAmount = $total - $vatableSales;
        $vatExemptSales = 0;
    }

    // Validation: Disallow finalizing a prescription checkout without patient contact details
    if ($hasRxItem || $generateJobOrder === 1) {
        if (!$patientId) {
            echo json_encode([
                'success' => false,
                'error'   => 'A registered or walk-in patient is required for Optical Job Orders. Anonymous checkout is not permitted for prescription orders.'
            ]);
            exit;
        }

        $ptRow = $db->prepare("SELECT full_name, phone FROM patients WHERE id=?");
        $ptRow->execute([$patientId]);
        $ptData = $ptRow->fetch(PDO::FETCH_ASSOC);

        $contactPhone = !empty($patientPhone) ? $patientPhone : ($ptData['phone'] ?? '');
        $cleanPhone = preg_replace('/[^0-9]/', '', $contactPhone);

        if (!empty($patientPhone) && preg_match('/^09\d{9}$/', $cleanPhone)) {
            // Update patient phone if provided in form
            $db->prepare("UPDATE patients SET phone=? WHERE id=?")->execute([$cleanPhone, $patientId]);
        }

        if (!preg_match('/^09\d{9}$/', $cleanPhone)) {
            echo json_encode([
                'success' => false,
                'error'   => 'Patient mobile contact number (11 digits starting with 09, e.g. 09171234567) is required for Optical Job Order pickup notifications.'
            ]);
            exit;
        }
    }

    // Payment Type & Balance Due calculation
    if ($paymentType === 'downpayment') {
        if ($depositAmount <= 0) {
            $depositAmount = round($total / 2, 2); // Default to 50%
        }
        $depositAmount = min($total, $depositAmount);
        $balanceDue = max(0, $total - $depositAmount);
        $orderStatus = 'in_progress';
        if ($payMethod !== 'cash') {
            $amountPaid = $depositAmount;
        }
        $change = max(0, $amountPaid - $depositAmount);
    } else {
        $paymentType = 'full';
        $depositAmount = $total;
        $balanceDue = 0.00;
        $orderStatus = 'completed';
        if ($payMethod !== 'cash') {
            $amountPaid = $total;
        }
        $change = max(0, $amountPaid - $total);
    }

    // Generate invoice and Job Order numbers
    $invDate = date('Ymd');
    $lastInv = $db->query("SELECT MAX(id) as m FROM sales")->fetch()['m'] ?? 0;
    $nextIdNum = $lastInv + 1;
    $invoiceNo = 'GOC-' . $invDate . '-' . str_pad($nextIdNum, 4, '0', STR_PAD_LEFT);
    $jobOrderNo = ($hasRxItem || $generateJobOrder === 1) ? ('JO-' . $invDate . '-' . str_pad($nextIdNum, 4, '0', STR_PAD_LEFT)) : null;

    $patientName = 'Walk-in Customer';
    if ($patientId > 0) {
        $ptRow = $db->prepare("SELECT full_name, phone FROM patients WHERE id=?");
        $ptRow->execute([$patientId]);
        $ptRow = $ptRow->fetch();
        if ($ptRow) {
            $patientName = $ptRow['full_name'];
        }
    }

    $dbPaymentMethod = in_array($payMethod, ['cash', 'gcash']) ? $payMethod : 'other';

    $db->beginTransaction();
    try {
        // Insert sale
        $saleStmt = $db->prepare("
            INSERT INTO sales (
                invoice_no, patient_id, cashier_id, appointment_id,
                subtotal, discount_type, senior_id, discount, total, 
                vatable_sales, vat_amount, vat_exempt_sales,
                payment_method, payment_type, deposit_amount, balance_due, 
                target_pickup_date, prescription_id, job_order_no, order_status,
                amount_paid, change_amount, status
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'completed')
        ");
        $saleStmt->execute([
            $invoiceNo, $patientId, $_SESSION['user_id'], $appointmentId,
            $subtotal, $discountType, $seniorId, $discount, $total,
            $vatableSales, $vatAmount, $vatExemptSales,
            $dbPaymentMethod, $paymentType, $depositAmount, $balanceDue, 
            $targetPickupDate, $prescriptionId, $jobOrderNo, $orderStatus,
            $amountPaid, $change
        ]);
        $saleId = $db->lastInsertId();

        // Insert items + deduct stock for inventory products
        foreach ($itemsDetailed as $item) {
            $itemType = $item['is_rx'] ? 'service' : 'product';
            $db->prepare("INSERT INTO sale_items (sale_id, product_id, item_name, item_type, quantity, unit_price, total_price, notes) VALUES (?,?,?, ?,?,?,?,?)")
               ->execute([$saleId, $item['product_id'], $item['name'], $itemType, $item['qty'], $item['price'], $item['total'], $item['notes']]);

            if ($item['product_id'] !== null) {
                // Deduct inventory stock
                $prevStock = $db->prepare("SELECT stock_quantity FROM products WHERE id=?"); 
                $prevStock->execute([$item['product_id']]); 
                $prevStock = (int)$prevStock->fetch()['stock_quantity'];
                $newStock = max(0, $prevStock - $item['qty']);
                
                $db->prepare("UPDATE products SET stock_quantity=? WHERE id=?")->execute([$newStock, $item['product_id']]);
                $db->prepare("INSERT INTO inventory_logs (product_id,type,quantity,previous_stock,new_stock,reason,reference_id,user_id) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$item['product_id'], 'stock_out', $item['qty'], $prevStock, $newStock, "Sale: $invoiceNo", $saleId, $_SESSION['user_id']]);
            }
        }

        // Update linked appointment to 'completed'
        if ($appointmentId > 0) {
            $upAppt = $db->prepare("UPDATE appointments SET status='completed' WHERE id=?");
            $upAppt->execute([$appointmentId]);
            $upAppt->closeCursor();
        } elseif ($patientId > 0) {
            $upAppt = $db->prepare("UPDATE appointments SET status='completed' WHERE patient_id=? AND status IN ('confirmed','in_progress','pending')");
            $upAppt->execute([$patientId]);
            $upAppt->closeCursor();
        }

        // Auto-create future Eyeglass Claim appointment on target pickup date
        if (!empty($targetPickupDate) && $patientId > 0) {
            $claimNotes = "Eyeglass Claim & Fitting for Job Order #" . ($jobOrderNo ?: $invoiceNo) . " (Invoice: $invoiceNo).";
            if ($balanceDue > 0) {
                $claimNotes .= " Balance Due to collect: ₱" . number_format($balanceDue, 2);
            } else {
                $claimNotes .= " Paid in Full.";
            }
            $insClaim = $db->prepare("
                INSERT INTO appointments (patient_id, appointment_date, appointment_time, appointment_type, purpose, status, notes, verified_by, created_at)
                VALUES (?, ?, '14:00:00', 'SCHEDULED', 'eyeglass_claim', 'confirmed', ?, ?, NOW())
            ");
            $insClaim->execute([$patientId, $targetPickupDate, $claimNotes, $_SESSION['user_id']]);
        }

        $db->commit();

        $logMsg = "Completed sale $invoiceNo for $patientName (Total: " . formatCurrency($total) . ", Type: " . strtoupper($paymentType);
        if ($jobOrderNo) {
            $logMsg .= ", Job Order #$jobOrderNo, Balance Due: " . formatCurrency($balanceDue);
        }
        $logMsg .= ")";
        logActivity($logMsg, "Sales / POS", $_SESSION['user_id'], 'staff');

        echo json_encode([
            'success'            => true,
            'invoice_no'         => $invoiceNo,
            'sale_id'            => $saleId,
            'job_order_no'       => $jobOrderNo,
            'has_job_order'      => !empty($jobOrderNo),
            'target_pickup_date' => $targetPickupDate ? date('M d, Y', strtotime($targetPickupDate)) : null,
            'payment_type'       => $paymentType,
            'deposit_amount'     => $depositAmount,
            'balance_due'        => $balanceDue,
            'date'               => date('M d, Y h:i A'),
            'cashier'            => $_SESSION['user_name'] ?? 'Staff Cashier',
            'patient'            => $patientName,
            'items'              => $itemsDetailed,
            'subtotal'           => $subtotal,
            'discount'           => $discount,
            'total'              => $total,
            'payment_method'     => strtoupper($payMethod),
            'amount_paid'        => $amountPaid,
            'change'             => $change
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success'=>false,'error'=>'Transaction failed: '.$e->getMessage()]);
    }
    exit;
}

// Search products AJAX
if (isset($_GET['search_products'])) {
    $q = '%' . sanitize($_GET['search_products']) . '%';
    $prods = $db->prepare("SELECT p.id, p.name, p.base_model, p.variant_name, p.product_code, p.price, p.stock_quantity, p.image, p.tier, c.name as category FROM products p JOIN categories c ON c.id=p.category_id WHERE (p.name LIKE ? OR c.name LIKE ? OR p.base_model LIKE ? OR p.variant_name LIKE ?) AND p.status='active' AND p.stock_quantity>0 ORDER BY COALESCE(p.base_model, p.name), p.name LIMIT 50");
    $prods->execute([$q,$q,$q,$q]);
    header('Content-Type: application/json');
    echo json_encode($prods->fetchAll());
    exit;
}

// Get all products by category for initial load
ensureProductVariantSchema($db);
$categories = $db->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.status='active' AND p.stock_quantity>0) as prod_count FROM categories c WHERE c.status='active' ORDER BY c.name")->fetchAll();
$allProducts = $db->query("SELECT p.id,p.name,p.base_model,p.variant_name,p.product_code,p.price,p.stock_quantity,p.image,p.tier,p.category_id,c.name as category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status='active' AND p.stock_quantity>0 ORDER BY c.name,COALESCE(p.base_model,p.name) ASC,p.name ASC")->fetchAll();

// Group products by base_model if identical base model exists
$groupedProducts = [];
foreach ($allProducts as $p) {
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

$today = date('Y-m-d');
$activeAppointments = [];
try {
    ensureAppointmentsSchema($db);
    $activeAppointments = $db->query("
        SELECT a.id as appointment_id,
               a.patient_id,
               a.appointment_type,
               a.status,
               a.appointment_time,
               p.full_name,
               p.phone,
               (SELECT rx.id FROM prescriptions rx WHERE rx.patient_id = a.patient_id ORDER BY rx.created_at DESC LIMIT 1) as latest_rx_id
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        WHERE a.appointment_date = '$today'
          AND a.status IN ('confirmed', 'in_progress', 'completed')
        ORDER BY 
            CASE a.status
                WHEN 'completed' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'confirmed' THEN 3
                ELSE 4
            END,
            a.appointment_time DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("POS activeAppointments query error: " . $e->getMessage());
    $activeAppointments = [];
}

$patients = $db->query("SELECT id, full_name, phone FROM patients WHERE status='active' ORDER BY full_name")->fetchAll();
$selectedPatientId = (int)($_GET['patient_id'] ?? 0);
$selectedApptId    = (int)($_GET['appt_id'] ?? 0);

if ($selectedApptId > 0 && !$selectedPatientId) {
    $apptPatientStmt = $db->prepare("SELECT patient_id FROM appointments WHERE id=?");
    $apptPatientStmt->execute([$selectedApptId]);
    $selectedPatientId = (int)$apptPatientStmt->fetchColumn() ?: 0;
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.pos-layout {
  display: grid;
  grid-template-columns: 1fr 420px;
  gap: 24px;
  align-items: start;
}
@media (max-width: 1024px) {
  .pos-layout {
    grid-template-columns: 1fr;
  }
}
.pos-cart-card {
  background: var(--bg-card);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
  position: sticky;
  top: 15px;
  display: flex;
  flex-direction: column;
}
.pos-cart-list {
  min-height: 100px;
  max-height: 215px; /* Fits 4 items cleanly, 5+ scrolls */
  overflow-y: auto;
  padding: 6px 14px;
  scrollbar-width: thin;
  scrollbar-color: var(--clr-primary) rgba(0, 0, 0, 0.05);
}
.pos-cart-list::-webkit-scrollbar {
  width: 6px;
}
.pos-cart-list::-webkit-scrollbar-track {
  background: rgba(0, 0, 0, 0.04);
  border-radius: 4px;
}
.pos-cart-list::-webkit-scrollbar-thumb {
  background: var(--clr-primary);
  border-radius: 4px;
}
.pos-cart-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 0;
  border-bottom: 1px solid var(--border-light);
  transition: background 0.15s ease;
}
.pos-cart-item:last-child {
  border-bottom: none;
}
.pos-qty-btn {
  width: 26px;
  height: 26px;
  border-radius: 6px;
  border: 1px solid var(--border-color);
  background: var(--bg-hover);
  cursor: pointer;
  font-size: 0.75rem;
  font-weight: bold;
  color: var(--text-primary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
}
.pos-qty-btn:hover {
  background: var(--clr-primary);
  color: #fff;
  border-color: var(--clr-primary);
}
.pos-remove-btn {
  background: none;
  border: none;
  color: var(--clr-danger);
  cursor: pointer;
  font-size: 0.85rem;
  padding: 4px 6px;
  border-radius: 4px;
  transition: background 0.15s ease;
}
.pos-remove-btn:hover {
  background: rgba(239, 68, 68, 0.12);
}
.quick-cash-btn {
  background: var(--bg-hover);
  border: 1px solid var(--border-color);
  color: var(--text-primary);
  font-size: 0.72rem;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.quick-cash-btn:hover {
  background: var(--clr-primary);
  color: #fff;
  border-color: var(--clr-primary);
}
/* ── Searchable Patient Dropdown ── */
#patientDropdownWrap { position: relative; }
.patient-dd-item { transition: background .1s ease; }
#patientDropdownPanel { animation: ddSlideIn .13s ease; }
@keyframes ddSlideIn {
  from { opacity: 0; transform: translateY(-5px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ── Modern POS Product Cards ── */
.prod-card {
  background: var(--bg-card);
  border: 1px solid var(--border-color);
  border-radius: 14px;
  overflow: hidden;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
  position: relative;
  user-select: none;
}
.prod-card:hover {
  transform: translateY(-3px);
  border-color: var(--clr-primary);
  box-shadow: 0 8px 24px rgba(37, 99, 235, 0.12);
}
.prod-card:active {
  transform: scale(0.98);
}
.prod-thumb-wrap {
  width: 100%;
  height: 125px;
  background: var(--bg-hover);
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-bottom: 1px solid var(--border-light);
}
.prod-thumb-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  padding: 8px;
  transition: transform .25s ease;
}
.prod-card:hover .prod-thumb-img {
  transform: scale(1.06);
}
.prod-fallback-icon {
  width: 50px;
  height: 50px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(37,99,235,0.1), rgba(59,130,246,0.18));
  color: var(--clr-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.35rem;
  transition: transform .25s ease;
}
.prod-card:hover .prod-fallback-icon {
  transform: scale(1.1);
}
.prod-badge-tier {
  position: absolute;
  top: 8px;
  left: 8px;
  z-index: 2;
}
.prod-badge-tier .badge {
  font-size: 0.65rem;
  padding: 3px 7px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.12);
}
.prod-badge-stock {
  position: absolute;
  top: 8px;
  right: 8px;
  z-index: 2;
}
.prod-badge-stock .badge {
  font-size: 0.65rem;
  padding: 3px 7px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}
.prod-card-body {
  padding: 10px 12px 12px;
  display: flex;
  flex-direction: column;
  flex: 1;
}
.prod-cat-tag {
  font-size: 0.65rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-muted);
  margin-bottom: 3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.prod-title {
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--text-primary);
  line-height: 1.3;
  margin-bottom: 8px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 2.15rem;
}
.prod-card-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
  padding-top: 8px;
  border-top: 1px dashed var(--border-light);
}
.prod-price {
  font-size: 0.95rem;
  font-weight: 800;
  color: var(--clr-success);
}
.prod-add-btn {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: rgba(37, 99, 235, 0.1);
  color: var(--clr-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
  transition: all .2s ease;
  border: 1px solid rgba(37, 99, 235, 0.2);
}
.prod-card:hover .prod-add-btn {
  background: var(--clr-primary);
  color: #fff;
  border-color: var(--clr-primary);
  transform: rotate(90deg);
}
</style>

<div class="pos-layout">

  <!-- LEFT: Products Panel -->
  <div style="display:flex;flex-direction:column;gap:14px;">
    <!-- Search + Filter Bar -->
    <div style="display:flex;gap:10px;flex-shrink:0;flex-wrap:wrap;">
      <input type="text" id="productSearch" class="form-control" placeholder="🔍 Search products by name..." style="flex:1;min-width:180px;">
      <select id="catFilter" class="form-select" style="width:160px;">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat['name']) ?> (<?= $cat['prod_count'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <select id="tierFilter" class="form-select" style="width:140px;">
        <option value="">All Tiers</option>
        <option value="budget">⚪ Budget</option>
        <option value="mid">🔵 Mid</option>
        <option value="high">🟣 High</option>
      </select>
    </div>

    <!-- Product Grid -->
    <div id="productGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:14px;align-content:start;">
      <?php if (empty($groupedProducts)): ?>
        <div style="grid-column:1 / -1;text-align:center;padding:48px 16px;color:var(--text-muted);background:var(--bg-card);border:1px dashed var(--border-color);border-radius:12px;">
          <i class="fas fa-box-open" style="font-size:2.5rem;margin-bottom:12px;opacity:0.35;"></i>
          <h6 style="font-weight:600;margin-bottom:4px;">No products available</h6>
          <p style="font-size:0.8rem;margin:0;">Add active products with available stock in Inventory.</p>
        </div>
      <?php else: ?>
        <?php foreach ($groupedProducts as $item): 
          $prod = $item['primary'];
          $hasImg = !empty($prod['image']);
          $catLower = strtolower($prod['category'] ?? '');
          $fallbackIcon = 'fa-glasses';
          if (str_contains($catLower, 'contact') || str_contains($catLower, 'lens')) {
              $fallbackIcon = 'fa-eye';
          } elseif (str_contains($catLower, 'solution') || str_contains($catLower, 'liquid')) {
              $fallbackIcon = 'fa-tint';
          } elseif (str_contains($catLower, 'accessor') || str_contains($catLower, 'case')) {
              $fallbackIcon = 'fa-box-open';
          } elseif (str_contains($catLower, 'sunglass')) {
              $fallbackIcon = 'fa-sun';
          }
          $isLowStock = ($prod['stock_quantity'] ?? 0) <= 5;
          $allTerms = strtolower($item['base_model'] . ' ' . implode(' ', array_column($item['variants'], 'variant_name')) . ' ' . implode(' ', array_column($item['variants'], 'product_code')) . ' ' . implode(' ', array_column($item['variants'], 'name')));
        ?>
        <div class="prod-card" 
             data-id="<?= $prod['id'] ?>" 
             data-name="<?= htmlspecialchars($prod['name'], ENT_QUOTES, 'UTF-8') ?>" 
             data-price="<?= $prod['price'] ?>" 
             data-stock="<?= $prod['stock_quantity'] ?>" 
             data-cat="<?= htmlspecialchars($prod['category'], ENT_QUOTES, 'UTF-8') ?>"
             data-tier="<?= htmlspecialchars($prod['tier'] ?? 'budget', ENT_QUOTES, 'UTF-8') ?>"
             data-search-terms="<?= htmlspecialchars($allTerms, ENT_QUOTES, 'UTF-8') ?>"
             onclick="addToCart(this)">
          
          <!-- Thumbnail Wrap with Badges -->
          <div class="prod-thumb-wrap">
            <!-- Floating Tier Badge -->
            <div class="prod-badge-tier">
              <?= tierBadge($prod['tier'] ?? 'budget') ?>
            </div>

            <!-- Floating Stock Badge -->
            <div class="prod-badge-stock">
              <?php if ($isLowStock): ?>
                <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i><?= $prod['stock_quantity'] ?> left</span>
              <?php else: ?>
                <span class="badge" style="background:rgba(15,23,42,0.65);color:#fff;backdrop-filter:blur(4px);"><i class="fas fa-boxes me-1"></i><?= $prod['stock_quantity'] ?></span>
              <?php endif; ?>
            </div>

            <!-- Product Image or Fallback -->
            <?php if ($hasImg): ?>
              <img class="prod-thumb-img" 
                   src="<?= BASE_URL ?>assets/images/products/<?= htmlspecialchars($prod['image'], ENT_QUOTES, 'UTF-8') ?>" 
                   alt="<?= htmlspecialchars($prod['name'], ENT_QUOTES, 'UTF-8') ?>"
                   loading="lazy"
                   onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
              <div class="prod-fallback-icon" style="display:none;">
                <i class="fas <?= $fallbackIcon ?>"></i>
              </div>
            <?php else: ?>
              <div class="prod-fallback-icon">
                <i class="fas <?= $fallbackIcon ?>"></i>
              </div>
            <?php endif; ?>
          </div>

          <!-- Product Card Body -->
          <div class="prod-card-body">
            <div class="prod-cat-tag" title="<?= sanitize($prod['category']) ?>">
              <?= sanitize($prod['category']) ?>
            </div>
            <div class="prod-title" title="<?= sanitize($item['base_model']) ?>" style="display:flex;align-items:baseline;justify-content:space-between;gap:4px;">
              <span><?= sanitize($item['base_model']) ?></span>
              <?php if ($item['has_variants']): ?>
                <span class="badge bg-primary" style="font-size:0.58rem; padding:1px 4px; flex-shrink:0;"><?= count($item['variants']) ?> opt</span>
              <?php endif; ?>
            </div>

            <?php if ($item['has_variants']): ?>
            <!-- Variant Selector Dropdown -->
            <div style="margin-bottom:8px;" onclick="event.stopPropagation();">
              <label style="font-size:0.65rem; font-weight:700; color:var(--clr-primary); margin-bottom:2px; display:block;">
                <i class="fas fa-palette me-1"></i>Color / Variant:
              </label>
              <select class="form-select form-select-sm pos-variant-select"
                      style="font-size:0.75rem; padding:2px 20px 2px 6px; font-weight:600; cursor:pointer;"
                      onchange="onPosVariantChange(this)"
                      onclick="event.stopPropagation();">
                <?php foreach ($item['variants'] as $v): ?>
                  <option value="<?= $v['id'] ?>"
                          data-code="<?= htmlspecialchars($v['product_code'] ?: '', ENT_QUOTES, 'UTF-8') ?>"
                          data-name="<?= htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8') ?>"
                          data-variant="<?= htmlspecialchars($v['variant_name'] ?: $v['name'], ENT_QUOTES, 'UTF-8') ?>"
                          data-price="<?= $v['price'] ?>"
                          data-stock="<?= (int)$v['stock_quantity'] ?>">
                    <?= htmlspecialchars($v['variant_name'] ?: $v['name']) ?> (<?= (int)$v['stock_quantity'] ?> left)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>

            <div class="prod-card-footer">
              <div class="prod-price">₱<?= number_format($prod['price'], 2) ?></div>
              <button type="button" class="prod-add-btn" title="Add to cart" aria-label="Add to cart">
                <i class="fas fa-plus"></i>
              </button>
            </div>
          </div>

        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT: Cart Panel -->
  <div class="pos-cart-card">

    <!-- Cart Header -->
    <div style="padding:14px 18px;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));color:#fff;flex-shrink:0;">
      <h6 style="margin:0;font-size:.95rem;font-weight:700;">
        <i class="fas fa-shopping-cart me-2"></i>Cart 
        <span id="cartCount" style="background:rgba(255,255,255,.25);padding:2px 8px;border-radius:10px;font-size:.75rem;margin-left:4px">0</span>
      </h6>
      <button type="button" onclick="clearCart()" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:4px 12px;font-size:.75rem;cursor:pointer;font-family:'Poppins',sans-serif;">Clear</button>
    </div>

    <!-- Cart Items List (max 4 visible before scroll) -->
    <div id="cartItems" class="pos-cart-list">
      <div id="emptyCart" style="text-align:center;padding:40px 16px;color:var(--text-muted);">
        <i class="fas fa-shopping-cart" style="font-size:2rem;margin-bottom:10px;display:block;opacity:.3"></i>
        <p style="font-size:.82rem;margin:0">Cart is empty.<br>Click products to add them.</p>
      </div>
    </div>

    <!-- Summary Panel -->
    <div style="padding:14px 16px;border-top:1px solid var(--border-light);background:var(--bg-hover);flex-shrink:0;">
      
      <!-- Patient Selection — Searchable Custom Dropdown -->
      <div style="margin-bottom:10px;" id="patientDropdownWrap">
        <label style="font-size:.72rem;font-weight:700;color:var(--text-muted);margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;">
          <span><i class="fas fa-user-circle me-1"></i> PATIENT / CLINIC QUEUE</span>
          <span id="queueSyncIndicator" class="text-success small" style="font-size:0.68rem;display:none;"><i class="fas fa-sync-alt fa-spin"></i> Live Queue</span>
        </label>

        <!-- Hidden native select — drives all existing JS data/event logic -->
        <select id="patientSelect" style="display:none;" aria-hidden="true">
          <option value="" data-phone="" data-has-rx="0" data-status="" data-type="retail">Walk-in / Anonymous Customer</option>

          <?php if (!empty($activeAppointments)): ?>
          <optgroup label="Today's Active Queue" id="optgroupActiveClinic">
            <?php foreach ($activeAppointments as $act):
                $isWalkin = ($act['appointment_type'] ?? '') === 'WALK_IN';
                $statusTag = 'Waiting in Queue';
                if ($act['status'] === 'in_progress') $statusTag = 'With Doctor';
                elseif ($act['status'] === 'completed') $statusTag = 'Ready for Dispensing';
            ?>
            <option value="<?= $act['patient_id'] ?>"
                    data-appt-id="<?= $act['appointment_id'] ?>"
                    data-phone="<?= sanitize($act['phone'] ?? '') ?>"
                    data-has-rx="<?= !empty($act['latest_rx_id']) ? '1' : '0' ?>"
                    data-status="<?= $act['status'] ?>"
                    data-name="<?= sanitize($act['full_name']) ?>"
                    data-type="active_clinic"
                    data-status-tag="<?= htmlspecialchars($statusTag) ?>"
                    <?= $act['patient_id'] === $selectedPatientId ? 'selected' : '' ?>>
              <?= sanitize($act['full_name']) ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php else: ?>
          <optgroup label="Today's Active Queue" id="optgroupActiveClinic">
            <option disabled value="">No active clinic consultations right now</option>
          </optgroup>
          <?php endif; ?>

          <optgroup label="Registered Patients">
            <?php foreach ($patients as $pt): ?>
            <option value="<?= $pt['id'] ?>"
                    data-phone="<?= sanitize($pt['phone'] ?? '') ?>"
                    data-has-rx="0"
                    data-name="<?= sanitize($pt['full_name']) ?>"
                    data-type="general"
                    <?= ($selectedApptId > 0 && $pt['id'] === $selectedPatientId) ? 'data-appt-id="' . $selectedApptId . '"' : '' ?>
                    <?= $pt['id'] === $selectedPatientId ? 'selected' : '' ?>>
              <?= sanitize($pt['full_name']) ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
        </select>

        <!-- Visible trigger button -->
        <button type="button" id="patientDropdownTrigger"
          onclick="togglePatientDropdown(event)"
          style="width:100%;text-align:left;background:var(--bg-card);border:1px solid var(--border-color);border-radius:8px;padding:7px 12px;font-size:.83rem;cursor:pointer;color:var(--text-primary);display:flex;justify-content:space-between;align-items:center;gap:8px;font-family:'Poppins',sans-serif;transition:border-color .15s,box-shadow .15s;">
          <span id="patientDropdownLabel" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-muted);">Select patient...</span>
          <i class="fas fa-chevron-down" id="patientDropdownChevron" style="font-size:.68rem;color:var(--text-muted);transition:transform .2s;flex-shrink:0;"></i>
        </button>

        <!-- Dropdown panel (uses position:fixed set by JS to escape overflow:hidden) -->
        <div id="patientDropdownPanel" style="display:none;position:fixed;z-index:1080;background:var(--bg-card);border:1px solid var(--border-color);border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.18);overflow:hidden;">
          <!-- Search -->
          <div style="padding:8px 8px 6px;border-bottom:1px solid var(--border-light);">
            <input type="text" id="patientSearchInput" class="form-control form-control-sm"
              placeholder="🔍 Search patient by name..."
              style="font-size:.82rem;"
              oninput="filterPatientDropdown(this.value)"
              onclick="event.stopPropagation()">
          </div>
          <!-- Scrollable list -->
          <div id="patientDropdownList"
            style="max-height:240px;overflow-y:auto;padding:4px 0;scrollbar-width:thin;scrollbar-color:var(--clr-primary) rgba(0,0,0,.05);">
          </div>
        </div>
      </div>

      <!-- Live Consultation / Queue Status Indicator -->
      <div id="patientStatusBanner" style="display:none; margin-bottom:10px;"></div>

      <!-- Doctor Prescription Available Banner with 1-Click Import (Sensitive Clinical Refraction Hidden) -->
      <div id="rxImportCard" class="card p-2 mb-2" style="display:none; background:rgba(37,99,235,0.06); border:1px solid rgba(37,99,235,0.3); border-radius:10px;">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span style="font-weight:700; font-size:0.78rem; color:var(--clr-primary);">
            <i class="fas fa-glasses me-1"></i> Doctor Prescription Available
          </span>
          <span class="badge bg-primary" id="rxDoctorBadge" style="font-size:0.65rem;">Dr. Attending</span>
        </div>
        <div style="font-size:0.72rem; color:var(--text-secondary); margin-top:2px;" id="rxLensType">
          Recommended Lens: Single Vision Multi-coated
        </div>
        <button type="button" class="btn btn-sm btn-primary mt-2 w-100 py-1" id="btnImportRx" onclick="importRxToCart()">
          <i class="fas fa-file-import me-1"></i> 1-Click Import Rx to Cart
        </button>
      </div>

      <!-- Optical Job Order (Mounting Lab) Options Card -->
      <div class="card p-2 mb-2" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:10px;">
        <div class="form-check form-switch mb-1">
          <input class="form-check-input" type="checkbox" id="chkGenerateJobOrder" onchange="toggleJobOrderPanel()">
          <label class="form-check-label fw-bold" for="chkGenerateJobOrder" style="font-size:0.76rem; cursor:pointer;">
            <i class="fas fa-tools me-1 text-primary"></i> Generate Optical Job Order (Mounting Lab)
          </label>
        </div>

        <div id="jobOrderPanel" style="display:none; padding-top:8px; border-top:1px dashed var(--border-light); margin-top:6px;">
          <!-- Payment Type: Full vs Downpayment -->
          <div class="mb-2">
            <label style="font-size:.68rem;font-weight:600;color:var(--text-muted);margin-bottom:3px;display:block;">PAYMENT TYPE</label>
            <div class="d-flex gap-3">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="payment_type" id="payTypeFull" value="full" checked onchange="togglePaymentType()">
                <label class="form-check-label" for="payTypeFull" style="font-size:.76rem;cursor:pointer;">Full Payment</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="payment_type" id="payTypeDown" value="downpayment" onchange="togglePaymentType()">
                <label class="form-check-label" for="payTypeDown" style="font-size:.76rem;cursor:pointer;">Downpayment / Deposit</label>
              </div>
            </div>
          </div>

          <!-- Downpayment Deposit Fields -->
          <div id="downpaymentFields" style="display:none; margin-bottom:8px;">
            <div class="row g-2">
              <div class="col-6">
                <label style="font-size:.68rem;font-weight:600;color:var(--text-muted);display:block;">DEPOSIT (₱)</label>
                <input type="number" id="depositAmount" class="form-control form-control-sm" placeholder="0.00" min="0" step="0.01" oninput="recalculateDeposit()">
              </div>
              <div class="col-6">
                <label style="font-size:.68rem;font-weight:600;color:var(--text-muted);display:block;">BALANCE DUE</label>
                <input type="text" id="balanceDueDisplay" class="form-control form-control-sm" value="₱0.00" readonly style="font-weight:700;color:var(--clr-danger);background:rgba(239,68,68,0.06);">
              </div>
            </div>
          </div>

          <!-- Target Pickup Date -->
          <div class="mb-2">
            <label style="font-size:.68rem;font-weight:600;color:var(--text-muted);margin-bottom:3px;display:block;">TARGET PICKUP DATE</label>
            <input type="date" id="targetPickupDate" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" min="<?= date('Y-m-d') ?>">
          </div>

          <!-- Patient Mobile for Pickup Notice (Validation required) -->
          <div class="mb-1">
            <label style="font-size:.68rem;font-weight:600;color:var(--text-muted);margin-bottom:3px;display:block;">PATIENT MOBILE FOR PICKUP NOTIFICATION <span class="text-danger">*</span></label>
            <input type="tel" id="jobPatientPhone" class="form-control form-control-sm" placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric">
            <small class="text-muted d-block mt-1" style="font-size:0.65rem;">Required for SMS/call notification once eyeglass mounting is complete.</small>
          </div>
        </div>
      </div>

      <!-- Discount Type & Amount -->
      <div style="display:flex;gap:8px;margin-bottom:10px;align-items:flex-end;">
        <div style="flex:1">
          <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">DISCOUNT TYPE</label>
          <select id="discountType" class="form-select form-select-sm" style="font-size:.82rem;" onchange="toggleDiscountType()">
            <option value="none">None</option>
            <option value="flat">Flat Amount (₱)</option>
            <option value="senior_pwd">Senior/PWD (20% + VAT Exempt)</option>
          </select>
        </div>
        <div style="flex:1" id="discountValueContainer" style="display:none;">
          <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">DISCOUNT (₱)</label>
          <input type="number" id="discountInput" class="form-control form-control-sm" value="0" min="0" step="0.01" style="font-size:.85rem;" oninput="recalculate()" disabled>
        </div>
      </div>

      <!-- Senior ID Input -->
      <div id="seniorIdContainer" style="display:none; margin-bottom:10px;">
        <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">SENIOR CITIZEN / PWD ID NO.</label>
        <input type="text" id="seniorIdInput" class="form-control form-control-sm" placeholder="Enter ID Number">
      </div>

      <!-- Payment Method -->
      <div style="margin-bottom:10px;">
        <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">PAYMENT METHOD</label>
        <select id="paymentMethod" class="form-select form-select-sm" style="font-size:.82rem;">
          <option value="cash">Cash</option>
          <option value="gcash">GCash</option>
          <option value="card">Card</option>
        </select>
      </div>

      <!-- Totals Card with VAT -->
      <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:10px;padding:10px 12px;margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:2px;"><span style="color:var(--text-muted)">Subtotal</span><span id="subtotalDisplay" style="font-weight:600;">₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:2px;"><span style="color:var(--clr-warning)">Discount</span><span id="discountDisplay" style="color:var(--clr-warning);font-weight:600;">-₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:2px;"><span style="color:var(--text-muted)">VAT Exempt Sales</span><span id="vatExemptDisplay" style="font-weight:600;">₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:2px;"><span style="color:var(--text-muted)">VATable Sales</span><span id="vatableDisplay" style="font-weight:600;">₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:4px;"><span style="color:var(--text-muted)">VAT Amount (12%)</span><span id="vatAmountDisplay" style="font-weight:600;">₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.95rem;font-weight:800;border-top:1px solid var(--border-light);padding-top:6px;"><span>TOTAL DUE</span><span id="totalDisplay" style="color:var(--clr-success)">₱0.00</span></div>
      </div>

      <!-- Amount Paid (cash only) -->
      <div id="cashPanel" style="margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
          <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:0;" id="labelAmountReceived">AMOUNT RECEIVED (₱)</label>
          <div style="display:flex;gap:4px;">
            <button type="button" class="quick-cash-btn" onclick="setExactAmount()">Exact</button>
            <button type="button" class="quick-cash-btn" onclick="setQuickCash(500)">500</button>
            <button type="button" class="quick-cash-btn" onclick="setQuickCash(1000)">1k</button>
            <button type="button" class="quick-cash-btn" onclick="setQuickCash(2000)">2k</button>
          </div>
        </div>
        <input type="number" id="amountPaid" class="form-control form-control-sm" placeholder="0.00" step="0.01" min="0" oninput="recalculate()" style="font-size:.95rem;font-weight:700;">
        <div style="display:flex;justify-content:space-between;margin-top:5px;font-size:.82rem;">
          <span style="color:var(--text-muted)">Change:</span>
          <span id="changeDisplay" style="font-weight:800;color:var(--clr-success)">₱0.00</span>
        </div>
      </div>

      <button id="checkoutBtn" onclick="processCheckout()" class="btn btn-primary w-100" style="padding:10px;font-size:.92rem;font-weight:700;" disabled>
        <i class="fas fa-cash-register me-1"></i> Process Sale
      </button>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- OFFICIAL PDF RECEIPT & OPTICAL JOB SLIP PREVIEW MODAL        -->
<!-- ============================================================ -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="background:var(--bg-card); border-radius:16px; border:1px solid var(--border-color); overflow:hidden;">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary)); color:#fff; padding:14px 20px;">
        <div class="d-flex align-items-center gap-2">
          <i class="fas fa-file-invoice fa-lg"></i>
          <div>
            <h5 class="modal-title fw-bold mb-0" style="font-size:1.05rem;" id="receiptModalTitle">Official Sales Receipt</h5>
            <small style="opacity:0.85;" id="receiptModalSubtitle">Gueco Optical Clinic</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      
      <!-- Embedded PDF-like Receipt Viewer -->
      <div class="modal-body p-0" style="background:#525659;">
        <iframe id="receiptIframe" src="" style="width:100%; height:520px; border:none; display:block; background:#525659;"></iframe>
      </div>
      
      <div class="modal-footer" style="background:var(--bg-hover); padding:12px 20px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div class="d-flex gap-2">
          <button type="button" onclick="printReceiptIframe()" class="btn btn-primary px-3">
            <i class="fas fa-print me-1"></i> Print Receipt
          </button>
          <button type="button" onclick="printJobSlip()" class="btn btn-warning px-3 fw-bold" id="btnModalJobSlip" style="display:none;">
            <i class="fas fa-tools me-1"></i> Print Job Slip
          </button>
        </div>
        <div class="d-flex gap-2">
          <a href="#" id="btnOpenReceiptTab" target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-external-link-alt me-1"></i> Receipt Tab
          </a>
          <a href="#" id="btnOpenJobSlipTab" target="_blank" class="btn btn-outline-secondary btn-sm" style="display:none;">
            <i class="fas fa-external-link-alt me-1"></i> Job Slip Tab
          </a>
          <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">
            <i class="fas fa-plus me-1"></i> New Sale
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Cart state
let cart = [];
let activePatientRx = null;
let activeAppointmentId = <?= (int)$selectedApptId ?> || null;
let lastCompletedSaleId = null;
const formatPeso = v => '₱' + parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

function escapeHtml(str) {
  if (!str) return '';
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

// Product search & filter
const searchInput = document.getElementById('productSearch');
const catFilter   = document.getElementById('catFilter');
const tierFilter  = document.getElementById('tierFilter');
searchInput.addEventListener('input', filterProducts);
catFilter.addEventListener('change', filterProducts);
tierFilter.addEventListener('change', filterProducts);

function filterProducts() {
  const q    = searchInput.value.toLowerCase().trim();
  const cat  = catFilter.value.toLowerCase().trim();
  const tier = tierFilter.value.toLowerCase().trim();
  document.querySelectorAll('.prod-card').forEach(card => {
    const name  = (card.dataset.name || '').toLowerCase();
    const terms = (card.dataset.searchTerms || '').toLowerCase();
    const c     = (card.dataset.cat || '').toLowerCase();
    const t     = (card.dataset.tier || 'budget').toLowerCase();
    const matchText = !q || name.includes(q) || terms.includes(q);
    const show = matchText && (!cat || c === cat) && (!tier || t === tier);
    card.style.display = show ? '' : 'none';
  });
}

function onPosVariantChange(selectEl) {
  const opt = selectEl.options[selectEl.selectedIndex];
  const card = selectEl.closest('.prod-card');
  if (!card || !opt) return;

  const id = parseInt(opt.value, 10);
  const name = opt.dataset.name;
  const price = parseFloat(opt.dataset.price);
  const stock = parseInt(opt.dataset.stock, 10);

  card.dataset.id = id;
  card.dataset.name = name;
  card.dataset.price = price;
  card.dataset.stock = stock;

  const priceEl = card.querySelector('.prod-price');
  if (priceEl) priceEl.textContent = formatPeso(price);

  const badgeStock = card.querySelector('.prod-badge-stock');
  if (badgeStock) {
    if (stock <= 5) {
      badgeStock.innerHTML = `<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>${stock} left</span>`;
    } else {
      badgeStock.innerHTML = `<span class="badge" style="background:rgba(15,23,42,0.65);color:#fff;backdrop-filter:blur(4px);"><i class="fas fa-boxes me-1"></i>${stock}</span>`;
    }
  }
}

// Add to cart
function addToCart(el) {
  const id    = parseInt(el.dataset.id, 10);
  const name  = el.dataset.name;
  const price = parseFloat(el.dataset.price);
  const stock = parseInt(el.dataset.stock, 10);

  if (!id || isNaN(id)) return;

  const existing = cart.find(i => i.id === id);
  if (existing) {
    if (existing.qty >= stock) { 
      showToast('Maximum stock reached for ' + name + '!', 'warning'); 
      return; 
    }
    existing.qty++;
  } else {
    cart.push({ id, name, price, stock, qty: 1, is_rx: false });
  }
  
  renderCart();
  
  // Visual click pulse
  el.style.transform = 'scale(0.97)';
  el.style.borderColor = 'var(--clr-primary)';
  setTimeout(() => {
    el.style.transform = '';
    el.style.borderColor = '';
  }, 180);
}

function removeFromCart(id) {
  cart = cart.filter(i => i.id !== id);
  renderCart();
}

function changeQty(id, delta) {
  const item = cart.find(i => i.id === id);
  if (!item) return;
  
  const newQty = item.qty + delta;
  if (newQty <= 0) {
    removeFromCart(id);
    return;
  }
  
  if (newQty > item.stock) {
    showToast('Only ' + item.stock + ' items in stock!', 'warning');
    return;
  }
  
  item.qty = newQty;
  renderCart();
}

function clearCart() {
  cart = []; 
  renderCart();
}

function renderCart() {
  const container = document.getElementById('cartItems');
  const empty     = document.getElementById('emptyCart');
  const btn       = document.getElementById('checkoutBtn');
  
  const totalCount = cart.reduce((s,i) => s + i.qty, 0);
  document.getElementById('cartCount').textContent = totalCount;

  if (cart.length === 0) {
    if (empty) empty.style.display = '';
    container.innerHTML = ''; 
    if (empty) container.appendChild(empty);
    btn.disabled = true; 
    recalculate(); 
    return;
  }

  // Clear container
  container.innerHTML = '';

  // Render every item row
  cart.forEach(item => {
    const itemRow = document.createElement('div');
    itemRow.className = 'pos-cart-item';
    
    const rxBadge = item.is_rx ? '<span class="badge bg-primary me-1" style="font-size:0.6rem;">Rx</span>' : '';

    itemRow.innerHTML = `
      <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text-primary);" title="${escapeHtml(item.name)}">${rxBadge}${escapeHtml(item.name)}</div>
        <div style="font-size:.7rem;color:var(--text-muted);">${formatPeso(item.price)} each</div>
      </div>
      <div style="display:flex;align-items:center;gap:5px;flex-shrink:0;">
        <button type="button" class="pos-qty-btn btn-qty-minus" data-id="${item.id}">−</button>
        <span style="min-width:22px;text-align:center;font-weight:700;font-size:.82rem;color:var(--text-primary);">${item.qty}</span>
        <button type="button" class="pos-qty-btn btn-qty-plus" data-id="${item.id}">+</button>
      </div>
      <div style="min-width:68px;text-align:right;font-weight:700;color:var(--clr-success);font-size:.85rem;flex-shrink:0;">
        ${formatPeso(item.price * item.qty)}
      </div>
      <button type="button" class="pos-remove-btn btn-remove-item" data-id="${item.id}" title="Remove item">✕</button>
    `;
    
    // Add event listeners
    itemRow.querySelector('.btn-qty-minus').addEventListener('click', (e) => {
      e.stopPropagation();
      changeQty(item.id, -1);
    });
    
    itemRow.querySelector('.btn-qty-plus').addEventListener('click', (e) => {
      e.stopPropagation();
      changeQty(item.id, 1);
    });
    
    itemRow.querySelector('.btn-remove-item').addEventListener('click', (e) => {
      e.stopPropagation();
      removeFromCart(item.id);
    });

    container.appendChild(itemRow);
  });

  btn.disabled = false;
  recalculate();
}

function toggleDiscountType() {
  const type = document.getElementById('discountType').value;
  const valContainer = document.getElementById('discountValueContainer');
  const discountInput = document.getElementById('discountInput');
  const seniorContainer = document.getElementById('seniorIdContainer');

  if (type === 'flat') {
    valContainer.style.display = 'block';
    discountInput.disabled = false;
    seniorContainer.style.display = 'none';
  } else if (type === 'senior_pwd') {
    valContainer.style.display = 'block';
    discountInput.disabled = true; // Auto-calculated
    seniorContainer.style.display = 'block';
  } else {
    valContainer.style.display = 'none';
    discountInput.value = '0';
    seniorContainer.style.display = 'none';
  }
  recalculate();
}

function recalculate() {
  const subtotal = cart.reduce((s,i) => s + i.price * i.qty, 0);
  const type = document.getElementById('discountType').value;
  let discount = parseFloat(document.getElementById('discountInput').value) || 0;
  
  let vatable = 0;
  let vatAmount = 0;
  let vatExempt = 0;
  let total = subtotal;

  if (type === 'senior_pwd') {
    // SC/PWD: subtotal is vat exempt, 20% discount on that
    vatExempt = subtotal / 1.12;
    discount = vatExempt * 0.20;
    total = Math.max(0, vatExempt - discount);
    document.getElementById('discountInput').value = discount.toFixed(2);
  } else {
    if (type === 'none') {
      discount = 0;
      document.getElementById('discountInput').value = '0';
    }
    total = Math.max(0, subtotal - discount);
    vatable = total / 1.12;
    vatAmount = total - vatable;
  }

  const paidInput = document.getElementById('amountPaid');
  const paid     = parseFloat(paidInput.value) || 0;
  const change   = Math.max(0, paid - total);

  document.getElementById('subtotalDisplay').textContent = formatPeso(subtotal);
  document.getElementById('discountDisplay').textContent = '-' + formatPeso(discount);
  document.getElementById('vatExemptDisplay').textContent = formatPeso(vatExempt);
  document.getElementById('vatableDisplay').textContent = formatPeso(vatable);
  document.getElementById('vatAmountDisplay').textContent = formatPeso(vatAmount);
  document.getElementById('totalDisplay').textContent    = formatPeso(total);
  document.getElementById('changeDisplay').textContent   = formatPeso(change);

  if (document.getElementById('payTypeDown').checked) {
    recalculateDeposit(total);
  }
}

function setExactAmount() {
  const type = document.getElementById('discountType').value;
  let totalStr = document.getElementById('totalDisplay').textContent.replace('₱', '').replace(',', '');
  let total = parseFloat(totalStr) || 0;
  
  const paidInput = document.getElementById('amountPaid');
  
  if (document.getElementById('payTypeDown').checked) {
    const dep = parseFloat(document.getElementById('depositAmount').value) || 0;
    paidInput.value = dep.toFixed(2);
  } else {
  }
  recalculate();
}

function setQuickCash(val) {
  const paidInput = document.getElementById('amountPaid');
  paidInput.value = parseFloat(val).toFixed(2);
  recalculate();
}

// Payment method toggle
document.getElementById('paymentMethod').addEventListener('change', function() {
  const isCash = this.value === 'cash';
  document.getElementById('cashPanel').style.display = isCash ? '' : 'none';
  if (!isCash) {
    setExactAmount();
  }
});

// ── Patient Selection & Active Queue Lookup ──────────────────
const patientSelect = document.getElementById('patientSelect');
const statusBanner  = document.getElementById('patientStatusBanner');
const rxImportCard  = document.getElementById('rxImportCard');
const jobPatientPhone = document.getElementById('jobPatientPhone');

patientSelect.addEventListener('change', async function() {
  const selectedOpt = this.options[this.selectedIndex];
  const patientId   = this.value;
  const status      = selectedOpt.dataset.status || '';
  const phone       = selectedOpt.dataset.phone || '';
  const optApptId   = selectedOpt.dataset.apptId;
  if (optApptId) {
    activeAppointmentId = optApptId;
  } else if (patientId && parseInt(patientId, 10) === <?= (int)$selectedPatientId ?> && <?= (int)$selectedApptId ?> > 0) {
    activeAppointmentId = <?= (int)$selectedApptId ?>;
  } else {
    activeAppointmentId = null;
  }

  // Auto-fill phone into Job Order phone input
  if (phone) {
    jobPatientPhone.value = phone;
  }

  // 1. Queue Status Indicator Banner
  if (status === 'in_progress') {
    statusBanner.style.display = 'block';
    statusBanner.innerHTML = `
      <div class="alert alert-warning py-1 px-2 mb-0 d-flex justify-content-between align-items-center" style="font-size:0.75rem; border-radius:8px;">
        <span class="text-dark"><i class="fas fa-stethoscope me-1 text-primary"></i> <strong>With Doctor:</strong> Patient is currently in consultation.</span>
        <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>In Progress</span>
      </div>
    `;
  } else if (status === 'completed') {
    statusBanner.style.display = 'block';
    statusBanner.innerHTML = `
      <div class="alert alert-success py-1 px-2 mb-0 d-flex justify-content-between align-items-center" style="font-size:0.75rem; border-radius:8px;">
        <span class="text-dark"><i class="fas fa-check-circle me-1 text-success"></i> <strong>Ready for Dispensing:</strong> Doctor finished consultation.</span>
        <span class="badge bg-success"><i class="fas fa-check-double me-1"></i>Ready for Billing</span>
      </div>
    `;
  } else if (status === 'confirmed') {
    statusBanner.style.display = 'block';
    statusBanner.innerHTML = `
      <div class="alert alert-info py-1 px-2 mb-0 d-flex justify-content-between align-items-center" style="font-size:0.75rem; border-radius:8px;">
        <span><i class="fas fa-clock me-1 text-info"></i> Patient is waiting in clinic queue.</span>
        <span class="badge bg-info text-white">Waiting in Queue</span>
      </div>
    `;
  } else {
    statusBanner.style.display = 'none';
    statusBanner.innerHTML = '';
  }

  // 2. Prescription Lookup Shortcut (Sensitive OD/OS/PD data is kept private for optometrist)
  if (patientId) {
    try {
      const res = await fetch('../api/get_patient_rx.php?patient_id=' + patientId);
      const data = await res.json();
      if (data.success && data.has_rx) {
        activePatientRx = data.rx;
        document.getElementById('rxDoctorBadge').textContent = 'Dr. ' + (data.rx.doctor_name || 'Optometrist');
        document.getElementById('rxLensType').textContent = 'Recommended Lens: ' + (data.rx.lens_type || 'Single Vision Multi-coated');
        if (rxImportCard) rxImportCard.style.display = 'block';
      } else {
        activePatientRx = null;
        if (rxImportCard) rxImportCard.style.display = 'none';
      }
    } catch (e) {
      activePatientRx = null;
      if (rxImportCard) rxImportCard.style.display = 'none';
    }
  } else {
    activePatientRx = null;
    if (rxImportCard) rxImportCard.style.display = 'none';
  }
});

// ── Searchable Custom Patient Dropdown ──────────────────────────────────────
let _ddOpen = false;

function togglePatientDropdown(e) {
  e && e.stopPropagation();
  _ddOpen ? closePatientDropdown() : openPatientDropdown();
}

function openPatientDropdown() {
  _ddOpen = true;
  const trigger = document.getElementById('patientDropdownTrigger');
  const panel   = document.getElementById('patientDropdownPanel');
  const rect    = trigger.getBoundingClientRect();

  // Position using fixed coords so overflow:hidden on parent card doesn't clip it
  panel.style.top   = (rect.bottom + 4) + 'px';
  panel.style.left  = rect.left + 'px';
  panel.style.width = rect.width + 'px';
  panel.style.display = 'block';

  document.getElementById('patientDropdownChevron').style.transform = 'rotate(180deg)';
  trigger.style.borderColor = 'var(--clr-primary)';
  trigger.style.boxShadow   = '0 0 0 2px rgba(37,99,235,.15)';

  buildPatientList('');
  const si = document.getElementById('patientSearchInput');
  si.value = '';
  setTimeout(() => si.focus(), 40);
}

function closePatientDropdown() {
  _ddOpen = false;
  document.getElementById('patientDropdownPanel').style.display = 'none';
  document.getElementById('patientDropdownChevron').style.transform = '';
  const trigger = document.getElementById('patientDropdownTrigger');
  trigger.style.borderColor = 'var(--border-color)';
  trigger.style.boxShadow   = '';
}

function filterPatientDropdown(val) {
  buildPatientList(val);
}

function buildPatientList(filter) {
  const list = document.getElementById('patientDropdownList');
  const sel  = document.getElementById('patientSelect');
  const q    = filter.toLowerCase().trim();
  list.innerHTML = '';
  let anyVisible = false;

  Array.from(sel.children).forEach(child => {
    if (child.tagName === 'OPTION') {
      // Walk-in / Anonymous top-level option
      if (!q || 'walk-in anonymous customer'.includes(q)) {
        list.appendChild(makePatientItem(child));
        anyVisible = true;
      }
    } else if (child.tagName === 'OPTGROUP') {
      // Collect matching items within this group
      const groupItems = [];
      Array.from(child.children).forEach(opt => {
        if (opt.disabled) return;
        const dataName = (opt.dataset.name || opt.text || '').trim();
        if (!dataName) return; // skip patients with no name recorded
        if (!q || dataName.toLowerCase().includes(q)) {
          groupItems.push(makePatientItem(opt));
        }
      });
      if (groupItems.length) {
        // Group header
        const hdr = document.createElement('div');
        hdr.style.cssText = 'font-size:.65rem;font-weight:700;letter-spacing:.05em;color:var(--text-muted);padding:8px 14px 3px;text-transform:uppercase;';
        hdr.textContent = child.id === 'optgroupActiveClinic' ? "Today's Active Queue" : 'Registered Patients';
        list.appendChild(hdr);
        groupItems.forEach(i => list.appendChild(i));
        anyVisible = true;
      }
    }
  });

  if (!anyVisible) {
    const empty = document.createElement('div');
    empty.style.cssText = 'padding:18px 12px;text-align:center;font-size:.82rem;color:var(--text-muted);';
    empty.innerHTML = '<i class="fas fa-search me-1"></i>No patients found';
    list.appendChild(empty);
  }
}

function makePatientItem(opt) {
  const wrap = document.createElement('div');
  wrap.className = 'patient-dd-item';

  const sel        = document.getElementById('patientSelect');
  const isSel      = opt.value === sel.value;
  const isActive   = opt.dataset.type === 'active_clinic';
  const statusTag  = opt.dataset.statusTag || '';
  const displayName = opt.value === ''
    ? 'Walk-in / Anonymous Customer'
    : (opt.dataset.name || opt.text || '');

  wrap.style.cssText = `padding:7px 14px;cursor:pointer;font-size:.84rem;display:flex;align-items:center;gap:9px;`
    + (isSel ? 'background:rgba(37,99,235,.09);font-weight:600;' : '');

  // Left icon
  const icon = document.createElement('span');
  icon.style.cssText = 'flex-shrink:0;width:16px;text-align:center;font-size:.72rem;';
  if (opt.value === '') {
    icon.innerHTML = '<i class="fas fa-user-slash text-muted"></i>';
  } else if (isActive) {
    icon.innerHTML = '<i class="fas fa-circle" style="color:var(--clr-success);font-size:.48rem;vertical-align:middle;"></i>';
  } else {
    icon.innerHTML = '<i class="fas fa-user text-muted"></i>';
  }

  // Name
  const name = document.createElement('span');
  name.style.cssText = 'flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
  name.textContent = displayName;

  wrap.appendChild(icon);
  wrap.appendChild(name);

  // Status badge (active queue only)
  if (isActive && statusTag) {
    const badge = document.createElement('span');
    badge.style.cssText = 'flex-shrink:0;font-size:.62rem;padding:2px 7px;border-radius:4px;background:rgba(37,99,235,.12);color:var(--clr-primary);';
    badge.textContent = statusTag;
    wrap.appendChild(badge);
  }

  // Checkmark if selected
  if (isSel) {
    const chk = document.createElement('span');
    chk.innerHTML = '<i class="fas fa-check" style="color:var(--clr-primary);font-size:.72rem;"></i>';
    wrap.appendChild(chk);
  }

  wrap.addEventListener('mouseenter', () => { if (!isSel) wrap.style.background = 'var(--bg-hover)'; });
  wrap.addEventListener('mouseleave', () => { wrap.style.background = isSel ? 'rgba(37,99,235,.09)' : ''; });
  wrap.addEventListener('click', () => {
    const sel = document.getElementById('patientSelect');
    sel.value = opt.value;
    const lbl = document.getElementById('patientDropdownLabel');
    lbl.textContent = displayName;
    lbl.style.color  = opt.value === '' ? 'var(--text-muted)' : 'var(--text-primary)';
    closePatientDropdown();
    sel.dispatchEvent(new Event('change'));
  });

  return wrap;
}

// Close dropdown when clicking outside
document.addEventListener('click', e => {
  if (_ddOpen && !document.getElementById('patientDropdownWrap').contains(e.target)
      && !document.getElementById('patientDropdownPanel').contains(e.target)) {
    closePatientDropdown();
  }
});

// Re-position dropdown on scroll/resize so it follows the trigger
window.addEventListener('resize', () => { if (_ddOpen) openPatientDropdown(); });
window.addEventListener('scroll', () => { if (_ddOpen) {
  const trigger = document.getElementById('patientDropdownTrigger');
  const panel   = document.getElementById('patientDropdownPanel');
  const rect    = trigger.getBoundingClientRect();
  panel.style.top  = (rect.bottom + 4) + 'px';
  panel.style.left = rect.left + 'px';
}}, true);

// Init: if patient pre-selected via URL ?patient_id=, update label & fire change
(function initPatientDropdownLabel() {
  const sel = document.getElementById('patientSelect');
  const idx = sel.selectedIndex;
  if (idx > 0) {
    const opt = sel.options[idx];
    const displayName = opt.dataset.name || opt.text || '';
    const lbl = document.getElementById('patientDropdownLabel');
    lbl.textContent = displayName;
    lbl.style.color = 'var(--text-primary)';
    sel.dispatchEvent(new Event('change'));
  }
})();

// 1-Click Import Rx into POS Cart (Sensitive clinical refraction measurements kept private)
function importRxToCart() {
  if (!activePatientRx) {
    showToast('No active prescription available to import.', 'warning');
    return;
  }

  const rxItemId = 'rx_' + activePatientRx.id;
  const existing = cart.find(i => i.id === rxItemId);

  if (existing) {
    showToast('Prescription lenses are already in the cart.', 'info');
    return;
  }

  const lensName = `Prescription Lenses (${activePatientRx.lens_type || 'Single Vision Multi-coated'})`;
  cart.push({
    id: rxItemId,
    name: lensName,
    price: 1500.00,
    stock: 999,
    qty: 1,
    is_rx: true,
    rx_id: activePatientRx.id,
    notes: `Prescription #${activePatientRx.id} · Refraction on file`
  });

  // Automatically enable Optical Job Order
  const chkJob = document.getElementById('chkGenerateJobOrder');
  if (chkJob) {
    chkJob.checked = true;
    toggleJobOrderPanel();
  }

  renderCart();
  showToast('Prescription lenses imported into cart!', 'success');
}

// ── Optical Job Order Controls ──────────────────────────────
function toggleJobOrderPanel() {
  const isChecked = document.getElementById('chkGenerateJobOrder').checked;
  document.getElementById('jobOrderPanel').style.display = isChecked ? 'block' : 'none';
  if (isChecked) {
    togglePaymentType();
  }
}

function togglePaymentType() {
  const isDown = document.getElementById('payTypeDown').checked;
  const downFields = document.getElementById('downpaymentFields');
  const labelAmt = document.getElementById('labelAmountReceived');

  downFields.style.display = isDown ? 'block' : 'none';
  labelAmt.textContent = isDown ? 'DEPOSIT AMOUNT RECEIVED (₱)' : 'AMOUNT RECEIVED (₱)';

  if (isDown) {
    const subtotal = cart.reduce((s,i) => s + i.price * i.qty, 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const total    = Math.max(0, subtotal - discount);
    const depInput = document.getElementById('depositAmount');
    if (!depInput.value || parseFloat(depInput.value) <= 0) {
      depInput.value = (total / 2).toFixed(2); // 50% deposit default
    }
    recalculateDeposit();
  } else {
    recalculate();
  }
}

function recalculateDeposit() {
  const subtotal = cart.reduce((s,i) => s + i.price * i.qty, 0);
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const total    = Math.max(0, subtotal - discount);
  const deposit  = parseFloat(document.getElementById('depositAmount').value) || 0;
  const balance  = Math.max(0, total - deposit);

  document.getElementById('balanceDueDisplay').value = formatPeso(balance);
  document.getElementById('amountPaid').value = deposit.toFixed(2);
  recalculate();
}

// ── Process Checkout Submission ─────────────────────────────
async function processCheckout() {
  if (cart.length === 0) {
    showToast('Your cart is empty. Please add items to sell.', 'warning');
    return;
  }
  
  const subtotal  = cart.reduce((s,i) => s + i.price * i.qty, 0);
  const discount  = parseFloat(document.getElementById('discountInput').value) || 0;
  const total     = Math.max(0, subtotal - discount);
  const payMethod = document.getElementById('paymentMethod').value;
  const paidInput = document.getElementById('amountPaid');
  const patientId = document.getElementById('patientSelect').value;
  
  const isJobOrder   = document.getElementById('chkGenerateJobOrder').checked;
  const hasRxInCart  = cart.some(i => i.is_rx);
  const paymentType  = document.getElementById('payTypeDown').checked ? 'downpayment' : 'full';
  const depositAmt   = parseFloat(document.getElementById('depositAmount').value) || 0;
  const pickupDate   = document.getElementById('targetPickupDate').value;
  const contactPhone = document.getElementById('jobPatientPhone').value.trim();

  // Strict Validation: Disallow finalizing a prescription checkout without patient contact details
  if (isJobOrder || hasRxInCart) {
    if (!patientId) {
      showToast('A clinic patient must be selected to generate an Optical Job Order / Prescription checkout. Anonymous sale is not permitted.', 'warning');
      document.getElementById('patientSelect').focus();
      return;
    }

    const cleanPhone = contactPhone.replace(/[^0-9]/g, '');
    if (!/^09\d{9}$/.test(cleanPhone)) {
      showToast('Patient mobile phone (11 digits starting with 09, e.g. 09171234567) is required for pickup notifications.', 'warning');
      document.getElementById('jobPatientPhone').focus();
      document.getElementById('jobPatientPhone').classList.add('is-invalid');
      return;
    }
  }

  let requiredPaid = total;
  if (paymentType === 'downpayment') {
    requiredPaid = depositAmt > 0 ? depositAmt : (total / 2);
  }

  let paid = parseFloat(paidInput.value) || 0;
  if (payMethod !== 'cash') {
    paid = requiredPaid;
  }

  if (payMethod === 'cash' && paid < requiredPaid) {
    paidInput.style.borderColor = '#ef4444';
    paidInput.style.boxShadow = '0 0 0 3px rgba(239,68,68,0.25)';
    paidInput.focus();
    showToast(`Amount received (₱${paid.toFixed(2)}) is less than required payment (₱${requiredPaid.toFixed(2)}).`, 'warning'); 
    return;
  }

  const btn = document.getElementById('checkoutBtn');
  btn.disabled = true; 
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';

  const form = new FormData();
  form.append('csrf_token', '<?= generateCsrfToken() ?>');
  form.append('action', 'process_sale');
  form.append('items', JSON.stringify(cart.map(i => ({ 
    id: i.id, 
    name: i.name, 
    qty: i.qty, 
    price: i.price,
    is_rx: i.is_rx || false,
    notes: i.notes || ''
  }))));
  form.append('patient_id', patientId);
  form.append('payment_method', payMethod);
  form.append('discount', discount);
  form.append('amount_paid', paid);
  form.append('generate_job_order', (isJobOrder || hasRxInCart) ? '1' : '0');
  form.append('payment_type', paymentType);
  form.append('deposit_amount', depositAmt);
  form.append('target_pickup_date', pickupDate);
  form.append('patient_phone', contactPhone);
  form.append('appointment_id', activeAppointmentId || '');
  if (activePatientRx) {
    form.append('prescription_id', activePatientRx.id);
  }

  try {
    const res = await fetch('pos.php', { method:'POST', body: form });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch(err) {
      console.error('Non-JSON response:', text);
      if (text.includes('login') || text.includes('password')) {
        showToast('Your session has expired. Redirecting to login...', 'danger');
        setTimeout(() => location.href = '../login.php', 1500);
        return;
      }
      showToast('Error processing sale: ' + text.substring(0, 100), 'danger');
      btn.disabled = false; 
      btn.innerHTML = '<i class="fas fa-cash-register me-1"></i> Process Sale';
      return;
    }

    if (data.success) {
      lastCompletedSaleId = data.sale_id;

      // 1. Reset inputs & clear cart
      clearCart();
      document.getElementById('amountPaid').value = '';
      document.getElementById('discountInput').value = '0';
      document.getElementById('patientSelect').value = '';
      document.getElementById('chkGenerateJobOrder').checked = false;
      document.getElementById('jobOrderPanel').style.display = 'none';
      statusBanner.style.display = 'none';
      if (rxImportCard) rxImportCard.style.display = 'none';
      activePatientRx = null;
      activeAppointmentId = null;
      recalculate();
      
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-cash-register me-1"></i> Process Sale';

      // 2. Open Receipt in Tab & Embedded Modal
      const receiptUrl = 'receipt.php?id=' + data.sale_id;
      const jobSlipUrl = 'job_slip.php?id=' + data.sale_id;
      
      try {
        window.open(receiptUrl + '&auto_print=1', '_blank');
      } catch(e) {
        console.log('Popup blocked');
      }

      document.getElementById('receiptModalTitle').textContent = `Official Receipt · ${data.invoice_no}`;
      document.getElementById('receiptModalSubtitle').textContent = `Total: ${formatPeso(data.total)} · Customer: ${data.patient}`;
      document.getElementById('receiptIframe').src = receiptUrl;
      document.getElementById('btnOpenReceiptTab').href = receiptUrl;

      // Optical Job Slip button in modal
      const btnModalJob = document.getElementById('btnModalJobSlip');
      const btnTabJob   = document.getElementById('btnOpenJobSlipTab');
      if (data.has_job_order) {
        btnModalJob.style.display = 'inline-block';
        btnTabJob.style.display = 'inline-block';
        btnTabJob.href = jobSlipUrl;
      } else {
        btnModalJob.style.display = 'none';
        btnTabJob.style.display = 'none';
      }

      const modalEl = document.getElementById('receiptModal');
      if (window.bootstrap && bootstrap.Modal) {
        const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        bsModal.show();
      } else {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
      }

      let successMsg = `Sale completed! Invoice #${data.invoice_no}`;
      if (data.has_job_order) {
        successMsg += ` &middot; Job Order #${data.job_order_no}`;
      }
      showToast(successMsg, 'success');
    } else {
      showToast(data.error || 'Sale failed!', 'danger');
      btn.disabled = false; 
      btn.innerHTML = '<i class="fas fa-cash-register me-1"></i> Process Sale';
    }
  } catch(e) {
    showToast('Network error while processing sale: ' + e.message, 'danger');
    btn.disabled = false; 
    btn.innerHTML = '<i class="fas fa-cash-register me-1"></i> Process Sale';
  }
}

function printReceiptIframe() {
  const iframe = document.getElementById('receiptIframe');
  if (iframe && iframe.contentWindow) {
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
  }
}

function printJobSlip() {
  if (lastCompletedSaleId) {
    const jobUrl = 'job_slip.php?id=' + lastCompletedSaleId + '&auto_print=1';
    window.open(jobUrl, '_blank');
  }
}

function showToast(msg, type='success') {
  const alertType = (type === 'error' || type === 'danger') ? 'danger' : (type === 'warning' ? 'warning' : (type === 'info' ? 'info' : 'success'));
  const iconName  = alertType === 'success' ? 'check-circle' : (alertType === 'warning' ? 'exclamation-triangle' : 'exclamation-circle');

  const t = document.createElement('div');
  t.className = `alert alert-${alertType}`;
  t.style.cssText = 'position:fixed;top:80px;right:20px;z-index:9999;min-width:300px;max-width:440px;animation:slideIn .3s ease;box-shadow:0 4px 16px rgba(0,0,0,0.2);';
  t.innerHTML = `<i class="fas fa-${iconName} me-2"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(() => {
    t.style.opacity = '0';
    t.style.transition = 'opacity 0.4s ease';
    setTimeout(() => t.remove(), 400);
  }, 4500);
}

// ── Background Poller for Live Queue Sync ────────────────────
let knownQueueStatuses = {};
setInterval(async () => {
  try {
    const res = await fetch('../api/get_active_walkins.php');
    const data = await res.json();
    if (data.success && Array.isArray(data.patients)) {
      const optgroup = document.getElementById('optgroupActiveClinic');
      if (!optgroup) return;

      const currentVal = patientSelect.value;
      let newHtml = '';

      if (data.patients.length === 0) {
        newHtml = '<option disabled value="">No active clinic consultations right now</option>';
      } else {
        data.patients.forEach(pt => {
          const isSelected = (String(pt.patient_id) === String(currentVal));
          const typeTag = pt.is_walkin ? 'Walk-in' : 'Scheduled';
          newHtml += `
            <option value="${pt.patient_id}" 
                    data-appt-id="${pt.appointment_id}" 
                    data-phone="${escapeHtml(pt.patient_phone)}" 
                    data-has-rx="${pt.has_rx ? '1' : '0'}"
                    data-status="${pt.status}"
                    data-name="${escapeHtml(pt.patient_name)}"
                    data-type="active_clinic"
                    ${isSelected ? 'selected' : ''}>
              ${escapeHtml(pt.patient_name)} — ${typeTag} [${pt.status_label}]
            </option>
          `;

          // Detect doctor completion transition to notify saleslady immediately
          if (knownQueueStatuses[pt.patient_id] && knownQueueStatuses[pt.patient_id] !== 'completed' && pt.status === 'completed') {
            showToast(`Patient <strong>${escapeHtml(pt.patient_name)}</strong> completed consultation! Ready for dispensing & checkout.`, 'info');
          }
          knownQueueStatuses[pt.patient_id] = pt.status;
        });
      }
      optgroup.innerHTML = newHtml;
    }
  } catch(e) {
    console.log('POS queue sync error:', e);
  }
}, 12000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
