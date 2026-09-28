<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Category Management';
$breadcrumb = ['Admin', 'Categories'];
$activeNav  = 'categories.php';
$db = getDB();
$msg = ''; $msgType = 'success';

// Handle POST
$reopenData = null;
ensureCategoriesSchema($db);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    // Add
    if ($action === 'add') {
        $name = sanitize(trim($_POST['name'] ?? ''));
        $desc = sanitize(trim($_POST['description'] ?? ''));
        if ($name) {
            $existing = $db->prepare("SELECT id FROM categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))");
            $existing->execute([$name]);
            if ($existing->fetch()) {
                $msg = "A category named \"$name\" already exists.";
                $msgType = 'danger';
            } else {
                $db->prepare("INSERT INTO categories (name, description) VALUES (?,?)")->execute([$name, $desc]);
                $msg = "Category \"$name\" added successfully.";
                logActivity("Added new category \"$name\"", "Categories", $_SESSION['user_id'], 'staff');
            }
        } else { $msg = 'Category name is required.'; $msgType = 'danger'; }
    } elseif ($action === 'edit') {
        $id   = (int)$_POST['id'];
        $name = sanitize(trim($_POST['name'] ?? ''));
        $desc = sanitize(trim($_POST['description'] ?? ''));
        $stat = $_POST['status'] ?? 'active';
        if ($name && $id) {
            $existing = $db->prepare("SELECT id FROM categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id != ?");
            $existing->execute([$name, $id]);
            if ($existing->fetch()) {
                $msg = "Another category named \"$name\" already exists.";
                $msgType = 'danger';
            } else {
                $stmt = $db->prepare("SELECT name, description, status FROM categories WHERE id=?");
                $stmt->execute([$id]);
                $old = $stmt->fetch();

                if ($old && $old['name'] === $name && $old['description'] === $desc && $old['status'] === $stat) {
                    $msg = "No changes were made. The category is already " . strtoupper($stat) . "!";
                    $msgType = "info";
                    $reopenData = ['id' => $id, 'name' => $name, 'desc' => $desc, 'stat' => $stat];
                } else {
                    $db->prepare("UPDATE categories SET name=?, description=?, status=? WHERE id=?")->execute([$name, $desc, $stat, $id]);
                    $msg = "Category successfully updated. Status is now " . strtoupper($stat) . "!";
                    $msgType = "success";
                    logActivity("Updated category \"$name\" (Status: " . strtoupper($stat) . ")", "Categories", $_SESSION['user_id'], 'staff');
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)$_POST['id'];
        $currentStatus = $_POST['current_status'] ?? 'active';
        $newStatus = $currentStatus === 'active' ? 'inactive' : 'active';
        $db->prepare("UPDATE categories SET status=? WHERE id=?")->execute([$newStatus, $id]);
        $msg = "Category " . ($newStatus === 'active' ? 'activated' : 'deactivated') . " successfully.";
        $msgType = "success";
        logActivity(($newStatus === 'active' ? "Activated" : "Deactivated") . " category #$id", "Categories", $_SESSION['user_id'], 'staff');
    }
}

$categories = $db->query("
    SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.status='active') as product_count
    FROM categories c ORDER BY c.name ASC
")->fetchAll();

$totalCats = count($categories);
$activeCats = count(array_filter($categories, fn($c) => $c['status'] === 'active'));

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/categories.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        title: '<?= $msgType === "success" ? "Success!" : ($msgType === "info" ? "Notice" : "Error") ?>',
        text: '<?= addslashes($msg) ?>',
        icon: '<?= $msgType === "success" ? "success" : ($msgType === "info" ? "info" : "error") ?>',
        confirmButtonColor: 'var(--clr-primary)',
        background: 'var(--bg-card)',
        color: 'var(--text-primary)',
        timer: 3000,
        timerProgressBar: true
    });
});
</script>
<?php endif; ?>

<!-- Header & Add Button -->
<div class="cat-header-wrap">
  <div class="cat-header-left">
    <div class="cat-header-icon-box">
      <i class="fas fa-layer-group"></i>
    </div>
    <div class="cat-header-titles">
      <h4>Product Categories</h4>
      <p>Organize inventory, optical frames, lenses, and clinic supplies</p>
    </div>
  </div>
  <div style="display:flex; align-items:center; gap:10px;">
    <span class="cat-stat-pill">
      <i class="fas fa-cubes text-primary"></i> <?= $totalCats ?> Total (<?= $activeCats ?> Active)
    </span>
    <button class="btn btn-primary" onclick="openModal('addModal')">
      <i class="fas fa-plus"></i> Add Category
    </button>
  </div>
