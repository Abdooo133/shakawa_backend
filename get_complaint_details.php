<?php
error_reporting(0); // 🛡️ حماية الـ JSON
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

// --- زتونة نظام الساعة (تحديث تلقائي للشكاوى المتأخرة) ---
// 🚀 التعديل: استخدمنا CONCAT عشان نحافظ على رد الأدمن القديم ونضيف عليه رسالة النظام
$sql_auto_solve = "UPDATE complaints 
                   SET status = 'تم الحل', 
                       admin_reply = CONCAT(IFNULL(admin_reply, ''), '\n\n⏳ تحديث آلي من النظام: تم إغلاق الشكوى تلقائياً لعدم رد العميل خلال المهلة المحددة (ساعة).') 
                   WHERE status = 'بانتظار تأكيد العميل' 
                   AND approval_requested_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)";

$conn->query($sql_auto_solve);
// -------------------------------------------------------

// 🛠️ التعديل: طلعنا الـ if بره الكومنت عشان الكود يشتغل
if (isset($_GET['id'])) {
    $id = intval($_GET['id']); // 🛡️ حماية ضد الـ SQL Injection
    
    // 🚀 استخدام LEFT JOIN لجلب اسم الموظف مع تفاصيل الشكوى
    $query = "SELECT c.*, st.name AS support_name 
              FROM complaints c 
              LEFT JOIN support_team st ON c.support_id = st.id 
              WHERE c.id = ?";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $image_path = trim($row['image_path']);
        $row['image_base64'] = ""; // افتراضي فاضي

        // التأكد إن الملف موجود فعلاً على السيرفر
        if (!empty($image_path) && file_exists($image_path)) {
            // تحويل الصورة لـ Base64
            $imageData = file_get_contents($image_path);
            $row['image_base64'] = base64_encode($imageData);
        }

        // 🛡️ التعديل: دعم العربي وتأكيد الأرقام لفلاتر
        echo json_encode(["status" => "success", "data" => $row], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    } else {
        echo json_encode(["status" => "error", "message" => "الشكوى غير موجودة"], JSON_UNESCAPED_UNICODE);
    }
    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "برجاء إرسال رقم الشكوى"], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>