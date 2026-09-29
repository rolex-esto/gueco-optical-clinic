<?php
define('BASE_URL', '../');
require_once __DIR__ . '/../config/functions.php';
requireRole('admin');

$pageTitle  = 'Page Management';
$breadcrumb = ['Admin', 'Page Management'];
$activeNav  = 'page_management.php';
$db = getDB();
$msg = ''; $msgType = 'success';

// ── Auto-create tables if they don't exist ──────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS clinic_booking_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_key VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(150) NOT NULL,
    icon VARCHAR(60) DEFAULT 'fa-calendar-check',
    description TEXT,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Seed default booking categories if empty ────────────────────────────────
$catCount = (int)$db->query("SELECT COUNT(*) FROM clinic_booking_categories")->fetchColumn();
if ($catCount === 0) {
    $defaultCats = [
        ['consultation', 'Eye Consultation & Check-up', 'fa-user-doctor', 'Comprehensive examination, visual acuity test, and licensed doctor consultation.', 1],
        ['eyeglass_claim', 'Eyeglasses & Frames', 'fa-glasses', 'Prescription frame selection, lens upgrades, claiming ready spectacles.', 2],
        ['contact_lens_fitting', 'Contact Lens Care', 'fa-circle-dot', 'Cornea curvature measurement, trial lens fitting, and supply orders.', 3],
        ['follow_up', 'Follow-up Visit', 'fa-rotate-right', 'Post-examination check, lens adaptation review, and progress evaluation.', 4],
        ['other', 'General Optical Services', 'fa-screwdriver-wrench', 'Frame repairs, ultrasonic bath cleaning, screw adjustments, or inquiries.', 5],
    ];
    $cIns = $db->prepare("INSERT INTO clinic_booking_categories (category_key, name, icon, description, sort_order) VALUES (?,?,?,?,?)");
    foreach ($defaultCats as $c) $cIns->execute($c);
}

$db->exec("CREATE TABLE IF NOT EXISTS clinic_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    purpose_category VARCHAR(50) NOT NULL,
    badge VARCHAR(50),
    description TEXT,
    duration VARCHAR(30),
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS clinic_faqs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    icon VARCHAR(50) DEFAULT 'fa-circle-question',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Seed default services if empty ─────────────────────────────────────────
$svcCount = (int)$db->query("SELECT COUNT(*) FROM clinic_services")->fetchColumn();
if ($svcCount === 0) {
    $defaults = [
        ['Comprehensive Eye Examination','consultation','Examination','Full eye health check, visual acuity test, and digital refraction test.','30–45 mins',1],
        ['Prescription & Visual Acuity Test','consultation','Examination','Precise sphere, cylinder & axis measurement for reading or distance glasses.','20–30 mins',2],
        ['Pediatric & Student Vision Screening','consultation','Specialized','Gentle eye exam designed for children, students, and early myopia detection.','25–35 mins',3],
        ['Senior Vision & Cataract Screening','consultation','Specialized','Assessment for presbyopia, cataracts, and age-related visual changes.','30–45 mins',4],
        ['Eyeglass Frame Selection & Styling','eyeglass_claim','Eyewear','Bridge sizing, facial ergonomics, and personalized frame styling assistance.','20–30 mins',5],
        ['Lens Upgrade (Blue Light / Transitions)','eyeglass_claim','Lenses','Anti-radiation computer lenses, photochromic transitions, or progressive lenses.','15–20 mins',6],
        ['Eyeglass Pick-up & Final Alignment','eyeglass_claim','Eyewear','Claim completed prescription glasses with custom temple & nosepad fitting.','15 mins',7],
        ['Frame Repair & Ultrasonic Cleaning','other','Care','Nosepad replacement, frame realignment, screw tightening, and deep ultrasonic bath.','15–20 mins',8],
        ['Contact Lens Fitting & Insertion Training','contact_lens_fitting','Contacts','Corneal measurement, comfort trial fitting, and contact lens handling training.','30–40 mins',9],
        ['Contact Lens Replenishment / Pick-up','contact_lens_fitting','Contacts','Claim monthly, bi-weekly, or daily disposable contact lens supplies.','10–15 mins',10],
        ['Post-Consultation Prescription Check','follow_up','Follow-up','Re-evaluating vision adaptation and visual comfort with newly acquired glasses.','15–20 mins',11],
        ['General Optical Inquiries & Consultation','other','General','Discuss specific vision concerns, eye symptoms, referrals, or clinic services.','15–20 mins',12],
    ];
    $ins = $db->prepare("INSERT INTO clinic_services (name,purpose_category,badge,description,duration,sort_order) VALUES (?,?,?,?,?,?)");
    foreach ($defaults as $d) $ins->execute($d);
}

// ── Seed default FAQs if empty ──────────────────────────────────────────────
$faqCount = (int)$db->query("SELECT COUNT(*) FROM clinic_faqs")->fetchColumn();
if ($faqCount === 0) {
    $defaultFaqs = [
        ['How often should I have a comprehensive eye examination?', 'Both adults and children are recommended to undergo a professional eye examination at least once every 12 months. Routine checkups ensure your optical prescription remains accurate and help detect subtle vision changes early. Patients who wear contact lenses, spend long hours on digital screens, or have pre-existing health conditions such as diabetes or hypertension may benefit from semi-annual checkups.', 'fa-eye', 1],
        ['How do I schedule an appointment through the patient portal?', 'Booking an appointment is seamless! Simply click the "Book an Appointment" button anywhere on this page. You can log in or register in seconds using your email address or Google Account. Once inside, select your preferred clinic date, convenient time slot, and reason for visit. You will receive immediate booking confirmation and appointment reminders.', 'fa-calendar-check', 2],
        ['What should I bring to my optical appointment?', 'To help our optometrists provide the most accurate assessment, please bring: your current eyeglasses or contact lens prescription details (if any), a valid photo ID for patient identification, a list of any current medications, eye drops, or chronic conditions (e.g., allergies, diabetes), and your sunglasses in case your eyes feel sensitive to bright light following ophthalmic screening.', 'fa-clipboard-list', 3],
        ['How long does it take to prepare my new prescription eyewear?', 'Standard single-vision prescription lenses and in-stock frames are typically crafted and ready for dispensing within 1 to 2 business days. Custom specialty orders including progressive multifocal lenses, ultra-thin high-index materials, blue-light blocking filters, and photochromic transition lenses typically require 3 to 5 business days for optical surfacing and quality inspection.', 'fa-glasses', 4],
        ['Do you offer warranties and aftercare on eyeglasses?', 'Yes! All authentic designer frames and premium prescription lens coatings purchased at Gueco Optical Clinic include manufacturer warranty coverage against verified factory defects. In addition, every patient receives Free Lifetime Maintenance including complimentary ultrasonic cleaning, screw tightening, nose pad replacements, and custom frame adjustments whenever you visit our clinic in Capas, Tarlac.', 'fa-shield-halved', 5],
        ['Is my personal and medical health information kept private?', 'Your health privacy is our utmost priority. All patient records, clinical charts, refraction results, and contact information are strictly protected under the Philippine Data Privacy Act of 2012 (RA 10173). We adhere to strict medical confidentiality. We never sell, rent, or distribute your personal details to outside advertisers or third parties.', 'fa-user-shield', 6],
    ];
    $faqIns = $db->prepare("INSERT INTO clinic_faqs (question,answer,icon,sort_order) VALUES (?,?,?,?)");
    foreach ($defaultFaqs as $f) $faqIns->execute($f);
}

// ── Seed default site_settings if empty ─────────────────────────────────────
$settCount = (int)$db->query("SELECT COUNT(*) FROM site_settings")->fetchColumn();
if ($settCount === 0) {
    $settDefaults = [
        ['hero_badge',        'Established in 1986'],
        ['hero_headline',     'See the World'],
        ['hero_highlight',    'Clearly & Beautifully'],
        ['hero_description',  'Providing exceptional, comprehensive eye care services to the Capas community. We combine state-of-the-art technology with compassionate care to help you achieve your best vision.'],
        ['stat1_value',       '40+'],
        ['stat1_label',       'Years of Service'],
        ['stat2_value',       '10k+'],
        ['stat2_label',       'Happy Patients'],
        ['stat3_value',       '100%'],
        ['stat3_label',       'Commitment'],
        ['card1_icon',        'fa-user-md'],
        ['card1_title',       'Expert Optometrists'],
        ['card1_desc',        'Our highly trained professionals provide thorough eye exams, accurate prescriptions, and personalized care tailored to your unique visual needs.'],
        ['card2_icon',        'fa-glasses'],
        ['card2_title',       'Premium Eyewear'],
        ['card2_desc',        'Choose from a wide selection of stylish frames, premium lenses, and comfortable contact lenses sourced from top international brands.'],
        ['card3_icon',        'fa-map-marker-alt'],
        ['card3_title',       'Convenient Location'],
        ['card3_desc',        'Located in the heart of Capas, Tarlac. We provide a comfortable, welcoming environment with modern facilities for all our patients.'],
    ];
    $sIns = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?)");
    foreach ($settDefaults as $s) $sIns->execute($s);
}

// ── Auto-migrate legacy <span> from hero_headline if present ────────────────
try {
    $existingHeadline = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'hero_headline'")->fetchColumn();
    if ($existingHeadline && strpos($existingHeadline, '<span') !== false) {
        $db->prepare("UPDATE site_settings SET setting_value = 'See the World' WHERE setting_key = 'hero_headline'")->execute();
        $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('hero_highlight', 'Clearly & Beautifully') ON DUPLICATE KEY UPDATE setting_value = 'Clearly & Beautifully'")->execute();
    }
} catch (Throwable $e) {}

// ── Available Clinic Icons for Quick Picker ────────────────────────────────
$availableIcons = [
    'fa-user-md'              => ['Doctor / Optometrist', 'Professional eye care specialist'],
    'fa-glasses'              => ['Eyewear & Frames', 'Designer frames and lenses'],
    'fa-eye'                  => ['Eye Examination', 'Vision testing and checkup'],
    'fa-map-marker-alt'       => ['Clinic Location', 'Capas, Tarlac clinic branch'],
    'fa-stethoscope'          => ['Medical Care', 'Ophthalmic consultations'],
    'fa-award'                => ['Certified Quality', 'Licensed practice excellence'],
    'fa-shield-halved'        => ['Warranty & Protection', 'Lifetime maintenance warranty'],
    'fa-clock'                => ['Fast Service', 'Quick dispensing & turnaround'],
    'fa-heart'                => ['Patient Care', 'Gentle, compassionate service'],
    'fa-microscope'           => ['Modern Equipment', 'High-precision digital tools'],
    'fa-calendar-check'       => ['Appointment Booking', 'Flexible scheduling'],
    'fa-clipboard-list'       => ['Prescription Records', 'Accurate optical measurements'],
    'fa-hospital'             => ['Clinic Facility', 'Clean, comfortable clinic'],
    'fa-headset'              => ['Patient Support', 'Inquiries and assistance'],
    'fa-thumbs-up'            => ['Trusted Service', 'Over 40 years community trust'],
    'fa-hand-holding-medical' => ['Care & Comfort', 'Dedicated vision treatment'],
    'fa-gem'                  => ['Premium Eyewear', 'Luxury and designer brands'],
    'fa-circle-question'      => ['Help & FAQs', 'General inquiries & support'],
    'fa-user-shield'          => ['Privacy & Records', 'Secure patient health privacy'],
    'fa-sparkles'             => ['Specialty Lenses', 'Blue light & transition lenses']
];

