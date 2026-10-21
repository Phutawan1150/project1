-- migration.sql
-- รันถ้ามีฐานข้อมูลอยู่แล้วและยังไม่ได้ run database.sql ใหม่ทั้งหมด
-- CREATE TABLE IF NOT EXISTS จะไม่แก้ตารางเก่าให้ ต้อง ALTER เพิ่มเอง
USE bangkok_stay;

-- สร้างตาราง users (เผื่อยังไม่มี)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user','owner') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- สร้างตาราง admin_users (เผื่อยังไม่มี)
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- เพิ่มคอลัมน์ใน accommodations ถ้ายังไม่มี
-- (ถ้ามีอยู่แล้ว MySQL จะ error บรรทัดนั้น ข้ามไปรันอันถัดไปได้เลย)
ALTER TABLE accommodations ADD COLUMN owner_id INT DEFAULT NULL AFTER lng;
ALTER TABLE accommodations ADD COLUMN status ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER owner_id;
ALTER TABLE accommodations ADD CONSTRAINT fk_accommodations_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;

-- คอลัมน์สำหรับหน้ารายละเอียดที่พัก
ALTER TABLE accommodations ADD COLUMN phone VARCHAR(30) DEFAULT NULL AFTER price;
ALTER TABLE accommodations ADD COLUMN description TEXT AFTER phone;
ALTER TABLE accommodations ADD COLUMN amenities TEXT AFTER description;
ALTER TABLE accommodations ADD COLUMN map_url VARCHAR(2048) DEFAULT NULL AFTER amenities;

-- ตารางรูปภาพของที่พัก (หลายรูปต่อที่พักได้)
CREATE TABLE IF NOT EXISTS accommodation_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accommodation_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (accommodation_id) REFERENCES accommodations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- รองรับรูปภาพจากลิงก์ (URL) ซึ่งยาวกว่าชื่อไฟล์ปกติ
ALTER TABLE accommodation_images MODIFY filename VARCHAR(2048) NOT NULL;

-- คำขอแก้ไขข้อมูลที่พักจากเจ้าของ (รอแอดมินอนุมัติ) — โค้ดจะสร้างตารางนี้ให้อัตโนมัติอยู่แล้ว ไฟล์นี้ไว้เผื่อรันเอง
CREATE TABLE IF NOT EXISTS accommodation_edit_requests (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;