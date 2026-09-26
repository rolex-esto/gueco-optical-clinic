<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('doctor');

$pageTitle  = 'Patient Records';
$breadcrumb = ['Doctor', 'Patients'];
$db = getDB();
$msg = ''; $msgType = 'success';

$search = sanitize($_GET['search'] ?? '');
$page   = max(1,(int)($_GET['page']??1)); $perPage = 15;

$where = ["p.status='active'"]; $params = [];
if ($search) { $where[] = "(p.full_name LIKE ? OR p.email LIKE ? OR p.phone LIKE ?)"; $params = ["%$search%","%$search%","%$search%"]; }
$whereStr = implode(' AND ', $where);

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
        $msg = 'Walk-in patient "' . htmlspecialchars($result['patient_name']) . '" registered and added to today\'s consultation queue successfully.';
        $msgType = 'success';
    }
}

$total = $db->prepare("SELECT COUNT(*) as c FROM patients p WHERE $whereStr");
$total->execute($params); $total = $total->fetch()['c'];
$pg = paginate($total, $perPage, $page);

$limit = (int)$perPage;
$offset = (int)$pg['offset'];
$patients = $db->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id) as appt_count,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id=p.id) as rx_count,
           (SELECT MAX(appointment_date) FROM appointments a WHERE a.patient_id=p.id AND a.status='completed') as last_visit
    FROM patients p WHERE $whereStr ORDER BY p.full_name ASC LIMIT $limit OFFSET $offset
");
$patients->execute($params); $patients = $patients->fetchAll() ?: [];

