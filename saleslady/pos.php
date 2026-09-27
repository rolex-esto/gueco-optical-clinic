<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');

$pageTitle  = 'Point of Sale';
$breadcrumb = ['Saleslady', 'POS'];
$db = getDB();
ensureJobOrderSchema($db);

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

    $total = max(0, $subtotal - $discount);

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
                subtotal, discount, total, payment_method,
                payment_type, deposit_amount, balance_due, target_pickup_date,
                prescription_id, job_order_no, order_status,
                amount_paid, change_amount, status
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'completed')
        ");
        $saleStmt->execute([
            $invoiceNo, $patientId, $_SESSION['user_id'], $appointmentId,
            $subtotal, $discount, $total, $dbPaymentMethod,
            $paymentType, $depositAmount, $balanceDue, $targetPickupDate,
            $prescriptionId, $jobOrderNo, $orderStatus,
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
    $prods = $db->prepare("SELECT p.id, p.name, p.price, p.stock_quantity, c.name as category FROM products p JOIN categories c ON c.id=p.category_id WHERE (p.name LIKE ? OR c.name LIKE ?) AND p.status='active' AND p.stock_quantity>0 ORDER BY p.name LIMIT 20");
    $prods->execute([$q,$q]);
    header('Content-Type: application/json');
    echo json_encode($prods->fetchAll());
    exit;
}

// Get all products by category for initial load
$categories = $db->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.status='active' AND p.stock_quantity>0) as prod_count FROM categories c WHERE c.status='active' ORDER BY c.name")->fetchAll();
$allProducts = $db->query("SELECT p.id,p.name,p.price,p.stock_quantity,p.tier,c.name as category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status='active' AND p.stock_quantity>0 ORDER BY c.name,p.name")->fetchAll();

$today = date('Y-m-d');
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

