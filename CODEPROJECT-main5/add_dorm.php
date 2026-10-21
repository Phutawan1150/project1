<?php
require_once __DIR__ . '/config.php';
?>

<h3>สิ่งอำนวยความสะดวก</h3>

<div class="amenities-list">
    <?php foreach ($amenityOptions as $amenity): ?>
        <label>
            <input
                type="checkbox"
                name="amenities[]"
                value="<?= htmlspecialchars($amenity, ENT_QUOTES, 'UTF-8') ?>"
            >
            <?= htmlspecialchars($amenity, ENT_QUOTES, 'UTF-8') ?>
        </label>
    <?php endforeach; ?>
</div>
