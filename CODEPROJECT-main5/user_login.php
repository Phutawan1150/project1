<?php
require_once 'config.php';

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($_SESSION['user_role'] === 'owner' ? 'owner_dashboard.php' : 'index.html'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_id = trim($_POST['login_id'] ?? '');
    $password = $_POST['password'] ?? '';

    $adminStmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = :login_id");
    $adminStmt->execute(['login_id' => $login_id]);
    $admin = $adminStmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $admin['username'];

        header('Location: admin.php');
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :login_id1 OR email = :login_id2");
    $stmt->execute(['login_id1' => $login_id, 'login_id2' => $login_id]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_role'] = $user['role'];

        header('Location: ' . ($user['role'] === 'owner' ? 'owner_dashboard.php' : 'index.html'));
        exit;
    } else {
        $error = "<div class='alert alert-error'>username/อีเมล หรือรหัสผ่านไม่ถูกต้อง</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - BKK STAYPOINT</title>
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
        <a href="register.php" class="btn btn-secondary-outline">สมัครสมาชิก</a>
    </div>
</nav>

<main class="container" style="max-width: 420px; margin-top: 80px; margin-bottom: 60px;">
    <section class="card" style="padding: 30px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 20px; color: var(--color-neutral);">
            <svg style="width: 24px; height: 24px; color: var(--color-primary);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            <h3 style="font-size: 1.3rem; font-weight: 700;">เข้าสู่ระบบ</h3>
        </div>

        <?php echo $error; ?>

        <form method="POST" action="user_login.php" style="display: flex; flex-direction: column; gap: 18px;">
            <div class="filter-group" style="width: 100%;">
                <label style="margin-bottom: 6px;">Username หรืออีเมล</label>
                <input type="text" name="login_id" required autofocus class="form-control">
            </div>
            <div class="filter-group" style="width: 100%;">
                <label style="margin-bottom: 6px;">รหัสผ่าน</label>
                <input type="password" name="password" required class="form-control">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px;">เข้าสู่ระบบ</button>
        </form>
        <p style="text-align: center; margin-top: 18px; color: #64748B;">ยังไม่มีบัญชี? <a href="register.php" style="color: var(--color-primary); font-weight: 600;">สมัครสมาชิก</a></p>
    </section>
</main>

</body>
</html>
