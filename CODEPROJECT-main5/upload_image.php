<?php
require_once 'config.php';
require_once 'image_helpers.php';
header('Content-Type: application/json');

// เช็คสิทธิ์ก่อน — ต้องเป็น admin หรือ owner ที่ล็อกอินอยู่
$is_admin = !empty($_SESSION['admin_logged_in']);
$is_owner = !empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'owner';

if (!$is_admin && !$is_owner) {
    http_response_code(403);
    echo json_encode(['error' => 'ไม่มีสิทธิ์อัปโหลดรูป']);
    exit;
}

$accommodation_id = intval($_POST['accommodation_id'] ?? 0);
if (!$accommodation_id) {
    echo json_encode(['error' => 'ไม่ได้ระบุ accommodation_id']);
    exit;
}

// ถ้าเป็น owner ต้องเช็คว่าที่พักนี้เป็นของตัวเองจริงๆ
if ($is_owner && !$is_admin) {
    $chk = $pdo->prepare("SELECT id FROM accommodations WHERE id = :id AND owner_id = :owner_id");
    $chk->execute(['id' => $accommodation_id, 'owner_id' => $_SESSION['user_id']]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'ที่พักนี้ไม่ใช่ของคุณ']);
        exit;
    }
}

if (empty($_FILES['images'])) {
    echo json_encode(['error' => 'ไม่พบไฟล์ที่อัปโหลด']);
    exit;
}

[$saved, $errors] = imgSaveUploadedFiles($pdo, $accommodation_id, $_FILES['images']);

echo json_encode(['saved' => $saved, 'errors' => $errors]);