// ── POST Handler ─────────────────────────────────────────────────────────────
$reopenData = null;
$activeTab  = $_POST['active_tab'] ?? $_GET['tab'] ?? 'homepage';
$activeSvcSubTab = $_POST['svc_sub_tab'] ?? $_GET['sub_tab'] ?? 'cats';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    $activeTab = $_POST['active_tab'] ?? 'homepage';
    $activeSvcSubTab = $_POST['svc_sub_tab'] ?? 'cats';

    // ── HOMEPAGE SETTINGS ──
    if ($action === 'save_homepage') {
        $keys = ['hero_badge','hero_headline','hero_highlight','hero_description',
                 'stat1_value','stat1_label','stat2_value','stat2_label','stat3_value','stat3_label',
                 'card1_icon','card1_title','card1_desc',
                 'card2_icon','card2_title','card2_desc',
                 'card3_icon','card3_title','card3_desc'];

        // Retrieve existing settings to detect if anything changed
        $currSettings = [];
        foreach ($db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll() as $row) {
            $currSettings[$row['setting_key']] = $row['setting_value'];
        }

        $hasChanges = false;
        foreach ($keys as $k) {
            $val = trim($_POST[$k] ?? '');
            if (!isset($currSettings[$k]) || trim($currSettings[$k]) !== $val) {
                $hasChanges = true;
                break;
            }
        }

        if (!$hasChanges) {
            $msg = 'No changes were made. Homepage content is already up to date!';
            $msgType = 'info';
        } else {
            $upsert = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?)
                                    ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            foreach ($keys as $k) {
                $val = trim($_POST[$k] ?? '');
                $upsert->execute([$k, $val]);
            }
            $msg = 'Homepage content updated successfully! Changes are now live on the patient website.';
            $msgType = 'success';
            logActivity('Updated homepage content via Page Management', 'Page Management', $_SESSION['user_id'], 'staff');
        }
    }

    // ── CLINIC LOGO: UPLOAD ──
    elseif ($action === 'upload_logo') {
        if (isset($_FILES['clinic_logo']) && $_FILES['clinic_logo']['error'] === UPLOAD_ERR_OK) {
            $allowedExts  = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
            $fileTmp      = $_FILES['clinic_logo']['tmp_name'];
            $fileName     = $_FILES['clinic_logo']['name'];
            $fileExt      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $fileSize     = $_FILES['clinic_logo']['size'];

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $fileMime = finfo_file($finfo, $fileTmp);
            finfo_close($finfo);

            if (!in_array($fileExt, $allowedExts) || !in_array($fileMime, $allowedMimes)) {
                $msg = 'Invalid image format. Please upload a PNG, JPG, WEBP, or SVG file.';
                $msgType = 'danger';
            } elseif ($fileSize > 5 * 1024 * 1024) {
                $msg = 'File size is too large. Maximum allowed size is 5MB.';
                $msgType = 'danger';
            } else {
                $destDir = __DIR__ . '/../assets/images/';
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }

                // Ensure backup of default logo exists
                if (!file_exists($destDir . 'logo_default_backup.png') && file_exists($destDir . 'logo.png')) {
                    @copy($destDir . 'logo.png', $destDir . 'logo_default_backup.png');
                }

                $newLogoName = 'clinic_logo_' . time() . '.' . $fileExt;
                $destPath = $destDir . $newLogoName;

                if (move_uploaded_file($fileTmp, $destPath)) {
                    // Remove previous custom logo if it exists
                    $prevLogo = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'clinic_logo'")->fetchColumn();
                    if ($prevLogo && !empty($prevLogo)) {
                        $prevFile = __DIR__ . '/../' . ltrim($prevLogo, '/');
                        if (file_exists($prevFile) && $prevFile !== $destPath && !str_contains($prevFile, 'logo_default_backup') && basename($prevFile) !== 'logo.png') {
                            @unlink($prevFile);
                        }
                    }

                    // Mirror to assets/images/logo.png for universal compatibility
                    @copy($destPath, $destDir . 'logo.png');

                    $relPath = 'assets/images/' . $newLogoName;
                    $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('clinic_logo', ?)
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$relPath]);

                    $msg = 'Clinic logo updated successfully! The new logo is now active across your patient website, portal, and clinic branding.';
                    $msgType = 'success';
                    logActivity('Updated official clinic logo', 'Page Management', $_SESSION['user_id'], 'staff');
                } else {
                    $msg = 'Failed to upload logo image. Please check directory permissions.';
                    $msgType = 'danger';
                }
            }
        } else {
            $msg = 'Please select a logo image file to upload.';
            $msgType = 'danger';
        }
    }

    // ── CLINIC LOGO: RESET TO DEFAULT ──
    elseif ($action === 'reset_logo') {
        $destDir = __DIR__ . '/../assets/images/';
        if (file_exists($destDir . 'logo_default_backup.png')) {
            @copy($destDir . 'logo_default_backup.png', $destDir . 'logo.png');
        }

        $prevLogo = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'clinic_logo'")->fetchColumn();
        if ($prevLogo && !empty($prevLogo)) {
            $prevFile = __DIR__ . '/../' . ltrim($prevLogo, '/');
            if (file_exists($prevFile) && !str_contains($prevFile, 'logo_default_backup') && basename($prevFile) !== 'logo.png') {
                @unlink($prevFile);
            }
        }

        $db->prepare("DELETE FROM site_settings WHERE setting_key = 'clinic_logo'")->execute();
        $msg = 'Clinic logo has been reset to the default official logo.';
        $msgType = 'success';
        logActivity('Reset clinic logo to default', 'Page Management', $_SESSION['user_id'], 'staff');
    }

    // ── BOOKING CATEGORIES: ADD ──
    elseif ($action === 'add_booking_category') {
        $activeTab = 'services';
        $activeSvcSubTab = 'cats';
        $name = trim(strip_tags($_POST['cat_name'] ?? ''));
        $key  = trim(strip_tags($_POST['cat_key'] ?? ''));
        $icon = trim(strip_tags($_POST['cat_icon'] ?? 'fa-calendar-check'));
        $desc = trim(strip_tags($_POST['cat_desc'] ?? ''));

        if (!$key && $name) {
            $key = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', trim($name)));
        }
        $key = trim($key, '_');

        if ($name && $key) {
            $cleanName = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
            $cleanDesc = html_entity_decode($desc, ENT_QUOTES, 'UTF-8');

            $chk = $db->prepare("SELECT COUNT(*) FROM clinic_booking_categories WHERE category_key = ?");
            $chk->execute([$key]);
            if ($chk->fetchColumn() > 0) {
                $msg = "A booking category with key \"$key\" already exists. Please choose a different category key or name.";
                $msgType = 'danger';
            } else {
                $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM clinic_booking_categories")->fetchColumn();
                $db->prepare("INSERT INTO clinic_booking_categories (category_key, name, icon, description, sort_order) VALUES (?,?,?,?,?)")
                   ->execute([$key, $cleanName, $icon, $cleanDesc, $maxOrder + 1]);
                $msg = "Booking Category \"$cleanName\" created successfully.";
                $msgType = 'success';
                logActivity("Added booking category \"$cleanName\"", 'Page Management', $_SESSION['user_id'], 'staff');
            }
        } else {
            $msg = 'Category name and identifier key are required.';
            $msgType = 'danger';
        }
    }

    // ── BOOKING CATEGORIES: EDIT ──
    elseif ($action === 'edit_booking_category') {
        $activeTab = 'services';
        $activeSvcSubTab = 'cats';
        $id   = (int)($_POST['cat_id'] ?? 0);
        $name = trim(strip_tags($_POST['cat_name'] ?? ''));
        $key  = trim(strip_tags($_POST['cat_key'] ?? ''));
        $icon = trim(strip_tags($_POST['cat_icon'] ?? 'fa-calendar-check'));
        $desc = trim(strip_tags($_POST['cat_desc'] ?? ''));
        $stat = (int)($_POST['cat_active'] ?? 1);

        if (!$key && $name) {
            $key = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', trim($name)));
        }
        $key = trim($key, '_');

        if ($name && $key && $id) {
            $stmt = $db->prepare("SELECT category_key, name, icon, description, is_active FROM clinic_booking_categories WHERE id = ?");
            $stmt->execute([$id]);
            $old = $stmt->fetch();

            $cleanName = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
            $cleanDesc = html_entity_decode($desc, ENT_QUOTES, 'UTF-8');

            if ($old &&
                trim($old['category_key']) === $key &&
                trim(html_entity_decode($old['name'], ENT_QUOTES, 'UTF-8')) === $cleanName &&
                trim($old['icon']) === $icon &&
                trim(html_entity_decode($old['description'] ?? '', ENT_QUOTES, 'UTF-8')) === $cleanDesc &&
                (int)$old['is_active'] === $stat
            ) {
                $msg = "No changes were made. Category \"$cleanName\" is already up to date!";
                $msgType = 'info';
            } else {
                $oldKey = $old['category_key'] ?? '';
                // Check if key is used by another record
                $chk = $db->prepare("SELECT COUNT(*) FROM clinic_booking_categories WHERE category_key = ? AND id != ?");
                $chk->execute([$key, $id]);
                if ($chk->fetchColumn() > 0) {
                    $msg = "Category key \"$key\" is already used by another category. Please choose a unique key.";
                    $msgType = 'danger';
                } else {
                    $db->prepare("UPDATE clinic_booking_categories SET category_key=?, name=?, icon=?, description=?, is_active=? WHERE id=?")
                       ->execute([$key, $cleanName, $icon, $cleanDesc, $stat, $id]);

                    // If key changed, update linked clinic_services
                    if ($oldKey && $oldKey !== $key) {
                        $db->prepare("UPDATE clinic_services SET purpose_category = ? WHERE purpose_category = ?")
                           ->execute([$key, $oldKey]);
                    }

                    $msg = "Booking Category \"$cleanName\" updated successfully.";
                    $msgType = 'success';
                    logActivity("Updated booking category #$id \"$cleanName\"", 'Page Management', $_SESSION['user_id'], 'staff');
                }
            }
        } else {
            $msg = 'Category name and identifier key are required.';
            $msgType = 'danger';
        }
    }

    // ── BOOKING CATEGORIES: TOGGLE ──
    elseif ($action === 'toggle_booking_category') {
        $activeTab = 'services';
        $activeSvcSubTab = 'cats';
        $id  = (int)($_POST['cat_id'] ?? 0);
        $cur = (int)($_POST['cat_current'] ?? 1);
        $new = $cur ? 0 : 1;
        $db->prepare("UPDATE clinic_booking_categories SET is_active=? WHERE id=?")->execute([$new, $id]);
        $msg = 'Category ' . ($new ? 'activated and visible in patient booking' : 'hidden from patient booking') . ' successfully.';
        $msgType = 'success';
        logActivity(($new ? 'Activated' : 'Deactivated') . " booking category #$id", 'Page Management', $_SESSION['user_id'], 'staff');
    }

    // ── BOOKING CATEGORIES: DELETE ──
    elseif ($action === 'delete_booking_category') {
        $activeTab = 'services';
        $activeSvcSubTab = 'cats';
        $id = (int)($_POST['cat_id'] ?? 0);
        if ($id) {
            $catKey = $db->prepare("SELECT category_key, name FROM clinic_booking_categories WHERE id = ?");
            $catKey->execute([$id]);
            $catRow = $catKey->fetch();
            if ($catRow) {
                $chk = $db->prepare("SELECT COUNT(*) FROM clinic_services WHERE purpose_category = ?");
                $chk->execute([$catRow['category_key']]);
                $linkedCount = (int)$chk->fetchColumn();

                if ($linkedCount > 0) {
                    $msg = "Cannot delete category \"{$catRow['name']}\" because it currently has $linkedCount service(s) linked to it. Please reassign or delete those services first.";
                    $msgType = 'danger';
                } else {
                    $db->prepare("DELETE FROM clinic_booking_categories WHERE id = ?")->execute([$id]);
                    $msg = "Category \"{$catRow['name']}\" deleted successfully.";
                    $msgType = 'success';
                    logActivity("Deleted booking category #$id \"{$catRow['name']}\"", 'Page Management', $_SESSION['user_id'], 'staff');
                }
            }
        }
    }

    // ── SERVICES: ADD ──
    elseif ($action === 'add_service') {
        $activeTab = 'services';
        $activeSvcSubTab = 'services';
        $name    = trim(strip_tags($_POST['svc_name'] ?? ''));
        $cat     = trim(strip_tags($_POST['svc_category'] ?? ''));
        $badge   = trim(strip_tags($_POST['svc_badge'] ?? ''));
        $desc    = trim(strip_tags($_POST['svc_desc'] ?? ''));
        $dur     = trim(strip_tags($_POST['svc_duration'] ?? ''));
        if ($name && $cat) {
            $cleanName = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
            $cleanBadge = html_entity_decode($badge, ENT_QUOTES, 'UTF-8');
            $cleanDesc = html_entity_decode($desc, ENT_QUOTES, 'UTF-8');
            $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM clinic_services")->fetchColumn();
            $db->prepare("INSERT INTO clinic_services (name,purpose_category,badge,description,duration,sort_order) VALUES (?,?,?,?,?,?)")
               ->execute([$cleanName,$cat,$cleanBadge,$cleanDesc,$dur,$maxOrder+1]);
            $msg = "Service \"$cleanName\" added successfully.";
            $msgType = 'success';
            logActivity("Added service \"$cleanName\"", 'Page Management', $_SESSION['user_id'], 'staff');
        } else { $msg = 'Service name and category are required.'; $msgType = 'danger'; }
    }

    // ── SERVICES: EDIT ──
    elseif ($action === 'edit_service') {
        $id    = (int)($_POST['svc_id'] ?? 0);
        $name  = trim(strip_tags($_POST['svc_name'] ?? ''));
        $cat   = trim(strip_tags($_POST['svc_category'] ?? ''));
        $badge = trim(strip_tags($_POST['svc_badge'] ?? ''));
        $desc  = trim(strip_tags($_POST['svc_desc'] ?? ''));
        $dur   = trim(strip_tags($_POST['svc_duration'] ?? ''));
        $stat  = (int)($_POST['svc_active'] ?? 1);

        if ($name && $cat && $id) {
            $stmt = $db->prepare("SELECT name, purpose_category, badge, description, duration, is_active FROM clinic_services WHERE id=?");
            $stmt->execute([$id]);
            $old = $stmt->fetch();

            $cleanName  = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
            $cleanBadge = html_entity_decode($badge, ENT_QUOTES, 'UTF-8');
            $cleanDesc  = html_entity_decode($desc, ENT_QUOTES, 'UTF-8');

            if ($old &&
                trim(html_entity_decode($old['name'], ENT_QUOTES, 'UTF-8')) === $cleanName &&
                trim($old['purpose_category']) === $cat &&
                trim(html_entity_decode($old['badge'] ?? '', ENT_QUOTES, 'UTF-8')) === $cleanBadge &&
                trim(html_entity_decode($old['description'] ?? '', ENT_QUOTES, 'UTF-8')) === $cleanDesc &&
                trim($old['duration'] ?? '') === $dur &&
                (int)$old['is_active'] === $stat
            ) {
                $msg = "No changes were made. Service \"$cleanName\" is already up to date!";
                $msgType = 'info';
            } else {
                $db->prepare("UPDATE clinic_services SET name=?,purpose_category=?,badge=?,description=?,duration=?,is_active=? WHERE id=?")
                   ->execute([$cleanName, $cat, $cleanBadge, $cleanDesc, $dur, $stat, $id]);
                $msg = "Service \"$cleanName\" updated successfully.";
                $msgType = 'success';
                logActivity("Updated service #$id \"$cleanName\"", 'Page Management', $_SESSION['user_id'], 'staff');
            }
        } else { $msg = 'Service name and category are required.'; $msgType = 'danger'; }
    }

    // ── SERVICES: TOGGLE ──
    elseif ($action === 'toggle_service') {
        $id  = (int)($_POST['svc_id'] ?? 0);
        $cur = (int)($_POST['svc_current'] ?? 1);
        $new = $cur ? 0 : 1;
        $db->prepare("UPDATE clinic_services SET is_active=? WHERE id=?")->execute([$new,$id]);
        $msg = 'Service ' . ($new ? 'activated and visible to patients' : 'hidden from patient booking') . ' successfully.';
        $msgType = 'success';
        logActivity(($new ? 'Activated' : 'Deactivated') . " service #$id", 'Page Management', $_SESSION['user_id'], 'staff');
    }

    // ── FAQS: ADD ──
    elseif ($action === 'add_faq') {
        $q    = trim(strip_tags($_POST['faq_question'] ?? ''));
        $a    = trim(strip_tags($_POST['faq_answer'] ?? ''));
        $icon = trim(strip_tags($_POST['faq_icon'] ?? 'fa-circle-question'));
        if ($q && $a) {
            $cleanQ = html_entity_decode($q, ENT_QUOTES, 'UTF-8');
            $cleanA = html_entity_decode($a, ENT_QUOTES, 'UTF-8');
            $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM clinic_faqs")->fetchColumn();
            $db->prepare("INSERT INTO clinic_faqs (question,answer,icon,sort_order) VALUES (?,?,?,?)")
               ->execute([$cleanQ,$cleanA,$icon,$maxOrder+1]);
            $msg = 'FAQ added successfully.';
            $msgType = 'success';
            logActivity('Added new FAQ', 'Page Management', $_SESSION['user_id'], 'staff');
        } else { $msg = 'Question and answer are required.'; $msgType = 'danger'; }
    }

    // ── FAQS: EDIT ──
    elseif ($action === 'edit_faq') {
        $id   = (int)($_POST['faq_id'] ?? 0);
        $q    = trim(strip_tags($_POST['faq_question'] ?? ''));
        $a    = trim(strip_tags($_POST['faq_answer'] ?? ''));
        $icon = trim(strip_tags($_POST['faq_icon'] ?? 'fa-circle-question'));
        $stat = (int)($_POST['faq_active'] ?? 1);

        if ($q && $a && $id) {
            $stmt = $db->prepare("SELECT question, answer, icon, is_active FROM clinic_faqs WHERE id=?");
            $stmt->execute([$id]);
            $old = $stmt->fetch();

            $cleanQ = html_entity_decode($q, ENT_QUOTES, 'UTF-8');
            $cleanA = html_entity_decode($a, ENT_QUOTES, 'UTF-8');

            if ($old &&
                trim(html_entity_decode($old['question'], ENT_QUOTES, 'UTF-8')) === $cleanQ &&
                trim(html_entity_decode($old['answer'], ENT_QUOTES, 'UTF-8')) === $cleanA &&
                trim($old['icon']) === $icon &&
                (int)$old['is_active'] === $stat
            ) {
                $msg = 'No changes were made. This FAQ is already up to date!';
                $msgType = 'info';
            } else {
                $db->prepare("UPDATE clinic_faqs SET question=?,answer=?,icon=?,is_active=? WHERE id=?")
                   ->execute([$cleanQ, $cleanA, $icon, $stat, $id]);
                $msg = 'FAQ updated successfully.';
                $msgType = 'success';
                logActivity("Updated FAQ #$id", 'Page Management', $_SESSION['user_id'], 'staff');
            }
        } else { $msg = 'Question and answer are required.'; $msgType = 'danger'; }
    }

    // ── FAQS: TOGGLE ──
    elseif ($action === 'toggle_faq') {
        $id  = (int)($_POST['faq_id'] ?? 0);
        $cur = (int)($_POST['faq_current'] ?? 1);
        $new = $cur ? 0 : 1;
        $db->prepare("UPDATE clinic_faqs SET is_active=? WHERE id=?")->execute([$new,$id]);
        $msg = 'FAQ ' . ($new ? 'published to website' : 'hidden from website') . ' successfully.';
        $msgType = 'success';
        logActivity(($new ? 'Published' : 'Hidden') . " FAQ #$id", 'Page Management', $_SESSION['user_id'], 'staff');
    }
}

