<?php
// คำขอแก้ไขข้อมูลที่พักจากเจ้าของ: เก็บแยกไว้ในตารางของมันเอง
// ที่พักที่อนุมัติแล้วจะยังแสดงข้อมูลเดิมบนเว็บ จนกว่าแอดมินจะกดอนุมัติคำขอ

const EDIT_REQUEST_FIELDS = [
    'name', 'district_id', 'address', 'price', 'phone',
    'description', 'amenities', 'map_url', 'lat', 'lng',
];

// สร้างตารางอัตโนมัติถ้ายังไม่มี (ไม่ต้องรัน SQL เองก็ใช้ได้)
function editRequestsEnsureTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS accommodation_edit_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        accommodation_id INT NOT NULL,
        owner_id INT DEFAULT NULL,
        name VARCHAR(255) NOT NULL,
        district_id INT DEFAULT NULL,
        address TEXT,
        price INT DEFAULT 0,
        phone VARCHAR(30) DEFAULT NULL,
        description TEXT,
        amenities TEXT,
        map_url VARCHAR(2048) DEFAULT NULL,
        lat DECIMAL(10, 8) NOT NULL,
        lng DECIMAL(11, 8) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_edit_request_accommodation (accommodation_id),
        FOREIGN KEY (accommodation_id) REFERENCES accommodations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// บันทึกคำขอแก้ไข (ที่พักหนึ่งแห่งมีคำขอค้างได้ 1 รายการ ถ้าแก้ซ้ำจะเขียนทับอันเดิม)
function editRequestSave(PDO $pdo, int $accommodationId, int $ownerId, array $data): void
{
    $stmt = $pdo->prepare("INSERT INTO accommodation_edit_requests
            (accommodation_id, owner_id, name, district_id, address, price, phone, description, amenities, map_url, lat, lng)
        VALUES
            (:accommodation_id, :owner_id, :name, :district_id, :address, :price, :phone, :description, :amenities, :map_url, :lat, :lng)
        ON DUPLICATE KEY UPDATE
            owner_id = VALUES(owner_id), name = VALUES(name), district_id = VALUES(district_id),
            address = VALUES(address), price = VALUES(price), phone = VALUES(phone),
            description = VALUES(description), amenities = VALUES(amenities), map_url = VALUES(map_url),
            lat = VALUES(lat), lng = VALUES(lng), created_at = CURRENT_TIMESTAMP");
    $stmt->execute($data + ['accommodation_id' => $accommodationId, 'owner_id' => $ownerId]);
}

// แอดมินอนุมัติ: เอาข้อมูลในคำขอไปทับที่พักจริง แล้วลบคำขอทิ้ง
function editRequestApply(PDO $pdo, int $requestId): bool
{
    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare("SELECT * FROM accommodation_edit_requests WHERE id = :id FOR UPDATE");
        $sel->execute(['id' => $requestId]);
        $req = $sel->fetch();
        if (!$req) {
            $pdo->rollBack();
            return false;
        }
        $upd = $pdo->prepare("UPDATE accommodations
            SET name = :name, district_id = :district_id, address = :address, price = :price,
                phone = :phone, description = :description, amenities = :amenities,
                map_url = :map_url, lat = :lat, lng = :lng
            WHERE id = :id");
        $params = ['id' => $req['accommodation_id']];
        foreach (EDIT_REQUEST_FIELDS as $f) {
            $params[$f] = $req[$f];
        }
        $upd->execute($params);
        $pdo->prepare("DELETE FROM accommodation_edit_requests WHERE id = :id")->execute(['id' => $requestId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}