$patients = $db->query("SELECT id, full_name, phone FROM patients WHERE status='active' ORDER BY full_name")->fetchAll();
$selectedPatientId = (int)($_GET['patient_id'] ?? 0);

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
    <div id="productGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;align-content:start;">
      <?php foreach ($allProducts as $prod): ?>
      <div class="prod-card" 
           data-id="<?= $prod['id'] ?>" 
           data-name="<?= htmlspecialchars($prod['name'], ENT_QUOTES, 'UTF-8') ?>" 
           data-price="<?= $prod['price'] ?>" 
           data-stock="<?= $prod['stock_quantity'] ?>" 
           data-cat="<?= htmlspecialchars($prod['category'], ENT_QUOTES, 'UTF-8') ?>"
           data-tier="<?= htmlspecialchars($prod['tier'] ?? 'budget', ENT_QUOTES, 'UTF-8') ?>"
           onclick="addToCart(this)"
           style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:14px;cursor:pointer;transition:all .2s ease;"
           onmouseover="this.style.borderColor='var(--clr-primary)';this.style.boxShadow='0 4px 20px rgba(37,99,235,.15)'"
           onmouseout="this.style.borderColor='var(--border-color)';this.style.boxShadow='none'">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
          <div style="width:36px;height:36px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:10px;display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-glasses" style="color:#fff;font-size:.85rem;"></i>
          </div>
          <?= tierBadge($prod['tier'] ?? 'budget') ?>
        </div>
        <div style="font-weight:700;font-size:.82rem;margin-bottom:4px;line-height:1.3"><?= sanitize($prod['name']) ?></div>
        <div style="font-size:.7rem;color:var(--text-muted);margin-bottom:8px"><?= sanitize($prod['category']) ?></div>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div style="font-weight:800;color:var(--clr-success);font-size:.9rem">₱<?= number_format($prod['price'],2) ?></div>
          <div style="font-size:.68rem;color:<?= $prod['stock_quantity']<=5?'var(--clr-warning)':'var(--text-muted)' ?>"><?= $prod['stock_quantity'] ?> left</div>
        </div>
      </div>
      <?php endforeach; ?>
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
      
      <!-- Patient Selection (Active Walk-in & Clinic Queue Lookup) -->
      <div style="margin-bottom:10px;">
        <label style="font-size:.72rem;font-weight:700;color:var(--text-muted);margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;">
          <span><i class="fas fa-user-circle me-1"></i> PATIENT / CLINIC QUEUE</span>
          <span id="queueSyncIndicator" class="text-success small" style="font-size:0.68rem;display:none;"><i class="fas fa-sync-alt fa-spin"></i> Live Queue</span>
        </label>
        
        <select id="patientSelect" class="form-select form-select-sm" style="font-size:.82rem;">
          <option value="" data-phone="" data-has-rx="0" data-status="" data-type="retail">Walk-in Customer (Retail / Anonymous / OTC)</option>
          
          <?php if (!empty($activeAppointments)): ?>
          <optgroup label="📍 TODAY'S ACTIVE CLINIC PATIENTS (Queue)" id="optgroupActiveClinic">
            <?php foreach ($activeAppointments as $act): 
                $isWalkin = ($act['appointment_type'] ?? '') === 'WALK_IN';
                $typeTag = $isWalkin ? 'Walk-in' : 'Scheduled';
                $statusTag = 'Waiting in Queue';
                if ($act['status'] === 'in_progress') $statusTag = 'With Doctor (In Consultation)';
                elseif ($act['status'] === 'completed') $statusTag = 'Refraction Done (Ready for Dispensing)';
            ?>
            <option value="<?= $act['patient_id'] ?>" 
                    data-appt-id="<?= $act['appointment_id'] ?>" 
                    data-phone="<?= sanitize($act['phone'] ?? '') ?>" 
                    data-has-rx="<?= !empty($act['latest_rx_id']) ? '1' : '0' ?>"
                    data-status="<?= $act['status'] ?>"
                    data-name="<?= sanitize($act['full_name']) ?>"
                    data-type="active_clinic"
                    <?= $act['patient_id'] === $selectedPatientId ? 'selected' : '' ?>>
              <?= sanitize($act['full_name']) ?> — <?= $typeTag ?> [<?= $statusTag ?>]
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php else: ?>
          <optgroup label="📍 TODAY'S ACTIVE CLINIC PATIENTS (Queue)" id="optgroupActiveClinic">
            <option disabled value="">No active clinic consultations right now</option>
          </optgroup>
          <?php endif; ?>

          <optgroup label="📁 Registered Patients (All)">
            <?php foreach ($patients as $pt): ?>
            <option value="<?= $pt['id'] ?>" data-phone="<?= sanitize($pt['phone']??'') ?>" data-has-rx="0" data-name="<?= sanitize($pt['full_name']) ?>" data-type="general">
              <?= sanitize($pt['full_name']) ?> — <?= sanitize($pt['phone'] ?: 'No phone') ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </div>

      <!-- Live Consultation / Queue Status Indicator -->
      <div id="patientStatusBanner" style="display:none; margin-bottom:10px;"></div>

      <!-- Doctor Prescription Available Banner with 1-Click Import -->
      <div id="rxImportCard" class="card p-2 mb-2" style="display:none; background:rgba(37,99,235,0.06); border:1px solid rgba(37,99,235,0.3); border-radius:10px;">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span style="font-weight:700; font-size:0.78rem; color:var(--clr-primary);">
            <i class="fas fa-glasses me-1"></i> Doctor Prescription Available
          </span>
          <span class="badge bg-primary" id="rxDoctorBadge" style="font-size:0.65rem;">Dr. Attending</span>
        </div>
        <div style="font-size:0.72rem; color:var(--text-main); font-family:monospace; background:var(--bg-card); padding:4px 8px; border-radius:6px; border:1px solid var(--border-light);" id="rxSummaryText">
          OD: Plano | OS: Plano | PD: 62 mm
        </div>
        <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;" id="rxLensType">
          Lens: Single Vision Multi-coated
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

      <!-- Discount & Payment Method -->
      <div style="display:flex;gap:8px;margin-bottom:10px;align-items:flex-end;">
        <div style="flex:1">
          <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">DISCOUNT (₱)</label>
          <input type="number" id="discountInput" class="form-control form-control-sm" value="0" min="0" step="0.01" style="font-size:.85rem;" oninput="recalculate()">
        </div>
        <div style="flex:1">
          <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;display:block;">PAYMENT</label>
          <select id="paymentMethod" class="form-select form-select-sm" style="font-size:.82rem;">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="card">Card</option>
          </select>
        </div>
      </div>

      <!-- Totals Card -->
      <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:10px;padding:10px 12px;margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:4px;"><span style="color:var(--text-muted)">Subtotal</span><span id="subtotalDisplay" style="font-weight:600;">₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:4px;"><span style="color:var(--clr-warning)">Discount</span><span id="discountDisplay" style="color:var(--clr-warning);font-weight:600;">-₱0.00</span></div>
        <div style="display:flex;justify-content:space-between;font-size:.95rem;font-weight:800;border-top:1px solid var(--border-light);padding-top:6px;"><span>TOTAL</span><span id="totalDisplay" style="color:var(--clr-success)">₱0.00</span></div>
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
let activeAppointmentId = null;
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
    const name = (card.dataset.name || '').toLowerCase();
    const c    = (card.dataset.cat || '').toLowerCase();
    const t    = (card.dataset.tier || 'budget').toLowerCase();
    const show = (!q || name.includes(q)) && (!cat || c === cat) && (!tier || t === tier);
    card.style.display = show ? '' : 'none';
  });
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
  el.style.background = 'rgba(37,99,235,.12)';
  setTimeout(() => {
    el.style.transform = '';
    el.style.background = 'var(--bg-card)';
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

function recalculate() {
  const subtotal = cart.reduce((s,i) => s + i.price * i.qty, 0);
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const total    = Math.max(0, subtotal - discount);
  const paidInput = document.getElementById('amountPaid');
  const paid     = parseFloat(paidInput.value) || 0;
  const change   = Math.max(0, paid - total);

  document.getElementById('subtotalDisplay').textContent = formatPeso(subtotal);
  document.getElementById('discountDisplay').textContent = '-' + formatPeso(discount);
  document.getElementById('totalDisplay').textContent    = formatPeso(total);
  document.getElementById('changeDisplay').textContent   = formatPeso(change);

  if (document.getElementById('payTypeDown').checked) {
    recalculateDeposit();
  }
}

function setExactAmount() {
  const subtotal = cart.reduce((s,i) => s + i.price * i.qty, 0);
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const total    = Math.max(0, subtotal - discount);
  const paidInput = document.getElementById('amountPaid');
  
  if (document.getElementById('payTypeDown').checked) {
    const dep = parseFloat(document.getElementById('depositAmount').value) || 0;
    paidInput.value = dep.toFixed(2);
  } else {
    paidInput.value = total.toFixed(2);
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
  activeAppointmentId = selectedOpt.dataset.apptId || null;

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
        <span class="text-dark"><i class="fas fa-check-circle me-1 text-success"></i> <strong>Ready for Dispensing:</strong> Doctor finished consultation & refraction.</span>
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

  // 2. Prescription Lookup Shortcut
  if (patientId) {
    try {
      const res = await fetch('../api/get_patient_rx.php?patient_id=' + patientId);
      const data = await res.json();
      if (data.success && data.has_rx) {
        activePatientRx = data.rx;
        document.getElementById('rxDoctorBadge').textContent = 'Dr. ' + (data.rx.doctor_name || 'Optometrist');
        document.getElementById('rxSummaryText').textContent = `OD: ${data.rx.od_summary} | OS: ${data.rx.os_summary} | PD: ${data.rx.pd_summary}`;
        document.getElementById('rxLensType').textContent = 'Lens Type: ' + (data.rx.lens_type || 'Single Vision Multi-coated');
        rxImportCard.style.display = 'block';
      } else {
        activePatientRx = null;
        rxImportCard.style.display = 'none';
      }
    } catch (e) {
      console.log('Rx fetch error:', e);
      rxImportCard.style.display = 'none';
    }
  } else {
    activePatientRx = null;
    rxImportCard.style.display = 'none';
  }
});

// 1-Click Import Rx into POS Cart
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

  const lensName = `Prescription Lenses (${activePatientRx.lens_type || 'Multicoated'} - ${activePatientRx.od_summary} / ${activePatientRx.os_summary})`;
  cart.push({
    id: rxItemId,
    name: lensName,
    price: 1500.00,
    stock: 999,
    qty: 1,
    is_rx: true,
    rx_id: activePatientRx.id,
    notes: `OD: ${activePatientRx.od_summary} | OS: ${activePatientRx.os_summary} | PD: ${activePatientRx.pd_summary} | Dr: ${activePatientRx.doctor_name}`
  });

  // Automatically enable Optical Job Order
  const chkJob = document.getElementById('chkGenerateJobOrder');
  chkJob.checked = true;
  toggleJobOrderPanel();

  renderCart();
  showToast('Prescription parameters imported into cart!', 'success');
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
      rxImportCard.style.display = 'none';
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
