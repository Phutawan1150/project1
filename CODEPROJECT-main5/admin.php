<?php
require_once 'config.php';
require_once 'amenities.php';
require_once 'image_helpers.php';
require_once 'edit_requests.php';

// ยังไม่ได้ล็อกอินก็เตะไปหน้า login ก่อน
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: user_login.php');
    exit;
}

$message_accommodation = '';
editRequestsEnsureTable($pdo);
$message_district = '';
$edit_notice = $_SESSION['admin_edit_notice'] ?? '';
$editPageDistrictId = null;
$districtSearch = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
unset($_SESSION['admin_edit_notice']);

// เพิ่มเขตใหม่เข้าระบบ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_district') {
    $name_th = trim($_POST['name_th']);
    $name_en = trim($_POST['name_en']);

    $dupCheck = $pdo->prepare("SELECT COUNT(*) FROM districts WHERE REPLACE(name_th, ' ', '') = :n");
    $dupCheck->execute(['n' => preg_replace('/\s+/u', '', $name_th)]);
    if (!empty($name_th) && $dupCheck->fetchColumn() > 0) {
        $message_district = "<div class='alert alert-error'>มีเขตนี้อยู่ในระบบแล้ว ไม่สามารถเพิ่มซ้ำได้</div>";
    } elseif (!empty($name_th) && !empty($name_en)) {
        try {
            $sql = "INSERT INTO districts (name_th, name_en) VALUES (:name_th, :name_en)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['name_th' => $name_th, 'name_en' => $name_en]);
            $message_district = "<div class='alert alert-success'>บันทึกข้อมูลเขตใหม่เข้าสู่ระบบเรียบร้อยแล้ว</div>";
        } catch (PDOException $e) {
            $message_district = "<div class='alert alert-error'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
    } else {
        $message_district = "<div class='alert alert-error'>กรุณากรอกข้อมูลเขตให้ครบถ้วน</div>";
    }
}

