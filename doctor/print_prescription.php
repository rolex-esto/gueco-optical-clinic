<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';

// Access control: Doctor and Admin can view/print
requireRole('doctor', 'admin');

$db = getDB();
ensurePrescriptionsSchema($db);

$rxId = (int)($_GET['id'] ?? 0);
if ($rxId <= 0) {
    die("Invalid Prescription ID.");
}

$stmt = $db->prepare("
    SELECT rx.*, 
           p.full_name as pt_db_name, 
           p.first_name as pt_first_name,
           p.last_name as pt_last_name,
           p.phone as pt_phone,
           p.email as pt_email,
           p.birthdate as pt_birthdate,
           p.gender as pt_gender,
           p.address as pt_address,
           u.full_name as issuer_name,
           u.email as issuer_email,
           u.role as issuer_role
    FROM prescriptions rx
    LEFT JOIN patients p ON p.id = rx.patient_id
    LEFT JOIN users u ON u.id = rx.doctor_id
    WHERE rx.id = ?
");
$stmt->execute([$rxId]);
$rx = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->closeCursor();

if (!$rx) {
    die("Prescription record not found.");
}

// Display values
$pName = !empty($rx['pt_db_name']) 
    ? trim($rx['pt_db_name']) 
    : trim(($rx['pt_first_name'] ?? '') . ' ' . ($rx['pt_last_name'] ?? ''));
$patientName    = htmlspecialchars($pName ?: ('Patient #' . $rx['patient_id']));
$patientAge     = !empty($rx['pt_birthdate']) ? calculateAge($rx['pt_birthdate']) : null;
$patientGender  = !empty($rx['pt_gender']) ? ucfirst($rx['pt_gender']) : '—';
$patientAddress = htmlspecialchars($rx['pt_address'] ?: 'Tarlac, Philippines');
$formattedDate  = date('F j, Y', strtotime($rx['created_at']));
$doctorName     = htmlspecialchars($rx['issuer_name'] ?: 'MARIA LUZ S. GUECO, O.D.');
$doctorTitle    = 'LICENSED OPTOMETRIST';
$odAdd          = $rx['od_add'] ?? $rx['add_power'] ?? '';
$osAdd          = $rx['os_add'] ?? $rx['add_power'] ?? '';
$notes          = $rx['notes'] ?? $rx['recommendations'] ?? '';
$pdVal          = !empty($rx['pd']) ? ($rx['pd'] . ' mm') : 'Standard';
$autoPrint      = isset($_GET['print']) && $_GET['print'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Optical Prescription Slip - Rx #<?= $rx['id'] ?> - <?= $patientName ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&display=swap" rel="stylesheet">
  <style>
    :root {
      --clr-primary: #00ADEF;
      --clr-secondary: #0F172A;
      --pad-width: 580px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      background: #e2e8f0;
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: #111827;
      padding: 24px;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 100vh;
    }

    /* Top Control Toolbar (Hidden in Print) */
    .toolbar {
      width: 100%;
      max-width: var(--pad-width);
      background: #ffffff;
      border-radius: 10px;
      padding: 12px 18px;
      margin-bottom: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
    }

    .toolbar-title {
      font-size: 0.85rem;
      font-weight: 700;
      color: #334155;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .toolbar-actions {
      display: flex;
      gap: 8px;
    }

    .btn-action {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      border-radius: 6px;
      font-size: 0.82rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.15s ease;
      border: 1px solid transparent;
    }

    .btn-print {
      background: #00ADEF;
      color: #ffffff;
    }
    .btn-print:hover {
      background: #0095ce;
    }

    .btn-back {
      background: #f1f5f9;
      color: #475569;
      border-color: #cbd5e1;
    }
    .btn-back:hover {
      background: #e2e8f0;
    }

    /* Authentic Prescription Pad Slip */
    .rx-slip {
      width: 100%;
      max-width: var(--pad-width);
      background: #ffffff;
      padding: 36px 38px 30px;
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.12);
      border: 1px solid #cbd5e1;
      position: relative;
      overflow: hidden;
    }

    .rx-watermark {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%) rotate(-15deg);
      font-size: 260px;
      font-family: 'Playfair Display', Georgia, serif;
      color: rgba(0, 173, 239, 0.04);
      user-select: none;
      pointer-events: none;
      z-index: 1;
      font-weight: 900;
    }

    .rx-content {
      position: relative;
      z-index: 2;
    }

    /* Header */
    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 2px solid #0F172A;
      padding-bottom: 16px;
      margin-bottom: 18px;
    }

    .clinic-brand {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .clinic-logo {
      width: 52px;
      height: 52px;
      object-fit: contain;
      border-radius: 8px;
    }

    .clinic-name {
      font-family: 'Playfair Display', Georgia, serif;
      font-size: 1.35rem;
      font-weight: 800;
      color: #0F172A;
      letter-spacing: -0.01em;
      line-height: 1.2;
    }

    .clinic-sub {
      font-size: 0.76rem;
      font-weight: 600;
      color: #00ADEF;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-top: 2px;
    }

    .clinic-contacts {
      text-align: right;
      font-size: 0.72rem;
      color: #475569;
      line-height: 1.45;
    }

    /* Patient Details Ribbon */
    .patient-ribbon {
      display: grid;
      grid-template-columns: 2fr 1fr 1.2fr;
      gap: 12px;
      background: #F8FAFC;
      border: 1px solid #E2E8F0;
      border-radius: 8px;
      padding: 10px 14px;
      margin-bottom: 20px;
    }

    .ribbon-label {
      font-size: 0.68rem;
      text-transform: uppercase;
      font-weight: 800;
      letter-spacing: 0.05em;
      color: #64748B;
      margin-bottom: 2px;
    }

    .ribbon-val {
      font-size: 0.88rem;
      font-weight: 700;
      color: #0F172A;
    }

    /* Rx Symbol Banner */
    .rx-symbol-banner {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 16px;
    }

    .rx-symbol-glyph {
      font-family: 'Playfair Display', Georgia, serif;
      font-size: 2.2rem;
      font-weight: 900;
      color: #00ADEF;
      line-height: 1;
    }

    .rx-banner-title {
      font-size: 0.95rem;
      font-weight: 800;
      color: #0F172A;
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .rx-badge {
      display: inline-block;
      margin-left: 8px;
      font-family: monospace;
      font-size: 0.78rem;
      color: #00ADEF;
      background: rgba(0, 173, 239, 0.1);
      padding: 2px 8px;
      border-radius: 6px;
      border: 1px solid rgba(0, 173, 239, 0.25);
    }

    /* Refraction Table */
    .rx-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 16px;
      background: #ffffff;
      border: 1px solid #E2E8F0;
      border-radius: 8px;
      overflow: hidden;
    }

    .rx-table th {
      background: #F1F5F9;
      color: #334155;
      font-size: 0.72rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      padding: 8px 10px;
      text-align: center;
      border-bottom: 1.5px solid #CBD5E1;
      border-right: 1px solid #E2E8F0;
    }

    .rx-table th:last-child {
      border-right: none;
    }

    .rx-table td {
      padding: 10px 10px;
      text-align: center;
      font-size: 0.88rem;
      font-weight: 700;
      font-family: monospace;
      color: #0F172A;
      border-bottom: 1px solid #E2E8F0;
      border-right: 1px solid #E2E8F0;
    }

    .rx-table td:last-child {
      border-right: none;
    }

    .rx-table tr:last-child td {
      border-bottom: none;
    }

    .eye-label {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-weight: 800 !important;
      font-size: 0.78rem !important;
      text-align: left !important;
      padding-left: 14px !important;
    }

    .eye-od { color: #00ADEF; }
    .eye-os { color: #7C3AED; }

    /* Extra Parameters (PD / ADD) */
    .extra-params {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 18px;
    }

    .param-box {
      background: #F8FAFC;
      border: 1px solid #E2E8F0;
      border-radius: 8px;
      padding: 8px 14px;
    }

    .param-label {
      font-size: 0.68rem;
      text-transform: uppercase;
      font-weight: 800;
      color: #64748B;
    }

    .param-val {
      font-size: 0.88rem;
      font-weight: 700;
      color: #0F172A;
      margin-top: 2px;
    }

    /* Clinical Directions / Notes */
    .rx-notes-box {
      background: #FFFBEB;
      border: 1px solid #FDE68A;
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 28px;
    }

    .rx-notes-label {
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #B45309;
      margin-bottom: 4px;
    }

    .rx-notes-body {
      font-size: 0.82rem;
      color: #78350F;
      line-height: 1.45;
      font-style: italic;
    }

    /* Footer & Signature */
    .rx-footer {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      margin-top: 24px;
      padding-top: 14px;
      border-top: 1px dashed #CBD5E1;
    }

    .rx-security-notice {
      font-size: 0.68rem;
      color: #94A3B8;
      max-width: 260px;
      line-height: 1.4;
    }

    .signature-block {
      text-align: center;
      min-width: 190px;
    }

    .signature-line {
      border-bottom: 1.5px solid #0F172A;
      margin-bottom: 6px;
      height: 24px;
    }

    .doctor-name {
      font-size: 0.85rem;
      font-weight: 800;
      color: #0F172A;
      text-transform: uppercase;
    }

    .doctor-meta {
      font-size: 0.68rem;
      color: #64748B;
      font-weight: 600;
      margin-top: 2px;
    }

    /* Print Styles */
    @media print {
      body {
        background: transparent !important;
        padding: 0 !important;
        min-height: auto !important;
      }
      .toolbar {
        display: none !important;
      }
      .rx-slip {
        box-shadow: none !important;
        border: none !important;
        padding: 10px 15px !important;
        max-width: 100% !important;
      }
      @page {
        margin: 10mm;
        size: auto;
      }
    }
  </style>
</head>
<body>

  <!-- Top Toolbar -->
  <div class="toolbar">
    <div class="toolbar-title">
      <i class="fas fa-file-prescription" style="color:#00ADEF;"></i>
      <span>Official Prescription Copy &middot; Rx #<?= $rx['id'] ?></span>
    </div>
    <div class="toolbar-actions">
      <a href="prescriptions.php<?= !empty($rx['patient_id']) ? '?patient_id=' . $rx['patient_id'] : '' ?>" class="btn-action btn-back">
        <i class="fas fa-arrow-left"></i> Back
      </a>
      <button type="button" onclick="window.print()" class="btn-action btn-print">
        <i class="fas fa-print"></i> Print Slip
      </button>
    </div>
  </div>

  <!-- Prescription Pad Slip -->
  <div class="rx-slip">
    <div class="rx-watermark">℞</div>
    <div class="rx-content">
      
      <!-- Clinic Header -->
      <div class="header">
        <div class="clinic-brand">
          <img src="<?= htmlspecialchars(getClinicLogoUrl('../')) ?>" alt="Gueco Optical Logo" class="clinic-logo">
          <div>
            <div class="clinic-name">GUECO OPTICAL CLINIC</div>
            <div class="clinic-sub">Professional Eye Care &middot; Optical Services</div>
          </div>
        </div>
        <div class="clinic-contacts">
          <div>Capas, Tarlac &middot; Angeles City, Pampanga</div>
          <div>Tel: (045) 123-4567 &middot; Mobile: 0917-123-4567</div>
          <div style="color:#00ADEF;font-weight:700;">guecooptical@gmail.com</div>
        </div>
      </div>

      <!-- Patient Demographics Ribbon -->
      <div class="patient-ribbon">
        <div>
          <div class="ribbon-label">Patient Name</div>
          <div class="ribbon-val"><?= $patientName ?></div>
        </div>
        <div>
          <div class="ribbon-label">Age / Gender</div>
          <div class="ribbon-val"><?= $patientAge ? ($patientAge . ' yrs') : '—' ?> / <?= $patientGender ?></div>
        </div>
        <div>
          <div class="ribbon-label">Date of Exam</div>
          <div class="ribbon-val"><?= $formattedDate ?></div>
        </div>
      </div>

      <!-- Rx Banner -->
      <div class="rx-symbol-banner">
        <div class="rx-symbol-glyph">℞</div>
        <div class="rx-banner-title">
          Optical Refraction Record <span class="rx-badge">Rx #<?= $rx['id'] ?></span>
        </div>
      </div>

      <!-- Optical Refraction Measurements -->
      <table class="rx-table">
        <thead>
          <tr>
            <th style="width:130px;text-align:left;padding-left:14px;">Eye</th>
            <th>SPH (Sphere)</th>
            <th>CYL (Cylinder)</th>
            <th>AXIS</th>
            <th>ADD (Near)</th>
            <?php if (!empty($rx['od_va']) || !empty($rx['os_va'])): ?>
            <th>VA (Acuity)</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="eye-label eye-od">OD (Right Eye)</td>
            <td><?= htmlspecialchars((string)($rx['od_sphere'] ?? '—')) ?></td>
            <td><?= htmlspecialchars((string)($rx['od_cylinder'] ?? '—')) ?></td>
            <td><?= !empty($rx['od_axis']) ? htmlspecialchars((string)$rx['od_axis']) . '°' : '—' ?></td>
            <td><?= htmlspecialchars((string)($odAdd ?: '—')) ?></td>
            <?php if (!empty($rx['od_va']) || !empty($rx['os_va'])): ?>
            <td><?= htmlspecialchars((string)($rx['od_va'] ?? '—')) ?></td>
            <?php endif; ?>
          </tr>
          <tr>
            <td class="eye-label eye-os">OS (Left Eye)</td>
            <td><?= htmlspecialchars((string)($rx['os_sphere'] ?? '—')) ?></td>
            <td><?= htmlspecialchars((string)($rx['os_cylinder'] ?? '—')) ?></td>
            <td><?= !empty($rx['os_axis']) ? htmlspecialchars((string)$rx['os_axis']) . '°' : '—' ?></td>
            <td><?= htmlspecialchars((string)($osAdd ?: '—')) ?></td>
            <?php if (!empty($rx['od_va']) || !empty($rx['os_va'])): ?>
            <td><?= htmlspecialchars((string)($rx['os_va'] ?? '—')) ?></td>
            <?php endif; ?>
          </tr>
        </tbody>
      </table>

      <!-- Additional Specs -->
      <div class="extra-params">
        <div class="param-box">
          <div class="param-label">Pupillary Distance (PD)</div>
          <div class="param-val"><?= htmlspecialchars((string)$pdVal) ?></div>
        </div>
        <div class="param-box">
          <div class="param-label">Recommended Lens Design</div>
          <div class="param-val"><?= htmlspecialchars((string)($rx['lens_type'] ?? 'Single Vision / As Advised')) ?></div>
        </div>
      </div>

      <!-- Clinical Notes -->
      <div class="rx-notes-box">
        <div class="rx-notes-label"><i class="fas fa-clipboard-check me-1"></i> Special Instructions &amp; Recommendations</div>
        <div class="rx-notes-body"><?= !empty($notes) ? nl2br(htmlspecialchars($notes)) : 'Standard ophthalmic lens dispensing as specified above.' ?></div>
      </div>

      <!-- Footer & Signature -->
      <div class="rx-footer">
        <div class="rx-security-notice">
          Official Gueco Optical Clinic Prescription Record.<br>
          Verified against clinical refraction protocol.
        </div>
        <div class="signature-block">
          <div class="signature-line"></div>
          <div class="doctor-name"><?= $doctorName ?></div>
          <div class="doctor-meta"><?= $doctorTitle ?> &middot; LIC. NO. 4385</div>
        </div>
      </div>

    </div>
  </div>

  <?php if ($autoPrint): ?>
  <script>
    window.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => {
        window.print();
      }, 350);
    });
  </script>
  <?php endif; ?>

</body>
</html>
