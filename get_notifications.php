<?php
error_reporting(0); // 🛡️ حماية الـ JSON
header('Content-Type: application/json; charset=utf-8');
include 'connect.php'; 

// 🛡️ التعديل الأول: التأكد إن الرقم مبعوت وأكبر من الصفر
if (isset($_GET['customer_id']) && intval($_GET['customer_id']) > 0) {
    $customer_id = intval($_GET['customer_id']);
    
    $stmt = $conn->prepare("SELECT id, complaint_id, message, is_read, created_at FROM notifications WHERE customer_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = intval($row['id']);
        $row['complaint_id'] = intval($row['complaint_id']); // تأكيد إضافي لفلاتر
        $row['is_read'] = intval($row['is_read']);
        $notifications[] = $row;
    }
    
    // 🚀 التعديل التاني: دمجنا الأرقام مع دعم الحروف العربية
    echo json_encode([
        "status" => "success", 
        "data" => $notifications
    ], JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    
    $stmt->close(); // 🧹 تنظيف الميموري
} else {
    // 🛡️ التعديل التالت: الرد الآمن لو الموبايل مبعتش رقم العميل
    echo json_encode([
        "status" => "error", 
        "message" => "رقم العميل غير صحيح أو مفقود"
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>