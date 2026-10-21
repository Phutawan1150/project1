<?php
require_once 'config.php';

$was_admin = !empty($_SESSION['admin_logged_in']);

$_SESSION = [];
session_destroy();

header('Location: ' . ($was_admin ? 'login.php' : 'index.html'));
exit;
