<?php
error_reporting(0); // 🛡️ حماية الـ JSON من أي تداخل
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' || $_SERVER['REQUEST_METHOD'] == 'GET') {
    
    // 1. استلام القيمة وتنظيفها (دعم POST و GET معاً لمرونة التطبيق)
    $search_value = isset($_POST['search_value']) ? trim($_POST['search_value']) : (isset($_GET['query']) ? trim($_GET['query']) : '');
    
    if (empty($search_value)) {
        echo json_encode(["status" => "error", "message" => "برجاء إدخال رقم الشكوى أو رقم الهاتف"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. الاستعلام الاحترافي (LEFT JOIN) لجلب بيانات الشكوى والعميل في خطوة واحدة
    $sql = "SELECT c.id, c.company_name, c.service_type, c.governorate, 
                   c.location, c.description, c.status, c.created_at, c.image_path,
                   cu.full_name, cu.phone 
            FROM complaints c
            LEFT JOIN customers cu ON c.customer_id = cu.id
            WHERE c.id = ? OR cu.phone = ?
            ORDER BY c.id DESC";

    $stmt = $conn->prepare($sql);
    
    // 🛡️ ملاحظة: بنبعت القيمة مرتين (مرة للـ ID ومرة للـ Phone)
    $stmt->bind_param("ss", $search_value, $search_value);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $complaints = [];
        while($row = $result->fetch_assoc()) {
            $complaints[] = $row;
        }
        
        // 🚀 التعديل الذهبي لفلاتر: نبعت الأرقام كأرقام والعربي كعربي
        echo json_encode([
            "status" => "success", 
            "data" => $complaints
        ], JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);

    } else {
        echo json_encode([
            "status" => "error", 
            "message" => "عفواً، لا توجد نتائج للبحث عن: " . $search_value
        ], JSON_UNESCAPED_UNICODE);
    }
    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "طريقة الطلب غير مدعومة"], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>