// ── Clean any legacy double-encoded &amp; ───────────────────────────────────
try {
    $db->exec("UPDATE clinic_services SET name = REPLACE(name, '&amp;', '&'), description = REPLACE(description, '&amp;', '&'), badge = REPLACE(badge, '&amp;', '&') WHERE name LIKE '%&amp;%' OR description LIKE '%&amp;%' OR badge LIKE '%&amp;%'");
    $db->exec("UPDATE clinic_faqs SET question = REPLACE(question, '&amp;', '&'), answer = REPLACE(answer, '&amp;', '&') WHERE question LIKE '%&amp;%' OR answer LIKE '%&amp;%'");
} catch (Throwable $e) {}

// ── Load Data ────────────────────────────────────────────────────────────────
$settings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
function gs(array $s, string $k, string $d = ''): string {
    return htmlspecialchars($s[$k] ?? $d, ENT_QUOTES);
}

// Active Logo status
$customLogo = $settings['clinic_logo'] ?? '';
$customLogoActive = (!empty($customLogo) && file_exists(__DIR__ . '/../' . ltrim($customLogo, '/')));
$currentLogoUrl = getClinicLogoUrl(BASE_URL);


$bookingCats = $db->query("SELECT * FROM clinic_booking_categories ORDER BY sort_order ASC, id ASC")->fetchAll();
$totalBookingCats  = count($bookingCats);
$activeBookingCats = count(array_filter($bookingCats, fn($c) => $c['is_active']));

// Count linked services per category
$servicesPerCat = [];
foreach ($db->query("SELECT purpose_category, COUNT(*) as cnt FROM clinic_services GROUP BY purpose_category")->fetchAll() as $row) {
    $servicesPerCat[$row['purpose_category']] = (int)$row['cnt'];
}

$services = $db->query("SELECT * FROM clinic_services ORDER BY sort_order ASC, id ASC")->fetchAll();
$faqs     = $db->query("SELECT * FROM clinic_faqs ORDER BY sort_order ASC, id ASC")->fetchAll();

$totalSvc  = count($services);
$activeSvc = count(array_filter($services, fn($s) => $s['is_active']));
$totalFaq  = count($faqs);
$activeFaq = count(array_filter($faqs, fn($f) => $f['is_active']));

$extraHead = '<link rel="stylesheet" href="'.BASE_URL.'assets/css/pages/page_management.css?v='.time().'">';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    Swal.fire({
        title: '<?= $msgType === "success" ? "Success!" : ($msgType === "info" ? "Notice" : "Error") ?>',
        text: '<?= addslashes(html_entity_decode($msg, ENT_QUOTES, "UTF-8")) ?>',
        icon: '<?= $msgType ?>',
        confirmButtonColor: 'var(--clr-primary)',
        background: 'var(--bg-card)',
        color: 'var(--text-primary)',
        timer: 3500,
        timerProgressBar: true
    });
});
</script>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     TOP BANNER & HEADER
     ═══════════════════════════════════════════════════════════════ -->
