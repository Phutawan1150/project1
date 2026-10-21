<?php
require_once 'config.php';
header('Content-Type: application/json');

$is_admin = !empty($_SESSION['admin_logged_in']);
$is_owner = !empty($_SESSION['user_id']) && $_SESSION['user_role'] === 'owner';

if (!$is_admin && !$is_owner) {
    http_response_code(403);
    echo json_encode(['error' => 'ไม่มีสิทธิ์ลบรูป']);
    exit;
}

$image_id = intval($_POST['image_id'] ?? 0);
if (!$image_id) {
    echo json_encode(['error' => 'ไม่ได้ระบุ image_id']);
    exit;
}

// ดึงข้อมูลรูปพร้อม accommodation เพื่อเช็คสิทธิ์
$stmt = $pdo->prepare("SELECT i.*, a.owner_id FROM accommodation_images i 
                        JOIN accommodations a ON i.accommodation_id = a.id 
                        WHERE i.id = :id");
$stmt->execute(['id' => $image_id]);
$img = $stmt->fetch();

if (!$img) {
    echo json_encode(['error' => 'ไม่พบรูปนี้ในระบบ']);
    exit;
}

// owner ลบได้เฉพาะรูปของที่พักตัวเอง
if ($is_owner && !$is_admin && $img['owner_id'] != $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'รูปนี้ไม่ใช่ของคุณ']);
    exit;
}

// ลบไฟล์จาก disk ก่อน
// ถ้าเป็นรูปจากลิงก์ภายนอก ไม่มีไฟล์ในเครื่องให้ลบ
if (!preg_match('#^https?://#i', $img['filename'])) {
    $filepath = __DIR__ . '/uploads/' . basename($img['filename']);
    if (file_exists($filepath)) {
        unlink($filepath);
    }
}

// แล้วค่อยลบออกจาก DB
$pdo->prepare("DELETE FROM accommodation_images WHERE id = :id")->execute(['id' => $image_id]);

echo json_encode(['success' => true]);