// เพิ่มที่พักใหม่ (มี district_id ผูกกับตารางเขต)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_accommodation') {
    $name = trim($_POST['name']);
    $district_id = $_POST['district_id'];
    $address = trim($_POST['address']);
    $price = parsePrice($_POST['price'] ?? '');
    $phone = trim($_POST['phone']);
    $description = trim($_POST['description']);
    $amenities = amenityCollect($_POST);
    $map_url = trim($_POST['map_url']);
    $lat = trim($_POST['lat']);
    $lng = trim($_POST['lng']);

    if ($price === null) {
        $message_accommodation = "<div class='alert alert-error'>ราคาต่อเดือนต้องเป็นตัวเลขเท่านั้น (ห้ามมีตัวอักษรหรือสัญลักษณ์)</div>";
    } elseif ($map_url !== '' && !filter_var($map_url, FILTER_VALIDATE_URL)) {
        $message_accommodation = "<div class='alert alert-error'>กรุณาใส่ลิงก์แผนที่ให้ถูกต้อง</div>";
    } elseif (!empty($name) && !empty($district_id) && !empty($phone) && !empty($lat) && !empty($lng)) {
        try {
            $sql = "INSERT INTO accommodations (name, district_id, address, price, phone, description, amenities, map_url, lat, lng) 
                    VALUES (:name, :district_id, :address, :price, :phone, :description, :amenities, :map_url, :lat, :lng)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'name' => $name, 'district_id' => $district_id, 
                'address' => $address, 'price' => $price, 
                'phone' => $phone, 'description' => $description, 'amenities' => $amenities, 'map_url' => $map_url ?: null,
                'lat' => $lat, 'lng' => $lng
            ]);
            $newId = (int) $pdo->lastInsertId();

            // รูปภาพ (ไม่บังคับ): อัปโหลดไฟล์ และ/หรือ วางลิงก์
            $imgSaved = 0;
            $imgErrors = [];
            if (!empty($_FILES['images'])) {
                [$ok, $err] = imgSaveUploadedFiles($pdo, $newId, $_FILES['images']);
                $imgSaved += count($ok);
                $imgErrors = array_merge($imgErrors, $err);
            }
            [$ok, $err] = imgSaveUrls($pdo, $newId, (string) ($_POST['image_urls'] ?? ''));
            $imgSaved += count($ok);
            $imgErrors = array_merge($imgErrors, $err);

            $extra = $imgSaved > 0 ? " (เพิ่มรูปภาพ $imgSaved รูป)" : '';
            $message_accommodation = "<div class='alert alert-success'>บันทึกข้อมูลที่พักใหม่เรียบร้อยแล้ว" . $extra . "</div>";
            if ($imgErrors) {
                $message_accommodation .= "<div class='alert alert-error'>รูปบางรายการเพิ่มไม่สำเร็จ: " . htmlspecialchars(implode(' | ', $imgErrors), ENT_QUOTES, 'UTF-8') . "</div>";
            }
        } catch (PDOException $e) {
            $message_accommodation = "<div class='alert alert-error'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
    } else {
        $message_accommodation = "<div class='alert alert-error'>กรุณากรอกช่องข้อมูลที่จำเป็นให้ครบถ้วน</div>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_accommodation') {
    $returnTo = $_POST['return_to'] ?? '';
    if (preg_match('/^admin\.php\?edit_by_district=1&district_id=([1-9][0-9]*)(?:&search=([^&]*))?$/', $returnTo, $returnMatch)) {
        $editPageDistrictId = filter_var($returnMatch[1], FILTER_VALIDATE_INT);
        if ($editPageDistrictId !== false && $editPageDistrictId > 0) {
            $districtSearch = urldecode($returnMatch[2] ?? '');
            $returnTo = 'admin.php?' . http_build_query([
                'edit_by_district' => '1',
                'district_id' => $editPageDistrictId,
                'search' => $districtSearch,
            ]);
        } else {
            $editPageDistrictId = null;
            $returnTo = 'admin.php';
        }
    }
    $accommodation_id = filter_var($_POST['accommodation_id'] ?? '', FILTER_VALIDATE_INT);
    $name = trim($_POST['name'] ?? '');
    $district_id = filter_var($_POST['district_id'] ?? '', FILTER_VALIDATE_INT);
    $address = trim($_POST['address'] ?? '');
    $price = parsePrice($_POST['price'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amenities = amenityCollect($_POST);
    $map_url = trim($_POST['map_url'] ?? '');
    $lat = trim($_POST['lat'] ?? '');
    $lng = trim($_POST['lng'] ?? '');
    $status = $_POST['status'] ?? '';
    $latitude = filter_var($lat, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($lng, FILTER_VALIDATE_FLOAT);

    $valid = $accommodation_id !== false
        && $name !== ''
        && $district_id !== false
        && $price !== null
        && $phone !== ''
        && $latitude !== false
        && $latitude >= -90
        && $latitude <= 90
        && $longitude !== false
        && $longitude >= -180
        && $longitude <= 180
        && in_array($status, ['pending', 'approved'], true)
        && ($map_url === '' || filter_var($map_url, FILTER_VALIDATE_URL));

    if (!$valid) {
        $edit_notice = "<div class='alert alert-error'>กรุณาตรวจสอบข้อมูลที่จำเป็น ราคา URL แผนที่ และพิกัดให้ถูกต้อง</div>";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE accommodations
                SET name = :name, district_id = :district_id, address = :address,
                    price = :price, phone = :phone, description = :description,
                    amenities = :amenities, map_url = :map_url, lat = :lat,
                    lng = :lng, status = :status
                WHERE id = :id");
            $stmt->execute([
                'name' => $name,
                'district_id' => $district_id,
                'address' => $address,
                'price' => $price,
                'phone' => $phone,
                'description' => $description,
                'amenities' => $amenities,
                'map_url' => $map_url !== '' ? $map_url : null,
                'lat' => $lat,
                'lng' => $lng,
                'status' => $status,
                'id' => $accommodation_id,
            ]);
            $_SESSION['admin_edit_notice'] = "<div class='alert alert-success'>บันทึกการแก้ไขข้อมูลที่พักแล้ว</div>";
            header('Location: ' . ($editPageDistrictId !== null ? $returnTo : 'admin.php'));
            exit;
        } catch (PDOException $e) {
            $edit_notice = "<div class='alert alert-error'>บันทึกข้อมูลไม่สำเร็จ กรุณาตรวจสอบเขตที่เลือกแล้วลองอีกครั้ง</div>";
        }
    }
}

// อนุมัติ/ปฏิเสธคำขอแก้ไขข้อมูลจากเจ้าของ (ที่พักที่อนุมัติแล้ว)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_edit_request') {
    $reqId = filter_var($_POST['request_id'] ?? '', FILTER_VALIDATE_INT);
    $ok = $reqId !== false && editRequestApply($pdo, $reqId);
    $_SESSION['admin_edit_notice'] = $ok
        ? "<div class='alert alert-success'>อนุมัติคำขอแก้ไขแล้ว ข้อมูลใหม่แสดงบนหน้าเว็บเรียบร้อย</div>"
        : "<div class='alert alert-error'>อนุมัติคำขอไม่สำเร็จ (คำขออาจถูกจัดการไปแล้ว หรือข้อมูลเขตไม่ถูกต้อง)</div>";
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_edit_request') {
    $reqId = filter_var($_POST['request_id'] ?? '', FILTER_VALIDATE_INT);
    if ($reqId !== false) {
        $pdo->prepare("DELETE FROM accommodation_edit_requests WHERE id = :id")->execute(['id' => $reqId]);
    }
    $_SESSION['admin_edit_notice'] = "<div class='alert alert-success'>ปฏิเสธคำขอแก้ไขแล้ว ที่พักยังคงใช้ข้อมูลเดิม</div>";
    header('Location: admin.php');
    exit;
}

// อนุมัติ/ปฏิเสธที่พักที่เจ้าของส่งเข้ามา
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'approve_accommodation') {
    $stmt = $pdo->prepare("UPDATE accommodations SET status = 'approved' WHERE id = :id");
    $stmt->execute(['id' => $_POST['accommodation_id']]);
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'reject_accommodation') {
    $stmt = $pdo->prepare("DELETE FROM accommodations WHERE id = :id AND status = 'pending'");
    $stmt->execute(['id' => $_POST['accommodation_id']]);
    header('Location: admin.php');
    exit;
}

// เอาลิสต์เขตมาใส่ dropdown
$districts = getUniqueDistricts($pdo);
$districtCanon = getDistrictCanonMap($districts);