<div class="pm-studio-header">
  <div class="pm-studio-left">
    <div class="pm-studio-emblem">
      <i class="fas fa-palette"></i>
    </div>
    <div>
      <div class="pm-studio-kicker"><i class="fas fa-circle text-success me-1" style="font-size:0.55rem;"></i> Live Page Customizer</div>
      <h3 class="pm-studio-title">Website Content Studio</h3>
      <p class="pm-studio-subtitle">Easily manage what your patients see on the landing page and booking system — no coding required!</p>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB NAVIGATION
     ═══════════════════════════════════════════════════════════════ -->
<div class="pm-nav-wrapper">
  <div class="pm-tab-pills">
    <button type="button" class="pm-tab-pill <?= $activeTab === 'homepage' ? 'active' : '' ?>" onclick="switchTab('homepage')">
      <div class="pm-tab-icon"><i class="fas fa-home"></i></div>
      <div class="pm-tab-text">
        <span class="pm-tab-name">Landing Page</span>
        <span class="pm-tab-sub">Hero, stats &amp; highlights</span>
      </div>
    </button>
    <button type="button" class="pm-tab-pill <?= $activeTab === 'services' ? 'active' : '' ?>" onclick="switchTab('services')">
      <div class="pm-tab-icon"><i class="fas fa-stethoscope"></i></div>
      <div class="pm-tab-text">
        <span class="pm-tab-name">Appointment Services</span>
        <span class="pm-tab-sub">Patient booking options</span>
      </div>
      <span class="pm-tab-counter"><?= $activeSvc ?>/<?= $totalSvc ?></span>
    </button>
    <button type="button" class="pm-tab-pill <?= $activeTab === 'faqs' ? 'active' : '' ?>" onclick="switchTab('faqs')">
      <div class="pm-tab-icon"><i class="fas fa-circle-question"></i></div>
      <div class="pm-tab-text">
        <span class="pm-tab-name">FAQ Center</span>
        <span class="pm-tab-sub">Common patient inquiries</span>
      </div>
      <span class="pm-tab-counter"><?= $activeFaq ?>/<?= $totalFaq ?></span>
    </button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     TAB 1: HOMEPAGE CONTENT (VISUAL STUDIO)
     ═══════════════════════════════════════════════════════════════ -->
<div class="pm-tab-panel <?= $activeTab === 'homepage' ? 'active' : '' ?>" id="tab-homepage">

  <!-- ═══════════════════════════════════════════════════════════════
       BRAND IDENTITY & CLINIC LOGO STUDIO
       ═══════════════════════════════════════════════════════════════ -->
  <div class="pm-card-box mb-4">
    <div class="pm-card-box-header">
      <div class="pm-header-badge-tag"><i class="fas fa-shield-halved"></i> BRAND IDENTITY</div>
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h4 class="pm-card-box-title">Clinic Brand &amp; Business Logo</h4>
          <p class="pm-card-box-desc">Manage the official business logo displayed on your patient website, navigation topbar, 3D hero emblem, prescription slips, and booking portal.</p>
        </div>
        <?php if (!empty($customLogoActive)): ?>
        <form method="POST" id="resetLogoForm" onsubmit="return confirmResetLogo(event)">
          <input type="hidden" name="action" value="reset_logo">
          <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
          <input type="hidden" name="active_tab" value="homepage">
          <button type="submit" class="pm-btn-reset-logo" title="Revert to original clinic logo">
            <i class="fas fa-rotate-left"></i> Reset to Default Logo
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="pm-logo-studio-grid">
      <!-- Left: Active Logo Display with Theme Stage Switcher -->
      <div class="pm-logo-preview-card">
        <div class="pm-logo-preview-header">
          <div class="pm-logo-preview-title"><i class="fas fa-eye text-primary"></i> Active Logo Display</div>
          <div class="pm-canvas-toggle-group">
            <button type="button" class="pm-canvas-btn active" id="canvasDarkBtn" onclick="setLogoCanvas('dark')" title="Preview on Dark Canvas"><i class="fas fa-moon"></i> Dark</button>
            <button type="button" class="pm-canvas-btn" id="canvasLightBtn" onclick="setLogoCanvas('light')" title="Preview on Light Canvas"><i class="fas fa-sun"></i> Light</button>
            <button type="button" class="pm-canvas-btn" id="canvasCheckBtn" onclick="setLogoCanvas('checker')" title="Preview on Transparent Grid"><i class="fas fa-border-all"></i> Grid</button>
          </div>
        </div>

        <div class="pm-logo-stage dark" id="logoPreviewStage">
          <img src="<?= htmlspecialchars($currentLogoUrl) ?>" alt="Clinic Logo" id="activeLogoImg" class="pm-stage-logo">
        </div>

        <div class="pm-logo-meta-info">
          <span class="pm-logo-status-tag <?= !empty($customLogoActive) ? 'custom' : 'default' ?>">
            <i class="fas <?= !empty($customLogoActive) ? 'fa-check-circle' : 'fa-info-circle' ?>"></i>
            <?= !empty($customLogoActive) ? 'Custom Logo Active' : 'Default Official Logo' ?>
          </span>
          <span class="pm-logo-file-note"><i class="fas fa-file-image me-1"></i><?= htmlspecialchars(basename(strtok($currentLogoUrl, '?'))) ?></span>
        </div>
      </div>

      <!-- Right: Upload New Logo -->
      <div class="pm-logo-upload-card">
        <form method="POST" enctype="multipart/form-data" id="logoUploadForm">
          <input type="hidden" name="action" value="upload_logo">
          <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
          <input type="hidden" name="active_tab" value="homepage">

          <div class="pm-upload-zone" id="logoDropZone" onclick="document.getElementById('logoFileInput').click()">
            <input type="file" name="clinic_logo" id="logoFileInput" accept="image/png,image/jpeg,image/webp,image/svg+xml" style="display:none" onchange="handleLogoSelect(this)">
            
            <div id="uploadZonePrompt">
              <div class="pm-upload-icon-circle">
                <i class="fas fa-cloud-arrow-up"></i>
              </div>
              <h5 class="pm-upload-prompt-title">Click to Browse or Drag &amp; Drop New Logo</h5>
              <p class="pm-upload-prompt-sub">Upload an image file to refresh your clinic's logo everywhere across the system</p>
              <div class="pm-upload-specs">
                <span><i class="fas fa-check-circle text-success"></i> Transparent PNG or SVG recommended</span>
                <span><i class="fas fa-check-circle text-success"></i> Recommended: 512 &times; 512 px</span>
                <span><i class="fas fa-check-circle text-success"></i> Max file size: 5 MB</span>
              </div>
            </div>

            <!-- Instant Live Preview of Selected File -->
            <div id="uploadZonePreview" style="display:none;">
              <div class="pm-new-logo-preview-box">
                <img id="newLogoPreviewImg" src="" alt="New Logo Preview">
              </div>
              <div class="pm-new-logo-info">
                <span id="newLogoFileName" class="fw-bold"></span>
                <span id="newLogoFileSize" class="pm-file-size-tag"></span>
              </div>
              <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="cancelLogoSelect(event)">
                <i class="fas fa-times me-1"></i> Choose Different Image
              </button>
            </div>
          </div>

          <div class="mt-3 d-flex justify-content-end">
            <button type="submit" id="saveLogoBtn" class="pm-save-logo-btn" disabled>
              <i class="fas fa-cloud-arrow-up"></i> Upload &amp; Apply New Logo
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <form method="POST" id="homepageForm" onsubmit="return validateHomepageChange(event, this)">
    <input type="hidden" name="action" value="save_homepage">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    <input type="hidden" name="active_tab" value="homepage">

    <!-- Section 1: Hero Banner Studio -->
    <div class="pm-card-box">
      <div class="pm-card-box-header">
        <div class="pm-header-badge-tag"><i class="fas fa-flag"></i> SECTION 1</div>
        <h4 class="pm-card-box-title">Hero Banner Studio</h4>
        <p class="pm-card-box-desc">This is the main headline and introduction displayed at the very top of your landing page.</p>
      </div>

      <div class="pm-hero-studio-grid">
        <!-- Left: Form Controls -->
        <div class="pm-hero-controls">
          <div class="pm-field-block">
            <label class="pm-input-label">
              <i class="fas fa-certificate text-warning"></i>
              <span>Top Badge Text</span>
            </label>
            <input type="text" name="hero_badge" id="heroBadgeInput" class="form-control pm-styled-input" 
                   value="<?= gs($settings,'hero_badge','Established in 1986') ?>" 
                   placeholder="e.g. Established in 1986" oninput="updateHeroLivePreview()">
            <span class="pm-input-hint">The golden badge shown above your main headline.</span>
          </div>

          <div class="pm-field-row-2">
            <div class="pm-field-block">
              <label class="pm-input-label">
                <i class="fas fa-font text-primary"></i>
                <span>Headline (First Part)</span>
              </label>
              <input type="text" name="hero_headline" id="heroHeadlineInput" class="form-control pm-styled-input" 
                     value="<?= gs($settings,'hero_headline','See the World') ?>" 
                     placeholder="e.g. See the World" oninput="updateHeroLivePreview()">
              <span class="pm-input-hint">Standard primary title text.</span>
            </div>

            <div class="pm-field-block">
              <label class="pm-input-label">
                <i class="fas fa-wand-magic-sparkles text-info"></i>
                <span>Accent Highlight Words</span>
              </label>
              <input type="text" name="hero_highlight" id="heroHighlightInput" class="form-control pm-styled-input" 
                     value="<?= gs($settings,'hero_highlight','Clearly & Beautifully') ?>" 
                     placeholder="e.g. Clearly & Beautifully" oninput="updateHeroLivePreview()">
              <span class="pm-input-hint">These words shine with luxury blue gradient.</span>
            </div>
          </div>

          <div class="pm-field-block">
            <label class="pm-input-label">
              <i class="fas fa-align-left text-muted"></i>
              <span>Clinic Introduction Paragraph</span>
            </label>
            <textarea name="hero_description" id="heroDescInput" class="form-control pm-styled-textarea" rows="3" 
                      placeholder="Write a warm introduction for your clinic..." oninput="updateHeroLivePreview()"><?= gs($settings,'hero_description') ?></textarea>
            <span class="pm-input-hint">Brief clinic mission or welcome message.</span>
          </div>
        </div>

        <!-- Right: Interactive Live Mockup -->
        <div class="pm-hero-mockup-wrapper">
          <div class="pm-mockup-banner-top">
            <span class="pm-mockup-dot red"></span>
            <span class="pm-mockup-dot yellow"></span>
            <span class="pm-mockup-dot green"></span>
            <span class="pm-mockup-url"><i class="fas fa-lock"></i> guecoopticalclinic.com</span>
          </div>
          <div class="pm-hero-mockup-inner">
            <div class="pm-mockup-badge" id="prevHeroBadge">
              <i class="fas fa-certificate text-warning me-1"></i>
              <span><?= gs($settings,'hero_badge','Established in 1986') ?></span>
            </div>
            <h1 class="pm-mockup-h1">
              <span id="prevHeroHeadline"><?= gs($settings,'hero_headline','See the World') ?></span>
              <span class="pm-mockup-grad" id="prevHeroHighlight"><?= gs($settings,'hero_highlight','Clearly & Beautifully') ?></span>
            </h1>
            <p class="pm-mockup-desc" id="prevHeroDesc"><?= gs($settings,'hero_description') ?></p>
            <div class="pm-mockup-cta">
              <span class="pm-mockup-btn-primary"><i class="fas fa-calendar-check"></i> Book an Appointment</span>
              <span class="pm-mockup-btn-outline">Explore Services</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 2: Milestones & Counters -->
    <div class="pm-card-box mt-4">
      <div class="pm-card-box-header">
        <div class="pm-header-badge-tag"><i class="fas fa-chart-line"></i> SECTION 2</div>
        <h4 class="pm-card-box-title">Clinic Milestones &amp; Statistics</h4>
        <p class="pm-card-box-desc">The 3 quick proof counters displayed directly below the hero section.</p>
      </div>

      <div class="pm-stats-builder-grid">
        <?php 
        $statDefaults = [
            ['stat1', '40+',  'Years of Service', 'fa-award',       '#235EAE'],
            ['stat2', '10k+', 'Happy Patients',   'fa-smile-beam',  '#00ADEF'],
            ['stat3', '100%', 'Commitment',       'fa-hand-holding-heart', '#10B981']
        ];
        foreach ($statDefaults as [$k, $dv, $dl, $icon, $accent]): 
        ?>
        <div class="pm-stat-builder-card">
          <div class="pm-stat-icon-top" style="color:<?= $accent ?>;">
            <i class="fas <?= $icon ?>"></i>
          </div>
          <div class="pm-stat-inputs">
            <div class="pm-stat-input-group">
              <label class="pm-stat-label">Displayed Number</label>
              <input type="text" name="<?= $k ?>_value" class="form-control pm-stat-number-input" 
                     value="<?= gs($settings,"{$k}_value",$dv) ?>" placeholder="e.g. 40+">
            </div>
            <div class="pm-stat-input-group">
              <label class="pm-stat-label">Stat Label</label>
              <input type="text" name="<?= $k ?>_label" class="form-control pm-stat-text-input" 
                     value="<?= gs($settings,"{$k}_label",$dl) ?>" placeholder="e.g. Years of Service">
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Section 3: Clinic Highlights (3 Feature Cards) -->
    <div class="pm-card-box mt-4">
      <div class="pm-card-box-header">
        <div class="pm-header-badge-tag"><i class="fas fa-layer-group"></i> SECTION 3</div>
        <h4 class="pm-card-box-title">Clinic Feature Highlights (3 Cards)</h4>
        <p class="pm-card-box-desc">The 3 luxury cards that describe your clinic's primary strengths. Click the icon to choose a different graphic!</p>
      </div>

      <div class="pm-feature-cards-grid">
        <?php
        $cardConfigs = [
          ['card1', 'fa-user-md',        'Expert Optometrists', 'Our highly trained professionals provide thorough eye exams, accurate prescriptions, and personalized care tailored to your unique visual needs.', 'Bronze Accent', 'pm-accent-bronze'],
          ['card2', 'fa-glasses',        'Premium Eyewear',     'Choose from a wide selection of stylish frames, premium lenses, and comfortable contact lenses sourced from top international brands.', 'Gold Accent',   'pm-accent-gold'],
          ['card3', 'fa-map-marker-alt', 'Convenient Location', 'Located in the heart of Capas, Tarlac. We provide a comfortable, welcoming environment with modern facilities for all our patients.',     'Emerald Accent','pm-accent-emerald']
        ];

        foreach ($cardConfigs as [$k, $di, $dt, $dd, $accentName, $accentClass]):
          $savedIcon = gs($settings,"{$k}_icon",$di);
        ?>
        <div class="pm-feature-builder-card <?= $accentClass ?>">
          <!-- Hidden Icon Input -->
          <input type="hidden" name="<?= $k ?>_icon" id="<?= $k ?>_icon_input" value="<?= $savedIcon ?>">

          <!-- Visual Icon Button -->
          <div class="pm-feature-top-bar">
            <button type="button" class="pm-feature-icon-btn" id="<?= $k ?>_icon_box" 
                    onclick="openIconPicker('<?= $k ?>')" title="Click to choose a different icon">
              <i class="fas <?= $savedIcon ?>"></i>
            </button>
            <div class="pm-feature-badge-wrap">
              <span class="pm-badge-accent"><?= $accentName ?></span>
              <button type="button" class="pm-change-icon-chip" onclick="openIconPicker('<?= $k ?>')">
                <i class="fas fa-icons"></i> Change Icon
              </button>
            </div>
          </div>

          <div class="pm-feature-form-body">
            <div class="pm-field-block">
              <label class="pm-input-label">Card Title</label>
              <input type="text" name="<?= $k ?>_title" class="form-control pm-styled-input fw-bold" 
                     value="<?= gs($settings,"{$k}_title",$dt) ?>" placeholder="e.g. Expert Optometrists">
            </div>

            <div class="pm-field-block">
              <label class="pm-input-label">Card Description</label>
              <textarea name="<?= $k ?>_desc" class="form-control pm-styled-textarea" rows="3" 
                        placeholder="Explain this clinic benefit..."><?= gs($settings,"{$k}_desc",$dd) ?></textarea>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Floating / Sticky Save Toolbar -->
    <div class="pm-sticky-save-bar">
      <div class="pm-save-bar-left">
        <i class="fas fa-check-circle text-success"></i>
        <span>Ready to update? Changes will take effect immediately on your live website.</span>
      </div>
      <button type="submit" class="pm-save-action-btn">
        <i class="fas fa-save"></i> Save All Homepage Changes
      </button>
    </div>
  </form>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 2: APPOINTMENT SERVICES & BOOKING CATEGORIES
     ═══════════════════════════════════════════════════════════ -->
