-- Add every Bangkok district without removing existing data.
-- Safe to run more than once: each district is inserted only if missing.
USE bangkok_stay;

INSERT INTO districts (name_th)
SELECT source.name_th
FROM (
    SELECT 'คลองเตย' AS name_th UNION ALL SELECT 'คลองสาน' UNION ALL
    SELECT 'คลองสามวา' UNION ALL SELECT 'คันนายาว' UNION ALL
    SELECT 'จตุจักร' UNION ALL SELECT 'จอมทอง' UNION ALL
    SELECT 'ดอนเมือง' UNION ALL SELECT 'ดินแดง' UNION ALL
    SELECT 'ดุสิต' UNION ALL SELECT 'ตลิ่งชัน' UNION ALL
    SELECT 'ทวีวัฒนา' UNION ALL SELECT 'ทุ่งครุ' UNION ALL
    SELECT 'ธนบุรี' UNION ALL SELECT 'บางกอกน้อย' UNION ALL
    SELECT 'บางกอกใหญ่' UNION ALL SELECT 'บางกะปิ' UNION ALL
    SELECT 'บางขุนเทียน' UNION ALL SELECT 'บางเขน' UNION ALL
    SELECT 'บางคอแหลม' UNION ALL SELECT 'บางแค' UNION ALL
    SELECT 'บางซื่อ' UNION ALL SELECT 'บางนา' UNION ALL
    SELECT 'บางบอน' UNION ALL SELECT 'บางพลัด' UNION ALL
    SELECT 'บางรัก' UNION ALL SELECT 'บึงกุ่ม' UNION ALL
    SELECT 'ปทุมวัน' UNION ALL SELECT 'ประเวศ' UNION ALL
    SELECT 'ป้อมปราบศัตรูพ่าย' UNION ALL SELECT 'พญาไท' UNION ALL
    SELECT 'พระโขนง' UNION ALL SELECT 'พระนคร' UNION ALL
    SELECT 'ภาษีเจริญ' UNION ALL SELECT 'มีนบุรี' UNION ALL
    SELECT 'ยานนาวา' UNION ALL SELECT 'ราชเทวี' UNION ALL
    SELECT 'ราษฎร์บูรณะ' UNION ALL SELECT 'ลาดกระบัง' UNION ALL
    SELECT 'ลาดพร้าว' UNION ALL SELECT 'วังทองหลาง' UNION ALL
    SELECT 'วัฒนา' UNION ALL SELECT 'สวนหลวง' UNION ALL
    SELECT 'สะพานสูง' UNION ALL SELECT 'สัมพันธวงศ์' UNION ALL
    SELECT 'สาทร' UNION ALL SELECT 'สายไหม' UNION ALL
    SELECT 'หนองแขม' UNION ALL SELECT 'หนองจอก' UNION ALL
    SELECT 'หลักสี่' UNION ALL SELECT 'ห้วยขวาง'
) AS source
WHERE NOT EXISTS (
    SELECT 1 FROM districts existing WHERE existing.name_th = source.name_th
);
