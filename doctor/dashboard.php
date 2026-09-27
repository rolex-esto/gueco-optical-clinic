<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('doctor');

$pageTitle  = 'Dashboard';
$breadcrumb = ['Doctor'];
$activeNav  = 'dashboard';

$db    = getDB();
$today = date('Y-m-d');

// Stats (Clinical consultations only)
$totalPatients = $db->query("SELECT COUNT(*) as c FROM patients WHERE status='active'")->fetch()['c'];
$todayAppts    = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date=? AND status NOT IN ('cancelled','no_show') AND purpose != 'eyeglass_claim'");
$todayAppts->execute([$today]); $todayAppts = $todayAppts->fetch()['c'];
$pendingAppts  = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date>=? AND status='pending' AND purpose != 'eyeglass_claim'");
$pendingAppts->execute([$today]); $pendingAppts = $pendingAppts->fetch()['c'];
$totalRx       = $db->query("SELECT COUNT(*) as c FROM prescriptions")->fetch()['c'];

// Today's appointment queue (Clinical consultations only)
$queue = $db->prepare("
    SELECT a.*, p.full_name as patient_name, p.phone, p.gender
    FROM appointments a
    JOIN patients p ON p.id = a.patient_id
    WHERE a.appointment_date = ? AND a.purpose != 'eyeglass_claim'
    ORDER BY FIELD(a.status,'confirmed','pending','completed','cancelled','no_show'), a.appointment_time ASC
");
$queue->execute([$today]);
$queue = $queue->fetchAll();

// Recent patients
$recentPatients = $db->query("
    SELECT p.*, 
           (SELECT COUNT(*) FROM appointments a WHERE a.patient_id=p.id) as appt_count,
           (SELECT COUNT(*) FROM prescriptions rx WHERE rx.patient_id=p.id) as rx_count
    FROM patients p
    ORDER BY p.created_at DESC LIMIT 5
")->fetchAll();

// Weekly appointment trend (Clinical consultations)
$weekLabels = []; $weekData = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $s = $db->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date=? AND status NOT IN ('cancelled','no_show') AND purpose != 'eyeglass_claim'");
    $s->execute([$d]); $weekData[] = $s->fetch()['c'];
    $weekLabels[] = date('D', strtotime($d));
}

// Appointment status breakdown (Clinical consultations)
$apptStatus = $db->prepare("
    SELECT status, COUNT(*) as cnt FROM appointments
    WHERE appointment_date >= DATE_SUB(?, INTERVAL 30 DAY) AND purpose != 'eyeglass_claim'
    GROUP BY status
");
$apptStatus->execute([$today]);
$apptStatusData = $apptStatus->fetchAll(PDO::FETCH_KEY_PAIR);

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/dashboard.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>

<!-- Stats -->
<div class="row g-2 g-sm-3 mb-4">
  <!-- Card 1: Total Patients -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat" style="--stat-color:#0EA5E9; --stat-rgb:14, 165, 233;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Total Patients</div>
        <div class="bento-value"><?= number_format($totalPatients) ?></div>
        <div class="bento-badge blue">
          <i class="fas fa-users"></i> Registered
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-user-injured"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 2: Today's Appointments -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat" style="--stat-color:#10B981; --stat-rgb:16, 185, 129;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Today's Appointments</div>
        <div class="bento-value"><?= number_format($todayAppts) ?></div>
        <div class="bento-badge green">
          <i class="fas fa-calendar-day"></i> Scheduled today
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-calendar-check"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 3: Pending Confirmations -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat" style="--stat-color:#F59E0B; --stat-rgb:245, 158, 11;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Pending Confirmations</div>
        <div class="bento-value"><?= number_format($pendingAppts) ?></div>
        <div class="bento-badge warning">
          <i class="fas fa-hourglass-half"></i> Needs action
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-clock"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Card 4: Prescriptions Written -->
  <div class="col-6 col-lg-3">
    <div class="bento-stat" style="--stat-color:#8B5CF6; --stat-rgb:139, 92, 246;">
      <div class="bento-stat-glow"></div>
      <div class="bento-stat-left">
        <div class="bento-label">Prescriptions Written</div>
        <div class="bento-value"><?= number_format($totalRx) ?></div>
        <div class="bento-badge purple">
          <i class="fas fa-glasses"></i> All time records
        </div>
      </div>
      <div class="bento-stat-right">
        <div class="bento-stat-icon">
          <i class="fas fa-file-medical"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
  <div class="col-12 col-lg-8">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-chart-bar me-2" style="color:var(--clr-primary)"></i>Appointments — Last 7 Days</h6>
      </div>
      <div class="card-body">
        <div class="chart-container" style="height:220px;">
          <canvas id="weekChart"></canvas>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-chart-pie me-2" style="color:var(--clr-secondary)"></i>Status Breakdown (30d)</h6>
      </div>
      <div class="card-body" style="display:flex;align-items:center;justify-content:center;">
        <div class="chart-container" style="height:200px;width:200px;">
          <canvas id="statusChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Queue + Recent Patients -->
<div class="row g-3 mb-4">
  <!-- Today's Queue -->
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <h6><i class="fas fa-users me-2" style="color:var(--clr-info)"></i>Today's Patient Queue</h6>
        <a href="../doctor/appointments.php" class="btn btn-sm btn-outline-primary">Manage</a>
      </div>
      <?php if (empty($queue)): ?>
      <div class="empty-state"><div class="empty-icon"><i class="fas fa-calendar"></i></div>
        <h6>No appointments today</h6><p>Enjoy a relaxed day!</p></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>#</th><th>Patient</th><th>Time</th><th>Purpose</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($queue as $i => $appt): ?>
          <tr>
            <td style="font-weight:700;color:var(--text-muted)"><?= $i+1 ?></td>
            <td>
              <div style="font-weight:600;font-size:.83rem"><?= sanitize($appt['patient_name']) ?></div>
              <div style="font-size:.7rem;color:var(--text-muted)"><?= sanitize($appt['phone']) ?></div>
            </td>
            <td style="font-size:.83rem;font-weight:600"><?= formatTime($appt['appointment_time']) ?></td>
            <td style="font-size:.75rem"><?= ucwords(str_replace('_',' ',$appt['purpose'])) ?></td>
            <td><?= statusBadge($appt['status']) ?></td>
            <td>
              <a href="../doctor/appointments.php?manage=<?= $appt['id'] ?>" class="btn btn-sm btn-outline-primary btn-icon" title="Manage">
                <i class="fas fa-stethoscope"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Patients -->
  <div class="col-12 col-lg-6">
    <div class="card">
      <div class="card-header">
        <h6><i class="fas fa-user-injured me-2" style="color:var(--clr-secondary)"></i>Recent Patients</h6>
        <a href="../doctor/patients.php" class="btn btn-sm btn-outline-primary">All Patients</a>
      </div>
      <?php if (empty($recentPatients)): ?>
      <div class="empty-state"><div class="empty-icon"><i class="fas fa-users"></i></div>
        <h6>No patients yet</h6></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Patient</th><th>Contact</th><th>Appts</th><th>Rx</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($recentPatients as $p): 
            $ptName = getPatientDisplayName($p);
            $ptInitial = strtoupper(substr($ptName, 0, 1)) ?: 'P';
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:32px;height:32px;background:linear-gradient(135deg,var(--clr-primary),var(--clr-secondary));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.78rem;flex-shrink:0;">
                  <?= $ptInitial ?>
                </div>
                <div>
                  <div style="font-weight:600;font-size:.83rem"><?= sanitize($ptName) ?></div>
                  <div style="font-size:.7rem;color:var(--text-muted)"><?= $p['gender'] ? ucfirst($p['gender']) : '—' ?></div>
                </div>
              </div>
            </td>
            <td style="font-size:.78rem;color:var(--text-muted)"><?= sanitize($p['phone'] ?? '—') ?></td>
            <td><span class="badge bg-info"><?= $p['appt_count'] ?></span></td>
            <td><span class="badge bg-secondary"><?= $p['rx_count'] ?></span></td>
            <td>
              <a href="../doctor/patients.php?view=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary btn-icon">
                <i class="fas fa-eye"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$statusColors = ['pending'=>'#268FC8','confirmed'=>'#0891B2','completed'=>'#059669','cancelled'=>'#DC2626','no_show'=>'#64748B'];
$sLabels = array_keys($apptStatusData);
$sCounts = array_values($apptStatusData);
$sColors = array_map(fn($s) => $statusColors[$s] ?? '#64748B', $sLabels);
$sLabels = array_map(fn($s) => ucwords(str_replace('_',' ',$s)), $sLabels);

$extraScripts = '<script>
const weekCtx = document.getElementById("weekChart");
if(weekCtx){
  new Chart(weekCtx,{
    type:"bar",
    data:{
      labels:'.json_encode($weekLabels).',
      datasets:[{
        label:"Appointments",
        data:'.json_encode($weekData).',
        backgroundColor:"rgba(37,99,235,.7)",
        borderRadius:8, borderSkipped:false
      }]
    },
    options:{
      responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false}},
      scales:{
        y:{beginAtZero:true,ticks:{stepSize:1,font:{family:"Poppins",size:11}},grid:{color:"rgba(0,0,0,.05)"}},
        x:{ticks:{font:{family:"Poppins",size:11}},grid:{display:false}}
      }
    }
  });
}
const statusCtx = document.getElementById("statusChart");
if(statusCtx){
  new Chart(statusCtx,{
    type:"doughnut",
    data:{
      labels:'.json_encode($sLabels).',
      datasets:[{data:'.json_encode($sCounts).',backgroundColor:'.json_encode($sColors).',borderWidth:0,hoverOffset:6}]
    },
    options:{
      responsive:true,maintainAspectRatio:false,cutout:"68%",
      plugins:{legend:{position:"bottom",labels:{font:{family:"Poppins",size:10},padding:8,boxWidth:10}}}
    }
  });
}
</script>';
include __DIR__ . '/../includes/footer.php';
