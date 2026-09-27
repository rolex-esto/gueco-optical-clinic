<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('saleslady');
$pageTitle  = 'Patient Lookup';
$breadcrumb = ['Saleslady', 'Patients'];
$db = getDB();

$search = sanitize($_GET['search'] ?? '');
$where = ["status='active'"]; $params = [];
if ($search) { $where[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }

$msg = ''; $msgType = '';
// Handle Walk-in Patient Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_walkin') {
    requireCsrfToken();
    $initialStatus = sanitize($_POST['initial_status'] ?? 'confirmed');
    $purpose       = sanitize($_POST['purpose'] ?? 'consultation');
    $staffUserId   = (int)($_SESSION['user_id'] ?? 0);

    $result = createWalkinAppointment($db, $_POST, $initialStatus, $staffUserId, $purpose);

    if (!$result['success']) {
        $msg = $result['error'] ?? 'Error registering walk-in patient.';
        $msgType = 'danger';
    } else {
        $msg = 'Walk-in patient "' . htmlspecialchars($result['patient_name']) . '" registered and queued for clinic consultation successfully.';
        $msgType = 'success';
    }
}
$patients = $db->prepare("SELECT * FROM patients WHERE " . implode(' AND ',$where) . " ORDER BY full_name LIMIT 30");
$patients->execute($params); $patients = $patients->fetchAll();

$extraHead = '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/calendar.css?v=' . time() . '">';
$extraHead .= '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">';
$extraHead .= '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>';
include __DIR__ . '/../includes/header.php';
?>
<div class="section-header" style="display:flex; justify-content:space-between; align-items:center;">
  <h5><i class="fas fa-users me-2" style="color:var(--clr-primary)"></i>Patient Lookup</h5>
  <div style="display:flex; gap:10px; align-items:center;">
    <form method="GET" style="display:flex;gap:8px;margin:0;">
      <input type="text" name="search" class="form-control" placeholder="Name, email, phone..." value="<?= htmlspecialchars($search) ?>" style="width:260px;">
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
      <?php if ($search): ?><a href="patients.php" class="btn btn-secondary"><i class="fas fa-undo"></i></a><?php endif; ?>
    </form>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWalkinModal">
      <i class="fas fa-user-plus me-1"></i> Register Walk-in
    </button>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ADD WALKIN MODAL -->
