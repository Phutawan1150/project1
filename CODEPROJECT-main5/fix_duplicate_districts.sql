-- รวมเขตที่ชื่อซ้ำกันในตาราง districts ให้เหลือแถวเดียว (เก็บ id น้อยที่สุด)
-- แล้วย้ายที่พัก/คำขอแก้ไขที่ผูกกับ id ซ้ำ ไปชี้ที่ id ที่เก็บไว้
-- ปลอดภัยที่จะรันซ้ำ  (แนะนำให้สำรองฐานข้อมูลก่อนรัน)

UPDATE accommodations a
JOIN districts d ON a.district_id = d.id
JOIN (SELECT name_th, MIN(id) AS keep_id FROM districts GROUP BY name_th) k ON k.name_th = d.name_th
SET a.district_id = k.keep_id
WHERE a.district_id <> k.keep_id;

-- (ถ้าเคยสร้างตาราง accommodation_edit_requests แล้ว)
UPDATE accommodation_edit_requests r
JOIN districts d ON r.district_id = d.id
JOIN (SELECT name_th, MIN(id) AS keep_id FROM districts GROUP BY name_th) k ON k.name_th = d.name_th
SET r.district_id = k.keep_id
WHERE r.district_id <> k.keep_id;

DELETE d FROM districts d
JOIN (SELECT name_th, MIN(id) AS keep_id FROM districts GROUP BY name_th) k ON k.name_th = d.name_th
WHERE d.id <> k.keep_id;
