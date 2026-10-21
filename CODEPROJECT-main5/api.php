<?php
header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'http://localhost:5500',
    'http://127.0.0.1:5500',
];
$originHost = parse_url($origin, PHP_URL_HOST);
$originPort = parse_url($origin, PHP_URL_PORT);
$requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
$isSameHostFiveServer = $originPort === 5500 && $originHost !== false
    && $originHost !== null && $originHost === $requestHost;
if (in_array($origin, $allowedOrigins, true) || $isSameHostFiveServer) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
require_once __DIR__ . '/config.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ส่งสถานะบัญชีให้หน้าแรกที่เป็น HTML ล้วนใช้แสดงเมนูให้ถูกต้อง
if ($action === 'get_session') {
    echo json_encode([
        'logged_in' => !empty($_SESSION['user_id']),
        'user_name' => $_SESSION['user_name'] ?? '',
        'user_role' => $_SESSION['user_role'] ?? '',
    ]);
    exit;
}

// เอาไว้ยิงมาทำ dropdown ฝั่งหน้าบ้าน
if ($action == 'get_districts') {
    $out = [];
    foreach (getUniqueDistricts($pdo) as $d) {
        unset($d['ids']);
        $out[] = $d;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// แยกข้อความสิ่งอำนวยความสะดวก (พิมพ์อิสระ คั่นด้วย , ; หรือขึ้นบรรทัดใหม่) ออกเป็นรายการ
function splitAmenities($text) {
    $text = str_replace("\u{2011}", '-', (string)$text); // Wi‑Fi (ขีดไม่ตัดบรรทัด) -> Wi-Fi
    $parts = preg_split('/[,;\r\n、，]+/u', $text);
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $k = amenityKey($part);
            if ($k !== '' && !isset($out[$k])) {
                $out[$k] = $part; // key ผ่าน amenityKey กันซ้ำ (รวมตัวพิมพ์/ช่องว่าง/คำนำหน้า "มี")
            }
        }
    }
    return $out;
}

// รายการสิ่งอำนวยความสะดวกทั้งหมดที่มีจริงในระบบ ไว้ทำ dropdown
if ($action == 'get_amenities') {
    $stmt = $pdo->query("SELECT amenities FROM accommodations WHERE status = 'approved' AND amenities IS NOT NULL AND amenities <> ''");
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        foreach (splitAmenities($row['amenities']) as $key => $label) {
            if (!isset($counts[$key])) $counts[$key] = ['label' => $label, 'count' => 0];
            $counts[$key]['count']++;
        }
    }
    uasort($counts, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
    echo json_encode(array_values($counts), JSON_UNESCAPED_UNICODE);
    exit;
}

// เอาไว้ตั้งขอบเขตต่ำสุด-สูงสุดของสไลเดอร์ราคา
if ($action == 'get_price_range') {
    $stmt = $pdo->query("SELECT MIN(price) as min_price, MAX(price) as max_price FROM accommodations WHERE status = 'approved'");
    $range = $stmt->fetch();
    echo json_encode([
        'min' => $range['min_price'] !== null ? intval($range['min_price']) : 0,
        'max' => $range['max_price'] !== null ? intval($range['max_price']) : 10000,
    ]);
    exit;
}

// filter ที่พักตามที่ user เลือก (เขต, ช่วงราคา, หรือตำแหน่งปัจจุบัน — ใช้ร่วมกันได้)
$district_id = isset($_GET['district_id']) ? $_GET['district_id'] : '';
$user_lat = isset($_GET['lat']) ? $_GET['lat'] : '';
$user_lng = isset($_GET['lng']) ? $_GET['lng'] : '';
$keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
$wanted_amenities = isset($_GET['amenities']) ? (array)$_GET['amenities'] : [];
$min_price = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? intval($_GET['min_price']) : null;
$max_price = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? intval($_GET['max_price']) : null;

try {
    $where = ["a.status = 'approved'"];
    $params = [];

    if ($district_id != '') {
        // รวมทุก id ที่เป็นเขตชื่อเดียวกัน (กันกรณีใน DB มีเขตซ้ำ แล้วที่พักผูกกับ id ซ้ำ)
        $where[] = "a.district_id IN (SELECT d2.id FROM districts d2 WHERE d2.name_th = (SELECT d1.name_th FROM districts d1 WHERE d1.id = :district_id))";
        $params['district_id'] = $district_id;
    }
    if ($min_price !== null) {
        $where[] = "a.price >= :min_price";
        $params['min_price'] = $min_price;
    }
    if ($max_price !== null) {
        $where[] = "a.price <= :max_price";
        $params['max_price'] = $max_price;
    }

    if ($keyword !== '') {
        // ค้นจากชื่อที่พัก ที่อยู่ หรือชื่อเขต
        $where[] = "(a.name LIKE :kw_name OR a.address LIKE :kw_address OR d.name_th LIKE :kw_district)";
        $like = '%' . addcslashes($keyword, '%_\\') . '%';
        $params['kw_name'] = $like;
        $params['kw_address'] = $like;
        $params['kw_district'] = $like;
    }

    $where_sql = implode(' AND ', $where);

    if ($user_lat != '' && $user_lng != '') {
        // haversine formula หาระยะทางจากพิกัด user, กรองเอาแค่ในรัศมี 10 กม.
        $params['lat1'] = $user_lat;
        $params['lng1'] = $user_lng;
        $params['lat2'] = $user_lat;

        $sql = "SELECT a.*, d.name_th as district_name,
                (SELECT filename FROM accommodation_images WHERE accommodation_id = a.id ORDER BY sort_order ASC LIMIT 1) AS cover_image,
                (6371 * acos(cos(radians(:lat1)) * cos(radians(a.lat)) * cos(radians(a.lng) - radians(:lng1)) + sin(radians(:lat2)) * sin(radians(a.lat)))) AS distance 
                FROM accommodations a
                LEFT JOIN districts d ON a.district_id = d.id
                WHERE $where_sql
                HAVING distance <= 10
                ORDER BY distance ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

    } else {
        $sql = "SELECT a.*, d.name_th as district_name,
                (SELECT filename FROM accommodation_images WHERE accommodation_id = a.id ORDER BY sort_order ASC LIMIT 1) AS cover_image
                FROM accommodations a 
                LEFT JOIN districts d ON a.district_id = d.id 
                WHERE $where_sql ORDER BY a.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    $rows = $stmt->fetchAll();

    // กรองสิ่งอำนวยความสะดวก: ที่พักต้องมีครบทุกอย่างที่เลือก
    $wanted = [];
    foreach ($wanted_amenities as $w) {
        $w = trim((string)$w);
        if ($w !== '') $wanted[] = amenityKey($w);
    }
    if ($wanted) {
        $rows = array_values(array_filter($rows, function ($row) use ($wanted) {
            $have = array_keys(splitAmenities($row['amenities']));
            return count(array_diff($wanted, $have)) === 0;
        }));
    }

    echo json_encode($rows);
} catch (PDOException $e) {
    // ส่ง error กลับเป็น JSON แทนที่จะพังเงียบๆ จนหน้าเว็บค้างที่ loading
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>