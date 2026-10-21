<?php
// ฟังก์ชันจัดการรูปที่พักที่ใช้ร่วมกัน: อัปโหลดไฟล์ และเพิ่มจากลิงก์

function imgNextOrder(PDO $pdo, int $accommodationId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM accommodation_images WHERE accommodation_id = :id");
    $stmt->execute(['id' => $accommodationId]);
    return (int) $stmt->fetchColumn();
}

// resize รูปให้กว้างไม่เกิน 1200px แล้วบันทึกเป็น JPEG quality 82
function imgResizeAndSave(string $src, string $mime, string $dest): bool
{
    $orig = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($src),
        'image/png'  => imagecreatefrompng($src),
        'image/webp' => imagecreatefromwebp($src),
        default      => false,
    };
    if (!$orig) return false;

    $ow = imagesx($orig);
    $oh = imagesy($orig);
    $max = 1200;

    if ($ow <= $max && $oh <= $max) {
        $nw = $ow;
        $nh = $oh;
    } elseif ($ow >= $oh) {
        $nw = $max;
        $nh = intval($oh * $max / $ow);
    } else {
        $nh = $max;
        $nw = intval($ow * $max / $oh);
    }

    $canvas = imagecreatetruecolor($nw, $nh);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopyresampled($canvas, $orig, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
    $ok = imagejpeg($canvas, $dest, 82);

    imagedestroy($orig);
    imagedestroy($canvas);
    return $ok;
}

// บันทึกไฟล์ที่อัปโหลด ($_FILES['images']) คืนค่า [saved, errors]
function imgSaveUploadedFiles(PDO $pdo, int $accommodationId, array $files): array
{
    $saved = [];
    $errors = [];
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $maxSize = 5 * 1024 * 1024;
    $nextOrder = imgNextOrder($pdo, $accommodationId);
    $ins = $pdo->prepare("INSERT INTO accommodation_images (accommodation_id, filename, sort_order) VALUES (:aid, :fn, :so)");

    $count = is_array($files['name']) ? count($files['name']) : 1;
    for ($i = 0; $i < $count; $i++) {
        $name = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
        $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $size = is_array($files['size'])     ? $files['size'][$i]     : $files['size'];
        $err  = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];

        if ($err === UPLOAD_ERR_NO_FILE) continue; // ไม่ได้เลือกไฟล์ ข้ามไปเงียบๆ
        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = "$name: upload error ($err)";
            continue;
        }
        if ($size > $maxSize) {
            $errors[] = "$name: ไฟล์ใหญ่เกิน 5MB";
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realType = finfo_file($finfo, $tmp);
        finfo_close($finfo);
        if (!in_array($realType, $allowed, true)) {
            $errors[] = "$name: รองรับแค่ JPG, PNG, WebP";
            continue;
        }

        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$realType];
        $canResize = function_exists('imagecreatetruecolor') && (
            ($realType === 'image/jpeg' && function_exists('imagecreatefromjpeg')) ||
            ($realType === 'image/png' && function_exists('imagecreatefrompng')) ||
            ($realType === 'image/webp' && function_exists('imagecreatefromwebp'))
        );

        $filename = uniqid('img_', true) . ($canResize ? '.jpg' : '.' . $ext);
        $dest = $uploadDir . $filename;
        $ok = $canResize ? imgResizeAndSave($tmp, $realType, $dest) : move_uploaded_file($tmp, $dest);
        if (!$ok) {
            $errors[] = "$name: บันทึกไฟล์ไม่สำเร็จ";
            continue;
        }

        $ins->execute(['aid' => $accommodationId, 'fn' => $filename, 'so' => $nextOrder]);
        $nextOrder++;
        $saved[] = ['id' => $pdo->lastInsertId(), 'filename' => $filename];
    }
    return [$saved, $errors];
}

// บันทึกรูปจากลิงก์ (รับข้อความหลายลิงก์คั่นด้วยบรรทัดใหม่/ช่องว่าง) คืนค่า [saved, errors]
function imgSaveUrls(PDO $pdo, int $accommodationId, string $text): array
{
    $saved = [];
    $errors = [];
    $urls = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if (!$urls) return [$saved, $errors];

    $nextOrder = imgNextOrder($pdo, $accommodationId);
    $ins = $pdo->prepare("INSERT INTO accommodation_images (accommodation_id, filename, sort_order) VALUES (:aid, :fn, :so)");

    foreach (array_slice($urls, 0, 20) as $url) {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = "$url: ลิงก์ไม่ถูกต้อง (ต้องขึ้นต้นด้วย http:// หรือ https://)";
            continue;
        }
        if (strlen($url) > 2048) {
            $errors[] = 'ลิงก์ยาวเกินไป';
            continue;
        }
        $ins->execute(['aid' => $accommodationId, 'fn' => $url, 'so' => $nextOrder]);
        $nextOrder++;
        $saved[] = ['id' => $pdo->lastInsertId(), 'filename' => $url];
    }
    return [$saved, $errors];
}
