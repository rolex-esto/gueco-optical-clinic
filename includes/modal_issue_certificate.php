<?php
// Reusable Certificate of Examination Modal
?>
<div class="modal fade" id="issueCertificateModal" tabindex="-1" aria-labelledby="issueCertModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content cal-modal">
      <div class="modal-header">
        <div class="d-flex align-items-center gap-2">
          <div style="width:38px;height:38px;background:rgba(14,165,233,0.15);border:1px solid rgba(14,165,233,0.35);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#0ea5e9;">
            <i class="fas fa-file-contract"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold cal-modal-title mb-0" id="issueCertModalLabel">Issue Certificate of Examination</h5>
            <small class="text-muted">Formal Medical Clearance &bull; Physical Pad Replication</small>
          </div>
        </div>
        <button type="button" class="btn-close cal-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="formIssueCertificate" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="patient_id" id="certPatientId" value="">
        <input type="hidden" name="appointment_id" id="certApptId" value="">

        <div class="modal-body p-4">
          <div id="certAlert" class="alert alert-danger py-2 px-3 mb-3 d-none" style="font-size:0.85rem;"></div>

          <!-- Section 1: Encounter & Patient Information -->
          <div class="mb-3">
            <h6 class="fw-bold text-uppercase small text-muted mb-2" style="letter-spacing:0.5px;">
              <i class="fas fa-user-check me-1 text-primary"></i> 1. Patient & Clinic Location
            </h6>
            <div class="row g-3">
              <div class="col-md-5">
                <label class="form-label small fw-bold text-muted text-uppercase">Certificate Date <span class="text-danger">*</span></label>
                <input type="date" name="certificate_date" id="certDate" class="form-control" value="<?= date('Y-m-d') ?>" required>
              </div>

              <div class="col-md-7">
                <label class="form-label small fw-bold text-muted text-uppercase">Issuing Clinic Branch <span class="text-danger">*</span></label>
                <select name="branch" id="certBranch" class="form-select" required>
                  <option value="Poblacion, Capas, Tarlac | Cel No.: 0923-425-7857" selected>Main: Poblacion, Capas, Tarlac (0923-425-7857)</option>
                  <option value="Anupul, Bamban, Tarlac | Cel No.: 0955604372">Branch: Anupul, Bamban, Tarlac (0955604372)</option>
                </select>
              </div>

              <div class="col-md-8">
                <label class="form-label small fw-bold text-muted text-uppercase">Patient Full Name <span class="text-danger">*</span></label>
                <input type="text" name="patient_name" id="certPatientName" class="form-control" placeholder="e.g. Juan Dela Cruz" required maxlength="150">
              </div>

              <div class="col-md-4">
                <label class="form-label small fw-bold text-muted text-uppercase">Age (Years) <span class="text-danger">*</span></label>
                <input type="number" name="patient_age" id="certPatientAge" class="form-control" min="0" max="130" placeholder="e.g. 24" required>
              </div>

              <div class="col-12">
                <label class="form-label small fw-bold text-muted text-uppercase">Residential Address <span class="text-danger">*</span></label>
                <input type="text" name="patient_address" id="certPatientAddress" class="form-control" placeholder="Barangay, Municipality, Province" required maxlength="255">
                <small class="text-muted" style="font-size:0.75rem;">Fills: <em>"resident of [Address]"</em></small>
              </div>
            </div>
          </div>

          <hr style="opacity:0.15; margin:16px 0;">

          <!-- Section 2: Clinical Examination & Purpose -->
          <div class="mb-3">
            <h6 class="fw-bold text-uppercase small text-muted mb-2" style="letter-spacing:0.5px;">
              <i class="fas fa-stethoscope me-1 text-info"></i> 2. Examination Findings & Purpose
            </h6>
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label small fw-bold text-muted text-uppercase">Reason for Examination <span class="text-danger">*</span></label>
                <input type="text" name="reason_for_exam" id="certReasonForExam" class="form-control" placeholder="e.g. Refraction / Visual Acuity Assessment" required list="certReasonPresets">
                <datalist id="certReasonPresets">
                  <option value="Refraction / Visual Acuity Assessment">
                  <option value="Comprehensive Eye Examination">
                  <option value="Eyeglass Prescription Evaluation & Fitting">
                  <option value="Routine Visual Screening">
                  <option value="Astigmatism & Hyperopia Check">
                  <option value="Contact Lens Assessment">
                </datalist>
                <small class="text-muted" style="font-size:0.75rem;">Fills: <em>"was examined in this clinic because of [Reason]"</em></small>
              </div>

              <div class="col-md-6">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label small fw-bold text-muted text-uppercase mb-0">Requested By <span class="text-danger">*</span></label>
                  <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" id="btnCertUsePatientName" style="font-size:0.72rem;">
                    <i class="fas fa-arrow-down me-1"></i>Use Patient
                  </button>
                </div>
                <input type="text" name="requested_by" id="certRequestedBy" class="form-control" placeholder="Mr./Mrs./Miss Name" required maxlength="150">
                <small class="text-muted" style="font-size:0.75rem;">Fills: <em>"upon the request of Mr./Mrs./Miss [Name]"</em></small>
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-bold text-muted text-uppercase">Purpose / Remarks <span class="text-danger">*</span></label>
                <input type="text" name="purpose" id="certPurpose" class="form-control" placeholder="e.g. Employment / Pre-Employment" required list="certPurposePresets">
                <datalist id="certPurposePresets">
                  <option value="Employment / Pre-Employment">
                  <option value="Driver's License / LTO Clearance">
                  <option value="School / University Requirement">
                  <option value="Fit to Work">
                  <option value="Medical Reference / Optical Evaluation">
                  <option value="Personal Reference">
                </datalist>
                <small class="text-muted" style="font-size:0.75rem;">Fills: <em>"for [Purpose]"</em></small>
              </div>
            </div>
          </div>

          <hr style="opacity:0.15; margin:16px 0;">

          <!-- Section 3: Sign-Off Details -->
          <div>
            <h6 class="fw-bold text-uppercase small text-muted mb-2" style="letter-spacing:0.5px;">
              <i class="fas fa-signature me-1 text-success"></i> 3. Attending Optometrist Sign-off
            </h6>
            <div class="row g-3">
              <div class="col-md-5">
                <label class="form-label small fw-bold text-muted text-uppercase">Doctor Name</label>
                <input type="text" name="doctor_name" id="certDoctorName" class="form-control" value="MARIA LUZ S. GUECO, O.D." required>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold text-muted text-uppercase">Title</label>
                <input type="text" name="doctor_title" id="certDoctorTitle" class="form-control" value="OPTOMETRIST" required>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-bold text-muted text-uppercase">PRC License No.</label>
                <input type="text" name="doctor_license_no" id="certDoctorLicenseNo" class="form-control" value="LIC. NO. 4385" required>
              </div>
            </div>
          </div>

        </div>

        <div class="modal-footer d-flex justify-content-between align-items-center">
          <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" id="btnSubmitIssueCert" class="btn btn-primary btn-sm px-4">
            <i class="fas fa-print me-1"></i> Issue & Print Certificate
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