<div class="modal fade" id="addWalkinModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" id="formAddWalkin" novalidate>
      <input type="hidden" name="action" value="add_walkin">
      <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2" style="color:var(--clr-primary)"></i>Register Walk-in Patient</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="walkinFormAlert" class="alert alert-danger py-2 px-3 mb-3 d-none small"></div>

        <div class="row g-2 mb-3">
          <div class="col-md-4">
            <label class="form-label fw-bold small text-uppercase">Last Name <span class="text-danger">*</span></label>
            <input type="text" name="last_name" id="walkinLastName" class="form-control alpha-only" required maxlength="50" placeholder="e.g. Dela Cruz" autocomplete="off">
            <div class="invalid-feedback" id="feedbackLastName">Please enter a valid last name (letters only).</div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-bold small text-uppercase">First Name <span class="text-danger">*</span></label>
            <input type="text" name="first_name" id="walkinFirstName" class="form-control alpha-only" required maxlength="50" placeholder="e.g. Juan" autocomplete="off">
            <div class="invalid-feedback" id="feedbackFirstName">Please enter a valid first name (letters only).</div>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-bold small text-uppercase">Middle Name</label>
            <input type="text" name="middle_name" id="walkinMiddleName" class="form-control alpha-only" maxlength="50" placeholder="e.g. Santos" autocomplete="off">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold small text-uppercase">Mobile Number (09XXXXXXXXX) <span class="text-danger">*</span></label>
          <input type="tel" name="phone" id="walkinPhone" class="form-control numeric-only" placeholder="09XXXXXXXXX" required maxlength="11" inputmode="numeric">
          <small class="text-muted d-block mt-1">11-digit Philippine mobile number starting with 09 (e.g. 09171234567).</small>
          <div class="invalid-feedback" id="feedbackPhone">Phone must be an 11-digit number starting with 09.</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold small text-uppercase">Email (Optional)</label>
          <input type="email" name="email" id="walkinEmail" class="form-control" placeholder="Leave blank if unknown" maxlength="100" autocomplete="off">
          <small class="text-muted d-block mt-1">A dummy email will be generated if left blank.</small>
          <div class="invalid-feedback" id="feedbackEmail">Please enter a valid email address.</div>
        </div>

        <div class="row">
          <div class="col-md-4 mb-3">
            <label class="form-label fw-bold small text-uppercase">Sex <span class="text-danger">*</span></label>
            <select name="gender" id="walkinGender" class="form-select" required>
              <option value="">Select Sex</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
            <div class="invalid-feedback" id="feedbackGender">Please select a sex.</div>
          </div>
          <div class="col-md-5 mb-3">
            <label class="form-label fw-bold small text-uppercase">Birthdate <span class="text-danger">*</span></label>
            <input type="text" name="birthdate" id="walkinBirthdate" class="form-control modern-birthdate-picker" placeholder="Select birthdate" required autocomplete="off">
            <div class="invalid-feedback" id="feedbackBirthdate">Birthdate is required.</div>
          </div>
          <div class="col-md-3 mb-3">
            <label class="form-label fw-bold small text-uppercase">Age</label>
            <div class="input-group">
              <input type="text" id="walkinAge" class="form-control bg-light" placeholder="—" readonly style="font-weight:700; text-align:center;">
              <span class="input-group-text small text-muted">yrs</span>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label fw-bold small text-uppercase">Consultation Purpose</label>
            <select name="purpose" id="patientModalPurpose" class="form-select">
              <option value="consultation" selected>Eye Examination / Refraction</option>
              <option value="eyeglass_claim">Eyeglass Claim / Fitting</option>
              <option value="follow_up">Follow-up</option>
              <option value="contact_lens_fitting">Contact Lens</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label fw-bold small text-uppercase">Queue Status</label>
            <select name="initial_status" id="patientModalInitialStatus" class="form-select">
              <option value="confirmed" selected>Waiting in Queue (Confirmed)</option>
              <option value="in_progress">Direct to Doctor (In-Progress)</option>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold small text-uppercase">Address <span class="text-danger">*</span></label>
          <input type="text" name="address" id="walkinAddress" class="form-control" placeholder="Barangay, City / Municipality, Province" required maxlength="255">
          <div class="invalid-feedback" id="feedbackAddress">Address must be at least 3 characters.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="btnSaveWalkin"><i class="fas fa-save me-1"></i> Save Patient</button>
      </div>
    </form>
  </div>
</div>

