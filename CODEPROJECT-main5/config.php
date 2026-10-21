
<?php
$isSessionApiRequest = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'api.php'
    && ($_GET['action'] ?? '') === 'get_session';

if (
    (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'api.php' || $isSessionApiRequest)
    && session_status() === PHP_SESSION_NONE
) {
    $sessionPath = __DIR__ . DIRECTORY_SEPARATOR . 'sessions';

    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0700, true);
    }

    session_save_path($sessionPath);
    session_start();
}

// ตรวจสอบว่ารันบน localhost หรือ server จริง
if (
    isset($_SERVER['HTTP_HOST'])
    && (
        $_SERVER['HTTP_HOST'] === 'localhost'
        || strpos($_SERVER['HTTP_HOST'], 'localhost') !== false
    )
) {
    $host = 'localhost';
    $db   = 'bangkok_stay';
    $user = 'root';
    $pass = '';
} else {
    $host = 'sql203.infinityfree.com';
    $db   = 'if0_43219978_bangkok_stay';
    $user = 'if0_43219978';
    $pass = 'oHK4E1vf5ck';
}

$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'Database connection failed'
    ]);
    exit;
}


// ---------- ตัวช่วยกลาง (ใช้ร่วมกันทุกไฟล์) ----------

// ดึงรายชื่อเขตแบบไม่ซ้ำ: ถ้าใน DB มีชื่อเขตซ้ำกัน เก็บแถวที่ id น้อยที่สุดไว้แถวเดียว
// แต่ละแถวมี 'ids' = id ทั้งหมดที่ชื่อเดียวกัน (ไว้ใช้กรองที่พักให้ครบ)
function getUniqueDistricts(PDO $pdo): array
{
    $unique = [];
    foreach ($pdo->query("SELECT * FROM districts ORDER BY name_th ASC, id ASC")->fetchAll() as $row) {
        $key = mb_strtolower(preg_replace('/\s+/u', '', (string) $row['name_th']), 'UTF-8');
        if ($key === '') {
            continue;
        }
        if (!isset($unique[$key])) {
            $row['ids'] = [(int) $row['id']];
            $unique[$key] = $row;
        } else {
            $unique[$key]['ids'][] = (int) $row['id'];
        }
    }
    return array_values($unique);
}

// แผนที่ id ของแถวซ้ำ => id ของแถวที่เก็บไว้ (ใช้เลือกค่าใน dropdown ตอนแก้ไข)
function getDistrictCanonMap(array $uniqueDistricts): array
{
    $map = [];
    foreach ($uniqueDistricts as $d) {
        foreach ($d['ids'] as $id) {
            $map[(int) $id] = (int) $d['id'];
        }
    }
    return $map;
}

// คีย์เทียบสิ่งอำนวยความสะดวก เพื่อรวมค่าที่ซ้ำกัน (ไม่สนตัวพิมพ์ ช่องว่าง ขีดแบบต่างๆ และคำนำหน้า "มี"/"มีบริการ")
function amenityKey($text): string
{
    $t = (string) $text;
    $t = str_replace(["\u{2011}", "\u{2010}", "\u{2013}", "\u{2014}"], '-', $t);
    $t = mb_strtolower(trim($t), 'UTF-8');
    $t = preg_replace('/^(มีบริการ|มี)/u', '', $t);
    return preg_replace('/\s+/u', '', $t);
}

// ตรวจราคา: รับเฉพาะตัวเลข 0-9 ล้วน (ไม่รับตัวอักษร เว้นวรรค จุด เครื่องหมาย หรือ e)
// คืนค่า int ถ้าถูกต้อง หรือ null ถ้าไม่ถูกต้อง
function parsePrice($value): ?int
{
    $value = trim((string) $value);
    $value = str_replace(',', '', $value);
    if ($value === '' || !preg_match('/^[0-9]{1,9}$/', $value)) {
        return null;
    }
    return (int) $value;
}

// รายการสิ่งอำนวยความสะดวกของหอพัก
$amenityOptions = [
    'Wi-Fi ฟรี',
    'เครื่องปรับอากาศ',
    'เครื่องทำน้ำอุ่น',
    'ที่จอดรถ',
    'ทีวี',
    'ตู้เย็น',
    'เตียงนอน',
    'โต๊ะและเก้าอี้',
    'เครื่องซักผ้า',
    'ฟิตเนส',
    'สระว่ายน้ำ',
    'ระบบรักษาความปลอดภัย',
    'กล้องวงจรปิด',
    'คีย์การ์ด',
    'ลิฟต์',
];
?>
