<?php
error_reporting(0); // 🛡️ حماية الـ JSON من أي تداخل
header('Content-Type: application/json; charset=utf-8');
include 'connect.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 🛡️ استلام الرقم وتأكيده كـ Integer لمنع الـ SQL Injection
    $notification_id = isset($_POST['notification_id']) ? intval($_POST['notification_id']) : 0;

    if ($notification_id > 0) {
        // تحديث حالة الإشعار (1 = مقروء)
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $stmt->bind_param("i", $notification_id);

        if ($stmt->execute()) {
            echo json_encode([
                "status" => "success", 
                "message" => "تم تحديث حالة الإشعار بنجاح"
            ], JSON_UNESCAPED_UNICODE); // دعم العربي في الرسالة
        } else {
            echo json_encode([
                "status" => "error", 
                "message" => "فشل التحديث، جرب مرة أخرى لاحقاً"
            ], JSON_UNESCAPED_UNICODE);
        }
        $stmt->close();
    } else {
        echo json_encode([
            "status" => "error", 
            "message" => "رقم الإشعار غير صحيح أو مفقود"
        ], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode([
        "status" => "error", 
        "message" => "طريقة الطلب غير مسموح بها (يجب استخدام POST)"
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close(); // 🧹 تنظيف الاتصال
?>