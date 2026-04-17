<?php
error_reporting(0); // 🛡️ التعديل الأول: منع ظهور أي تحذيرات تبوظ الـ JSON
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

// 🛡️ التعديل التاني: حماية المتغير action لو مش مبعوت
$action = $_GET['action'] ?? '';

if ($action == 'save') {
    // 🛡️ التعديل التالت: تنظيف وحماية المدخلات بالكامل
    $cust_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $cat = mysqli_real_escape_string($conn, $_POST['category'] ?? 'غير مصنف');
    $desc = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    
    if ($cust_id > 0) {
        $sql = "INSERT INTO complaints (customer_id, ai_category, description, status) 
                VALUES ('$cust_id', '$cat', '$desc', 'جديدة')";
        
        if ($conn->query($sql)) {
            $new_id = $conn->insert_id; 
            
            echo json_encode([
                "status" => "success", 
                "msg" => "تم تسجيل شكواك بنجاح يا بطل! رقم شكوتك هو: #$new_id"
            ], JSON_UNESCAPED_UNICODE); // دعم الحروف العربية
        } else {
            // شيلنا طباعة إيرور الداتابيز المباشر عشان محدش يعرف هيكل السيرفر
            echo json_encode(["status" => "error", "msg" => "حدث خطأ في قاعدة البيانات، جرب تاني."], JSON_UNESCAPED_UNICODE);
        }
    } else {
        echo json_encode(["status" => "error", "msg" => "رقم العميل غير صحيح."], JSON_UNESCAPED_UNICODE);
    }
} 

elseif ($action == 'track') {
    // 🛡️ التعديل الرابع: حماية رقم الشكوى
    $id = isset($_POST['complaint_id']) ? intval($_POST['complaint_id']) : 0;
    
    if ($id > 0) {
        // 🚀 التعديل الخامس: بدل SELECT * حددنا العواميد المطلوبة بس عشان السرعة
        $res = $conn->query("SELECT status, ai_category, description FROM complaints WHERE id = $id");
        
        if ($row = $res->fetch_assoc()) {
            echo json_encode([
                "status" => "success", 
                "status_val" => $row['status'],
                "service_type" => $row['ai_category'], 
                "description" => $row['description']
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["status" => "error", "msg" => "الرقم ده مش موجود عندي يا هندسة."], JSON_UNESCAPED_UNICODE);
        }
    } else {
        echo json_encode(["status" => "error", "msg" => "رقم الشكوى غير صحيح."], JSON_UNESCAPED_UNICODE);
    }
} 

else {
    // لو حد فتح اللينك من المتصفح بالغلط
    echo json_encode(["status" => "error", "msg" => "إجراء غير معروف."], JSON_UNESCAPED_UNICODE);
}
?>