<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>#</th><th>Patient</th><th>Contact</th><th>Address</th><th>Gender</th><th>Registered</th></tr></thead>
      <tbody>
        <?php if (empty($patients)): ?>
        <tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div><h6>No patients found</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($patients as $i => $p): 
            $ptName = getPatientDisplayName($p);
            $ptInitial = strtoupper(substr($ptName, 0, 1)) ?: 'P';
        ?>
        <tr>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $i+1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:34px;height:34px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.8rem;flex-shrink:0;"><?= $ptInitial ?></div>
              <div>
                <div style="font-weight:600;font-size:.88rem">
                  <a href="javascript:void(0)" class="patient-name-link text-decoration-none" onclick="openPatientHistory(<?= (int)$p['id'] ?>)" style="color:var(--clr-primary);cursor:pointer;" title="Click to view transaction history & receipts">
                    <?= sanitize($ptName) ?>
                    <i class="fas fa-history ms-1 text-muted" style="font-size:0.72rem;"></i>
                  </a>
                </div>
                <div style="font-size:.7rem;color:var(--text-muted)"><?= sanitize($p['email'] ?? '') ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:.82rem"><?= sanitize($p['phone']??'—') ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= sanitize($p['address']??'—') ?></td>
          <td style="font-size:.82rem"><?= $p['gender']?ucfirst($p['gender']):'—' ?></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= formatDate($p['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('formAddWalkin');
  if (!form) return;

  const alertBox = document.getElementById('walkinFormAlert');
  const lastNameInput = document.getElementById('walkinLastName');
  const firstNameInput = document.getElementById('walkinFirstName');
  const middleNameInput = document.getElementById('walkinMiddleName');
  const phoneInput = document.getElementById('walkinPhone');
  const emailInput = document.getElementById('walkinEmail');
  const genderInput = document.getElementById('walkinGender');
  const bdateInput = document.getElementById('walkinBirthdate');
  const ageInput = document.getElementById('walkinAge');
  const addrInput = document.getElementById('walkinAddress');

  function calculateAge(dateStr) {
    if (!dateStr) return '';
    const birth = new Date(dateStr);
    if (isNaN(birth.getTime())) return '';
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
      age--;
    }
    return (age >= 0 && age <= 130) ? age : '';
  }

  // Flatpickr Modern Birthdate Picker with Year Dropdown
  let walkinBirthPicker = null;
  if (bdateInput && typeof flatpickr !== 'undefined') {
    walkinBirthPicker = flatpickr(bdateInput, {
      dateFormat: 'Y-m-d',
      altInput: true,
      altFormat: 'F j, Y',
      altInputClass: 'form-control modern-birthdate-picker',
      minDate: '1900-01-01',
      maxDate: 'today',
      monthSelectorType: 'dropdown',
      disableMobile: true,
      placeholder: 'Select birthdate',
      onReady: function(selectedDates, dateStr, fp) {
        const monthElem = fp.calendarContainer.querySelector('.flatpickr-current-month');
        if (monthElem && !monthElem.querySelector('.flatpickr-year-custom-wrap')) {
          const wrap = document.createElement('div');
          wrap.className = 'flatpickr-year-custom-wrap';

          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'flatpickr-year-custom-btn';
          btn.innerHTML = `${fp.currentYear} <i class="fas fa-chevron-down" style="font-size:0.7rem;margin-left:4px;"></i>`;

          const menu = document.createElement('div');
          menu.className = 'flatpickr-year-custom-menu d-none';

          const curYear = new Date().getFullYear();
          for (let y = curYear; y >= 1900; y--) {
            const item = document.createElement('div');
            item.className = 'flatpickr-year-custom-item' + (y === fp.currentYear ? ' active' : '');
            item.dataset.year = y;
            item.textContent = y;
            item.addEventListener('click', function(ev) {
              ev.stopPropagation();
              const chosen = parseInt(this.dataset.year, 10);
              fp.jumpToDate(new Date(chosen, fp.currentMonth, 1));
              btn.innerHTML = `${chosen} <i class="fas fa-chevron-down" style="font-size:0.7rem;margin-left:4px;"></i>`;
              menu.classList.add('d-none');
            });
            menu.appendChild(item);
          }

          btn.addEventListener('click', function(ev) {
            ev.stopPropagation();
            const isClosed = menu.classList.contains('d-none');
            menu.classList.toggle('d-none');
            if (isClosed) {
              const active = menu.querySelector('.flatpickr-year-custom-item.active');
              if (active) active.scrollIntoView({ block: 'center' });
            }
          });

          document.addEventListener('click', function(ev) {
            if (!wrap.contains(ev.target)) {
              menu.classList.add('d-none');
            }
          });

          wrap.appendChild(btn);
          wrap.appendChild(menu);
          monthElem.appendChild(wrap);

          const numWrap = monthElem.querySelector('.numInputWrapper');
          if (numWrap) numWrap.remove();
        }
      },
      onMonthChange: function(selectedDates, dateStr, fp) {
        const btn = fp.calendarContainer.querySelector('.flatpickr-year-custom-btn');
        if (btn) btn.innerHTML = `${fp.currentYear} <i class="fas fa-chevron-down" style="font-size:0.7rem;margin-left:4px;"></i>`;
        const items = fp.calendarContainer.querySelectorAll('.flatpickr-year-custom-item');
        items.forEach(el => el.classList.toggle('active', parseInt(el.dataset.year, 10) === fp.currentYear));
      },
      onYearChange: function(selectedDates, dateStr, fp) {
        const btn = fp.calendarContainer.querySelector('.flatpickr-year-custom-btn');
        if (btn) btn.innerHTML = `${fp.currentYear} <i class="fas fa-chevron-down" style="font-size:0.7rem;margin-left:4px;"></i>`;
        const items = fp.calendarContainer.querySelectorAll('.flatpickr-year-custom-item');
        items.forEach(el => el.classList.toggle('active', parseInt(el.dataset.year, 10) === fp.currentYear));
      },
      onChange: function(selectedDates, dateStr) {
        if (ageInput) ageInput.value = calculateAge(dateStr);
      }
    });

    bdateInput.addEventListener('change', function() {
      if (ageInput) ageInput.value = calculateAge(this.value);
    });
  }

  // Dynamic Consultation Purpose adaptation for Eyeglass Claim
  const ptPurpose = document.getElementById('patientModalPurpose');
  const ptStatus = document.getElementById('patientModalInitialStatus');
  if (ptPurpose && ptStatus) {
    ptPurpose.addEventListener('change', function() {
      if (this.value === 'eyeglass_claim') {
        ptStatus.innerHTML = `
          <option value="confirmed" selected>Ready for Fitting / Pickup (Confirmed)</option>
          <option value="completed">Claim Completed &amp; Handed Over (Done)</option>
        `;
      } else {
        ptStatus.innerHTML = `
          <option value="confirmed" selected>Waiting in Queue (Confirmed)</option>
          <option value="in_progress">Direct to Doctor (In-Progress)</option>
        `;
      }
    });
  }

  function showAlert(msg) {
    if (alertBox) {
      alertBox.textContent = msg;
      alertBox.classList.remove('d-none');
      alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  function clearAlert() {
    if (alertBox) {
      alertBox.textContent = '';
      alertBox.classList.add('d-none');
    }
    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
  }

  // Enforce alphabetical-only on name fields
  form.querySelectorAll('.alpha-only').forEach(el => {
    el.addEventListener('input', function() {
      this.value = this.value.replace(/[^a-zA-Z\sñÑáéíóúÁÉÍÓÚ]/g, '');
    });
    el.addEventListener('keypress', function(e) {
      if (!/^[a-zA-Z\sñÑáéíóúÁÉÍÓÚ]$/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete' && e.key !== 'Tab') {
        e.preventDefault();
      }
    });
  });

  // Enforce numbers only on phone
  if (phoneInput) {
    phoneInput.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
    });
    phoneInput.addEventListener('keypress', function(e) {
      if (!/^[0-9]$/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete' && e.key !== 'Tab') {
        e.preventDefault();
      }
    });
  }

  form.addEventListener('submit', function(e) {
    clearAlert();

    // 1. Name Validation
    const lastVal = (lastNameInput ? lastNameInput.value : '').trim();
    const firstVal = (firstNameInput ? firstNameInput.value : '').trim();
    if (!lastVal) {
      showAlert('Last Name is required.');
      if (lastNameInput) { lastNameInput.classList.add('is-invalid'); lastNameInput.focus(); }
      e.preventDefault();
      return;
    }
    if (!firstVal) {
      showAlert('First Name is required.');
      if (firstNameInput) { firstNameInput.classList.add('is-invalid'); firstNameInput.focus(); }
      e.preventDefault();
      return;
    }

    // 2. Phone Validation (Required)
    const phoneVal = (phoneInput.value || '').trim();
    if (!phoneVal) {
      showAlert('Mobile number is required.');
      phoneInput.classList.add('is-invalid');
      phoneInput.focus();
      e.preventDefault();
      return;
    }
    const cleanPhone = phoneVal.replace(/[^0-9]/g, '');
    if (!/^09\d{9}$/.test(cleanPhone)) {
      showAlert('Phone number must be an 11-digit Philippine mobile number starting with 09 (e.g., 09171234567).');
      phoneInput.classList.add('is-invalid');
      phoneInput.focus();
      e.preventDefault();
      return;
    }
    if (/^09(\d)\1{8}$/.test(cleanPhone)) {
      showAlert('Please enter a valid phone number, not repeated digits.');
      phoneInput.classList.add('is-invalid');
      phoneInput.focus();
      e.preventDefault();
      return;
    }
    if (cleanPhone === '09123456789' || cleanPhone === '09987654321') {
      showAlert('Please enter a valid phone number, not a sequential test number.');
      phoneInput.classList.add('is-invalid');
      phoneInput.focus();
      e.preventDefault();
      return;
    }

    // 3. Email Validation
    const emailVal = (emailInput.value || '').trim();
    if (emailVal) {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(emailVal)) {
        showAlert('Please enter a valid email address (e.g., name@example.com).');
        emailInput.classList.add('is-invalid');
        emailInput.focus();
        e.preventDefault();
        return;
      }
    }

    // 4. Sex Validation (Required)
    const sexVal = (genderInput ? genderInput.value : '').trim();
    if (!sexVal) {
      showAlert('Sex is required. Please select Male, Female, or Other.');
      if (genderInput) {
        genderInput.classList.add('is-invalid');
        genderInput.focus();
      }
      e.preventDefault();
      return;
    }

    // 5. Birthdate Validation (Required)
    const bdateVal = (bdateInput.value || '').trim();
    if (!bdateVal) {
      showAlert('Birthdate is required. Please select patient birthdate.');
      bdateInput.classList.add('is-invalid');
      bdateInput.focus();
      e.preventDefault();
      return;
    }
    const d = new Date(bdateVal);
    const today = new Date();
    today.setHours(23, 59, 59, 999);
    const minDate = new Date('1900-01-01');
    if (isNaN(d.getTime()) || d > today || d < minDate) {
      showAlert('Birthdate must be a valid past date (between 1900 and today).');
      bdateInput.classList.add('is-invalid');
      bdateInput.focus();
      e.preventDefault();
      return;
    }

    // 6. Address Validation (Required)
    const addrVal = (addrInput.value || '').trim();
    if (!addrVal) {
      showAlert('Address is required.');
      addrInput.classList.add('is-invalid');
      addrInput.focus();
      e.preventDefault();
      return;
    } else if (addrVal.length < 3) {
      showAlert('Address must be at least 3 characters long.');
      addrInput.classList.add('is-invalid');
      addrInput.focus();
      e.preventDefault();
      return;
    } else if (!/[a-zA-Z]/.test(addrVal)) {
      showAlert('Address must contain letters identifying the location.');
      addrInput.classList.add('is-invalid');
      addrInput.focus();
      e.preventDefault();
      return;
    }
  });
});

