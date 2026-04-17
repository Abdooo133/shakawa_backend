<?php
// 1. نبدأ الجلسة عشان نقدر نتحكم فيها
session_start();

// 2. نفضي كل المتغيرات اللي متسجلة (زي اسم الأدمن وصلاحياته)
session_unset();

// 3. 🛡️ إضافة أمنية: تدمير ملف تعريف الارتباط (Cookie) الخاص بالجلسة من المتصفح
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. ندمر الجلسة تماماً من على السيرفر
session_destroy();

// 5. نحوله فوراً لصفحة تسجيل الدخول
header("Location: login.php");
exit();
?>