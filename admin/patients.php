<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');
$pageTitle  = 'Patient Accounts';
$breadcrumb = ['Admin', 'Patients'];
$db = getDB();

// Automatic removal of specified test patient accounts and empty unverified records
try {
    ensurePatientSchema($db);
    $cleanupEmails = [
        'sorianodaeshawne@gmail.com',
        'sorianoshawne@gmail.com',
        'shawnesoriano@gmail.com'
    ];
    $placeholders = implode(',', array_fill(0, count($cleanupEmails), '?'));
    $cleanupStmt = $db->prepare("SELECT id, avatar FROM patients WHERE email IN ($placeholders)");
    $cleanupStmt->execute($cleanupEmails);
    $cleanupRows = $cleanupStmt->fetchAll();
    if (!empty($cleanupRows)) {
        foreach ($cleanupRows as $cRow) {
            if (!empty($cRow['avatar']) && !str_starts_with($cRow['avatar'], 'http')) {
                $avatarFile = __DIR__ . '/../' . ltrim($cRow['avatar'], '/');
                if (file_exists($avatarFile)) {
                    @unlink($avatarFile);
                }
            }
        }
        $db->prepare("DELETE FROM patients WHERE email IN ($placeholders)")->execute($cleanupEmails);
    }

    // Clean up empty unverified test records that have no names, no phone, and no appointments
    $db->exec("DELETE FROM patients WHERE (full_name IS NULL OR full_name = '') AND (first_name IS NULL OR first_name = '') AND (phone IS NULL OR phone = '') AND email_verified = 0 AND id NOT IN (SELECT DISTINCT patient_id FROM appointments)");
} catch (Exception $e) {}

$search = sanitize($_GET['search'] ?? '');
$page   = max(1,(int)($_GET['page']??1)); $perPage = 15;
$where = ["(p.email_verified = 1 OR p.email_verified IS NULL)"]; $params = [];
if ($search) { 
    $where[] = "(p.full_name LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.middle_name LIKE ? OR p.email LIKE ? OR p.phone LIKE ?)"; 
    $params = ["%$search%","%$search%","%$search%","%$search%","%$search%","%$search%"]; 
}
$whereStr = implode(' AND ',$where);

$total = $db->prepare("SELECT COUNT(*) as c FROM patients p WHERE $whereStr"); $total->execute($params); $total = (int)($total->fetch()['c'] ?? 0);
$pg = paginate($total,$perPage,$page);

$limit = (int)$perPage;
$offset = (int)$pg['offset'];
$patients = $db->prepare("SELECT p.*, (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id) as appt_count FROM patients p WHERE $whereStr ORDER BY p.created_at DESC LIMIT $limit OFFSET $offset");
$patients->execute($params); $patients = $patients->fetchAll() ?: [];

