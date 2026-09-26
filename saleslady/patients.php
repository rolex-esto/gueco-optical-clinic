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

        <div class="mb-3">
          <label class="form-label fw-bold">Full Name <span class="text-danger">*</span></label>
          <input type="text" name="full_name" id="walkinFullName" class="form-control" required minlength="3" maxlength="100" placeholder="e.g. Juan Dela Cruz" autocomplete="off">
          <small class="text-muted d-block mt-1">Please enter both First Name and Last Name (letters only).</small>
          <div class="invalid-feedback" id="feedbackFullName">Please enter a valid patient name (at least 2 words, no numbers or repetitive letters).</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Email (Optional)</label>
          <input type="email" name="email" id="walkinEmail" class="form-control" placeholder="Leave blank if unknown" maxlength="100" autocomplete="off">
          <small class="text-muted d-block mt-1">A dummy email will be generated if left blank.</small>
          <div class="invalid-feedback" id="feedbackEmail">Please enter a valid email address.</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Phone (Optional)</label>
          <input type="tel" name="phone" id="walkinPhone" class="form-control" placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric">
          <small class="text-muted d-block mt-1">11-digit Philippine mobile number starting with 09 (e.g. 09171234567).</small>
          <div class="invalid-feedback" id="feedbackPhone">Phone must be an 11-digit number starting with 09.</div>
        </div>

        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label fw-bold">Gender</label>
            <select name="gender" id="walkinGender" class="form-select">
              <option value="">Select</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label fw-bold">Birthdate</label>
            <input type="date" name="birthdate" id="walkinBirthdate" class="form-control" min="1900-01-01" max="<?= date('Y-m-d') ?>">
            <small class="text-muted d-block mt-1">Must be a past date.</small>
            <div class="invalid-feedback" id="feedbackBirthdate">Birthdate cannot be in the future.</div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label fw-bold">Consultation Purpose</label>
            <select name="purpose" class="form-select">
              <option value="consultation" selected>Eye Examination / Refraction</option>
              <option value="eyeglass_claim">Eyeglass Claim</option>
              <option value="follow_up">Follow-up</option>
              <option value="contact_lens_fitting">Contact Lens</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label fw-bold">Queue Status</label>
            <select name="initial_status" class="form-select">
              <option value="confirmed" selected>Waiting in Queue (Confirmed)</option>
              <option value="in_progress">Direct to Doctor (In-Progress)</option>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">Address (Optional)</label>
          <input type="text" name="address" id="walkinAddress" class="form-control" placeholder="City, Province" maxlength="255">
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
  const nameInput = document.getElementById('walkinFullName');
  const phoneInput = document.getElementById('walkinPhone');
  const emailInput = document.getElementById('walkinEmail');
  const bdateInput = document.getElementById('walkinBirthdate');
  const addrInput = document.getElementById('walkinAddress');

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

  // Prevent typing 3 consecutive identical letters in real-time
  if (nameInput) {
    nameInput.addEventListener('input', function() {
      this.value = this.value.replace(/([a-zA-ZñÑáéíóúÁÉÍÓÚ\s\.\'\-])\1{2,}/g, '$1$1');
      this.value = this.value.replace(/[^a-zA-ZñÑáéíóúÁÉÍÓÚ\s\.\'\-]/g, '');
    });
  }

  // Enforce numbers only on phone
  if (phoneInput) {
    phoneInput.addEventListener('input', function() {
      this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
    });
  }

  form.addEventListener('submit', function(e) {
    clearAlert();

    // 1. Full Name Validation
    const nameVal = (nameInput.value || '').trim().replace(/\s+/g, ' ');
    if (!nameVal) {
      showAlert('Full Name is required.');
      nameInput.classList.add('is-invalid');
      nameInput.focus();
      e.preventDefault();
      return;
    }
    if (nameVal.length < 3) {
      showAlert('Full Name must be at least 3 characters long.');
      nameInput.classList.add('is-invalid');
      nameInput.focus();
      e.preventDefault();
      return;
    }
    if (/(.)\1{2,}/i.test(nameVal)) {
      showAlert('Full Name contains excessive repetitive characters. Please enter a legitimate patient name.');
      nameInput.classList.add('is-invalid');
      nameInput.focus();
      e.preventDefault();
      return;
    }
    const words = nameVal.split(' ').filter(w => w.trim().length > 0);
    if (words.length < 2) {
      showAlert('Please enter both First Name and Last Name (e.g., "Juan Dela Cruz").');
      nameInput.classList.add('is-invalid');
      nameInput.focus();
      e.preventDefault();
      return;
    }
    let hasNoVowels = false;
    for (let w of words) {
      const stripped = w.replace(/\./g, '');
      if (stripped.length > 1 && !/[aeiouyAEIOUYñÑáéíóúÁÉÍÓÚ]/.test(stripped)) {
        hasNoVowels = true;
        break;
      }
    }
    if (hasNoVowels) {
      showAlert('Full Name contains invalid words without vowels. Please enter a legitimate name.');
      nameInput.classList.add('is-invalid');
      nameInput.focus();
      e.preventDefault();
      return;
    }

    const lowerName = nameVal.toLowerCase();
    const badNames = ['test', 'asdf', 'qwerty', 'zxcv', 'none', 'unknown', 'sample', 'walkin', 'patient', 'fake'];
    for (let bad of badNames) {
      if (lowerName === bad || lowerName.startsWith(bad + ' ') || lowerName.endsWith(' ' + bad)) {
        showAlert('Please enter a genuine patient name, not a placeholder or test string.');
        nameInput.classList.add('is-invalid');
        nameInput.focus();
        e.preventDefault();
        return;
      }
    }

    // 2. Phone Validation
    const phoneVal = (phoneInput.value || '').trim();
    if (phoneVal) {
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

    // 4. Birthdate Validation
    const bdateVal = (bdateInput.value || '').trim();
    if (bdateVal) {
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
    }

    // 5. Address Validation
    const addrVal = (addrInput.value || '').trim();
    if (addrVal) {
      if (addrVal.length < 3) {
        showAlert('Address must be at least 3 characters long.');
        addrInput.classList.add('is-invalid');
        addrInput.focus();
        e.preventDefault();
        return;
      }
      if (!/[a-zA-Z]/.test(addrVal)) {
        showAlert('Address must contain letters identifying the location.');
        addrInput.classList.add('is-invalid');
        addrInput.focus();
        e.preventDefault();
        return;
      }
    }
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