// ── Modern Pop-Up Modal: Patient History & Receipt Viewer ──────────────────
let phModal = null;
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
  if (!phModal) {
    const el = document.getElementById('patientHistoryModal');
    if (el) phModal = new bootstrap.Modal(el);
  }
  if (phModal) phModal.show();

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
        empty.querySelector('h6').textContent = 'Unable to Load Records';
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
      document.getElementById('phPatientGender').textContent = p.gender ? (p.gender.charAt(0).toUpperCase() + p.gender.slice(1)) : '—';
      document.getElementById('phPatientRegistered').textContent = p.registered ? new Date(p.registered).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
      document.getElementById('phTotalCount').textContent = data.total_transactions;
      document.getElementById('phTotalSpent').textContent = formatMoney(data.total_spent);

      if (!data.sales || data.sales.length === 0) {
        empty.style.display = 'block';
        empty.querySelector('h6').textContent = 'No Transactions Recorded';
        empty.querySelector('p').textContent = 'This patient has no purchase or payment records yet.';
        return;
      }

      list.style.display = 'flex';
      data.sales.forEach(sale => {
        const card = document.createElement('div');
        card.className = 'card shadow-sm border mb-2';
        card.style.borderRadius = '14px';
        card.style.background = 'var(--bg-card)';
        card.style.overflow = 'hidden';

        const saleDate = new Date(sale.created_at).toLocaleString('en-US', {
          year: 'numeric', month: 'short', day: 'numeric',
          hour: '2-digit', minute: '2-digit', hour12: true
        });

        let paymentBadge = `<span class="badge bg-secondary text-uppercase">${escapeHtml(sale.payment_method || 'Cash')}</span>`;
        if (sale.payment_method === 'cash') paymentBadge = `<span class="badge bg-success-subtle text-success border border-success px-2 py-1"><i class="fas fa-money-bill-wave me-1"></i>Cash</span>`;
        else if (sale.payment_method === 'gcash') paymentBadge = `<span class="badge bg-primary-subtle text-primary border border-primary px-2 py-1"><i class="fas fa-mobile-alt me-1"></i>GCash</span>`;
        else if (sale.payment_method === 'card') paymentBadge = `<span class="badge bg-purple-subtle text-purple border px-2 py-1"><i class="fas fa-credit-card me-1"></i>Card</span>`;

        let typeBadge = '';
        if (sale.payment_type === 'downpayment') {
          typeBadge = `<span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2 py-1"><i class="fas fa-clock me-1"></i>Downpayment (Balance: ${formatMoney(sale.balance_due)})</span>`;
        } else {
          typeBadge = `<span class="badge bg-success-subtle text-success border border-success px-2 py-1"><i class="fas fa-check-circle me-1"></i>Full Payment</span>`;
        }

        let itemsHtml = '';
        if (sale.items && sale.items.length > 0) {
          itemsHtml = `
            <div class="table-responsive mt-2 mb-1">
              <table class="table table-sm table-borderless align-middle mb-0" style="font-size: 0.8rem;">
                <thead class="text-muted border-bottom" style="font-size: 0.72rem;">
                  <tr>
                    <th>ITEM DESCRIPTION</th>
                    <th class="text-center">QTY</th>
                    <th class="text-end">UNIT PRICE</th>
                    <th class="text-end">AMOUNT</th>
                  </tr>
                </thead>
                <tbody>
                  ${sale.items.map(item => `
                    <tr class="border-bottom border-light-subtle">
                      <td>
                        <div class="fw-semibold text-dark">${escapeHtml(item.display_name || item.item_name)}</div>
                        ${item.item_type === 'service' ? '<span class="badge bg-info-subtle text-info small" style="font-size:0.65rem;">Optical Service / Rx</span>' : ''}
                      </td>
                      <td class="text-center fw-bold">${item.quantity}</td>
                      <td class="text-end text-muted">${formatMoney(item.unit_price)}</td>
                      <td class="text-end fw-bold text-dark">${formatMoney(item.total_price)}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          `;
        } else {
          itemsHtml = `<div class="text-muted small fst-italic py-2"><i class="fas fa-box-open me-1"></i>Standard optical products / services checkout</div>`;
        }

        card.innerHTML = `
          <div class="card-header d-flex justify-content-between align-items-center py-2 px-3 flex-wrap gap-2" style="background: var(--bg-hover); border-bottom: 1px solid var(--border-light);">
            <div class="d-flex align-items-center gap-2">
              <div class="p-1 px-2 rounded fw-bold text-primary bg-primary-subtle" style="font-size: 0.88rem; font-family: monospace;">
                <i class="fas fa-file-invoice me-1"></i>${escapeHtml(sale.invoice_no)}
              </div>
              <span class="text-muted small" style="font-size: 0.76rem;"><i class="far fa-calendar-alt me-1"></i>${saleDate}</span>
            </div>
            <div class="d-flex gap-1 align-items-center flex-wrap">
              ${paymentBadge}
              ${typeBadge}
            </div>
          </div>
          <div class="card-body p-3">
            ${itemsHtml}
            
            <div class="row pt-2 mt-2 border-top align-items-center g-2">
              <div class="col-sm-6">
                <div class="small text-muted">
                  Processed by Cashier: <strong class="text-dark">${escapeHtml(sale.cashier_name || 'Staff')}</strong>
                </div>
                ${sale.job_order_no ? `<div class="small text-info mt-1"><i class="fas fa-tools me-1"></i>Job Order: <strong>${escapeHtml(sale.job_order_no)}</strong></div>` : ''}
              </div>
              <div class="col-sm-6 text-sm-end">
                ${parseFloat(sale.discount || 0) > 0 ? `<div class="text-muted small">Discount: -${formatMoney(sale.discount)}</div>` : ''}
                <div class="d-flex justify-content-sm-end align-items-baseline gap-2">
                  <span class="text-muted small fw-semibold">Grand Total:</span>
                  <span class="fw-bold fs-5 text-success">${formatMoney(sale.total)}</span>
                </div>
              </div>
            </div>
          </div>
          <div class="card-footer bg-transparent py-2 px-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: var(--bg-hover);">
            <small class="text-muted" style="font-size: 0.74rem;">
              <i class="fas fa-check-double text-success me-1"></i>Status: <strong>${escapeHtml((sale.order_status || sale.status || 'Completed').toUpperCase())}</strong>
            </small>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-sm btn-primary py-1 px-3 shadow-sm" onclick="viewOfficialReceipt(${sale.id})">
                <i class="fas fa-receipt me-1"></i> Official Receipt
              </button>
              <a href="../saleslady/receipt.php?id=${sale.id}" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Open in new tab">
                <i class="fas fa-external-link-alt"></i>
              </a>
            </div>
          </div>
        `;
        list.appendChild(card);
      });
    })
    .catch(err => {
      loading.style.display = 'none';
      empty.style.display = 'block';
      empty.querySelector('h6').textContent = 'Network Connection Error';
      empty.querySelector('p').textContent = 'Could not retrieve data from server. Please check your connection.';
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

<!-- MODERN POP-UP MODAL: PATIENT TRANSACTION HISTORY & RECEIPTS -->
<div class="modal fade" id="patientHistoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl" style="max-width: 960px; margin: 1.75rem auto;">
    <div class="modal-content shadow-lg border-0" style="border-radius: 16px; overflow: hidden; background: var(--bg-card); max-height: 90vh; display: flex; flex-direction: column;">
      
      <!-- Modal Header (Sticky / Non-collapsing) -->
      <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, var(--clr-primary), var(--clr-secondary)); color: #fff; border: none; flex-shrink: 0;">
        <div class="d-flex align-items-center gap-3">
          <div id="phAvatar" style="width: 44px; height: 44px; border-radius: 50%; background: rgba(255,255,255,0.25); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.2rem; color: #fff; border: 2px solid rgba(255,255,255,0.4); flex-shrink: 0;">P</div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-white" id="phPatientName" style="font-size: 1.15rem;">Patient Name</h5>
            <small style="opacity: 0.9; font-size: 0.78rem; color: #fff;"><i class="fas fa-history me-1"></i>Transaction Records &amp; Official Receipts</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      
      <!-- Modal Body (Full Content & Scrollable) -->
      <div class="modal-body p-3 p-md-4" style="background: var(--bg-body); overflow-y: auto; flex: 1 1 auto; min-height: 300px;">
        
        <!-- Patient Profile Overview Card -->
        <div class="card border shadow-sm mb-4" style="border-radius: 14px; background: var(--bg-card); border-color: var(--border-color) !important;">
          <div class="card-body p-3">
            <div class="row g-3 align-items-center">
              
              <!-- Personal Data -->
              <div class="col-lg-8">
                <div class="row g-2" style="font-size: 0.82rem;">
                  <div class="col-sm-6">
                    <div class="text-muted small"><i class="fas fa-phone-alt me-1 text-primary"></i> Mobile Contact:</div>
                    <div class="fw-bold text-dark text-truncate" id="phPatientPhone">—</div>
                  </div>
                  <div class="col-sm-6">
                    <div class="text-muted small"><i class="fas fa-envelope me-1 text-primary"></i> Email Address:</div>
                    <div class="fw-bold text-dark text-truncate" id="phPatientEmail">—</div>
                  </div>
                  <div class="col-sm-6">
                    <div class="text-muted small"><i class="fas fa-map-marker-alt me-1 text-primary"></i> Complete Address:</div>
                    <div class="fw-bold text-dark text-truncate" id="phPatientAddress">—</div>
                  </div>
                  <div class="col-sm-3">
                    <div class="text-muted small"><i class="fas fa-venus-mars me-1 text-primary"></i> Gender:</div>
                    <div class="fw-bold text-dark" id="phPatientGender">—</div>
                  </div>
                  <div class="col-sm-3">
                    <div class="text-muted small"><i class="fas fa-calendar-check me-1 text-primary"></i> Registered:</div>
                    <div class="fw-bold text-dark" id="phPatientRegistered">—</div>
                  </div>
                </div>
              </div>
              
              <!-- Summary Statistics Cards -->
              <div class="col-lg-4">
                <div class="row g-2">
                  <div class="col-6">
                    <div class="p-2 rounded text-center border" style="background: var(--bg-hover); border-color: var(--border-color) !important;">
                      <div class="text-muted small" style="font-size: 0.68rem; font-weight: 700;">TRANSACTIONS</div>
                      <div class="fw-bold fs-4 text-primary" id="phTotalCount">0</div>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="p-2 rounded text-center border" style="background: var(--bg-hover); border-color: var(--border-color) !important;">
                      <div class="text-muted small" style="font-size: 0.68rem; font-weight: 700;">TOTAL SPENT</div>
                      <div class="fw-bold fs-5 text-success" id="phTotalSpent" style="line-height:1.7;">₱0.00</div>
                    </div>
                  </div>
                </div>
              </div>
              
            </div>
          </div>
        </div>

        <!-- Transactions Section -->
        <div>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0 text-dark">
              <i class="fas fa-receipt me-2 text-primary"></i>Transaction History
            </h6>
            <small class="text-muted">Click "Official Receipt" on any purchase to view or print</small>
          </div>

          <div class="text-center py-5 text-muted" id="phLoadingState">
            <div class="spinner-border text-primary mb-2" role="status"></div>
            <div class="fw-semibold">Loading transaction records...</div>
          </div>

          <div id="phSalesList" style="display: none; flex-direction: column; gap: 14px;"></div>

          <div id="phEmptyState" class="card border shadow-sm text-center py-5 px-3" style="display: none; border-radius: 14px; background: var(--bg-card); border-color: var(--border-color) !important;">
            <div class="mb-3">
              <div class="d-inline-flex p-3 rounded-circle bg-light">
                <i class="fas fa-file-invoice fa-3x text-muted" style="opacity: 0.4;"></i>
              </div>
            </div>
            <h6 class="fw-bold text-dark">No Transactions Recorded</h6>
            <p class="text-muted small mb-0">This patient has no purchase or billing records yet.</p>
          </div>
        </div>

      </div>

      <!-- Modal Footer (Sticky bottom) -->
      <div class="modal-footer py-2 px-4" style="background: var(--bg-hover); border-top: 1px solid var(--border-light); flex-shrink: 0;">
        <button type="button" class="btn btn-secondary px-4 py-2" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>

<!-- RECEIPT PREVIEW MODAL -->
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
