<?php
require_once 'config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = ($_POST['role'] ?? '') === 'owner' ? 'owner' : 'user';

    if ($full_name === '' || $username === '' || $email === '' || strlen($password) < 8) {
        $error = "<div class='alert alert-error'>กรอกข้อมูลให้ครบ และรหัสผ่านต้องอย่างน้อย 8 ตัวอักษร</div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "<div class='alert alert-error'>รูปแบบอีเมลไม่ถูกต้อง</div>";
    } else {
        // เช็คซ้ำ username / email ก่อน
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e");
        $stmt->execute(['u' => $username, 'e' => $email]);

        if ($stmt->fetch()) {
            $error = "<div class='alert alert-error'>username หรืออีเมลนี้มีคนใช้แล้ว</div>";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email, password_hash, role) 
                                    VALUES (:full_name, :username, :email, :password_hash, :role)");
            $stmt->execute([
                'full_name' => $full_name, 'username' => $username,
                'email' => $email, 'password_hash' => $hash, 'role' => $role
            ]);

            session_regenerate_id(true);
            $_SESSION['user_id']   = $pdo->lastInsertId();
            $_SESSION['user_name'] = $full_name;
            $_SESSION['user_role'] = $role;

            header('Location: ' . ($role === 'owner' ? 'owner_dashboard.php' : 'index.html'));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - BKK STAYPOINT</title>
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
        <a href="user_login.php" class="btn btn-secondary-outline">เข้าสู่ระบบ</a>
    </div>
</nav>

<main class="container" style="max-width: 480px; margin-top: 60px; margin-bottom: 60px;">
    <section class="card" style="padding: 30px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px; color: var(--color-neutral);">
            <svg style="width: 24px; height: 24px; color: var(--color-primary);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            <h3 style="font-size: 1.3rem; font-weight: 700;">สมัครสมาชิก</h3>
        </div>

        <?php echo $error; ?>

        <form method="POST" action="register.php" style="display: flex; flex-direction: column; gap: 18px;">
            <div class="filter-group" style="width: 100%;">
                <label style="margin-bottom: 6px;">ชื่อ-นามสกุล</label>
                <input type="text" name="full_name" required class="form-control" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
            </div>
            <div class="filter-group" style="width: 100%;">
                <label style="margin-bottom: 6px;">Username</label>
                <input type="text" name="username" required class="form-control" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="filter-group" style="width: 100%;">
                <label style="margin-bottom: 6px;">อีเมล</label>
                <input type="email" name="email" required class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="filter-group" style="width: 100%;">
                <label style="margin-bottom: 6px;">รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label>
                <input type="password" name="password" required minlength="8" class="form-control">
            </div>

            <div class="filter-group" style="width: 100%;">
                <label style="margin-bottom: 10px;">สมัครเป็น</label>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <label style="display: flex; align-items: center; gap: 10px; padding: 14px 16px; border: 1px solid #CBD5E1; border-radius: 8px; cursor: pointer; font-weight: 500;">
                        <input type="radio" name="role" value="user" checked style="width: 18px; height: 18px;">
                        ผู้ใช้ทั่วไป — เข้ามาค้นหาที่พัก
                    </label>
                    <label style="display: flex; align-items: center; gap: 10px; padding: 14px 16px; border: 1px solid #CBD5E1; border-radius: 8px; cursor: pointer; font-weight: 500;">
                        <input type="radio" name="role" value="owner" style="width: 18px; height: 18px;">
                        เจ้าของที่พัก — ต้องการเพิ่มที่พักของตัวเองลงในเว็บ
                    </label>
                </div>
                <p style="font-size: 0.85rem; color: #64748B; margin-top: 8px;">* ที่พักที่เจ้าของเพิ่มเข้ามาจะต้องรอแอดมินตรวจสอบและอนุมัติก่อน ถึงจะแสดงบนหน้าเว็บ</p>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px;">สมัครสมาชิก</button>
        </form>
    </section>
</main>

</body>
</html>