// View single patient
$viewPatient = null; $patientRx = []; $patientAppts = [];
if (isset($_GET['view'])) {
    $vid = (int)$_GET['view'];
    $viewPatient = $db->prepare("SELECT * FROM patients WHERE id=?"); $viewPatient->execute([$vid]); $viewPatient = $viewPatient->fetch();
    if ($viewPatient) {
        $patientRx = $db->prepare("SELECT rx.*, u.full_name as doctor_name FROM prescriptions rx JOIN users u ON u.id=rx.doctor_id WHERE rx.patient_id=? ORDER BY rx.created_at DESC"); $patientRx->execute([$vid]); $patientRx = $patientRx->fetchAll();
        $patientAppts = $db->prepare("SELECT * FROM appointments WHERE patient_id=? ORDER BY appointment_date DESC LIMIT 10"); $patientAppts->execute([$vid]); $patientAppts = $patientAppts->fetchAll();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($viewPatient): ?>
<!-- PATIENT DETAIL VIEW -->
<div style="margin-bottom:16px;">
  <a href="patients.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Back to Patients</a>
</div>

<div class="row" style="margin-bottom:20px;">
  <div class="col-4">
    <div class="card">
      <div class="card-body" style="text-align:center;padding:30px 20px;">
        <?php 
          $vpName = getPatientDisplayName($viewPatient);
          $vpInitial = strtoupper(substr($vpName, 0, 1)) ?: 'P';
        ?>
        <div style="width:80px;height:80px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:1.8rem;margin:0 auto 16px;">
          <?= $vpInitial ?>
        </div>
        <h5 style="font-size:1.05rem;font-weight:700;margin-bottom:4px"><?= sanitize($vpName) ?></h5>
        <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:20px"><?= sanitize($viewPatient['email'] ?? '') ?></p>

        <?php $info = [['fas fa-phone','Phone',$viewPatient['phone'],'—'],['fas fa-birthday-cake','Birthday',formatDate($viewPatient['birthdate']),'Not set'],['fas fa-venus-mars','Gender',ucfirst($viewPatient['gender'] ?? ''),'Not set'],['fas fa-map-marker-alt','Address',$viewPatient['address'],'Not set']]; ?>
        <?php foreach ($info as [$icon,$label,$val,$def]): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--border-light);text-align:left;">
          <span style="font-size:.75rem;color:var(--text-muted)"><i class="<?= $icon ?> me-1"></i><?= $label ?></span>
          <span style="font-size:.78rem;font-weight:500;color:var(--text-primary)"><?= sanitize($val ?: $def) ?></span>
        </div>
        <?php endforeach; ?>

        <div style="display:flex;gap:10px;margin-top:20px;">
          <div style="flex:1;text-align:center;background:rgba(37,99,235,.08);border-radius:10px;padding:12px;">
            <div style="font-weight:800;font-size:1.3rem;color:var(--clr-primary)"><?= count($patientAppts) ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)">Visits</div>
          </div>
          <div style="flex:1;text-align:center;background:rgba(124,58,237,.08);border-radius:10px;padding:12px;">
            <div style="font-weight:800;font-size:1.3rem;color:var(--clr-secondary)"><?= count($patientRx) ?></div>
            <div style="font-size:.7rem;color:var(--text-muted)">Prescriptions</div>
          </div>
        </div>

        <a href="prescriptions.php?patient_id=<?= $viewPatient['id'] ?>" class="btn btn-primary w-100 mt-3">
          <i class="fas fa-plus"></i> Write Prescription
        </a>
      </div>
    </div>
  </div>
  <div class="col-8">
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header">
        <h6><i class="fas fa-glasses me-2" style="color:var(--clr-secondary)"></i>Prescriptions (<?= count($patientRx) ?>)</h6>
        <a href="prescriptions.php?patient_id=<?= $viewPatient['id'] ?>" class="btn btn-sm btn-primary">New Rx</a>
      </div>
      <?php if (empty($patientRx)): ?>
      <div class="empty-state" style="padding:30px"><div class="empty-icon"><i class="fas fa-glasses"></i></div><h6>No prescriptions yet</h6></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Date</th><th>OD (Right)</th><th>OS (Left)</th><th>PD</th><th>Notes</th></tr></thead>
          <tbody>
          <?php foreach ($patientRx as $rx): ?>
          <tr>
            <td style="font-size:.8rem;font-weight:600"><?= formatDate($rx['created_at']) ?></td>
            <td style="font-size:.75rem">SPH: <?= $rx['od_sphere']??'—' ?> / CYL: <?= $rx['od_cylinder']??'—' ?> / AXIS: <?= $rx['od_axis']??'—' ?></td>
            <td style="font-size:.75rem">SPH: <?= $rx['os_sphere']??'—' ?> / CYL: <?= $rx['os_cylinder']??'—' ?> / AXIS: <?= $rx['os_axis']??'—' ?></td>
            <td style="font-size:.8rem"><?= $rx['pd']??'—' ?></td>
            <td style="font-size:.75rem;color:var(--text-muted)"><?= sanitize($rx['notes']??'—') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-header"><h6><i class="fas fa-calendar me-2" style="color:var(--clr-info)"></i>Visit History</h6></div>
      <?php if (empty($patientAppts)): ?>
      <div class="empty-state" style="padding:30px"><div class="empty-icon"><i class="fas fa-calendar"></i></div><h6>No visits recorded</h6></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Date</th><th>Time</th><th>Purpose</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($patientAppts as $appt): ?>
          <tr>
            <td style="font-size:.83rem;font-weight:600"><?= formatDate($appt['appointment_date']) ?></td>
            <td style="font-size:.8rem"><?= formatTime($appt['appointment_time']) ?></td>
            <td style="font-size:.8rem"><?= ucwords(str_replace('_',' ',$appt['purpose'])) ?></td>
            <td><?= statusBadge($appt['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php else: ?>
<!-- PATIENT LIST -->
<div class="section-header" style="display:flex; justify-content:space-between; align-items:center;">
  <h5><i class="fas fa-user-injured me-2" style="color:var(--clr-primary)"></i>Patient Records (<?= $total ?>)</h5>
  <div style="display:flex; gap:10px; align-items:center;">
    <form method="GET" style="display:flex;gap:8px;margin:0;">
      <input type="text" name="search" class="form-control" placeholder="Name, email, phone..." value="<?= htmlspecialchars($search) ?>" style="width:240px;">
      <button type="submit" class="btn btn-outline-primary"><i class="fas fa-search"></i></button>
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
              <option value="in_progress">Examining Now (In-Progress)</option>
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
      <thead><tr><th>#</th><th>Patient</th><th>Contact</th><th>Gender</th><th>Visits</th><th>Last Visit</th><th>Prescriptions</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($patients)): ?>
        <tr><td colspan="8"><div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div><h6>No patients found</h6></div></td></tr>
        <?php else: ?>
        <?php foreach ($patients as $i => $p): 
            $ptName = getPatientDisplayName($p);
            $ptInitial = strtoupper(substr($ptName, 0, 1)) ?: 'P';
        ?>
        <tr>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $pg['offset']+$i+1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:34px;height:34px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.8rem;flex-shrink:0;"><?= $ptInitial ?></div>
              <div>
                <div style="font-weight:600;font-size:.88rem"><?= sanitize($ptName) ?></div>
                <div style="font-size:.7rem;color:var(--text-muted)"><?= sanitize($p['email'] ?? '') ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:.82rem"><?= sanitize($p['phone'] ?? '—') ?></td>
          <td style="font-size:.82rem"><?= $p['gender'] ? ucfirst($p['gender']) : '—' ?></td>
          <td><span class="badge bg-info"><?= $p['appt_count'] ?></span></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $p['last_visit'] ? formatDate($p['last_visit']) : 'Never' ?></td>
          <td><span class="badge bg-secondary"><?= $p['rx_count'] ?></span></td>
          <td>
            <a href="patients.php?view=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary btn-icon" title="View Record"><i class="fas fa-eye"></i></a>
            <a href="prescriptions.php?patient_id=<?= $p['id'] ?>" class="btn btn-sm btn-primary btn-icon" title="Write Prescription"><i class="fas fa-glasses"></i></a>
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
      <?php if ($pg['has_prev']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page-1 ?>" class="page-btn"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
      <?php for ($p=1;$p<=$pg['total_pages'];$p++): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $p ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a><?php endfor; ?>
      <?php if ($pg['has_next']): ?><a href="?search=<?= urlencode($search) ?>&page=<?= $page+1 ?>" class="page-btn"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

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
