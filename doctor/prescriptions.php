<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('doctor', 'admin');

$pageTitle  = 'Prescriptions';
$breadcrumb = ['Doctor', 'Prescriptions'];
$db = getDB();
ensurePrescriptionsSchema($db);
ensureAppointmentsSchema($db);

$msg = ''; 
$msgType = 'success';

// Pre-fill patient & appointment
$prePatientId = (int)($_GET['patient_id'] ?? 0);
$preApptId    = (int)($_GET['appt_id'] ?? 0);
$prePatient   = null;
$preAppt      = null;

// Fetch appointment info if provided
if ($preApptId > 0) {
    try {
        $apStmt = $db->prepare("SELECT a.*, p.full_name, p.first_name, p.last_name, p.phone, p.email, p.birthdate, p.gender FROM appointments a LEFT JOIN patients p ON p.id = a.patient_id WHERE a.id = ?");
        $apStmt->execute([$preApptId]);
        $preAppt = $apStmt->fetch(PDO::FETCH_ASSOC);
        $apStmt->closeCursor();
        if ($preAppt && empty($prePatientId)) {
            $prePatientId = (int)($preAppt['patient_id'] ?? 0);
        }
    } catch (Exception $e) {
        error_log("Failed to fetch appointment #$preApptId: " . $e->getMessage());
    }
}

// Fetch patient info if provided
if ($prePatientId > 0) {
    try {
        $ptStmt = $db->prepare("SELECT * FROM patients WHERE id = ?");
        $ptStmt->execute([$prePatientId]);
        $prePatient = $ptStmt->fetch(PDO::FETCH_ASSOC);
        $ptStmt->closeCursor();
    } catch (Exception $e) {
        error_log("Failed to fetch patient #$prePatientId: " . $e->getMessage());
    }
}

$prePatientName = '';
if ($prePatient) {
    $prePatientName = !empty($prePatient['full_name']) 
        ? $prePatient['full_name'] 
        : trim(($prePatient['first_name'] ?? '') . ' ' . ($prePatient['last_name'] ?? ''));
    if (!$prePatientName) {
        $prePatientName = 'Patient #' . $prePatientId;
    }
}