<div class="pm-tab-panel <?= $activeTab === 'services' ? 'active' : '' ?>" id="tab-services">
  <!-- Sub-Navigation: 2 Pages (Booking Categories vs Offered Sub-Services) -->
  <div class="pm-subnav-bar">
    <div class="pm-subnav-pills">
      <button type="button" class="pm-subnav-pill <?= $activeSvcSubTab === 'cats' ? 'active' : '' ?>" id="subBtnCats" onclick="switchSvcSub('cats')">
        <i class="fas fa-layer-group"></i>
        <span>1. Consultation Purposes & Categories</span>
        <span class="pm-subnav-counter" id="bookingCatCounter"><?= $activeBookingCats ?>/<?= $totalBookingCats ?></span>
      </button>
      <button type="button" class="pm-subnav-pill <?= $activeSvcSubTab === 'services' ? 'active' : '' ?>" id="subBtnServices" onclick="switchSvcSub('services')">
        <i class="fas fa-stethoscope"></i>
        <span>2. Offered Services (Sub-Categories)</span>
        <span class="pm-subnav-counter" id="subSvcCounter"><?= $activeSvc ?>/<?= $totalSvc ?></span>
      </button>
    </div>
  </div>

  <!-- ── SUB-PAGE 1: BOOKING CATEGORIES (STEP 1 OF WIZARD) ── -->
  <div class="pm-svc-subpanel <?= $activeSvcSubTab === 'cats' ? 'active' : '' ?>" id="svcSubCats" style="display: <?= $activeSvcSubTab === 'cats' ? 'block' : 'none' ?>;">
    <div class="pm-info-callout">
      <div class="pm-callout-icon"><i class="fas fa-layer-group"></i></div>
      <div class="pm-callout-content">
        <h6>Consultation Purposes & Booking Categories</h6>
        <p>These define the consultation purposes and booking categories used across the entire clinic system (Walk-in Patient registration for Saleslady and Doctor, and online patient booking). Add new purposes, edit names, customize icons/descriptions, reorder, or toggle active/hidden anytime.</p>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="pm-hub-toolbar">
      <div class="pm-hub-search">
        <i class="fas fa-search"></i>
        <input type="text" id="catSearch" placeholder="Search categories by name or key identifier..." autocomplete="off">
      </div>

      <button type="button" class="pm-add-btn" onclick="openModal('addCatModal')">
        <i class="fas fa-plus"></i> Add Booking Category
      </button>
    </div>

    <!-- Booking Categories Grid -->
    <div class="pm-categories-grid" id="catGrid">
      <?php if (empty($bookingCats)): ?>
      <div class="pm-empty-card">
        <i class="fas fa-layer-group"></i>
        <h5>No Booking Categories Configured</h5>
        <p>Click "Add Booking Category" above to create your first booking category.</p>
      </div>
      <?php else: foreach ($bookingCats as $c): 
        $cKey = $c['category_key'];
        $linkedCount = $servicesPerCat[$cKey] ?? 0;
      ?>
      <div class="pm-cat-card <?= $c['is_active'] ? '' : 'pm-is-hidden' ?>"
           data-name="<?= strtolower(htmlspecialchars($c['name'])) ?>"
           data-key="<?= strtolower(htmlspecialchars($cKey)) ?>">
        
        <div class="pm-cat-card-top">
          <div class="pm-cat-icon-emblem">
            <i class="fas <?= htmlspecialchars($c['icon'] ?: 'fa-calendar-check') ?>" id="catCardIcon_<?= $c['id'] ?>"></i>
          </div>
          <div class="pm-cat-card-badges">
            <span class="pm-cat-key-badge">key: <?= htmlspecialchars($cKey) ?></span>
            <span class="pm-status-pill <?= $c['is_active'] ? 'active' : 'hidden' ?>">
              <i class="fas fa-<?= $c['is_active'] ? 'check-circle' : 'eye-slash' ?>"></i>
              <?= $c['is_active'] ? 'Active in Wizard' : 'Hidden' ?>
            </span>
          </div>
        </div>

        <h5 class="pm-cat-title"><?= htmlspecialchars($c['name']) ?></h5>
        <p class="pm-cat-desc"><?= htmlspecialchars($c['description'] ?? 'No description provided.') ?></p>

        <div class="pm-cat-meta-row">
          <span class="pm-cat-linked-badge">
            <i class="fas fa-stethoscope"></i> <?= $linkedCount ?> <?= $linkedCount === 1 ? 'Service Linked' : 'Services Linked' ?>
          </span>
          <button type="button" class="pm-cat-view-services-btn" onclick="filterAndJumpToServices('<?= htmlspecialchars($cKey) ?>')">
            <span>View Services</span> <i class="fas fa-arrow-right"></i>
          </button>
        </div>

        <div class="pm-cat-card-actions">
          <button type="button" class="pm-action-btn edit" title="Edit Category Details"
            onclick="openEditCat(<?= $c['id'] ?>, '<?= addslashes($c['name']) ?>', '<?= addslashes($cKey) ?>', '<?= addslashes($c['icon']) ?>', '<?= addslashes($c['description'] ?? '') ?>', <?= $c['is_active'] ?>)">
            <i class="fas fa-edit"></i> Edit Details
          </button>

          <form method="POST" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="toggle_booking_category">
            <input type="hidden" name="active_tab" value="services">
            <input type="hidden" name="svc_sub_tab" value="cats">
            <input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
            <input type="hidden" name="cat_current" value="<?= $c['is_active'] ?>">
            <button type="submit" class="pm-action-btn <?= $c['is_active'] ? 'toggle-hide' : 'toggle-show' ?>"
              data-confirm="<?= $c['is_active'] ? 'Hide this booking category from patients during appointment booking?' : 'Make this booking category visible to patients during appointment booking?' ?>">
              <i class="fas fa-<?= $c['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
              <?= $c['is_active'] ? 'Hide Category' : 'Show Category' ?>
            </button>
          </form>

          <?php if ($linkedCount === 0): ?>
          <form method="POST" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="delete_booking_category">
            <input type="hidden" name="active_tab" value="services">
            <input type="hidden" name="svc_sub_tab" value="cats">
            <input type="hidden" name="cat_id" value="<?= $c['id'] ?>">
            <button type="submit" class="pm-action-btn delete" data-confirm="Are you sure you want to permanently delete category &quot;<?= htmlspecialchars($c['name']) ?>&quot;?">
              <i class="fas fa-trash-alt"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ── SUB-PAGE 2: OFFERED SERVICES / SUB-CATEGORIES (STEP 2 OF WIZARD) ── -->
  <div class="pm-svc-subpanel <?= $activeSvcSubTab === 'services' ? 'active' : '' ?>" id="svcSubServices" style="display: <?= $activeSvcSubTab === 'services' ? 'block' : 'none' ?>;">
    <div class="pm-info-callout">
      <div class="pm-callout-icon"><i class="fas fa-stethoscope"></i></div>
      <div class="pm-callout-content">
        <h6>Step 2: Offered Services &amp; Sub-Categories</h6>
        <p>These are the specific optical care procedures and choices offered under each booking category in Step 2 of the booking wizard. Add procedures, configure estimated durations, or link them to any category.</p>
      </div>
    </div>

    <!-- Filter & Action Toolbar -->
    <div class="pm-hub-toolbar">
      <div class="pm-hub-search">
        <i class="fas fa-search"></i>
        <input type="text" id="svcSearch" placeholder="Search services by name, badge, or category..." autocomplete="off">
      </div>

      <div class="pm-category-pills" id="svcCategoryFilterPills">
        <button type="button" class="pm-cat-filter active" data-filter="all">All (<?= $totalSvc ?>)</button>
        <?php foreach ($bookingCats as $bc): 
          $cnt = $servicesPerCat[$bc['category_key']] ?? 0;
        ?>
        <button type="button" class="pm-cat-filter" data-filter="<?= htmlspecialchars($bc['category_key']) ?>">
          <?= htmlspecialchars($bc['name']) ?> (<?= $cnt ?>)
        </button>
        <?php endforeach; ?>
      </div>

      <button type="button" class="pm-add-btn" onclick="openModal('addSvcModal')">
        <i class="fas fa-plus"></i> Add New Service
      </button>
    </div>

    <!-- Services Grid -->
    <div class="pm-services-hub-grid" id="svcGrid">
      <?php if (empty($services)): ?>
      <div class="pm-empty-card">
        <i class="fas fa-stethoscope"></i>
        <h5>No Services Configured</h5>
        <p>Click the "Add New Service" button above to create your first appointment service.</p>
      </div>
      <?php else: foreach ($services as $svc): 
        $catKey = $svc['purpose_category'];
        // Find category name
        $catObj = null;
        foreach ($bookingCats as $bc) {
          if ($bc['category_key'] === $catKey) { $catObj = $bc; break; }
        }
        $catDisplayName = $catObj ? $catObj['name'] : ucfirst(str_replace('_',' ',$catKey));
      ?>
      <div class="pm-hub-svc-card <?= $svc['is_active'] ? '' : 'pm-is-hidden' ?>"
           data-name="<?= strtolower(htmlspecialchars($svc['name'])) ?>"
           data-badge="<?= strtolower(htmlspecialchars($svc['badge'] ?? '')) ?>"
           data-cat="<?= $catKey ?>">
        
        <div class="pm-svc-topline">
          <span class="pm-badge-category pm-cat-<?= $catKey ?>">
            <?= htmlspecialchars($svc['badge'] ?: $catDisplayName) ?>
          </span>

          <span class="pm-status-pill <?= $svc['is_active'] ? 'active' : 'hidden' ?>">
            <i class="fas fa-<?= $svc['is_active'] ? 'check-circle' : 'eye-slash' ?>"></i>
            <?= $svc['is_active'] ? 'Visible to Patients' : 'Hidden from Booking' ?>
          </span>
        </div>

        <h5 class="pm-svc-card-title"><?= htmlspecialchars($svc['name']) ?></h5>
        <p class="pm-svc-card-desc"><?= htmlspecialchars($svc['description'] ?? 'No description provided.') ?></p>

        <div class="pm-svc-card-meta">
          <span class="pm-duration-chip"><i class="fas fa-clock"></i> <?= htmlspecialchars($svc['duration'] ?: '15–30 mins') ?></span>
          <span class="pm-category-label"><i class="fas fa-folder me-1"></i><?= htmlspecialchars($catDisplayName) ?></span>
        </div>

        <div class="pm-svc-card-actions">
          <button type="button" class="pm-action-btn edit" title="Edit Service Details"
            onclick="openEditSvc(<?= $svc['id'] ?>, '<?= addslashes($svc['name']) ?>', '<?= $svc['purpose_category'] ?>', '<?= addslashes($svc['badge'] ?? '') ?>', '<?= addslashes($svc['description'] ?? '') ?>', '<?= addslashes($svc['duration'] ?? '') ?>', <?= $svc['is_active'] ?>)">
            <i class="fas fa-edit"></i> Edit Details
          </button>

          <form method="POST" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="toggle_service">
            <input type="hidden" name="active_tab" value="services">
            <input type="hidden" name="svc_sub_tab" value="services">
            <input type="hidden" name="svc_id" value="<?= $svc['id'] ?>">
            <input type="hidden" name="svc_current" value="<?= $svc['is_active'] ?>">
            <button type="submit" class="pm-action-btn <?= $svc['is_active'] ? 'toggle-hide' : 'toggle-show' ?>"
              data-confirm="<?= $svc['is_active'] ? 'Hide this service from patients during appointment booking?' : 'Make this service visible to patients during appointment booking?' ?>">
              <i class="fas fa-<?= $svc['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
              <?= $svc['is_active'] ? 'Hide Service' : 'Show Service' ?>
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     TAB 3: FAQ KNOWLEDGEBASE
     ═══════════════════════════════════════════════════════════ -->