// Handle status toggle
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='toggle') {
    requireCsrfToken();
    $id = (int)$_POST['id'];
    $cur = $_POST['cur'] ?? 'active';
    $db->prepare("UPDATE patients SET status=? WHERE id=?")->execute([$cur==='active'?'inactive':'active', $id]);
    header('Location: patients.php'); exit;
}

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/patients.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>
<div class="section-header">
  <h5><i  class="fas fa-users me-2 pat-b6b6a8"></i>Patient Accounts (<?= $total ?>)</h5>
  <form method="GET" class="pat-1952d6">
    <input type="text" name="search" class="form-control" placeholder="Search name, email, phone..." value="<?= htmlspecialchars($search) ?>" class="pat-a712ff">
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
    <?php if ($search): ?><a href="patients.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
  </form>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>Patient</th><th>Contact</th><th>Address</th><th>Appointments</th><th>Status</th><th>Registered</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($patients)): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div><h6>No patients yet</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($patients as $i => $p): 
            $patientName = getPatientDisplayName($p);
            $initial = strtoupper(substr($patientName, 0, 1)) ?: 'P';
            $hasAvatar = !empty($p['avatar']);
            $avatarSrc = '';
            if ($hasAvatar) {
                $avatarSrc = str_starts_with($p['avatar'], 'http') ? $p['avatar'] : (BASE_URL . ltrim($p['avatar'], '/'));
            }
        ?>
        <tr>
          <td class="pat-67fd48"><?= $pg['offset']+$i+1 ?></td>
          <td>
            <div class="pat-3b6fff">
              <?php if ($hasAvatar): ?>
                <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="<?= htmlspecialchars($patientName) ?>" class="pat-avatar-img" width="34" height="34" style="width:34px;height:34px;min-width:34px;min-height:34px;max-width:34px;max-height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;display:inline-block;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="pat-ec276b" style="display:none;"><?= $initial ?></div>
              <?php else: ?>
                <div class="pat-ec276b"><?= $initial ?></div>
              <?php endif; ?>
              <div>
                <div class="pat-bac3c9">
                  <a href="javascript:void(0)" class="patient-name-link text-decoration-none" onclick="openPatientHistory(<?= (int)$p['id'] ?>)" style="color:var(--clr-primary);cursor:pointer;font-weight:600;" title="Click to view transaction history & receipts">
                    <?= sanitize($patientName) ?>
                    <i class="fas fa-history ms-1 text-muted" style="font-size:0.72rem;"></i>
                  </a>
                </div>
                <div class="pat-26a4f5"><?= sanitize($p['email'] ?? '') ?></div>
              </div>
            </div>
          </td>
          <td class="pat-0de4e7"><?= sanitize($p['phone'] ?? '—') ?></td>
          <td class="pat-67fd48"><?= sanitize($p['address'] ?? '—') ?></td>
          <td><span class="badge bg-info"><?= (int)($p['appt_count'] ?? 0) ?> appts</span></td>
          <td><?= statusBadge($p['status'] ?? 'active') ?></td>
          <td class="pat-67fd48"><?= formatDate($p['created_at'] ?? '') ?></td>
          <td>
            <div class="d-flex gap-1 align-items-center">
              <button type="button" class="btn btn-sm btn-outline-primary btn-icon" onclick="openPatientHistory(<?= (int)$p['id'] ?>)" title="View Transaction History & Receipts">
                <i class="fas fa-receipt"></i>
              </button>
              <form method="POST" class="pat-5677b9 m-0">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="cur" value="<?= $p['status'] ?? 'active' ?>">
                <button class="btn btn-sm <?= ($p['status'] ?? 'active') === 'active' ? 'btn-warning' : 'btn-success' ?> btn-icon"
                        data-confirm="<?= ($p['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate' ?> this patient account?"
                        title="<?= ($p['status'] ?? 'active') === 'active' ? 'Deactivate' : 'Activate' ?>">
                  <i class="fas fa-<?= ($p['status'] ?? 'active') === 'active' ? 'ban' : 'check' ?>"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pg['total_pages']>1): ?>
  <div class="pat-4174a0">
    <div class="pagination">
      <?php if ($pg['has_prev']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p=1;$p<=$pg['total_pages'];$p++): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($pg['has_next']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- PATIENT TRANSACTION HISTORY OFFCANVAS -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="patientHistoryOffcanvas" style="width: 650px; max-width: 95vw; background: var(--bg-card); color: var(--text-primary); border-left: 1px solid var(--border-color);">
  <div class="offcanvas-header" style="background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary)); color: #fff; padding: 16px 20px;">
    <div class="d-flex align-items-center gap-3">
      <div id="phAvatar" style="width: 44px; height: 44px; border-radius: 50%; background: rgba(255,255,255,0.25); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; color: #fff; flex-shrink: 0; border: 2px solid rgba(255,255,255,0.4);">P</div>
      <div>
        <h5 class="offcanvas-title fw-bold mb-0 text-white" id="phPatientName" style="font-size: 1.1rem; line-height: 1.2;">Patient Name</h5>
        <small style="opacity: 0.85; font-size: 0.78rem; color: #fff;" id="phPatientSubtitle">Transaction History &amp; Official Receipts</small>
      </div>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  
  <div class="offcanvas-body p-0" style="display: flex; flex-direction: column;">
    <!-- Patient Info Banner -->
    <div style="background: var(--bg-hover); padding: 14px 20px; border-bottom: 1px solid var(--border-light);">
      <div class="row g-2" style="font-size: 0.8rem;">
        <div class="col-sm-6">
          <div class="text-muted small"><i class="fas fa-phone me-1"></i> Contact:</div>
          <div class="fw-semibold text-truncate" id="phPatientPhone">—</div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted small"><i class="fas fa-envelope me-1"></i> Email:</div>
          <div class="fw-semibold text-truncate" id="phPatientEmail">—</div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i> Address:</div>
          <div class="fw-semibold text-truncate" id="phPatientAddress">—</div>
        </div>
        <div class="col-sm-6">
          <div class="text-muted small"><i class="fas fa-calendar-alt me-1"></i> Registered:</div>
          <div class="fw-semibold" id="phPatientRegistered">—</div>
        </div>
      </div>
      <div class="d-flex gap-3 mt-3 pt-2 border-top border-light-subtle">
        <div class="flex-fill p-2 rounded text-center" style="background: var(--bg-card); border: 1px solid var(--border-color);">
          <div class="text-muted small" style="font-size: 0.7rem;">TOTAL TRANSACTIONS</div>
          <div class="fw-bold fs-6 text-primary" id="phTotalCount">0</div>
        </div>
        <div class="flex-fill p-2 rounded text-center" style="background: var(--bg-card); border: 1px solid var(--border-color);">
          <div class="text-muted small" style="font-size: 0.7rem;">TOTAL SPENT</div>
          <div class="fw-bold fs-6 text-success" id="phTotalSpent">₱0.00</div>
        </div>
      </div>
    </div>

    <!-- History List -->
    <div class="p-3" style="flex: 1; overflow-y: auto;" id="phContentArea">
      <div class="text-center py-5 text-muted" id="phLoadingState">
        <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
        <div>Loading transaction history...</div>
      </div>
      <div id="phSalesList" style="display: none; flex-direction: column; gap: 12px;"></div>
      <div id="phEmptyState" style="display: none; text-align: center; padding: 40px 20px; color: var(--text-muted);">
        <i class="fas fa-receipt fa-3x mb-3" style="opacity: 0.25;"></i>
        <h6>No Transactions Found</h6>
        <p class="small text-muted mb-0">This patient has no purchase or payment records yet.</p>
      </div>
    </div>
  </div>
</div>

<!-- RECEIPT PREVIEW MODAL -->
<div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-hidden="true" style="z-index: 1090;">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="background:var(--bg-card); border-radius:16px; border:1px solid var(--border-color); overflow:hidden;">
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
        <iframe id="receiptPreviewIframe" src="" style="width:100%; height:520px; border:none; display:block; background:#525659;"></iframe>
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

<script>
let phOffcanvas = null;
let receiptModal = null;

function escapeHtml(str) {
  if (!str) return '';
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

function formatMoney(amount) {
  return '₱' + parseFloat(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function openPatientHistory(patientId) {
  if (!phOffcanvas) {
    const el = document.getElementById('patientHistoryOffcanvas');
    if (el) phOffcanvas = new bootstrap.Offcanvas(el);
  }
  if (phOffcanvas) phOffcanvas.show();

  const loading = document.getElementById('phLoadingState');
  const list = document.getElementById('phSalesList');
  const empty = document.getElementById('phEmptyState');

  loading.style.display = 'block';
  list.style.display = 'none';
  empty.style.display = 'none';
  list.innerHTML = '';

  fetch(`../api/get_patient_history.php?patient_id=${patientId}`)
    .then(res => res.json())
    .then(data => {
      loading.style.display = 'none';
      if (!data.success) {
        empty.style.display = 'block';
        empty.querySelector('p').textContent = data.error || 'Failed to load transaction history.';
        return;
      }

      // Populate Patient Info
      const p = data.patient;
      const initial = p.name ? p.name.charAt(0).toUpperCase() : 'P';
      document.getElementById('phAvatar').textContent = initial;
      document.getElementById('phPatientName').textContent = p.name || 'Unknown Patient';
      document.getElementById('phPatientPhone').textContent = p.phone || '—';
      document.getElementById('phPatientEmail').textContent = p.email || '—';
      document.getElementById('phPatientAddress').textContent = p.address || '—';
      document.getElementById('phPatientRegistered').textContent = p.registered ? new Date(p.registered).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
      document.getElementById('phTotalCount').textContent = data.total_transactions;
      document.getElementById('phTotalSpent').textContent = formatMoney(data.total_spent);

      if (!data.sales || data.sales.length === 0) {
        empty.style.display = 'block';
        return;
      }

      list.style.display = 'flex';
      data.sales.forEach(sale => {
        const card = document.createElement('div');
        card.className = 'card shadow-sm border mb-1';
        card.style.borderRadius = '12px';
        card.style.background = 'var(--bg-card)';
        card.style.overflow = 'hidden';

        const saleDate = new Date(sale.created_at).toLocaleString('en-US', {
          year: 'numeric', month: 'short', day: 'numeric',
          hour: '2-digit', minute: '2-digit', hour12: true
        });

        let paymentBadge = `<span class="badge bg-secondary text-uppercase">${escapeHtml(sale.payment_method || 'Cash')}</span>`;
        if (sale.payment_method === 'cash') paymentBadge = `<span class="badge bg-success text-uppercase"><i class="fas fa-money-bill-wave me-1"></i>Cash</span>`;
        else if (sale.payment_method === 'gcash') paymentBadge = `<span class="badge bg-primary text-uppercase"><i class="fas fa-mobile-alt me-1"></i>GCash</span>`;

        let typeBadge = '';
        if (sale.payment_type === 'downpayment') {
          typeBadge = `<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Downpayment (Bal: ${formatMoney(sale.balance_due)})</span>`;
        }

        let itemsHtml = '';
        if (sale.items && sale.items.length > 0) {
          itemsHtml = sale.items.map(item => `
            <div class="d-flex justify-content-between align-items-center py-1 border-bottom border-light-subtle" style="font-size: 0.78rem;">
              <div class="text-truncate me-2">
                <span class="fw-medium">${escapeHtml(item.item_name)}</span>
                <span class="text-muted ms-1">× ${item.quantity}</span>
              </div>
              <div class="fw-semibold text-end text-nowrap">${formatMoney(item.total_price)}</div>
            </div>
          `).join('');
        } else {
          itemsHtml = `<div class="text-muted small fst-italic py-1">Standard optical items / services</div>`;
        }

        card.innerHTML = `
          <div class="card-header d-flex justify-content-between align-items-center py-2 px-3" style="background: var(--bg-hover); border-bottom: 1px solid var(--border-light);">
            <div>
              <span class="fw-bold text-primary" style="font-size: 0.86rem;"><i class="fas fa-file-invoice me-1"></i>${escapeHtml(sale.invoice_no)}</span>
              <span class="text-muted ms-2 small" style="font-size: 0.72rem;">${saleDate}</span>
            </div>
            <div class="d-flex gap-1 align-items-center">
              ${paymentBadge}
              ${typeBadge}
            </div>
          </div>
          <div class="card-body p-3">
            <div class="mb-2">
              ${itemsHtml}
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
              <div class="small text-muted">
                Cashier: <span class="fw-medium text-dark">${escapeHtml(sale.cashier_name || 'Staff')}</span>
              </div>
              <div class="text-end">
                <span class="text-muted small me-1">Total:</span>
                <span class="fw-bold fs-6 text-success">${formatMoney(sale.total)}</span>
              </div>
            </div>
          </div>
          <div class="card-footer bg-transparent py-2 px-3 border-top d-flex justify-content-between align-items-center" style="background: var(--bg-hover);">
            <small class="text-muted" style="font-size: 0.72rem;">
              ${sale.job_order_no ? `<i class="fas fa-tools me-1 text-info"></i>JO: <strong>${escapeHtml(sale.job_order_no)}</strong>` : ''}
            </small>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-sm btn-primary py-1 px-2" style="font-size: 0.75rem;" onclick="viewOfficialReceipt(${sale.id})">
                <i class="fas fa-receipt me-1"></i> Official Receipt
              </button>
            </div>
          </div>
        `;
        list.appendChild(card);
      });
    })
    .catch(err => {
      loading.style.display = 'none';
      empty.style.display = 'block';
      empty.querySelector('p').textContent = 'Network error while fetching patient history.';
      console.error(err);
    });
}

function viewOfficialReceipt(saleId) {
  const receiptUrl = `../saleslady/receipt.php?id=${saleId}`;
  const iframe = document.getElementById('receiptPreviewIframe');
  const newTabBtn = document.getElementById('btnOpenReceiptTabLink');
  
  if (iframe) iframe.src = receiptUrl;
  if (newTabBtn) newTabBtn.href = receiptUrl;

  if (!receiptModal) {
    const el = document.getElementById('receiptPreviewModal');
    if (el) receiptModal = new bootstrap.Modal(el);
  }
  if (receiptModal) receiptModal.show();
}

function printReceiptPreviewIframe() {
  const iframe = document.getElementById('receiptPreviewIframe');
  if (iframe && iframe.contentWindow) {
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
