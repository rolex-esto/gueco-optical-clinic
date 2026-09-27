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
                <div style="font-weight:600;font-size:.88rem"><?= sanitize($ptName) ?></div>
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
