<?php
error_reporting(0); // 🛡️ حماية الـ JSON
header('Content-Type: application/json; charset=utf-8');
include 'connect.php'; 

// 🚀 التعديل الجوهري: استخدام المطابقة الدقيقة بدل LIKE لتجنب الأخطاء المنطقية
$res = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status IN ('تم الحل', 'مغلق') THEN 1 ELSE 0 END) as solved,
    SUM(CASE WHEN status IN ('جاري المعالجة', 'بانتظار تأكيد العميل','معلقة') THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'جديدة' THEN 1 ELSE 0 END) as new_count
    FROM complaints");

if ($res && $row = $res->fetch_assoc()) {
    echo json_encode([
        "status" => "success", // 👈 الكلمة السحرية للفرونت إند
        "total"     => intval($row['total'] ?? 0),
        "solved"    => intval($row['solved'] ?? 0),
        "pending"   => intval($row['pending'] ?? 0),
        "new_count" => intval($row['new_count'] ?? 0)
    ], JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE); 
} else {
    // 🛡️ لو حصل خطأ في الداتابيز، نبعت أصفار بدل ما التطبيق يضرب Crash
    echo json_encode([
        "status" => "success",
        "total" => 0, "solved" => 0, "pending" => 0, "new_count" => 0
    ], JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE); 
}

$conn->close(); // 🧹 تنظيف الميموري
?>