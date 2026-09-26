<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';

// Access control: Doctor and Admin can view/print
requireRole('doctor', 'admin');

$db = getDB();
ensureCertificateSchema($db);

$certId = (int)($_GET['id'] ?? 0);
if ($certId <= 0) {
    die("Invalid Certificate ID.");
}

$stmt = $db->prepare("
    SELECT c.*, 
           p.full_name as pt_db_name, 
           p.phone as pt_phone,
           p.email as pt_email,
           p.birthdate as pt_birthdate,
           p.address as pt_db_address,
           u.full_name as issuer_name,
           u.email as issuer_email
    FROM examination_certificates c
    LEFT JOIN patients p ON p.id = c.patient_id
    LEFT JOIN users u ON u.id = c.doctor_id
    WHERE c.id = ?
");
$stmt->execute([$certId]);
$cert = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cert) {
    die("Certificate of Examination record not found.");
}

// Display values
$patientName    = htmlspecialchars($cert['patient_name'] ?: ($cert['pt_db_name'] ?? ''));
$patientAge     = $cert['patient_age'] !== null ? (int)$cert['patient_age'] : calculateAge($cert['pt_birthdate']);
$patientAddress = htmlspecialchars($cert['patient_address'] ?: ($cert['pt_db_address'] ?? 'Tarlac, Philippines'));
$formattedDate  = date('F j, Y', strtotime($cert['certificate_date']));
$reasonForExam  = htmlspecialchars($cert['reason_for_exam']);
$requestedBy    = htmlspecialchars($cert['requested_by']);
$purpose        = htmlspecialchars($cert['purpose']);
$doctorName     = htmlspecialchars($cert['doctor_name'] ?: 'MARIA LUZ S. GUECO, O.D.');
$doctorTitle    = htmlspecialchars($cert['doctor_title'] ?: 'OPTOMETRIST');
$doctorLicNo    = htmlspecialchars($cert['doctor_license_no'] ?: 'LIC. NO. 4385');
$certNo         = htmlspecialchars($cert['certificate_no']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Certificate of Examination - <?= $certNo ?> - <?= $patientName ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&family=Cinzel:wght@600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --clr-primary: #1e3a8a;
      --pad-width: 540px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      background: #e2e8f0;
      font-family: 'Times New Roman', Times, 'Georgia', serif;
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
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
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
      background: #2563eb;
      color: #ffffff;
    }
    .btn-print:hover {
      background: #1d4ed8;
    }

    .btn-back {
      background: #f1f5f9;
      color: #475569;
      border-color: #cbd5e1;
    }
    .btn-back:hover {
      background: #e2e8f0;
    }

    /* Certificate Physical Pad Replication */
    .certificate-pad {
      width: 100%;
      max-width: var(--pad-width);
      background: #ffffff;
      padding: 40px 42px 48px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.12);
      border-radius: 4px;
      position: relative;
      min-height: 740px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    /* Pad Header Layout */
    .pad-header {
      display: grid;
      grid-template-columns: 1fr 1.35fr 1fr;
      align-items: flex-start;
      margin-bottom: 26px;
      line-height: 1.25;
    }

    .branch-left {
      text-align: left;
      font-size: 0.72rem;
      color: #000;
      font-weight: 500;
    }

    .branch-left .label {
      font-weight: 700;
      text-transform: capitalize;
    }

    .clinic-center {
      text-align: center;
    }

    .clinic-name {
      font-family: 'Cinzel', 'Playfair Display', 'Times New Roman', serif;
      font-size: 1.3rem;
      font-weight: 800;
      letter-spacing: 1px;
      color: #000;
      text-transform: uppercase;
      line-height: 1.1;
      margin-bottom: 2px;
    }

    .clinic-sub {
      font-size: 0.74rem;
      font-weight: 600;
      color: #000;
    }

    .clinic-tel {
      font-size: 0.72rem;
      color: #000;
      margin-top: 1px;
    }

    .hours-right {
      text-align: right;
      font-size: 0.68rem;
      color: #000;
      line-height: 1.3;
    }

    .hours-right .label {
      font-weight: 700;
    }

    /* Title */
    .pad-title-section {
      text-align: center;
      margin: 18px 0 22px;
    }

    .pad-title {
      font-family: 'Cinzel', 'Playfair Display', 'Times New Roman', serif;
      font-size: 1.05rem;
      font-weight: 800;
      letter-spacing: 2px;
      color: #000;
      text-transform: uppercase;
      display: inline-block;
      padding-bottom: 2px;
    }

    /* Date Line */
    .date-row {
      margin-bottom: 24px;
      font-size: 0.95rem;
      color: #000;
    }

    .date-label {
      font-weight: 700;
    }

    .date-val {
      font-weight: 600;
      display: inline-block;
      min-width: 140px;
      border-bottom: 1px solid #111;
      padding-bottom: 1px;
    }

    /* Salutation */
    .salutation {
      font-size: 0.96rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      margin-bottom: 20px;
      color: #000;
    }

    /* Certificate Body */
    .cert-body {
      font-size: 0.98rem;
      line-height: 2.1;
      color: #000;
      text-align: justify;
      margin-bottom: 30px;
    }

    .cert-paragraph {
      text-indent: 40px;
      margin-bottom: 22px;
    }

    /* Fill-in Underlines (Matches physical printed pad blanks) */
    .fill-blank {
      font-weight: 700;
      color: #000;
      border-bottom: 1.2px solid #222;
      padding: 0 4px 1px 4px;
      display: inline;
    }

    /* Signature Section */
    .signature-section {
      margin-top: 36px;
      display: flex;
      justify-content: flex-end;
      position: relative;
    }

    .signature-block {
      width: 250px;
      text-align: center;
      position: relative;
    }

    .signature-line {
      width: 100%;
      border-bottom: 1.5px solid #000;
      margin-bottom: 5px;
    }

    .doctor-name {
      font-family: 'Cinzel', 'Playfair Display', 'Times New Roman', serif;
      font-size: 0.88rem;
      font-weight: 800;
      letter-spacing: 0.5px;
      color: #000;
      line-height: 1.2;
    }

    .doctor-title {
      font-size: 0.76rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      color: #000;
      line-height: 1.2;
    }

    .doctor-lic {
      font-size: 0.74rem;
      font-weight: 700;
      color: #000;
      line-height: 1.2;
    }

    /* Bottom reference watermark */
    .cert-footer-meta {
      margin-top: 30px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      font-size: 0.65rem;
      color: #64748b;
      border-top: 1px dashed #cbd5e1;
      padding-top: 8px;
    }

    /* PRINT STYLES */
    @media print {
      body {
        background: #ffffff !important;
        padding: 0 !important;
        margin: 0 !important;
      }

      .no-print {
        display: none !important;
      }

      .certificate-pad {
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 24px 30px !important;
        max-width: 100% !important;
        width: 100% !important;
        min-height: auto !important;
      }

      @page {
        size: auto;
        margin: 12mm 15mm;
      }
    }
  </style>