</div>

<!-- Search & Filter Toolbar -->
<div class="cat-toolbar">
  <div class="cat-search-box">
    <i class="fas fa-search"></i>
    <input type="text" id="catSearch" placeholder="Search categories by name or description..." autocomplete="off">
  </div>
  <div class="cat-filter-tabs">
    <button type="button" class="cat-filter-btn active" data-filter="all">All (<?= $totalCats ?>)</button>
    <button type="button" class="cat-filter-btn" data-filter="active">Active (<?= $activeCats ?>)</button>
    <button type="button" class="cat-filter-btn" data-filter="inactive">Inactive (<?= $totalCats - $activeCats ?>)</button>
  </div>
</div>

<!-- Table Card -->
<div class="table-wrapper">
  <div class="table-responsive">
    <table class="table" id="catTable">
      <thead>
        <tr>
          <th style="width:60px;">#</th>
          <th>Category Name</th>
          <th>Description</th>
          <th style="width:140px;">Products</th>
          <th style="width:120px;">Status</th>
          <th style="width:150px;">Created Date</th>
          <th style="width:110px; text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($categories)): ?>
        <tr id="noDataRow">
          <td colspan="7">
            <div class="empty-state" style="text-align:center; padding:48px 20px;">
              <div class="empty-icon" style="font-size:2.5rem; color:var(--text-muted); margin-bottom:12px;">
                <i class="fas fa-layer-group"></i>
              </div>
              <h6 style="font-weight:700; color:var(--text-primary); margin-bottom:4px;">No categories found</h6>
              <p style="font-size:0.84rem; color:var(--text-muted);">Click "+ Add Category" to create your first product category.</p>
            </div>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($categories as $i => $cat): ?>
        <tr class="cat-row" data-name="<?= strtolower(htmlspecialchars($cat['name'])) ?>" data-desc="<?= strtolower(htmlspecialchars($cat['description'] ?? '')) ?>" data-status="<?= $cat['status'] ?>">
          <td style="color:var(--text-muted); font-size:0.78rem; font-weight:600;"><?= $i+1 ?></td>
          <td>
            <div class="cat-cell-name">
              <div class="cat-icon-badge">
                <i class="fas fa-folder-open"></i>
              </div>
              <div>
                <span class="cat-name-text"><?= sanitize($cat['name']) ?></span>
              </div>
            </div>
          </td>
          <td style="color:var(--text-secondary); font-size:0.83rem; max-width:320px;">
            <?= sanitize($cat['description'] ?: '—') ?>
          </td>
          <td>
            <span class="cat-count-badge">
              <i class="fas fa-boxes"></i> <?= $cat['product_count'] ?> products
            </span>
          </td>
          <td><?= statusBadge($cat['status']) ?></td>
          <td style="color:var(--text-muted); font-size:0.78rem;"><?= formatDate($cat['created_at']) ?></td>
          <td style="text-align:right;">
            <div class="cat-actions-group" style="justify-content:flex-end;">
              <button class="btn btn-sm btn-outline-primary btn-icon" title="Edit Category"
                onclick="openEdit(<?= $cat['id'] ?>, '<?= addslashes($cat['name']) ?>', '<?= addslashes($cat['description'] ?? '') ?>', '<?= $cat['status'] ?>')">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" style="display:inline-block; margin:0;">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                <input type="hidden" name="current_status" value="<?= $cat['status'] ?>">
                <button class="btn btn-sm <?= $cat['status']==='active'?'btn-warning':'btn-success' ?> btn-icon" 
                        title="<?= $cat['status']==='active'?'Deactivate Category':'Activate Category' ?>" 
                        data-confirm="<?= $cat['status']==='active'?'Deactivate':'Activate' ?> category &quot;<?= addslashes($cat['name']) ?>&quot;?">
                  <i class="fas fa-<?= $cat['status']==='active'?'ban':'check' ?>"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <tr id="noMatchRow" style="display:none;">
          <td colspan="7">
            <div class="empty-state" style="text-align:center; padding:36px 20px;">
              <div class="empty-icon" style="font-size:2rem; color:var(--text-muted); margin-bottom:8px;">
                <i class="fas fa-search"></i>
              </div>
              <h6 style="font-weight:700; color:var(--text-primary); margin-bottom:4px;">No matching categories</h6>
              <p style="font-size:0.82rem; color:var(--text-muted);">Try adjusting your search query or filter tab.</p>
            </div>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Category Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box">
    <div class="modal-header">
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="modal-icon-badge">
          <i class="fas fa-folder-plus"></i>
        </div>
        <div class="modal-header-titles">
          <h5>Add Category</h5>
          <small>Create a new product grouping</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('addModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label">Category Name <span style="color:var(--clr-danger)">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="e.g. Optical Frames, Single Vision Lenses" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3" placeholder="Brief details about what products belong to this category..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Category</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Category Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-header">
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="modal-icon-badge">
          <i class="fas fa-pen-to-square"></i>
        </div>
        <div class="modal-header-titles">
          <h5>Edit Category</h5>
          <small>Modify category details and status</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('editModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" onsubmit="return confirmEditCategory(event, this)">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="id" id="editId">
        <div class="form-group">
          <label class="form-label">Category Name <span style="color:var(--clr-danger)">*</span></label>
          <input type="text" name="name" id="editName" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" id="editDesc" class="form-control" rows="3" placeholder="Brief details..."></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" id="editStatus" class="form-select">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Category</button>
      </div>
    </form>
  </div>
</div>

<script>
let currentEditCat = null;
function openEdit(id, name, desc, status) {
  currentEditCat = { id, name, desc, status };
  document.getElementById('editId').value = id;
  document.getElementById('editName').value = name;
  document.getElementById('editDesc').value = desc;
  document.getElementById('editStatus').value = status;
  openModal('editModal');
}

function confirmEditCategory(e, form) {
  e.preventDefault();
  const c = currentEditCat;
  if (c) {
    const name = document.getElementById('editName').value.trim();
    const desc = document.getElementById('editDesc').value.trim();
    const status = document.getElementById('editStatus').value;

    if (name === c.name && desc === (c.desc || '') && status === c.status) {
      Swal.fire({
        title: 'Notice',
        text: 'No changes were made. The category is already up to date!',
        icon: 'info',
        background: 'var(--bg-card)',
        color: 'var(--text-primary)',
        confirmButtonColor: 'var(--clr-primary)'
      });
      return false;
    }
  }

  Swal.fire({
    title: 'Save Changes?',
    text: 'Are you sure you want to update this category?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: 'var(--clr-primary)',
    cancelButtonColor: 'var(--clr-danger)',
    confirmButtonText: 'Yes, update it!',
    cancelButtonText: 'Cancel',
    background: 'var(--bg-card)',
    color: 'var(--text-primary)'
  }).then((result) => {
    if (result.isConfirmed) {
      form._isSubmitting = true;
      form.submit();
    }
  });
  return false;
}

// Live Search and Filter
document.addEventListener('DOMContentLoaded', function() {
  const searchInput = document.getElementById('catSearch');
  const filterBtns = document.querySelectorAll('.cat-filter-btn');
  const rows = document.querySelectorAll('.cat-row');
  const noMatchRow = document.getElementById('noMatchRow');
  let currentFilter = 'all';

  function applyFilter() {
    const q = searchInput ? searchInput.value.toLowerCase().trim() : '';
    let visibleCount = 0;

    rows.forEach(row => {
      const name = row.dataset.name || '';
      const desc = row.dataset.desc || '';
      const status = row.dataset.status || '';

      const matchesSearch = !q || name.includes(q) || desc.includes(q);
      const matchesFilter = (currentFilter === 'all') || (status === currentFilter);

      if (matchesSearch && matchesFilter) {
        row.style.display = '';
        visibleCount++;
      } else {
        row.style.display = 'none';
      }
    });

    if (noMatchRow) {
      noMatchRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyFilter);
  }

  filterBtns.forEach(btn => {
    btn.addEventListener('click', function() {
      filterBtns.forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      currentFilter = this.dataset.filter;
      applyFilter();
    });
  });
});

<?php if ($reopenData): ?>
// Re-open the modal automatically if there were no changes
document.addEventListener("DOMContentLoaded", function() {
    openEdit(
        <?= $reopenData['id'] ?>, 
        '<?= addslashes($reopenData['name']) ?>', 
        '<?= addslashes($reopenData['desc']) ?>', 
        '<?= $reopenData['stat'] ?>'
    );
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>