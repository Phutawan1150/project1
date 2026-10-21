<?php
require_once 'config.php';
require_once 'amenities.php';
require_once 'image_helpers.php';
require_once 'edit_requests.php';

if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'owner') {
    header('Location: user_login.php');
    exit;
}

$message = '';
$message_edit = '';
editRequestsEnsureTable($pdo);

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
        $message = "<div class='alert alert-error'>ราคาต่อคืนต้องเป็นตัวเลขเท่านั้น (ห้ามมีตัวอักษรหรือสัญลักษณ์)</div>";
    } elseif ($map_url !== '' && !filter_var($map_url, FILTER_VALIDATE_URL)) {
        $message = "<div class='alert alert-error'>กรุณาใส่ลิงก์แผนที่ให้ถูกต้อง</div>";
    } elseif (!empty($name) && !empty($district_id) && !empty($phone) && !empty($lat) && !empty($lng)) {
        $sql = "INSERT INTO accommodations (name, district_id, address, price, phone, description, amenities, map_url, lat, lng, owner_id, status) 
                VALUES (:name, :district_id, :address, :price, :phone, :description, :amenities, :map_url, :lat, :lng, :owner_id, 'pending')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'name' => $name, 'district_id' => $district_id,
            'address' => $address, 'price' => $price,
            'phone' => $phone, 'description' => $description, 'amenities' => $amenities, 'map_url' => $map_url ?: null,
            'lat' => $lat, 'lng' => $lng, 'owner_id' => $_SESSION['user_id']
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
        $message = "<div class='alert alert-success'>ส่งที่พักเข้าระบบแล้ว รอแอดมินตรวจสอบและอนุมัติก่อนจะขึ้นหน้าเว็บ" . $extra . "</div>";
        if ($imgErrors) {
            $message .= "<div class='alert alert-error'>รูปบางรายการเพิ่มไม่สำเร็จ: " . htmlspecialchars(implode(' | ', $imgErrors), ENT_QUOTES, 'UTF-8') . "</div>";
        }
    } else {
        $message = "<div class='alert alert-error'>กรุณากรอกช่องข้อมูลที่จำเป็นให้ครบถ้วน</div>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_accommodation') {
    $accommodation_id = filter_var($_POST['accommodation_id'] ?? '', FILTER_VALIDATE_INT);
    $e_name = trim($_POST['name'] ?? '');
    $e_district = filter_var($_POST['district_id'] ?? '', FILTER_VALIDATE_INT);
    $e_address = trim($_POST['address'] ?? '');
    $e_price = parsePrice($_POST['price'] ?? '');
    $e_phone = trim($_POST['phone'] ?? '');
    $e_desc = trim($_POST['description'] ?? '');
    $e_amen = amenityCollect($_POST);
    $e_map = trim($_POST['map_url'] ?? '');
    $e_lat = trim($_POST['lat'] ?? '');
    $e_lng = trim($_POST['lng'] ?? '');
    $e_latf = filter_var($e_lat, FILTER_VALIDATE_FLOAT);
    $e_lngf = filter_var($e_lng, FILTER_VALIDATE_FLOAT);

    $own = null;
    if ($accommodation_id !== false) {
        $chk = $pdo->prepare("SELECT id, status FROM accommodations WHERE id = :id AND owner_id = :oid");
        $chk->execute(['id' => $accommodation_id, 'oid' => $_SESSION['user_id']]);
        $own = $chk->fetch();
    }
    $districtOk = false;
    if ($e_district !== false) {
        $dchk = $pdo->prepare("SELECT id FROM districts WHERE id = :id");
        $dchk->execute(['id' => $e_district]);
        $districtOk = (bool) $dchk->fetch();
    }

    if (!$own) {
        $message_edit = "<div class='alert alert-error'>ไม่พบที่พักนี้ หรือที่พักนี้ไม่ใช่ของคุณ</div>";
    } elseif (
        $e_name === '' || !$districtOk || $e_price === null || $e_phone === ''
        || $e_latf === false || $e_latf < -90 || $e_latf > 90
        || $e_lngf === false || $e_lngf < -180 || $e_lngf > 180
        || ($e_map !== '' && !filter_var($e_map, FILTER_VALIDATE_URL))
    ) {
        $message_edit = "<div class='alert alert-error'>กรุณาตรวจสอบข้อมูลที่จำเป็น ราคา ลิงก์แผนที่ และพิกัดให้ถูกต้อง</div>";
    } else {
        $data = [
            'name' => $e_name, 'district_id' => $e_district, 'address' => $e_address,
            'price' => $e_price, 'phone' => $e_phone, 'description' => $e_desc,
            'amenities' => $e_amen, 'map_url' => $e_map !== '' ? $e_map : null,
            'lat' => $e_lat, 'lng' => $e_lng,
        ];
        try {
            if ($own['status'] === 'pending') {
                // ยังไม่เคยอนุมัติ: แก้ที่รายการเดิมได้เลย (ยังรอแอดมินตรวจอยู่แล้ว)
                $upd = $pdo->prepare("UPDATE accommodations SET name = :name, district_id = :district_id, address = :address,
                        price = :price, phone = :phone, description = :description, amenities = :amenities,
                        map_url = :map_url, lat = :lat, lng = :lng WHERE id = :id AND owner_id = :oid");
                $upd->execute($data + ['id' => $accommodation_id, 'oid' => $_SESSION['user_id']]);
                $message_edit = "<div class='alert alert-success'>บันทึกการแก้ไขแล้ว ที่พักนี้ยังรอแอดมินอนุมัติอยู่</div>";
            } else {
                // อนุมัติแล้ว: ส่งเป็นคำขอแก้ไข ข้อมูลเดิมยังแสดงบนเว็บจนกว่าแอดมินจะอนุมัติ
                editRequestSave($pdo, $accommodation_id, (int) $_SESSION['user_id'], $data);
                $message_edit = "<div class='alert alert-success'>ส่งคำขอแก้ไขแล้ว รอแอดมินอนุมัติ ระหว่างนี้หน้าเว็บจะยังแสดงข้อมูลเดิม</div>";
            }
        } catch (PDOException $e) {
            $message_edit = "<div class='alert alert-error'>บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง</div>";
        }
    }
}

// กันตัวเลือกเขตซ้ำ: ถ้าใน DB มีชื่อเขตซ้ำกัน ให้เก็บแถวที่ id น้อยที่สุดไว้แถวเดียว
$districts = getUniqueDistricts($pdo);
$districtCanon = getDistrictCanonMap($districts); // id ของแถวซ้ำ => id ของแถวที่เก็บไว้

$stmt = $pdo->prepare("SELECT a.*, d.name_th as district_name FROM accommodations a 
                        LEFT JOIN districts d ON a.district_id = d.id
                        WHERE a.owner_id = :owner_id ORDER BY a.id DESC");
$stmt->execute(['owner_id' => $_SESSION['user_id']]);
$my_listings = $stmt->fetchAll();

// คำขอแก้ไขที่ยังรอแอดมินอนุมัติ (key = id ที่พัก)
$myEditRequests = [];
$reqStmt = $pdo->prepare("SELECT * FROM accommodation_edit_requests WHERE owner_id = :oid");
$reqStmt->execute(['oid' => $_SESSION['user_id']]);
foreach ($reqStmt->fetchAll() as $r) {
    $myEditRequests[(int) $r['accommodation_id']] = $r;
}

function ownerEsc($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}


function renderOwnerEditForm(
    array $item,
    ?array $request,
    array $districts,
    array $districtCanon
): void {
    global $pdo;

    $v = $request ?? $item;
    $id = (int) $item['id'];

    $selected = $districtCanon[(int) ($v['district_id'] ?? 0)]
        ?? (int) ($v['district_id'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT * FROM accommodation_images
         WHERE accommodation_id = :id
         ORDER BY sort_order ASC"
    );
    $stmt->execute(['id' => $id]);
    $imgs = $stmt->fetchAll();
    ?>

    <details class="owner-edit" style="margin:14px 0;">
        <summary style="cursor:pointer;color:var(--color-primary);font-weight:600;">
            แก้ไขข้อมูลที่พักและจัดการรูปภาพ
        </summary>

        <div style="padding-top:16px;">

            <form method="POST" action="owner_dashboard.php"
                  style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">

                <input type="hidden" name="action" value="edit_accommodation">
                <input type="hidden" name="accommodation_id" value="<?php echo $id; ?>">

                <div class="filter-group" style="grid-column:1/-1;">
                    <label>ชื่อที่พัก *</label>
                    <input type="text" name="name" required class="form-control"
                           value="<?php echo ownerEsc($v['name']); ?>">
                </div>

                <div class="filter-group">
                    <label>เขต *</label>
                    <select name="district_id" required>
                        <?php foreach ($districts as $d): ?>
                            <option value="<?php echo (int) $d['id']; ?>"
                                <?php echo (int) $d['id'] === $selected ? 'selected' : ''; ?>>
                                เขต<?php echo ownerEsc($d['name_th']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label>ราคาต่อเดือน (บาท) *</label>
                    <input type="text" name="price" inputmode="numeric" pattern="[0-9]+" maxlength="9" required
                           class="form-control price-only" title="กรอกเป็นตัวเลขเท่านั้น"
                           value="<?php echo (int) $v['price']; ?>">
                </div>

                <div class="filter-group">
                    <label>เบอร์โทร *</label>
                    <input type="tel" name="phone" required class="form-control"
                           value="<?php echo ownerEsc($v['phone']); ?>">
                </div>

                <div class="filter-group" style="grid-column:1/-1;">
                    <label>ที่อยู่</label>
                    <textarea name="address" rows="3" class="form-control"
                              style="font-family:inherit;"><?php
                        echo ownerEsc($v['address'] ?? '');
                    ?></textarea>
                </div>

                <div class="filter-group" style="grid-column:1/-1;">
                    <label>รายละเอียดที่พัก</label>
                    <textarea name="description" rows="4" class="form-control"
                              style="font-family:inherit;"><?php
                        echo ownerEsc($v['description'] ?? '');
                    ?></textarea>
                </div>

                <div class="filter-group" style="grid-column:1/-1;">
                    <label>สิ่งอำนวยความสะดวก</label>
                    <?php amenityRenderCheckboxes($v['amenities'] ?? ''); ?>
                </div>

                <div class="filter-group" style="grid-column:1/-1;">
                    <label>ลิงก์ Google Maps</label>
                    <input type="url" name="map_url" class="form-control"
                           value="<?php echo ownerEsc($v['map_url'] ?? ''); ?>">
                </div>

                <div class="filter-group">
                    <label>ละติจูด *</label>
                    <input type="number" name="lat" min="-90" max="90" step="any"
                           required class="form-control"
                           value="<?php echo ownerEsc($v['lat'] ?? ''); ?>">
                </div>

                <div class="filter-group">
                    <label>ลองจิจูด *</label>
                    <input type="number" name="lng" min="-180" max="180" step="any"
                           required class="form-control"
                           value="<?php echo ownerEsc($v['lng'] ?? ''); ?>">
                </div>

                <div style="grid-column:1/-1;">
            <div class="image-manager"
                 id="imgmgr-<?php echo $id; ?>"
                 style="margin-top:8px;border-top:1px solid var(--color-border);padding-top:20px;">

                <h4>จัดการรูปภาพ (<?php echo count($imgs); ?> รูป)</h4>

                <div class="image-grid" id="thumbs-<?php echo $id; ?>">
                    <?php foreach ($imgs as $img): ?>
                        <div class="image-thumb" id="thumb-<?php echo (int) $img['id']; ?>">
                            <img src="<?php echo ownerEsc(imageSrc($img['filename'])); ?>"
                                 alt="">
                            <button type="button"
                                    class="image-thumb-delete"
                                    onclick="deleteImage(<?php echo (int) $img['id']; ?>)"
                                    title="ลบรูปนี้">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <label class="upload-area" for="upload-<?php echo $id; ?>">
                    <input type="file"
                           id="upload-<?php echo $id; ?>"
                           multiple
                           accept="image/jpeg,image/png,image/webp"
                           onchange="uploadImages(this, <?php echo $id; ?>)">
                    📷 คลิกเพื่อเลือกรูปภาพ (JPG, PNG, WebP ไม่เกิน 5MB/รูป)
                </label>

                <div id="upload-status-<?php echo $id; ?>"
                     style="margin-top:8px;font-size:0.85rem;color:#64748B;"></div>

                <div class="url-add">
                    <textarea id="url-input-<?php echo $id; ?>"
                              rows="2"
                              class="form-control"
                              placeholder="วางลิงก์รูปภาพ โดยขึ้นบรรทัดใหม่เมื่อต้องการเพิ่มหลายรูป"></textarea>

                    <button type="button"
                            class="btn btn-secondary-outline"
                            onclick="addImageUrls(<?php echo $id; ?>)">
                        + เพิ่มรูปจากลิงก์
                    </button>
                </div>
            </div>
                </div>

                <div style="grid-column:1/-1;">
                    <?php if ($item['status'] === 'approved'): ?>
                        <p style="color:#64748B;font-size:0.85rem;">
                            ข้อมูลที่แก้ไขจะรอแอดมินอนุมัติก่อนแสดงบนเว็บไซต์
                        </p>
                        <button type="submit" class="btn btn-primary">
                            ส่งคำขอแก้ไขข้อมูล
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary">
                            บันทึกการแก้ไขข้อมูล
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </details>

    <?php
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ดเจ้าของที่พัก - BKK STAYPOINT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<nav class="navbar">
    <div class="nav-container">
        <a href="index.html" class="nav-brand">
            <svg class="icon-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            <span>BKK STAYPOINT</span>
        </a>
        <div style="display: flex; align-items: center; gap: 12px;">
            <span style="color: #64748B; font-size: 0.9rem;">เจ้าของที่พัก: <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
            <a href="index.html" class="btn btn-secondary-outline">กลับไปหน้าค้นหา</a>
            <a href="logout.php" class="btn btn-secondary-outline">ออกจากระบบ</a>
        </div>
    </div>
</nav>

<main class="container" style="margin-top: 40px; margin-bottom: 60px;">

    <div class="section-header">
        <h2>แดชบอร์ดเจ้าของที่พัก</h2>
        <p style="color: #64748B; margin-top: 4px;">เพิ่มที่พักของคุณเข้าระบบ — ที่พักที่เพิ่มใหม่ต้องรอแอดมินอนุมัติก่อนจึงจะแสดงบนหน้าเว็บ</p>
        <div class="divider"></div>
    </div>

    <div class="admin-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 32px;">

        <section class="card" style="padding: 30px; height: fit-content;">
            <h3 style="font-size: 1.3rem; font-weight: 700; margin-bottom: 20px;">เพิ่มที่พักของคุณ</h3>

            <?php echo $message; ?>

            <form method="POST" action="owner_dashboard.php" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 18px;">
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
                    <label style="margin-bottom: 6px;">ราคาค่าที่พักต่อคืน (บาท) *</label>
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

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px;">ส่งเพื่อรออนุมัติ</button>
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
                    status.textContent = this.files.length ? 'เลือกไว้ ' + this.files.length + ' รูป (จะอัปโหลดตอนกดส่ง)' : '';
                });
            </script>
        </section>

        <section class="card" style="padding: 30px;">
            <h3 style="font-size: 1.3rem; font-weight: 700; margin-bottom: 20px;">ที่พักของฉัน</h3>
            <?php echo $message_edit; ?>
            <?php if (empty($my_listings)): ?>
                <p style="color: #64748B;">ยังไม่มีที่พักในระบบ ลองเพิ่มรายการแรกของคุณดูสิ</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <?php foreach ($my_listings as $item): 
                        $imgs_stmt = $pdo->prepare("SELECT * FROM accommodation_images WHERE accommodation_id = :id ORDER BY sort_order ASC");
                        $imgs_stmt->execute(['id' => $item['id']]);
                        $imgs = $imgs_stmt->fetchAll();
                    ?>
                        <div style="border: 1px solid var(--color-border); border-radius: 10px; padding: 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: start; gap: 10px;">
                                <div>
                                    <h4 style="font-weight: 600; color: var(--color-neutral);"><?php echo htmlspecialchars($item['name']); ?></h4>
                                    <p style="color: #64748B; font-size: 0.9rem;">เขต<?php echo htmlspecialchars($item['district_name'] ?? 'ไม่ระบุ'); ?> · ฿<?php echo number_format($item['price']); ?>/คืน</p>
                                </div>
                                <?php if ($item['status'] === 'approved'): ?>
                                    <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-end;">
                                        <span class="badge" style="background-color: #DCFCE7; color: #15803D; white-space: nowrap;">อนุมัติแล้ว</span>
                                        <?php if (isset($myEditRequests[(int) $item['id']])): ?>
                                            <span class="badge" style="background-color: #FEF3C7; color: #B45309; white-space: nowrap;">คำขอแก้ไขรออนุมัติ</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge" style="background-color: #FEF3C7; color: #B45309; white-space: nowrap;">รออนุมัติ</span>
                                <?php endif; ?>
                            </div>

                            <?php
                            renderOwnerEditForm(
                                $item,
                                $myEditRequests[(int) $item['id']] ?? null,
                                $districts,
                                $districtCanon
                            );
                            ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<script src="admin-images.js"></script>

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