<div class="pm-tab-panel <?= $activeTab === 'faqs' ? 'active' : '' ?>" id="tab-faqs">
  <!-- Info Banner -->
  <div class="pm-info-callout">
    <div class="pm-callout-icon"><i class="fas fa-circle-question"></i></div>
    <div class="pm-callout-content">
      <h6>Landing Page FAQs Manager</h6>
      <p>These questions and answers appear in the accordion section at the bottom of your landing page. You can add new common patient inquiries, edit solutions, and hide questions at any time.</p>
    </div>
  </div>

  <!-- Filter & Action Toolbar -->
  <div class="pm-hub-toolbar">
    <div class="pm-hub-search">
      <i class="fas fa-search"></i>
      <input type="text" id="faqSearch" placeholder="Search FAQs by question text..." autocomplete="off">
    </div>

    <button type="button" class="pm-add-btn" onclick="openModal('addFaqModal')">
      <i class="fas fa-plus"></i> Add New Question
    </button>
  </div>

  <!-- FAQ Accordion List -->
  <div class="pm-faq-accordion-list" id="faqList">
    <?php if (empty($faqs)): ?>
    <div class="pm-empty-card">
      <i class="fas fa-circle-question"></i>
      <h5>No Questions Configured</h5>
      <p>Click the "Add New Question" button to create your first frequently asked question.</p>
    </div>
    <?php else: foreach ($faqs as $i => $faq): ?>
    <div class="pm-faq-accordion-card <?= $faq['is_active'] ? '' : 'pm-is-hidden' ?>" data-q="<?= strtolower(htmlspecialchars($faq['question'])) ?>">
      <div class="pm-faq-card-head" onclick="toggleFaqAccordion(this)">
        <div class="pm-faq-head-left">
          <div class="pm-faq-number-badge"><?= $i+1 ?></div>
          <div class="pm-faq-icon-avatar"><i class="fas <?= htmlspecialchars($faq['icon']) ?>"></i></div>
          <div class="pm-faq-question-title"><?= htmlspecialchars($faq['question']) ?></div>
        </div>
        <div class="pm-faq-head-right">
          <span class="pm-status-pill <?= $faq['is_active'] ? 'active' : 'hidden' ?>">
            <?= $faq['is_active'] ? 'Published' : 'Hidden' ?>
          </span>
          <div class="pm-faq-chevron"><i class="fas fa-chevron-down"></i></div>
        </div>
      </div>

      <div class="pm-faq-card-body">
        <div class="pm-faq-answer-text">
          <?= nl2br(htmlspecialchars($faq['answer'])) ?>
        </div>
        <div class="pm-faq-card-actions">
          <button type="button" class="pm-action-btn edit" title="Edit Question & Answer"
            onclick="openEditFaq(<?= $faq['id'] ?>, '<?= addslashes($faq['question']) ?>', '<?= addslashes($faq['answer']) ?>', '<?= addslashes($faq['icon']) ?>', <?= $faq['is_active'] ?>)">
            <i class="fas fa-edit"></i> Edit FAQ
          </button>
          <form method="POST" style="margin:0;display:inline;">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="toggle_faq">
            <input type="hidden" name="active_tab" value="faqs">
            <input type="hidden" name="faq_id" value="<?= $faq['id'] ?>">
            <input type="hidden" name="faq_current" value="<?= $faq['is_active'] ?>">
            <button type="submit" class="pm-action-btn <?= $faq['is_active'] ? 'toggle-hide' : 'toggle-show' ?>"
              data-confirm="<?= $faq['is_active'] ? 'Hide this FAQ from the website?' : 'Publish this FAQ on the website?' ?>">
              <i class="fas fa-<?= $faq['is_active'] ? 'eye-slash' : 'eye' ?>"></i>
              <?= $faq['is_active'] ? 'Hide from Patients' : 'Publish Question' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════
     MODAL: ADD BOOKING CATEGORY
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addCatModal">
  <div class="modal-box" style="max-width:580px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-layer-group"></i></div>
        <div class="modal-header-titles">
          <h5>Add Booking Category</h5>
          <small>Create a primary appointment reason category (Step 1)</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('addCatModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_booking_category">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="services">
        <input type="hidden" name="svc_sub_tab" value="cats">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Category Name <span class="text-danger">*</span></label>
          <input type="text" name="cat_name" id="addCatName" class="form-control" placeholder="e.g. Eye Consultation &amp; Check-up" required autofocus oninput="autoGenerateKey(this.value, 'addCatKey')">
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">Identifier Key <span class="text-danger">*</span></label>
            <input type="text" name="cat_key" id="addCatKey" class="form-control" placeholder="e.g. consultation" required>
            <small class="text-muted" style="font-size:0.75rem;">Unique code (e.g. eye_exam, lenses)</small>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">Category Icon</label>
            <div class="input-group">
              <span class="input-group-text" id="addCatIconPreview"><i class="fas fa-calendar-check"></i></span>
              <input type="text" name="cat_icon" id="addCatIcon" class="form-control" value="fa-calendar-check" placeholder="fa-calendar-check" oninput="updateCatIconPreview('addCatIcon', 'addCatIconPreview')">
              <button type="button" class="btn btn-outline-secondary" onclick="openIconPickerForTarget('addCatIcon', 'addCatIconPreview')"><i class="fas fa-icons"></i></button>
            </div>
          </div>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Category Description</label>
          <textarea name="cat_desc" class="form-control" rows="3" placeholder="Briefly describe what this booking category covers..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addCatModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Category</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: EDIT BOOKING CATEGORY
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editCatModal">
  <div class="modal-box" style="max-width:580px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-pen-to-square"></i></div>
        <div class="modal-header-titles">
          <h5>Edit Booking Category</h5>
          <small>Modify booking category details and visibility</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('editCatModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="editCatForm" onsubmit="return validateCatChange(event, this)">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit_booking_category">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="services">
        <input type="hidden" name="svc_sub_tab" value="cats">
        <input type="hidden" name="cat_id" id="editCatId">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Category Name <span class="text-danger">*</span></label>
          <input type="text" name="cat_name" id="editCatName" class="form-control" required>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">Identifier Key <span class="text-danger">*</span></label>
            <input type="text" name="cat_key" id="editCatKey" class="form-control" required>
            <small class="text-muted" style="font-size:0.75rem;">Changing key updates linked services</small>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">Category Icon</label>
            <div class="input-group">
              <span class="input-group-text" id="editCatIconPreview"><i class="fas fa-calendar-check"></i></span>
              <input type="text" name="cat_icon" id="editCatIcon" class="form-control" oninput="updateCatIconPreview('editCatIcon', 'editCatIconPreview')">
              <button type="button" class="btn btn-outline-secondary" onclick="openIconPickerForTarget('editCatIcon', 'editCatIconPreview')"><i class="fas fa-icons"></i></button>
            </div>
          </div>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Category Description</label>
          <textarea name="cat_desc" id="editCatDesc" class="form-control" rows="3"></textarea>
        </div>

        <div class="form-group">
          <label class="form-label fw-bold">Visibility in Patient Wizard</label>
          <select name="cat_active" id="editCatActive" class="form-select">
            <option value="1">Active (Visible in Step 1 to Patients)</option>
            <option value="0">Hidden (Disabled from Patient Booking)</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editCatModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Category</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: ADD SERVICE
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addSvcModal">
  <div class="modal-box" style="max-width:580px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-stethoscope"></i></div>
        <div class="modal-header-titles">
          <h5>Add Clinic Service</h5>
          <small>Add an optical service for patient appointment booking</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('addSvcModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_service">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="services">
        <input type="hidden" name="svc_sub_tab" value="services">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Service Name <span class="text-danger">*</span></label>
          <input type="text" name="svc_name" class="form-control" placeholder="e.g. Comprehensive Eye Examination" required autofocus>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">Booking Category <span class="text-danger">*</span></label>
            <select name="svc_category" class="form-select" required>
              <option value="">Select category...</option>
              <?php foreach ($bookingCats as $bc): ?>
              <option value="<?= htmlspecialchars($bc['category_key']) ?>">
                <?= htmlspecialchars($bc['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">Card Badge</label>
            <input type="text" name="svc_badge" class="form-control" placeholder="e.g. Examination, Lenses, Care">
          </div>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Service Description</label>
          <textarea name="svc_desc" class="form-control" rows="3" placeholder="Briefly describe what this service includes..."></textarea>
        </div>

        <div class="form-group">
          <label class="form-label fw-bold">Estimated Appointment Duration</label>
          <input type="text" name="svc_duration" class="form-control" placeholder="e.g. 20–30 mins">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addSvcModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save &amp; Add Service</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: EDIT SERVICE
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editSvcModal">
  <div class="modal-box" style="max-width:580px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-pen-to-square"></i></div>
        <div class="modal-header-titles">
          <h5>Edit Clinic Service</h5>
          <small>Modify service information and booking settings</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('editSvcModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="editSvcForm" onsubmit="return validateSvcChange(event, this)">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit_service">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="services">
        <input type="hidden" name="svc_sub_tab" value="services">
        <input type="hidden" name="svc_id" id="editSvcId">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Service Name <span class="text-danger">*</span></label>
          <input type="text" name="svc_name" id="editSvcName" class="form-control" required>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">Booking Category <span class="text-danger">*</span></label>
            <select name="svc_category" id="editSvcCat" class="form-select" required>
              <?php foreach ($bookingCats as $bc): ?>
              <option value="<?= htmlspecialchars($bc['category_key']) ?>">
                <?= htmlspecialchars($bc['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">Card Badge</label>
            <input type="text" name="svc_badge" id="editSvcBadge" class="form-control">
          </div>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Service Description</label>
          <textarea name="svc_desc" id="editSvcDesc" class="form-control" rows="3"></textarea>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">Estimated Duration</label>
            <input type="text" name="svc_duration" id="editSvcDur" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">Patient Visibility</label>
            <select name="svc_active" id="editSvcActive" class="form-select">
              <option value="1">Visible to Patients</option>
              <option value="0">Hidden from Booking</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editSvcModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: ADD FAQ
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addFaqModal">
  <div class="modal-box" style="max-width:600px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-circle-question"></i></div>
        <div class="modal-header-titles">
          <h5>Add Frequently Asked Question</h5>
          <small>Create a new helpful answer for patients</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('addFaqModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_faq">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="faqs">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Icon Category</label>
          <select name="faq_icon" class="form-select">
            <?php foreach ($availableIcons as $iCls => [$iTitle, $iDesc]): ?>
            <option value="<?= $iCls ?>" <?= $iCls === 'fa-circle-question' ? 'selected' : '' ?>>
              <?= $iTitle ?> (<?= $iCls ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Question Text <span class="text-danger">*</span></label>
          <input type="text" name="faq_question" class="form-control" placeholder="e.g. How often should I have an eye examination?" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label fw-bold">Detailed Answer <span class="text-danger">*</span></label>
          <textarea name="faq_answer" class="form-control" rows="5" placeholder="Write a clear, helpful answer for your patients..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addFaqModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Publish Question</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: EDIT FAQ
     ═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editFaqModal">
  <div class="modal-box" style="max-width:600px;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-pen-to-square"></i></div>
        <div class="modal-header-titles">
          <h5>Edit Question &amp; Answer</h5>
          <small>Modify question text, answer, or visibility</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('editFaqModal')" type="button"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="editFaqForm" onsubmit="return validateFaqChange(event, this)">
      <div class="modal-body">
        <input type="hidden" name="action" value="edit_faq">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="active_tab" value="faqs">
        <input type="hidden" name="faq_id" id="editFaqId">

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Icon Category</label>
          <select name="faq_icon" id="editFaqIcon" class="form-select">
            <?php foreach ($availableIcons as $iCls => [$iTitle, $iDesc]): ?>
            <option value="<?= $iCls ?>">
              <?= $iTitle ?> (<?= $iCls ?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Question Text <span class="text-danger">*</span></label>
          <input type="text" name="faq_question" id="editFaqQ" class="form-control" required>
        </div>

        <div class="form-group mb-3">
          <label class="form-label fw-bold">Detailed Answer <span class="text-danger">*</span></label>
          <textarea name="faq_answer" id="editFaqA" class="form-control" rows="5" required></textarea>
        </div>

        <div class="form-group">
          <label class="form-label fw-bold">Visibility on Landing Page</label>
          <select name="faq_active" id="editFaqActive" class="form-select">
            <option value="1">Published (Visible on landing page)</option>
            <option value="0">Hidden (Not shown to patients)</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editFaqModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: VISUAL ICON PICKER (GRID) — TOP-LEVEL STACKING
     ═══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="iconPickerModal" style="z-index: 10500 !important;">
  <div class="modal-box pm-icon-picker-box" style="z-index: 10501 !important;">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:12px;">
        <div class="modal-icon-badge"><i class="fas fa-icons"></i></div>
        <div class="modal-header-titles">
          <h5>Choose an Icon</h5>
          <small>Click any icon below to apply it immediately</small>
        </div>
      </div>
      <button class="modal-close" onclick="closeModal('iconPickerModal', true)" type="button"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body pm-icon-picker-body">
      <div class="pm-icon-grid">
        <?php foreach ($availableIcons as $iCls => [$iTitle, $iDesc]): ?>
        <button type="button" class="pm-icon-tile" onclick="selectIcon('<?= $iCls ?>')">
          <div class="pm-icon-tile-sym"><i class="fas <?= $iCls ?>"></i></div>
          <div class="pm-icon-tile-name"><?= $iTitle ?></div>
          <div class="pm-icon-tile-tag"><?= $iCls ?></div>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="closeModal('iconPickerModal', true)">Close</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PAGE JAVASCRIPT
     ═══════════════════════════════════════════════════════════════ -->
<script>
// ── Tab switching ────────────────────────────────────────────────────────────
function switchTab(tab) {
    document.querySelectorAll('.pm-tab-pill').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.pm-tab-panel').forEach(p => p.classList.remove('active'));
    const btn = document.querySelector(`.pm-tab-pill[onclick="switchTab('${tab}')"]`);
    if (btn) btn.classList.add('active');
    const pnl = document.getElementById('tab-' + tab);
    if (pnl) pnl.classList.add('active');
}

// ── Hero live preview ────────────────────────────────────────────────────────
function updateHeroLivePreview() {
    const badge     = document.getElementById('heroBadgeInput') ? document.getElementById('heroBadgeInput').value : '';
    const headline  = document.getElementById('heroHeadlineInput') ? document.getElementById('heroHeadlineInput').value : '';
    const highlight = document.getElementById('heroHighlightInput') ? document.getElementById('heroHighlightInput').value : '';
    const desc      = document.getElementById('heroDescInput') ? document.getElementById('heroDescInput').value : '';

    const prevBadge = document.getElementById('prevHeroBadge');
    if (prevBadge) {
        const badgeSpan = prevBadge.querySelector('span');
        if (badgeSpan) badgeSpan.textContent = badge;
    }

    const prevHl = document.getElementById('prevHeroHeadline');
    if (prevHl) prevHl.textContent = headline + (headline && highlight ? ' ' : '');

    const prevHg = document.getElementById('prevHeroHighlight');
    if (prevHg) prevHg.textContent = highlight;

    const prevDesc = document.getElementById('prevHeroDesc');
    if (prevDesc) prevDesc.textContent = desc;
}

// ── Services Sub-Page Switching (Categories vs Offered Services) ──────────────
function switchSvcSub(sub) {
    document.querySelectorAll('.pm-subnav-pill').forEach(p => p.classList.remove('active'));
    const btn = document.getElementById(sub === 'cats' ? 'subBtnCats' : 'subBtnServices') ||
                document.querySelector(`.pm-subnav-pill[onclick="switchSvcSub('${sub}')"]`);
    if (btn) btn.classList.add('active');

    const catsPanel = document.getElementById('svcSubCats');
    const svcPanel  = document.getElementById('svcSubServices');

    if (catsPanel) {
        catsPanel.classList.toggle('active', sub === 'cats');
        catsPanel.style.display = (sub === 'cats') ? 'block' : 'none';
    }
    if (svcPanel) {
        svcPanel.classList.toggle('active', sub === 'services');
        svcPanel.style.display = (sub === 'services') ? 'block' : 'none';
    }
}

function filterAndJumpToServices(catKey) {
    switchSvcSub('services');
    const filterBtn = document.querySelector(`.pm-cat-filter[data-filter="${catKey}"]`);
    if (filterBtn) {
        filterBtn.click();
    }
    const target = document.getElementById('svcSubServices');
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// ── Visual Icon Picker System ────────────────────────────────────────────────
let activeIconTargetKey = null;
let activeIconTargetInputId = null;
let activeIconTargetPreviewId = null;

function openIconPicker(cardKey) {
    activeIconTargetKey = cardKey;
    activeIconTargetInputId = null;
    activeIconTargetPreviewId = null;
    openModal('iconPickerModal');
}

function openIconPickerForTarget(inputId, previewId) {
    activeIconTargetKey = null;
    activeIconTargetInputId = inputId;
    activeIconTargetPreviewId = previewId;
    openModal('iconPickerModal');
}

function selectIcon(iconClass) {
    if (activeIconTargetKey) {
        const input = document.getElementById(activeIconTargetKey + '_icon_input');
        if (input) input.value = iconClass;
        const box = document.getElementById(activeIconTargetKey + '_icon_box');
        if (box) box.innerHTML = '<i class="fas ' + iconClass + '"></i>';
    } else if (activeIconTargetInputId) {
        const input = document.getElementById(activeIconTargetInputId);
        if (input) {
            input.value = iconClass;
            if (activeIconTargetPreviewId) {
                const prev = document.getElementById(activeIconTargetPreviewId);
                if (prev) prev.innerHTML = '<i class="fas ' + iconClass + '"></i>';
            }
        }
    }
    closeModal('iconPickerModal', true);
}

function updateCatIconPreview(inputId, previewId) {
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    if (!input || !preview) return;
    const iconClass = (input.value || 'fa-calendar-check').trim();
    preview.innerHTML = '<i class="fas ' + iconClass + '"></i>';
}

function autoGenerateKey(name, targetId) {
    const target = document.getElementById(targetId);
    if (!target) return;
    const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    target.value = slug;
}

let currentEditCat = null;
let currentEditSvc = null;
let currentEditFaq = null;
let initialHomepageData = null;

// ── Booking Category edit modal & change detection ───────────────────────────
function openEditCat(id, name, key, icon, desc, active) {
    currentEditCat = {
        id: id,
        name: (name || '').trim(),
        key: (key || '').trim(),
        icon: (icon || '').trim(),
        desc: (desc || '').trim(),
        active: active ? '1' : '0'
    };
    document.getElementById('editCatId').value = id;
    document.getElementById('editCatName').value = name;
    document.getElementById('editCatKey').value = key;
    document.getElementById('editCatIcon').value = icon;
    document.getElementById('editCatDesc').value = desc;
    document.getElementById('editCatActive').value = active ? '1' : '0';
    updateCatIconPreview('editCatIcon', 'editCatIconPreview');
    openModal('editCatModal');
}

function validateCatChange(e, form) {
    if (!currentEditCat) return true;
    const name   = document.getElementById('editCatName').value.trim();
    const key    = document.getElementById('editCatKey').value.trim();
    const icon   = document.getElementById('editCatIcon').value.trim();
    const desc   = document.getElementById('editCatDesc').value.trim();
    const active = document.getElementById('editCatActive').value;

    if (name === currentEditCat.name &&
        key === currentEditCat.key &&
        icon === currentEditCat.icon &&
        desc === currentEditCat.desc &&
        active === currentEditCat.active) {
        e.preventDefault();
        Swal.fire({
            title: 'Notice',
            text: 'No changes were made. The booking category is already up to date!',
            icon: 'info',
            confirmButtonColor: 'var(--clr-primary)',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });
        return false;
    }
    return true;
}

// ── Service edit modal & change detection ───────────────────────────────────
function openEditSvc(id, name, cat, badge, desc, dur, active) {
    currentEditSvc = {
        id: id,
        name: name.trim(),
        cat: cat.trim(),
        badge: (badge || '').trim(),
        desc: (desc || '').trim(),
        dur: (dur || '').trim(),
        active: active ? '1' : '0'
    };
    document.getElementById('editSvcId').value     = id;
    document.getElementById('editSvcName').value   = name;
    document.getElementById('editSvcCat').value    = cat;
    document.getElementById('editSvcBadge').value  = badge;
    document.getElementById('editSvcDesc').value   = desc;
    document.getElementById('editSvcDur').value    = dur;
    document.getElementById('editSvcActive').value = active ? '1' : '0';
    openModal('editSvcModal');
}

function validateSvcChange(e, form) {
    if (!currentEditSvc) return true;
    const name   = document.getElementById('editSvcName').value.trim();
    const cat    = document.getElementById('editSvcCat').value.trim();
    const badge  = document.getElementById('editSvcBadge').value.trim();
    const desc   = document.getElementById('editSvcDesc').value.trim();
    const dur    = document.getElementById('editSvcDur').value.trim();
    const active = document.getElementById('editSvcActive').value;

    if (name === currentEditSvc.name &&
        cat === currentEditSvc.cat &&
        badge === currentEditSvc.badge &&
        desc === currentEditSvc.desc &&
        dur === currentEditSvc.dur &&
        active === currentEditSvc.active) {
        e.preventDefault();
        Swal.fire({
            title: 'Notice',
            text: 'No changes were made. The service is already up to date!',
            icon: 'info',
            confirmButtonColor: 'var(--clr-primary)',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });
        return false;
    }
    return true;
}

// ── FAQ edit modal & change detection ───────────────────────────────────────
function openEditFaq(id, q, a, icon, active) {
    currentEditFaq = {
        id: id,
        q: q.trim(),
        a: a.trim(),
        icon: icon.trim(),
        active: active ? '1' : '0'
    };
    document.getElementById('editFaqId').value     = id;
    document.getElementById('editFaqQ').value      = q;
    document.getElementById('editFaqA').value      = a;
    const sel = document.getElementById('editFaqIcon');
    if (sel) sel.value = icon;
    document.getElementById('editFaqActive').value = active ? '1' : '0';
    openModal('editFaqModal');
}

function validateFaqChange(e, form) {
    if (!currentEditFaq) return true;
    const q      = document.getElementById('editFaqQ').value.trim();
    const a      = document.getElementById('editFaqA').value.trim();
    const icon   = document.getElementById('editFaqIcon').value.trim();
    const active = document.getElementById('editFaqActive').value;

    if (q === currentEditFaq.q &&
        a === currentEditFaq.a &&
        icon === currentEditFaq.icon &&
        active === currentEditFaq.active) {
        e.preventDefault();
        Swal.fire({
            title: 'Notice',
            text: 'No changes were made. This FAQ is already up to date!',
            icon: 'info',
            confirmButtonColor: 'var(--clr-primary)',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });
        return false;
    }
    return true;
}

// ── Homepage change validation ───────────────────────────────────────────────
function validateHomepageChange(e, form) {
    if (!initialHomepageData) return true;
    const currentData = new URLSearchParams(new FormData(form)).toString();
    if (currentData === initialHomepageData) {
        e.preventDefault();
        Swal.fire({
            title: 'Notice',
            text: 'No changes were made. Homepage content is already up to date!',
            icon: 'info',
            confirmButtonColor: 'var(--clr-primary)',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });
        return false;
    }
    return true;
}

// ── FAQ Accordion Toggle ─────────────────────────────────────────────────────
function toggleFaqAccordion(headerEl) {
    const card = headerEl.closest('.pm-faq-accordion-card');
    if (card) {
        card.classList.toggle('open');
    }
}

// ── Logo Canvas Mode Switcher ────────────────────────────────────────────────
function setLogoCanvas(mode) {
    const stage = document.getElementById('logoPreviewStage');
    if (!stage) return;
    stage.classList.remove('dark', 'light', 'checker');
    stage.classList.add(mode);
    
    document.getElementById('canvasDarkBtn')?.classList.toggle('active', mode === 'dark');
    document.getElementById('canvasLightBtn')?.classList.toggle('active', mode === 'light');
    document.getElementById('canvasCheckBtn')?.classList.toggle('active', mode === 'checker');
}

// ── Logo File Selection & Live Preview ──────────────────────────────────────
function handleLogoSelect(input) {
    const file = input.files && input.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        Swal.fire({
            title: 'File Too Large',
            text: 'Logo image must be smaller than 5 MB.',
            icon: 'error',
            confirmButtonColor: 'var(--clr-primary)',
            background: 'var(--bg-card)',
            color: 'var(--text-primary)'
        });
        input.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('newLogoPreviewImg').src = e.target.result;
        document.getElementById('newLogoFileName').textContent = file.name;
        document.getElementById('newLogoFileSize').textContent = '(' + (file.size / 1024).toFixed(1) + ' KB)';
        document.getElementById('uploadZonePrompt').style.display = 'none';
        document.getElementById('uploadZonePreview').style.display = 'block';
        document.getElementById('saveLogoBtn').disabled = false;
    };
    reader.readAsDataURL(file);
}

function cancelLogoSelect(e) {
    if (e) e.stopPropagation();
    const input = document.getElementById('logoFileInput');
    if (input) input.value = '';
    document.getElementById('uploadZonePreview').style.display = 'none';
    document.getElementById('uploadZonePrompt').style.display = 'block';
    document.getElementById('saveLogoBtn').disabled = true;
}

function confirmResetLogo(e) {
    e.preventDefault();
    Swal.fire({
        title: 'Reset to Default Logo?',
        text: 'This will restore the original Gueco Optical Clinic logo across your website and portals.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: 'var(--clr-primary)',
        cancelButtonColor: '#6B7280',
        confirmButtonText: 'Yes, Restore Default',
        cancelButtonText: 'Cancel',
        background: 'var(--bg-card)',
        color: 'var(--text-primary)'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('resetLogoForm').submit();
        }
    });
    return false;
}

// ── Search & Filter Logic ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    // Snapshot homepage form data for change detection
    const hpForm = document.getElementById('homepageForm');
    if (hpForm) {
        initialHomepageData = new URLSearchParams(new FormData(hpForm)).toString();
    }
    // Confirmation buttons
    document.querySelectorAll('button[data-confirm]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const form = this.closest('form');
            const msg  = this.dataset.confirm;
            Swal.fire({
                title: 'Confirm Action',
                text: msg,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: 'var(--clr-primary)',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Yes, proceed',
                cancelButtonText: 'Cancel',
                background: 'var(--bg-card)',
                color: 'var(--text-primary)'
            }).then(r => { if (r.isConfirmed) form.submit(); });
        });
    });

    // Service category filter tabs
    const catFilters = document.querySelectorAll('.pm-cat-filter');
    const svcCards   = document.querySelectorAll('.pm-hub-svc-card');
    const svcSearch  = document.getElementById('svcSearch');

    function applySvcFilter() {
        const activeCat = document.querySelector('.pm-cat-filter.active')?.dataset.filter || 'all';
        const q = svcSearch ? svcSearch.value.toLowerCase().trim() : '';

        svcCards.forEach(card => {
            const name  = card.dataset.name  || '';
            const badge = card.dataset.badge || '';
            const cat   = card.dataset.cat   || '';

            const matchCat  = (activeCat === 'all' || cat === activeCat);
            const matchText = (!q || name.includes(q) || badge.includes(q) || cat.includes(q));

            card.style.display = (matchCat && matchText) ? 'flex' : 'none';
        });
    }

    catFilters.forEach(btn => {
        btn.addEventListener('click', function() {
            catFilters.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            applySvcFilter();
        });
    });

    if (svcSearch) {
        svcSearch.addEventListener('input', applySvcFilter);
    }

    // Category live search
    const catSearch = document.getElementById('catSearch');
    if (catSearch) {
        catSearch.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.pm-cat-card').forEach(card => {
                const name = card.dataset.name || '';
                const key  = card.dataset.key  || '';
                const desc = card.dataset.desc || '';
                card.style.display = (!q || name.includes(q) || key.includes(q) || desc.includes(q)) ? 'flex' : 'none';
            });
        });
    }

    // FAQ live search
    const faqSearch = document.getElementById('faqSearch');
    if (faqSearch) {
        faqSearch.addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.pm-faq-accordion-card').forEach(c => {
                const text = c.dataset.q || '';
                c.style.display = (!q || text.includes(q)) ? '' : 'none';
            });
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