// ที่พักที่เจ้าของส่งเข้ามารออนุมัติ
$pending = $pdo->query("SELECT a.*, d.name_th as district_name, u.full_name as owner_name 
                         FROM accommodations a
                         LEFT JOIN districts d ON a.district_id = d.id
                         LEFT JOIN users u ON a.owner_id = u.id
                         WHERE a.status = 'pending' ORDER BY a.created_at ASC")->fetchAll();

// คำขอแก้ไขจากเจ้าของที่พักที่อนุมัติแล้ว
$editRequests = $pdo->query("SELECT r.*, a.name AS cur_name, a.district_id AS cur_district_id, a.address AS cur_address,
                                    a.price AS cur_price, a.phone AS cur_phone, a.description AS cur_description,
                                    a.amenities AS cur_amenities, a.map_url AS cur_map_url, a.lat AS cur_lat, a.lng AS cur_lng,
                                    cd.name_th AS cur_district_name, nd.name_th AS new_district_name, u.full_name AS owner_name
                             FROM accommodation_edit_requests r
                             JOIN accommodations a ON a.id = r.accommodation_id
                             LEFT JOIN districts cd ON cd.id = a.district_id
                             LEFT JOIN districts nd ON nd.id = r.district_id
                             LEFT JOIN users u ON u.id = r.owner_id
                             ORDER BY r.created_at ASC")->fetchAll();

// Admin can manage images for listings after they have been approved.
$approved = $pdo->query("SELECT a.*, d.name_th as district_name, u.full_name as owner_name
                          FROM accommodations a
                          LEFT JOIN districts d ON a.district_id = d.id
                          LEFT JOIN users u ON a.owner_id = u.id
                          WHERE a.status = 'approved' ORDER BY a.id DESC")->fetchAll();

function adminEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function renderAccommodationEditForm(array $item, array $districts, ?string $returnTo = null): void
{
    ?>
    <details style="margin: 14px 0;">
        <summary style="cursor: pointer; color: var(--color-primary); font-weight: 600;">แก้ไขข้อมูลที่พัก</summary>
        <form method="POST" action="admin.php" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; padding-top: 16px;">
            <input type="hidden" name="action" value="edit_accommodation">
            <input type="hidden" name="accommodation_id" value="<?php echo (int) $item['id']; ?>">
            <?php if ($returnTo !== null): ?>
                <input type="hidden" name="return_to" value="<?php echo adminEscape($returnTo); ?>">
            <?php endif; ?>

            <div class="filter-group">
                <label>ชื่อที่พัก *</label>
                <input type="text" name="name" required class="form-control" value="<?php echo adminEscape($item['name']); ?>">
            </div>
            <div class="filter-group">
                <label>เขต *</label>
                <select name="district_id" required>
                    <?php foreach ($districts as $district): ?>
                        <option value="<?php echo (int) $district['id']; ?>" <?php echo (int) $district['id'] === (int) ($GLOBALS['districtCanon'][(int) $item['district_id']] ?? (int) $item['district_id']) ? 'selected' : ''; ?>>
                            เขต<?php echo adminEscape($district['name_th']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>ราคาต่อเดือน (บาท) *</label>
                <input type="text" name="price" inputmode="numeric" pattern="[0-9]+" maxlength="9" required class="form-control price-only" title="กรอกเป็นตัวเลขเท่านั้น" value="<?php echo (int) $item['price']; ?>">
            </div>
            <div class="filter-group">
                <label>เบอร์โทร *</label>
                <input type="tel" name="phone" required class="form-control" value="<?php echo adminEscape($item['phone']); ?>">
            </div>
            <div class="filter-group" style="grid-column: 1 / -1;">
                <label>ที่อยู่</label>
                <textarea name="address" rows="3" class="form-control" style="font-family: inherit;"><?php echo adminEscape($item['address']); ?></textarea>
            </div>
            <div class="filter-group" style="grid-column: 1 / -1;">
                <label>รายละเอียดที่พัก</label>
                <textarea name="description" rows="4" class="form-control" style="font-family: inherit;"><?php echo adminEscape($item['description']); ?></textarea>
            </div>
            <div class="filter-group" style="grid-column: 1 / -1;">
                <label>สิ่งอำนวยความสะดวก</label>
                <?php amenityRenderCheckboxes($item['amenities'] ?? ''); ?>
            </div>
            <div class="filter-group" style="grid-column: 1 / -1;">
                <label>ลิงก์ Google Maps</label>
                <input type="url" name="map_url" class="form-control" value="<?php echo adminEscape($item['map_url']); ?>">
            </div>
            <div class="filter-group">
                <label>ละติจูด *</label>
                <input type="number" name="lat" min="-90" max="90" step="any" required class="form-control" value="<?php echo adminEscape($item['lat']); ?>">
            </div>
            <div class="filter-group">
                <label>ลองจิจูด *</label>
                <input type="number" name="lng" min="-180" max="180" step="any" required class="form-control" value="<?php echo adminEscape($item['lng']); ?>">
            </div>
            <div class="filter-group">
                <label>สถานะ *</label>
                <select name="status" required>
                    <option value="pending" <?php echo $item['status'] === 'pending' ? 'selected' : ''; ?>>รออนุมัติ</option>
                    <option value="approved" <?php echo $item['status'] === 'approved' ? 'selected' : ''; ?>>อนุมัติแล้ว</option>
                </select>
            </div>
            <div style="grid-column: 1 / -1;">
                <button type="submit" class="btn btn-primary">บันทึกข้อมูลที่พัก</button>
            </div>
        </form>
    </details>
    <?php
}

function renderAccommodationImageManager(array $item, array $images): void
{
    ?>
    <div class="image-manager" id="imgmgr-<?php echo (int) $item['id']; ?>">
        <h4>รูปภาพ (<?php echo count($images); ?> รูป)</h4>
        <div class="image-grid" id="thumbs-<?php echo (int) $item['id']; ?>">
            <?php foreach ($images as $image): ?>
                <div class="image-thumb" id="thumb-<?php echo (int) $image['id']; ?>">
                    <img src="<?php echo adminEscape(imageSrc($image['filename'])); ?>" alt="">
                    <button type="button" class="image-thumb-delete" onclick="deleteImage(<?php echo (int) $image['id']; ?>)" title="ลบรูปนี้">×</button>
                </div>
            <?php endforeach; ?>
        </div>
        <label class="upload-area" for="upload-<?php echo (int) $item['id']; ?>">
            <input type="file" id="upload-<?php echo (int) $item['id']; ?>" multiple accept="image/jpeg,image/png,image/webp"
                   onchange="uploadImages(this, <?php echo (int) $item['id']; ?>)">
            📷 เพิ่มรูปภาพ (JPG, PNG, WebP, ไม่เกิน 5MB/รูป)
        </label>
        <div id="upload-status-<?php echo (int) $item['id']; ?>" style="margin-top:8px; font-size:0.85rem; color:#64748B;"></div>
        <div class="url-add">
            <textarea id="url-input-<?php echo (int) $item['id']; ?>" rows="2" class="form-control" placeholder="หรือวางลิงก์รูปภาพที่นี่ (หลายลิงก์ให้ขึ้นบรรทัดใหม่)"></textarea>
            <button type="button" class="btn btn-secondary-outline" onclick="addImageUrls(<?php echo (int) $item['id']; ?>)">+ เพิ่มจากลิงก์</button>
        </div>
    </div>
    <?php
}

$districtEditorRequested = ($_GET['edit_by_district'] ?? '') === '1' || $editPageDistrictId !== null;
if ($districtEditorRequested) {
    $requestedDistrictId = $editPageDistrictId ?? filter_var($_GET['district_id'] ?? '', FILTER_VALIDATE_INT);
    $selectedDistrict = null;
    $districtAccommodations = [];

    if ($requestedDistrictId !== false && $requestedDistrictId !== null && $requestedDistrictId > 0) {
        $canonId = $districtCanon[(int) $requestedDistrictId] ?? null;
        $selectedIds = [];
        foreach ($districts as $dRow) {
            if ($canonId !== null && (int) $dRow['id'] === $canonId) {
                $selectedDistrict = ['id' => (int) $dRow['id'], 'name_th' => $dRow['name_th']];
                $selectedIds = array_map('intval', $dRow['ids']);
                break;
            }
        }

        if ($selectedDistrict) {
            $accommodationsStmt = $pdo->prepare("SELECT a.*, d.name_th AS district_name
                FROM accommodations a
                LEFT JOIN districts d ON a.district_id = d.id
                WHERE a.district_id IN (" . implode(',', $selectedIds) . ")
                " . ($districtSearch !== '' ? "AND (
                    a.name LIKE :search_name
                    OR a.address LIKE :search_address
                    OR a.description LIKE :search_description
                    OR a.amenities LIKE :search_amenities
                    OR a.phone LIKE :search_phone
                ) " : '') . "
                ORDER BY a.id DESC");
            $accommodationParams = [];
            if ($districtSearch !== '') {
                $searchTerm = '%' . $districtSearch . '%';
                $accommodationParams += [
                    'search_name' => $searchTerm,
                    'search_address' => $searchTerm,
                    'search_description' => $searchTerm,
                    'search_amenities' => $searchTerm,
                    'search_phone' => $searchTerm,
                ];
            }
            $accommodationsStmt->execute($accommodationParams);
            $districtAccommodations = $accommodationsStmt->fetchAll();
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>เลือกเขตเพื่อแก้ไขที่พัก - BKK STAYPOINT</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="style.css">
        <script src="admin-images.js" defer></script>
    </head>
    <body>
    <nav class="navbar">
        <div class="nav-container">
            <a href="admin.php" class="nav-brand">กลับหน้าแอดมิน</a>
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="color: #64748B; font-size: 0.9rem;">สวัสดี, <?php echo adminEscape($_SESSION['admin_username']); ?></span>
                <a href="logout.php" class="btn btn-secondary-outline">ออกจากระบบ</a>
            </div>
        </div>
    </nav>
    <main class="container" style="margin-top: 40px; margin-bottom: 60px;">
        <div class="section-header">
            <h2>เลือกเขตเพื่อแก้ไขข้อมูลที่พัก</h2>
            <p style="color: #64748B; margin-top: 4px;">เลือกหนึ่งเขตเพื่อดูและแก้ไขเฉพาะที่พักในเขตนั้น</p>
            <div class="divider"></div>
        </div>

        <?php echo $edit_notice; ?>

        <section class="card" style="padding: 24px; margin-bottom: 24px;">
            <form method="GET" action="admin.php" style="display: flex; flex-wrap: wrap; align-items: end; gap: 16px;">
                <input type="hidden" name="edit_by_district" value="1">
                <div class="filter-group" style="min-width: 240px;">
                    <label for="district_id">เลือกเขต</label>
                    <select id="district_id" name="district_id" required>
                        <option value="">เลือกเขตพื้นที่...</option>
                        <?php foreach ($districts as $district): ?>
                            <option value="<?php echo (int) $district['id']; ?>" <?php echo $selectedDistrict && (int) $selectedDistrict['id'] === (int) $district['id'] ? 'selected' : ''; ?>>
                                เขต<?php echo adminEscape($district['name_th']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="min-width: 240px; flex: 1;">
                    <label for="search">ค้นหาที่พัก</label>
                    <input type="search" id="search" name="search" class="form-control"
                           placeholder="ชื่อ ที่อยู่ รายละเอียด หรือเบอร์โทร"
                           value="<?php echo adminEscape($districtSearch); ?>">
                </div>
                <button type="submit" class="btn btn-primary">ค้นหา</button>
            </form>
        </section>

        <?php if ($requestedDistrictId !== false && $requestedDistrictId !== null && $requestedDistrictId > 0 && !$selectedDistrict): ?>
            <div class="alert alert-error">ไม่พบเขตที่เลือก กรุณาเลือกเขตอีกครั้ง</div>
        <?php endif; ?>

        <?php if ($selectedDistrict): ?>
            <div class="section-header">
                <h3>ที่พักในเขต<?php echo adminEscape($selectedDistrict['name_th']); ?> (<?php echo count($districtAccommodations); ?>)</h3>
                <div class="divider"></div>
            </div>
            <?php if (!$districtAccommodations): ?>
                <div class="card" style="padding: 24px;">
                    <?php echo $districtSearch !== '' ? 'ไม่พบที่พักที่ตรงกับคำค้นหาในเขตนี้' : 'ยังไม่มีที่พักในเขตนี้'; ?>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <?php foreach ($districtAccommodations as $item):
                        $imgsStmt = $pdo->prepare("SELECT id, filename FROM accommodation_images WHERE accommodation_id = :id ORDER BY sort_order ASC");
                        $imgsStmt->execute(['id' => $item['id']]);
                        $imgs = $imgsStmt->fetchAll();
                        $returnToDistrict = 'admin.php?' . http_build_query([
                            'edit_by_district' => '1',
                            'district_id' => (int) $selectedDistrict['id'],
                            'search' => $districtSearch,
                        ]);
                    ?>
                        <section class="card" style="padding: 20px 24px;">
                            <h4 style="font-weight: 700; color: var(--color-neutral);"><?php echo adminEscape($item['name']); ?></h4>
                            <p style="color: #64748B; font-size: 0.9rem; margin-top: 4px;">
                                เขต<?php echo adminEscape($item['district_name']); ?> ·
                                ฿<?php echo number_format((int) $item['price']); ?>/คืน ·
                                <?php echo $item['status'] === 'pending' ? 'รออนุมัติ' : 'อนุมัติแล้ว'; ?>
                            </p>
                            <?php renderAccommodationEditForm(
                                $item,
                                $districts,
                                $returnToDistrict
                            ); ?>
                            <?php renderAccommodationImageManager($item, $imgs); ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <script>
    // ช่องราคา: อนุญาตเฉพาะตัวเลข 0-9 (กันพิมพ์/วางตัวอักษร)
    document.querySelectorAll('input.price-only').forEach(function (el) {
        el.addEventListener('input', function () { el.value = el.value.replace(/[^0-9]/g, ''); });
        el.addEventListener('keypress', function (e) { if (e.key.length === 1 && !/[0-9]/.test(e.key)) e.preventDefault(); });
        el.addEventListener('paste', function (e) {
            e.preventDefault();
            var t = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
            document.execCommand('insertText', false, t);
        });
    });
    </script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการข้อมูลระบบ - BKK STAYPOINT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="admin-images.js" defer></script>
</head>
<body>

<nav class="navbar">
    <div class="nav-container">
        <a href="index.html" class="nav-brand">
            <svg class="icon-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            <span>รวมที่พักทั่วกรุงเทพ</span>
        </a>
        <div style="display: flex; align-items: center; gap: 12px;">
            <span style="color: #64748B; font-size: 0.9rem;">สวัสดี, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
            <a href="index.html" class="btn btn-secondary-outline">กลับไปหน้าค้นหา</a>
            <a href="logout.php" class="btn btn-secondary-outline">ออกจากระบบ</a>
        </div>
    </div>
</nav>

<main class="container" style="margin-top: 40px; margin-bottom: 60px;">
    
    <div class="section-header">
        <h2>แผงควบคุมหลังบ้านและการจัดการข้อมูล</h2>
        <p style="color: #64748B; margin-top: 4px;">เพิ่มข้อมูลพื้นที่เขต และอัปเดตรายชื่อที่พักใหม่เข้าสู่ระบบฐานข้อมูล</p>
        <div class="divider"></div>
    </div>

    <?php echo $edit_notice; ?>

    <div class="admin-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 32px;">
        
        <section class="card" style="padding: 30px; height: fit-content;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px; color: var(--color-neutral);">
                <svg style="width: 24px; height: 24px; color: var(--color-primary);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></circle><circle cx="12" cy="10" r="3"></circle></svg>
                <h3 style="font-size: 1.3rem; font-weight: 700;">เพิ่มเขตพื้นที่ใหม่</h3>
            </div>
            
            <?php echo $message_district; ?>
            
            <form method="POST" action="admin.php" style="display: flex; flex-direction: column; gap: 18px;">
                <input type="hidden" name="action" value="add_district">
                
                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">ชื่อเขต (ภาษาไทย) *</label>
                    <input type="text" name="name_th" required placeholder="เช่น ดินแดง" class="form-control">
                </div>
                
                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">ชื่อเขต (ภาษาอังกฤษ) *</label>
                    <input type="text" name="name_en" required placeholder="เช่น Din Daeng" class="form-control">
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px;">เพิ่มเขตพื้นที่เข้าสู่ระบบ</button>
            </form>
        </section>

        <section class="card" style="padding: 30px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px; color: var(--color-neutral);">
                <svg style="width: 24px; height: 24px; color: var(--color-primary);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
                <h3 style="font-size: 1.3rem; font-weight: 700;">เพิ่มข้อมูลที่พักใหม่</h3>
            </div>
            
            <?php echo $message_accommodation; ?>
            
            <form method="POST" action="admin.php" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 18px;">
                <input type="hidden" name="action" value="add_accommodation">
                
                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">ชื่อโรงแรม / ที่พัก *</label>
                    <input type="text" name="name" required placeholder="เช่น สยามบูทีค เรสซิเดนซ์" class="form-control">
                </div>

                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">เขตพื้นที่ตั้ง *</label>
                    <select name="district_id" required>
                        <option value="">เลือกเขตพื้นที่ที่ตั้ง...</option>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?php echo $d['id']; ?>">เขต<?php echo $d['name_th']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">ราคาค่าที่พักต่อเดือน (บาท) *</label>
                    <input type="text" name="price" inputmode="numeric" pattern="[0-9]+" maxlength="9" required placeholder="เช่น 1500" class="form-control price-only" title="กรอกเป็นตัวเลขเท่านั้น">
                </div>

                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">ที่อยู่โดยละเอียด</label>
                    <textarea name="address" rows="3" placeholder="ระบุเลขที่ ถนน ซอย หรือจุดสังเกตสำคัญ" class="form-control" style="resize: none; font-family: inherit;"></textarea>
                </div>

                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">เบอร์โทรติดต่อ *</label>
                    <input type="tel" name="phone" required placeholder="เช่น 081-234-5678" class="form-control">
                </div>

                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">รายละเอียดที่พัก</label>
                    <textarea name="description" rows="4" placeholder="เช่น ห้องพักใกล้รถไฟฟ้า บรรยากาศเงียบสงบ มีพนักงานต้อนรับ 24 ชั่วโมง" class="form-control" style="resize: vertical; font-family: inherit;"></textarea>
                </div>

                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">สิ่งอำนวยความสะดวก</label>
                    <?php amenityRenderCheckboxes(''); ?>
                </div>

                <div class="filter-group" style="width: 100%;">
                    <label style="margin-bottom: 6px;">ลิงก์ Google Maps</label>
                    <input type="url" name="map_url" placeholder="วางลิงก์จากปุ่ม แชร์ ใน Google Maps" class="form-control">
                    <small style="color: #64748B;">ผู้เข้าชมจะกดเปิดตำแหน่งนี้ใน Google Maps ได้</small>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="filter-group" style="width: 100%;">
                        <label style="margin-bottom: 6px;">ละติจูด (Latitude) *</label>
                        <input type="text" name="lat" required placeholder="เช่น 13.7441" class="form-control">
                    </div>
                    <div class="filter-group" style="width: 100%;">
                        <label style="margin-bottom: 6px;">ลองจิจูด (Longitude) *</label>
                        <input type="text" name="lng" required placeholder="เช่น 100.5284" class="form-control">
                    </div>
                </div>

                <div class="image-manager" style="margin-top: 0;">
                    <h4>รูปภาพ <span style="font-weight: 400; color: #64748B; font-size: 0.85rem;">(ไม่บังคับ)</span></h4>
                    <div class="image-grid" id="new-previews"></div>
                    <label class="upload-area" for="new-images">
                        <input type="file" id="new-images" name="images[]" multiple accept="image/jpeg,image/png,image/webp">
                        📷 คลิกเพื่อเลือกรูปภาพ (JPG, PNG, WebP, ไม่เกิน 5MB/รูป)
                    </label>
                    <div id="new-images-status" style="margin-top:8px; font-size:0.85rem; color:#64748B;"></div>
                    <div class="url-add">
                        <textarea name="image_urls" rows="2" class="form-control" placeholder="หรือวางลิงก์รูปภาพที่นี่ (หลายลิงก์ให้ขึ้นบรรทัดใหม่)"></textarea>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; background-color: var(--color-primary);">บันทึกข้อมูลที่พัก</button>
            </form>
            <script>
                document.getElementById('new-images').addEventListener('change', function () {
                    const box = document.getElementById('new-previews');
                    const status = document.getElementById('new-images-status');
                    box.innerHTML = '';
                    [...this.files].forEach(file => {
                        const div = document.createElement('div');
                        div.className = 'image-thumb';
                        const img = document.createElement('img');
                        img.src = URL.createObjectURL(file);
                        img.alt = '';
                        div.appendChild(img);
                        box.appendChild(div);
                    });
                    status.textContent = this.files.length ? 'เลือกไว้ ' + this.files.length + ' รูป (จะอัปโหลดตอนกดบันทึก)' : '';
                });
            </script>
        </section>

    </div>

    <?php if (!empty($editRequests)): ?>
    <div class="section-header">
        <h2>คำขอแก้ไขข้อมูลจากเจ้าของ (<?php echo count($editRequests); ?>)</h2>
        <p style="color: #64748B; margin-top: 4px;">ข้อมูลเดิมยังแสดงบนหน้าเว็บ จนกว่าจะกดอนุมัติคำขอ</p>
        <div class="divider"></div>
    </div>
    <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 40px;">
        <?php
        $fieldLabels = [
            'name' => 'ชื่อที่พัก', 'district' => 'เขต', 'address' => 'ที่อยู่', 'price' => 'ราคาต่อเดือน (บาท)',
            'phone' => 'เบอร์โทร', 'description' => 'รายละเอียด', 'amenities' => 'สิ่งอำนวยความสะดวก',
            'map_url' => 'ลิงก์ Google Maps', 'lat' => 'ละติจูด', 'lng' => 'ลองจิจูด',
        ];
        foreach ($editRequests as $req):
            $changes = [];
            $pairs = [
                'name' => [$req['cur_name'], $req['name']],
                'district' => [$req['cur_district_name'] ?? '-', $req['new_district_name'] ?? '-'],
                'address' => [$req['cur_address'], $req['address']],
                'price' => [$req['cur_price'], $req['price']],
                'phone' => [$req['cur_phone'], $req['phone']],
                'description' => [$req['cur_description'], $req['description']],
                'amenities' => [implode(', ', amenityParse($req['cur_amenities'])), implode(', ', amenityParse($req['amenities']))],
                'map_url' => [$req['cur_map_url'], $req['map_url']],
                'lat' => [$req['cur_lat'], $req['lat']],
                'lng' => [$req['cur_lng'], $req['lng']],
            ];
            foreach ($pairs as $key => [$old, $new]) {
                $same = in_array($key, ['lat', 'lng'], true)
                    ? abs((float) $old - (float) $new) < 1e-7
                    : trim((string) $old) === trim((string) $new);
                if (!$same) {
                    $changes[$key] = [$old, $new];
                }
            }
        ?>
            <div class="card" style="padding: 20px 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h4 style="font-weight: 700; color: var(--color-neutral);"><?php echo adminEscape($req['cur_name']); ?></h4>
                        <p style="color: #64748B; font-size: 0.9rem;">
                            ส่งโดย: <?php echo adminEscape($req['owner_name'] ?? 'ไม่ทราบ'); ?> ·
                            <?php echo adminEscape($req['created_at']); ?>
                        </p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <form method="POST" action="admin.php">
                            <input type="hidden" name="action" value="approve_edit_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) $req['id']; ?>">
                            <button type="submit" class="btn btn-primary" style="padding: 10px 18px;">อนุมัติการแก้ไข</button>
                        </form>
                        <form method="POST" action="admin.php" onsubmit="return confirm('ปฏิเสธคำขอแก้ไขนี้ใช่ไหม? ที่พักจะยังใช้ข้อมูลเดิม');">
                            <input type="hidden" name="action" value="reject_edit_request">
                            <input type="hidden" name="request_id" value="<?php echo (int) $req['id']; ?>">
                            <button type="submit" class="btn btn-secondary" style="padding: 10px 18px;">ปฏิเสธ</button>
                        </form>
                    </div>
                </div>

                <?php if (!$changes): ?>
                    <p style="color: #64748B; margin-top: 14px;">คำขอนี้ไม่มีข้อมูลที่ต่างจากของเดิม</p>
                <?php else: ?>
                    <div style="margin-top: 14px; overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                            <thead>
                                <tr style="text-align: left; color: #64748B; border-bottom: 1px solid var(--color-border);">
                                    <th style="padding: 8px 10px; width: 20%;">หัวข้อ</th>
                                    <th style="padding: 8px 10px; width: 40%;">ข้อมูลเดิม</th>
                                    <th style="padding: 8px 10px; width: 40%;">ข้อมูลใหม่</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($changes as $key => [$old, $new]): ?>
                                    <tr style="border-bottom: 1px solid var(--color-border); vertical-align: top;">
                                        <td style="padding: 8px 10px; font-weight: 600;"><?php echo adminEscape($fieldLabels[$key]); ?></td>
                                        <td style="padding: 8px 10px; color: #94A3B8; white-space: pre-wrap; word-break: break-word;"><?php echo adminEscape(trim((string) $old) !== '' ? $old : '-'); ?></td>
                                        <td style="padding: 8px 10px; color: var(--color-neutral); white-space: pre-wrap; word-break: break-word;"><?php echo adminEscape(trim((string) $new) !== '' ? $new : '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($pending)): ?>
    <div class="section-header">
        <h2>ที่พักรออนุมัติจากเจ้าของ (<?php echo count($pending); ?>)</h2>
        <div class="divider"></div>
    </div>
    <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 40px;">
        <?php foreach ($pending as $item): 
            $imgs_stmt = $pdo->prepare("SELECT * FROM accommodation_images WHERE accommodation_id = :id ORDER BY sort_order ASC");
            $imgs_stmt->execute(['id' => $item['id']]);
            $imgs = $imgs_stmt->fetchAll();
        ?>
            <div class="card" style="padding: 20px 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h4 style="font-weight: 700; color: var(--color-neutral);"><?php echo htmlspecialchars($item['name']); ?></h4>
                        <p style="color: #64748B; font-size: 0.9rem;">
                            เขต<?php echo htmlspecialchars($item['district_name'] ?? 'ไม่ระบุ'); ?> ·
                            ฿<?php echo number_format($item['price']); ?>/คืน ·
                            ส่งโดย: <?php echo htmlspecialchars($item['owner_name'] ?? 'ไม่ทราบ'); ?>
                        </p>
                        <p style="color: #94A3B8; font-size: 0.85rem; margin-top: 4px;"><?php echo htmlspecialchars($item['address']); ?></p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <form method="POST" action="admin.php">
                            <input type="hidden" name="action" value="approve_accommodation">
                            <input type="hidden" name="accommodation_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" class="btn btn-primary" style="padding: 10px 18px;">อนุมัติ</button>
                        </form>
                        <form method="POST" action="admin.php" onsubmit="return confirm('ปฏิเสธและลบรายการนี้ทิ้งเลยใช่ไหม?');">
                            <input type="hidden" name="action" value="reject_accommodation">
                            <input type="hidden" name="accommodation_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" class="btn btn-secondary" style="padding: 10px 18px;">ปฏิเสธ</button>
                        </form>
                    </div>
                </div>

                <?php renderAccommodationEditForm($item, $districts); ?>

                <div class="image-manager" id="imgmgr-<?php echo $item['id']; ?>">
                    <h4>รูปภาพ (<?php echo count($imgs); ?> รูป)</h4>
                    <div class="image-grid" id="thumbs-<?php echo $item['id']; ?>">
                        <?php foreach ($imgs as $img): ?>
                            <div class="image-thumb" id="thumb-<?php echo $img['id']; ?>">
                                <img src="<?php echo htmlspecialchars(imageSrc($img['filename'])); ?>" alt="">
                                <button class="image-thumb-delete" onclick="deleteImage(<?php echo $img['id']; ?>)" title="ลบรูปนี้">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="upload-area" for="upload-<?php echo $item['id']; ?>">
                        <input type="file" id="upload-<?php echo $item['id']; ?>" multiple accept="image/jpeg,image/png,image/webp"
                               onchange="uploadImages(this, <?php echo $item['id']; ?>)">
                        📷 คลิกหรือลากรูปมาวางที่นี่
                    </label>
                    <div id="upload-status-<?php echo $item['id']; ?>" style="margin-top:8px; font-size:0.85rem; color:#64748B;"></div>
        <div class="url-add">
            <textarea id="url-input-<?php echo (int) $item['id']; ?>" rows="2" class="form-control" placeholder="หรือวางลิงก์รูปภาพที่นี่ (หลายลิงก์ให้ขึ้นบรรทัดใหม่)"></textarea>
            <button type="button" class="btn btn-secondary-outline" onclick="addImageUrls(<?php echo (int) $item['id']; ?>)">+ เพิ่มจากลิงก์</button>
        </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="section-header">
        <h2>จัดการที่พักที่อนุมัติแล้ว (<?php echo count($approved); ?>)</h2>
        <p style="color: #64748B; margin-top: 4px;">แก้ไขข้อมูล เพิ่มหรือลบรูปของที่พักได้ภายหลัง</p>
        <p style="margin-top: 12px;"><a class="btn btn-secondary-outline" href="admin.php?edit_by_district=1">เลือกเขตเพื่อแก้ไขที่พัก</a></p>
        <div class="divider"></div>
    </div>
    <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 40px;">
        <?php foreach ($approved as $item):
            $imgs_stmt = $pdo->prepare("SELECT * FROM accommodation_images WHERE accommodation_id = :id ORDER BY sort_order ASC");
            $imgs_stmt->execute(['id' => $item['id']]);
            $imgs = $imgs_stmt->fetchAll();
        ?>
            <div class="card" style="padding: 20px 24px;">
                <h4 style="font-weight: 700; color: var(--color-neutral);"><?php echo htmlspecialchars($item['name']); ?></h4>
                <p style="color: #64748B; font-size: 0.9rem; margin: 4px 0 14px;">เขต<?php echo htmlspecialchars($item['district_name'] ?? 'ไม่ระบุ'); ?></p>
                <?php renderAccommodationEditForm($item, $districts); ?>
                <div class="image-manager" id="imgmgr-<?php echo $item['id']; ?>">
                    <h4>รูปภาพ (<?php echo count($imgs); ?> รูป)</h4>
                    <div class="image-grid" id="thumbs-<?php echo $item['id']; ?>">
                        <?php foreach ($imgs as $img): ?>
                            <div class="image-thumb" id="thumb-<?php echo $img['id']; ?>">
                                <img src="<?php echo htmlspecialchars(imageSrc($img['filename'])); ?>" alt="">
                                <button class="image-thumb-delete" onclick="deleteImage(<?php echo $img['id']; ?>)" title="ลบรูปนี้">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label class="upload-area" for="upload-<?php echo $item['id']; ?>">
                        <input type="file" id="upload-<?php echo $item['id']; ?>" multiple accept="image/jpeg,image/png,image/webp" onchange="uploadImages(this, <?php echo $item['id']; ?>)">
                        📷 คลิกหรือลากรูปมาวางที่นี่ (JPG, PNG, WebP, ไม่เกิน 5MB/รูป)
                    </label>
                    <div id="upload-status-<?php echo $item['id']; ?>" style="margin-top:8px; font-size:0.85rem; color:#64748B;"></div>
        <div class="url-add">
            <textarea id="url-input-<?php echo (int) $item['id']; ?>" rows="2" class="form-control" placeholder="หรือวางลิงก์รูปภาพที่นี่ (หลายลิงก์ให้ขึ้นบรรทัดใหม่)"></textarea>
            <button type="button" class="btn btn-secondary-outline" onclick="addImageUrls(<?php echo (int) $item['id']; ?>)">+ เพิ่มจากลิงก์</button>
        </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</main>


<script>
// ช่องราคา: อนุญาตเฉพาะตัวเลข 0-9 (กันพิมพ์/วางตัวอักษร)
document.querySelectorAll('input.price-only').forEach(function (el) {
    el.addEventListener('input', function () { el.value = el.value.replace(/[^0-9]/g, ''); });
    el.addEventListener('keypress', function (e) { if (e.key.length === 1 && !/[0-9]/.test(e.key)) e.preventDefault(); });
    el.addEventListener('paste', function (e) {
        e.preventDefault();
        var t = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
        document.execCommand('insertText', false, t);
    });
});
</script>
</body>
</html>