</head>
<body>

  <!-- Top Action Toolbar -->
  <div class="toolbar no-print">
    <div class="toolbar-title">
      <i class="fas fa-file-contract text-primary"></i>
      <span>Certificate of Examination &bull; <strong><?= $certNo ?></strong></span>
    </div>
    <div class="toolbar-actions">
      <a href="patients.php?view=<?= $cert['patient_id'] ?>" class="btn-action btn-back">
        <i class="fas fa-arrow-left"></i> Patient Profile
      </a>
      <a href="appointments.php" class="btn-action btn-back">
        <i class="fas fa-calendar-alt"></i> Calendar
      </a>
      <button type="button" class="btn-action btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Certificate
      </button>
    </div>
  </div>

  <!-- Certificate Physical Pad Container -->
  <div class="certificate-pad">
    <div>
      <!-- Header -->
      <div class="pad-header">
        <div class="branch-left">
          <div class="label">Branch:</div>
          <div>Anupul, Bamban, Tarlac</div>
          <div>Cel No. 0955604372</div>
        </div>

        <div class="clinic-center">
          <div class="clinic-name">Gueco Optical</div>
          <div class="clinic-sub">Poblacion, Capas, Tarlac</div>
          <div class="clinic-tel">Cel No.: 0923-425-7857</div>
        </div>

        <div class="hours-right">
          <div class="label">Clinic Hours:</div>
          <div>Mon - Fri 9:00am - 5:00pm</div>
          <div>Saturday 9:00am - 12:00pm</div>
        </div>
      </div>

      <!-- Title -->
      <div class="pad-title-section">
        <div class="pad-title">CERTIFICATE OF EXAMINATION</div>
      </div>

      <!-- Date -->
      <div class="date-row">
        <span class="date-label">Date:</span>
        <span class="date-val"><?= $formattedDate ?></span>
      </div>

      <!-- Salutation -->
      <div class="salutation">TO WHOM IT MAY CONCERN:</div>

      <!-- Main Examination Text -->
      <div class="cert-body">
        <p class="cert-paragraph">
          This is to certify that <span class="fill-blank"><?= $patientName ?></span>, 
          <span class="fill-blank"><?= $patientAge ?></span> years of age and resident of 
          <span class="fill-blank"><?= $patientAddress ?></span>, was examined in this clinic because of 
          <span class="fill-blank"><?= $reasonForExam ?></span>.
        </p>

        <p class="cert-paragraph">
          This certification is issued upon the request of Mr./Mrs./Miss 
          <span class="fill-blank"><?= $requestedBy ?></span> for 
          <span class="fill-blank"><?= $purpose ?></span>.
        </p>
      </div>
    </div>

    <!-- Sign-off Block -->
    <div>
      <div class="signature-section">
        <div class="signature-block">
          <div class="signature-line"></div>
          <div class="doctor-name"><?= $doctorName ?></div>
          <div class="doctor-title"><?= $doctorTitle ?></div>
          <div class="doctor-lic"><?= $doctorLicNo ?></div>
        </div>
      </div>

      <!-- Verification Footer (Small metadata) -->
      <div class="cert-footer-meta">
        <span>Ref: <strong><?= $certNo ?></strong></span>
        <span>Issuing Clinic: <?= htmlspecialchars(explode('|', $cert['branch'])[0] ?? 'Capas Main') ?></span>
        <span>Issued: <?= date('Y-m-d H:i', strtotime($cert['created_at'])) ?></span>
      </div>
    </div>
  </div>

</body>
</html>