// Save prescription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCsrfToken();
    $patId  = (int)($_POST['patient_id'] ?? 0);
    $apptId = (int)($_POST['appt_id'] ?? 0);

    $cleanStr = function($v) {
        $t = trim((string)$v);
        return $t !== '' ? sanitize($t) : null;
    };

    $cleanAxis = function($v) use ($cleanStr) {
        $t = $cleanStr($v);
        if ($t === null) return null;
        $t = str_replace(['°', 'deg'], '', $t);
        return is_numeric($t) ? (int)$t : $t;
    };

    if (!$patId) {
        $msg = 'Please select a patient.';
        $msgType = 'danger';
    } else {
        try {
            $od_sphere   = $cleanStr($_POST['od_sphere'] ?? '');
            $od_cylinder = $cleanStr($_POST['od_cylinder'] ?? '');
            $od_axis     = $cleanAxis($_POST['od_axis'] ?? '');
            $os_sphere   = $cleanStr($_POST['os_sphere'] ?? '');
            $os_cylinder = $cleanStr($_POST['os_cylinder'] ?? '');
            $os_axis     = $cleanAxis($_POST['os_axis'] ?? '');
            $pd          = $cleanStr($_POST['pd'] ?? '');
            $add_power   = $cleanStr($_POST['add_power'] ?? '');
            $notes       = $cleanStr($_POST['notes'] ?? '');

            $insertStmt = $db->prepare("
                INSERT INTO prescriptions (
                    patient_id, doctor_id, appointment_id,
                    od_sphere, od_cylinder, od_axis, od_add,
                    os_sphere, os_cylinder, os_axis, os_add,
                    pd, add_power, notes, recommendations
                ) VALUES (
                    :patient_id, :doctor_id, :appointment_id,
                    :od_sphere, :od_cylinder, :od_axis, :od_add,
                    :os_sphere, :os_cylinder, :os_axis, :os_add,
                    :pd, :add_power, :notes, :recommendations
                )
            ");
            $insertStmt->execute([
                ':patient_id'      => $patId,
                ':doctor_id'       => $_SESSION['user_id'] ?? 1,
                ':appointment_id'  => $apptId > 0 ? $apptId : null,
                ':od_sphere'       => $od_sphere,
                ':od_cylinder'     => $od_cylinder,
                ':od_axis'         => $od_axis,
                ':od_add'          => $add_power,
                ':os_sphere'       => $os_sphere,
                ':os_cylinder'     => $os_cylinder,
                ':os_axis'         => $os_axis,
                ':os_add'          => $add_power,
                ':pd'              => $pd,
                ':add_power'       => $add_power,
                ':notes'           => $notes,
                ':recommendations' => $notes,
            ]);
            $insertStmt->closeCursor();

            $newRxId = (int)$db->lastInsertId();

            if ($apptId > 0) {
                $upStmt = $db->prepare("UPDATE appointments SET status='completed' WHERE id=? AND patient_id=?");
                $upStmt->execute([$apptId, $patId]);
                $upStmt->closeCursor();
                $msg = 'Prescription saved successfully! Consultation marked as completed and official copy has been forwarded directly to the patient\'s portal account. <br><br><a href="print_prescription.php?id=' . $newRxId . '" target="_blank" class="btn btn-sm btn-primary text-white"><i class="fas fa-print me-1"></i> View / Print Slip Copy</a>';
            } else {
                $msg = 'Prescription saved successfully! Official copy has been forwarded directly to the patient\'s portal account. <br><br><a href="print_prescription.php?id=' . $newRxId . '" target="_blank" class="btn btn-sm btn-primary text-white"><i class="fas fa-print me-1"></i> View / Print Slip Copy</a>';
            }

            $prePatientId = $patId;
            $ptStmt = $db->prepare("SELECT * FROM patients WHERE id = ?");
            $ptStmt->execute([$patId]);
            $prePatient = $ptStmt->fetch(PDO::FETCH_ASSOC);
            $ptStmt->closeCursor();

            $pName = !empty($prePatient['full_name']) 
                ? $prePatient['full_name'] 
                : trim(($prePatient['first_name'] ?? '') . ' ' . ($prePatient['last_name'] ?? ''));
            $patientName = $pName ?: ('Patient #' . $patId);
            logActivity("Created optical prescription record for patient: $patientName", "Prescriptions", $_SESSION['user_id'] ?? 1, 'staff');
        } catch (Exception $e) {
            error_log("Error saving prescription: " . $e->getMessage());
            $msg = 'Failed to save prescription: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }
}

// Prescriptions list for selected patient or all recent
$rxList = [];
try {
    if ($prePatientId > 0) {
        $rxStmt = $db->prepare("
            SELECT rx.*, 
                   COALESCE(NULLIF(TRIM(p.full_name), ''), CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')), CONCAT('Patient #', rx.patient_id)) as patient_name 
            FROM prescriptions rx 
            LEFT JOIN patients p ON p.id = rx.patient_id 
            WHERE rx.patient_id = ? 
            ORDER BY rx.created_at DESC
        ");
        $rxStmt->execute([$prePatientId]);
        $rxList = $rxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rxStmt->closeCursor();
    } else {
        $rxStmt = $db->prepare("
            SELECT rx.*, 
                   COALESCE(NULLIF(TRIM(p.full_name), ''), CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')), CONCAT('Patient #', rx.patient_id)) as patient_name 
            FROM prescriptions rx 
            LEFT JOIN patients p ON p.id = rx.patient_id 
            WHERE rx.doctor_id = ? 
            ORDER BY rx.created_at DESC 
            LIMIT 20
        ");
        $rxStmt->execute([$_SESSION['user_id'] ?? 1]);
        $rxList = $rxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rxStmt->closeCursor();
    }
} catch (Exception $e) {
    error_log("Failed to fetch prescriptions: " . $e->getMessage());
    $rxList = [];
}

// All patients for dropdown
$allPatients = [];
try {
    $whereDropdown = "status = 'active'";
    if ($prePatientId > 0) {
        $whereDropdown .= " OR id = " . (int)$prePatientId;
    }
    $allStmt = $db->query("
        SELECT id, 
               COALESCE(NULLIF(TRIM(full_name), ''), CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')), CONCAT('Patient #', id)) as full_name 
        FROM patients 
        WHERE $whereDropdown 
        ORDER BY full_name ASC
    ");
    if ($allStmt) {
        $allPatients = $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $allStmt->closeCursor();
    }
} catch (Exception $e) {
    error_log("Failed to fetch patients dropdown: " . $e->getMessage());
    $allPatients = [];
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        title: '<?= $msgType === "success" ? "Success!" : ($msgType === "info" ? "Notice" : "Error") ?>',
        html: <?= json_encode($msg) ?>,
        icon: '<?= $msgType === "success" ? "success" : ($msgType === "info" ? "info" : "error") ?>',
        confirmButtonColor: 'var(--clr-primary)',
        confirmButtonText: 'OK',
        background: 'var(--bg-card)',
        color: 'var(--text-primary)',
        showCloseButton: true
    });
});
</script>
<?php endif; ?>

<?php if ($preAppt): ?>
<!-- Appointment Clinical Banner -->
<div class="alert alert-primary d-flex align-items-center justify-content-between mb-4 shadow-sm" style="border-radius:14px; background: rgba(37,99,235,0.08); border: 1.5px solid rgba(37,99,235,0.25);">
  <div class="d-flex align-items-center gap-3">
    <div style="width:42px; height:42px; border-radius:10px; background:var(--clr-primary); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
      <i class="fas fa-calendar-check"></i>
    </div>
    <div>
      <div class="fw-bold" style="color:var(--text-primary); font-size:0.95rem;">
        Prescription for Appointment #<?= $preApptId ?> &mdash; <?= sanitize($prePatientName ?: 'Patient #' . $prePatientId) ?>
      </div>
      <div class="text-muted small">
        <i class="far fa-calendar-alt me-1"></i> <?= formatDate($preAppt['appointment_date']) ?>
        <i class="far fa-clock ms-2 me-1"></i> <?= formatTime($preAppt['appointment_time']) ?>
        &bull; <strong>Purpose:</strong> <?= ucwords(str_replace('_', ' ', $preAppt['purpose'])) ?>
      </div>
    </div>
  </div>
  <div>
    <span class="badge bg-primary text-uppercase px-3 py-2"><?= sanitize($preAppt['status']) ?></span>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- Write Rx Form -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="m-0"><i class="fas fa-glasses me-2 text-primary"></i>Write Prescription</h6>
        <?php if ($preApptId > 0): ?>
          <span class="badge bg-light text-dark border">Appt #<?= $preApptId ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="appt_id" value="<?= $preApptId ?>">

          <div class="mb-3">
            <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
            <select name="patient_id" class="form-select" required>
              <option value="">Select patient...</option>
              <?php foreach ($allPatients as $pt): ?>
              <option value="<?= $pt['id'] ?>" <?= $prePatientId == $pt['id'] ? 'selected' : '' ?>><?= sanitize($pt['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Prescription grid -->
          <div style="background:var(--bg-hover); border-radius:12px; padding:16px; margin-bottom:16px; border: 1px solid var(--border-light);">
            <div style="font-size:.78rem; font-weight:700; margin-bottom:12px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.05em">
              <i class="fas fa-eye me-1"></i> Prescription Values
            </div>
            <div style="display:grid; grid-template-columns:auto 1fr 1fr 1fr; gap:8px; align-items:center; font-size:.78rem;">
              <div></div>
              <div style="text-align:center; font-weight:700; color:var(--text-muted)">SPH</div>
              <div style="text-align:center; font-weight:700; color:var(--text-muted)">CYL</div>
              <div style="text-align:center; font-weight:700; color:var(--text-muted)">AXIS</div>

              <div style="font-weight:700; color:var(--clr-primary); padding-right:6px;">OD <span style="font-weight:400; font-size:.7rem">(Right)</span></div>
              <input type="text" name="od_sphere"   class="form-control" placeholder="0.00">
              <input type="text" name="od_cylinder" class="form-control" placeholder="0.00">
              <input type="text" name="od_axis"     class="form-control" placeholder="0°">

              <div style="font-weight:700; color:var(--clr-secondary); padding-right:6px;">OS <span style="font-weight:400; font-size:.7rem">(Left)</span></div>
              <input type="text" name="os_sphere"   class="form-control" placeholder="0.00">
              <input type="text" name="os_cylinder" class="form-control" placeholder="0.00">
              <input type="text" name="os_axis"     class="form-control" placeholder="0°">
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold">PD (Pupillary Distance)</label>
              <input type="text" name="pd" class="form-control" placeholder="e.g. 62 or 31/31">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">ADD Power</label>
              <input type="text" name="add_power" class="form-control" placeholder="e.g. +1.50">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Clinical Notes / Recommendations</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Recommended lens type, anti-radiation, progressive, follow-up instructions..."></textarea>
          </div>

          <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
            <i class="fas fa-save me-1"></i> Save & Issue Prescription
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Rx History -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="m-0">
          <i class="fas fa-history me-2 text-info"></i>
          <?= $prePatientName ? 'Prescriptions for ' . sanitize($prePatientName) : 'Recent Prescriptions' ?>
        </h6>
        <?php if ($prePatientId > 0): ?>
          <a href="patients.php?view=<?= $prePatientId ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-user me-1"></i> View Patient</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($rxList)): ?>
        <div class="empty-state text-center py-5">
          <div class="empty-icon mb-2 text-muted"><i class="fas fa-glasses fa-3x"></i></div>
          <h6>No prescriptions found</h6>
          <p class="text-muted small">Write a new prescription using the form on the left.</p>
        </div>
        <?php else: ?>
        <div style="overflow-y:auto; max-height:600px;">
          <?php foreach ($rxList as $rx): ?>
          <div style="padding:16px 20px; border-bottom:1px solid var(--border-light);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
              <div>
                <div style="font-weight:700; font-size:.88rem"><?= sanitize($rx['patient_name'] ?? 'Patient') ?></div>
                <div style="font-size:.72rem; color:var(--text-muted)"><i class="far fa-clock me-1"></i><?= formatDateTime($rx['created_at']) ?></div>
              </div>
              <div class="d-flex align-items-center gap-2">
                <span class="badge bg-secondary">Rx #<?= $rx['id'] ?></span>
                <a href="print_prescription.php?id=<?= $rx['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="font-size:.72rem; padding:2px 8px; border-radius:6px;" title="Print official prescription pad copy">
                  <i class="fas fa-print me-1"></i> Print Slip
                </a>
              </div>
            </div>
            <!-- Rx Table mini -->
            <div style="background:var(--bg-hover); border-radius:8px; padding:10px; border: 1px solid var(--border-light);">
              <table style="width:100%; font-size:.74rem; border-collapse:collapse;">
                <thead>
                  <tr>
                    <th style="padding:4px 8px; color:var(--text-muted); font-weight:600; text-align:left;"></th>
                    <th style="padding:4px 8px; text-align:center; color:var(--text-muted)">SPH</th>
                    <th style="padding:4px 8px; text-align:center; color:var(--text-muted)">CYL</th>
                    <th style="padding:4px 8px; text-align:center; color:var(--text-muted)">AXIS</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td style="padding:4px 8px; font-weight:700; color:var(--clr-primary)">OD</td>
                    <td style="text-align:center; padding:4px 8px"><?= $rx['od_sphere'] ?? '—' ?></td>
                    <td style="text-align:center; padding:4px 8px"><?= $rx['od_cylinder'] ?? '—' ?></td>
                    <td style="text-align:center; padding:4px 8px"><?= ($rx['od_axis'] !== null && $rx['od_axis'] !== '') ? $rx['od_axis'] . '°' : '—' ?></td>
                  </tr>
                  <tr>
                    <td style="padding:4px 8px; font-weight:700; color:var(--clr-secondary)">OS</td>
                    <td style="text-align:center; padding:4px 8px"><?= $rx['os_sphere'] ?? '—' ?></td>
                    <td style="text-align:center; padding:4px 8px"><?= $rx['os_cylinder'] ?? '—' ?></td>
                    <td style="text-align:center; padding:4px 8px"><?= ($rx['os_axis'] !== null && $rx['os_axis'] !== '') ? $rx['os_axis'] . '°' : '—' ?></td>
                  </tr>
                </tbody>
              </table>
              <div style="margin-top:6px; font-size:.72rem; color:var(--text-muted)">
                PD: <strong><?= $rx['pd'] ?? '—' ?></strong> &nbsp;|&nbsp; ADD: <strong><?= $rx['add_power'] ?? ($rx['od_add'] ?? '—') ?></strong>
              </div>
              <?php 
                $rxNotes = $rx['notes'] ?? ($rx['recommendations'] ?? '');
                if (!empty($rxNotes)): 
              ?>
              <div style="margin-top:6px; font-size:.72rem; color:var(--text-secondary); font-style:italic">
                <i class="far fa-comment-alt me-1"></i><?= sanitize($rxNotes) ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

