<?php
require_once 'config.php';
require_once 'image_helpers.php';
header('Content-Type: application/json');

$is_admin = !empty($_SESSION['admin_logged_in']);
$is_owner = !empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'owner';
if (!$is_admin && !$is_owner) {
    http_response_code(403);
    echo json_encode(['error' => 'ไม่มีสิทธิ์เพิ่มรูป']);
    exit;
}

$accommodation_id = intval($_POST['accommodation_id'] ?? 0);
if (!$accommodation_id) {
    echo json_encode(['error' => 'ไม่ได้ระบุ accommodation_id']);
    exit;
}

if ($is_owner && !$is_admin) {
    $chk = $pdo->prepare("SELECT id FROM accommodations WHERE id = :id AND owner_id = :oid");
    $chk->execute(['id' => $accommodation_id, 'oid' => $_SESSION['user_id']]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'ที่พักนี้ไม่ใช่ของคุณ']);
        exit;
    }
}

$text = trim((string) ($_POST['urls'] ?? ''));
if ($text === '') {
    echo json_encode(['error' => 'กรุณาวางลิงก์รูปภาพ']);
    exit;
}

[$saved, $errors] = imgSaveUrls($pdo, $accommodation_id, $text);
echo json_encode(['saved' => $saved, 'errors' => $errors]);
