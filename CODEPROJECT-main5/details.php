<?php
require_once 'config.php';
require_once 'amenities.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.html');
    exit;
}

$stmt = $pdo->prepare("SELECT a.*, d.name_th AS district_name
                       FROM accommodations a
                       LEFT JOIN districts d ON a.district_id = d.id
                       WHERE a.id = :id AND a.status = 'approved'");
$stmt->execute(['id' => $id]);
$accommodation = $stmt->fetch();

if (!$accommodation) {
    http_response_code(404);
}

// ดึงรูปทั้งหมดของที่พักนี้เรียงตาม sort_order
$img_stmt = $pdo->prepare("SELECT * FROM accommodation_images WHERE accommodation_id = :id ORDER BY sort_order ASC");
$img_stmt->execute(['id' => $id]);
$images = $img_stmt->fetchAll();

$mapUrl = $accommodation && !empty($accommodation['map_url']) && filter_var($accommodation['map_url'], FILTER_VALIDATE_URL)
    ? $accommodation['map_url']
    : null;

// แผนที่ฝังจากพิกัด (ไม่ต้องใช้ API key)
$hasCoords = $accommodation
    && is_numeric($accommodation['lat'] ?? null) && is_numeric($accommodation['lng'] ?? null)
    && !((float) $accommodation['lat'] == 0 && (float) $accommodation['lng'] == 0);
$mapEmbedUrl = $hasCoords
    ? 'https://www.google.com/maps?q=' . rawurlencode($accommodation['lat'] . ',' . $accommodation['lng']) . '&z=16&hl=th&output=embed'
    : null;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $accommodation ? htmlspecialchars($accommodation['name']) : 'ไม่พบที่พัก'; ?> - BKK STAYPOINT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <div class="nav-container">
        <a href="index.html" class="nav-brand">
            <svg class="icon-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            <span>BKK STAYPOINT</span>
        </a>
        <a href="index.html" class="btn btn-secondary-outline">กลับไปหน้าค้นหา</a>
    </div>
</nav>

<main class="container details-page">
    <?php if (!$accommodation): ?>
        <section class="card details-not-found">
            <h1>ไม่พบที่พักนี้</h1>
            <p>รายการอาจถูกลบหรือยังไม่ได้รับการอนุมัติ</p>
            <a href="index.html" class="btn btn-primary">กลับไปหน้าค้นหา</a>
        </section>
    <?php else: ?>
        <?php if (!empty($images)): ?>
        <div class="gallery" id="gallery">
            <?php foreach ($images as $i => $img): ?>
                <div class="gallery-slide <?php echo $i === 0 ? 'active' : ''; ?>">
                    <img src="<?php echo htmlspecialchars(imageSrc($img['filename'])); ?>" alt="รูปที่ <?php echo $i + 1; ?>">
                </div>
            <?php endforeach; ?>
            <?php if (count($images) > 1): ?>
                <button class="gallery-btn gallery-btn--prev" onclick="galleryPrev()">&#8592;</button>
                <button class="gallery-btn gallery-btn--next" onclick="galleryNext()">&#8594;</button>
                <div class="gallery-dots">
                    <?php foreach ($images as $i => $img): ?>
                        <button class="gallery-dot <?php echo $i === 0 ? 'active' : ''; ?>" onclick="galleryGo(<?php echo $i; ?>)"></button>
                    <?php endforeach; ?>
                </div>
                <div class="gallery-counter"><span id="gallery-cur">1</span> / <?php echo count($images); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <section class="details-hero">
            <div class="details-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            </div>
            <div>
                <span class="badge">เขต<?php echo htmlspecialchars($accommodation['district_name'] ?? 'ไม่ระบุ'); ?></span>
                <h1><?php echo htmlspecialchars($accommodation['name']); ?></h1>
                <p class="details-address"><?php echo htmlspecialchars($accommodation['address'] ?: 'ยังไม่ได้ระบุที่อยู่'); ?></p>
            </div>
            <div class="details-price">฿<?php echo number_format($accommodation['price']); ?><span> / คืน</span></div>
        </section>

        <section class="details-grid">
            <article class="details-card details-card--wide">
                <h2>เกี่ยวกับที่พัก</h2>
                <p><?php echo nl2br(htmlspecialchars($accommodation['description'] ?: 'ที่พักนี้ยังไม่ได้เพิ่มรายละเอียดเพิ่มเติม')); ?></p>
            </article>

            <article class="details-card">
                <h2>ติดต่อที่พัก</h2>
                <?php if (!empty($accommodation['phone'])): ?>
                    <a class="contact-phone" href="tel:<?php echo htmlspecialchars($accommodation['phone']); ?>"><?php echo htmlspecialchars($accommodation['phone']); ?></a>
                <?php else: ?>
                    <p>ยังไม่ได้ระบุเบอร์โทร</p>
                <?php endif; ?>
            </article>

            <article class="details-card">
                <h2>สิ่งอำนวยความสะดวก</h2>
                <p><?php echo nl2br(htmlspecialchars($accommodation['amenities'] ?: 'ยังไม่ได้ระบุสิ่งอำนวยความสะดวก')); ?></p>
            </article>

            <article class="details-card details-card--wide">
                <h2>ตำแหน่งที่พัก</h2>
                <?php if ($mapEmbedUrl): ?>
                    <div class="map-embed">
                        <iframe src="<?php echo htmlspecialchars($mapEmbedUrl); ?>" title="แผนที่ <?php echo htmlspecialchars($accommodation['name']); ?>"
                                loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                <?php endif; ?>
                <?php if ($mapUrl): ?>
                    <a class="map-link" href="<?php echo htmlspecialchars($mapUrl); ?>" target="_blank" rel="noopener noreferrer">เปิดใน Google Maps</a>
                <?php elseif (!$mapEmbedUrl): ?>
                    <p>ยังไม่ได้ระบุตำแหน่งที่พัก</p>
                <?php endif; ?>
            </article>

           
        </section>
    <?php endif; ?>
</main>
<?php if (!empty($images) && count($images) > 1): ?>
<script>
    let galIdx = 0;
    const slides = document.querySelectorAll('.gallery-slide');
    const dots   = document.querySelectorAll('.gallery-dot');
    const counter = document.getElementById('gallery-cur');

    function galleryGo(n) {
        slides[galIdx].classList.remove('active');
        dots[galIdx].classList.remove('active');
        galIdx = (n + slides.length) % slides.length;
        slides[galIdx].classList.add('active');
        dots[galIdx].classList.add('active');
        if (counter) counter.textContent = galIdx + 1;
    }
    function galleryNext() { galleryGo(galIdx + 1); }
    function galleryPrev() { galleryGo(galIdx - 1); }

    // swipe รูปด้วยนิ้วบนมือถือได้ด้วย
    const g = document.getElementById('gallery');
    let touchX = 0;
    g.addEventListener('touchstart', e => touchX = e.touches[0].clientX);
    g.addEventListener('touchend', e => {
        const diff = touchX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) diff > 0 ? galleryNext() : galleryPrev();
    });
</script>
<?php endif; ?>
</body>
</html>
