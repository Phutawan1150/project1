<?php
// สคริปต์นี้ใช้สร้างแอดมินคนแรกเท่านั้น รันครั้งเดียวแล้วลบไฟล์นี้ทิ้งเลย
// (เก็บไว้จะเป็นช่องโหว่ ใครก็เข้ามาสร้างแอดมินเพิ่มได้)
require_once 'config.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username !== '' && strlen($password) >= 8) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (:u, :p)");
            $stmt->execute(['u' => $username, 'p' => $hash]);
            $message = "<div class='alert alert-success'>สร้างแอดมิน '{$username}' เรียบร้อยแล้ว ไปล็อกอินได้เลย แล้วอย่าลืมลบไฟล์ create_admin.php ทิ้ง</div>";
        } catch (PDOException $e) {
            $message = "<div class='alert alert-error'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='alert alert-error'>กรอกชื่อผู้ใช้ และรหัสผ่านอย่างน้อย 8 ตัวอักษร</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>สร้างแอดมินคนแรก</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container" style="max-width: 420px; margin-top: 80px;">
    <section class="card" style="padding: 30px;">
        <h3 style="font-size: 1.3rem; font-weight: 700; margin-bottom: 16px;">สร้างแอดมินคนแรก</h3>
        <p style="color:#DC2626; font-weight:600; margin-bottom:16px;">⚠️ ลบไฟล์นี้ทิ้งทันทีหลังใช้งานเสร็จ</p>
        <?php echo $message; ?>
        <form method="POST" style="display: flex; flex-direction: column; gap: 18px;">
            <div class="filter-group" style="width: 100%;">
                <label style="margin-bottom: 6px;">ชื่อผู้ใช้</label>
                <input type="text" name="username" required class="form-control">
            </div>
            <div class="filter-group" style="width: 100%;">
                <label style="margin-bottom: 6px;">รหัสผ่าน (อย่างน้อย 8 ตัว)</label>
                <input type="password" name="password" required minlength="8" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px;">สร้างแอดมิน</button>
        </form>
    </section>
</main>
</body>
</html>