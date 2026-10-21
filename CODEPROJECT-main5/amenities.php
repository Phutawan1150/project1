<?php
// รายการสิ่งอำนวยความสะดวกที่ให้ติ๊กเลือก (แก้ไข/เพิ่มได้ที่นี่ที่เดียว)
const AMENITY_PRESETS = [
    'Wi-Fi ฟรี', 'เครื่องปรับอากาศ','พัดลม', 'ที่จอดรถ', 'เครื่องทำน้ำอุ่น',
    'ทีวี', 'ตู้เย็น', 'ไมโครเวฟ', 'เครื่องซักผ้า',
    'สระว่ายน้ำ', 'ฟิตเนส', 'ลิฟต์', 'รักษาความปลอดภัย 24 ชม.',
    'กล้องวงจรปิด', 'อนุญาตให้เลี้ยงสัตว์', 'ห้องครัว', 'ระเบียง', 'ระบบรักษาความปลอดภัย', 'มีบริการเครื่องซักผ้า',
];

// แยกข้อความที่เก็บใน DB (คั่นด้วย , ; หรือขึ้นบรรทัดใหม่) ออกเป็นรายการ
function amenityParse($text): array
{
    $text = str_replace("\u{2011}", '-', (string) $text);
    $out = [];
    foreach (preg_split('/[,;\r\n、，]+/u', $text) as $part) {
        $part = trim($part);
        if ($part !== '' && !isset($out[amenityKey($part)])) {
            $out[amenityKey($part)] = $part;
        }
    }
    return array_values($out);
}

// รวมค่าจากฟอร์ม (checkbox + ช่องอื่นๆ) เป็นข้อความเดียวสำหรับเก็บลง DB
function amenityCollect(array $post): string
{
    $picked = [];
    foreach ((array) ($post['amenities'] ?? []) as $a) {
        if (is_string($a) && in_array($a, AMENITY_PRESETS, true)) {
            $picked[] = $a;
        }
    }
    $other = amenityParse($post['amenities_other'] ?? '');
    $all = [];
    foreach (array_merge($picked, $other) as $a) {
        if (!isset($all[amenityKey($a)])) {
            $all[amenityKey($a)] = $a;
        }
    }
    return implode(', ', $all);
}

// วาด checkbox ทั้งหมด พร้อมติ๊กค่าที่มีอยู่แล้ว และช่อง "อื่นๆ" สำหรับค่าที่ไม่อยู่ในรายการ
function amenityRenderCheckboxes(?string $current = ''): void
{
    $existing = amenityParse($current);
    $presetKeys = array_map('amenityKey', AMENITY_PRESETS);
    $checked = [];
    $others = [];
    foreach ($existing as $a) {
        $k = amenityKey($a);
        if (in_array($k, $presetKeys, true)) {
            $checked[$k] = true;
        } else {
            $others[] = $a;
        }
    }
    ?>
    <div class="amenity-grid">
        <?php foreach (AMENITY_PRESETS as $preset): ?>
            <label class="amenity-check">
                <input type="checkbox" name="amenities[]" value="<?php echo htmlspecialchars($preset, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo isset($checked[amenityKey($preset)]) ? 'checked' : ''; ?>>
                <span><?php echo htmlspecialchars($preset, ENT_QUOTES, 'UTF-8'); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <input type="text" name="amenities_other" class="form-control" style="margin-top:10px;"
           placeholder="อื่นๆ (พิมพ์เพิ่มได้ คั่นด้วยเครื่องหมายจุลภาค)"
           value="<?php echo htmlspecialchars(implode(', ', $others), ENT_QUOTES, 'UTF-8'); ?>">
    <?php
}

// ที่อยู่รูป: ถ้าเป็นลิงก์ http(s) ใช้ตรงๆ ถ้าเป็นชื่อไฟล์ให้ชี้ไปโฟลเดอร์ uploads
function imageSrc(string $filename): string
{
    return preg_match('#^https?://#i', $filename) ? $filename : 'uploads/' . $filename;
}
