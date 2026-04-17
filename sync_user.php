<?php
error_reporting(0); // 🛡️ حماية الـ JSON
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $firebase_uid = mysqli_real_escape_string($conn, $_POST['firebase_uid'] ?? '');
    $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name'] ?? 'مستخدم جديد');
    
    // 🛠️ معالجة رقم التليفون (NULL Logic)
    if (empty($_POST['phone']) || $_POST['phone'] == 'null') {
        $phone_sql = "NULL";
    } else {
        $phone_clean = mysqli_real_escape_string($conn, $_POST['phone']);
        $phone_sql = "'$phone_clean'";
    }

    if (!empty($firebase_uid) || !empty($email)) {
        // 🔍 فحص هل المستخدم موجود (بالـ UID أو الإيميل)
        $checkUser = $conn->query("SELECT id FROM customers WHERE firebase_uid = '$firebase_uid' OR email = '$email'");

        if ($checkUser && $checkUser->num_rows > 0) {
            // 🔄 تحديث بيانات المستخدم الحالي
            $update_sql = "UPDATE customers SET 
                            firebase_uid = '$firebase_uid', 
                            full_name = '$full_name', 
                            phone = $phone_sql 
                           WHERE email = '$email' OR firebase_uid = '$firebase_uid'";
            
            if ($conn->query($update_sql)) {
                echo json_encode(["status" => "success", "message" => "updated"], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(["status" => "error", "message" => "فشل التحديث"], JSON_UNESCAPED_UNICODE);
            }
        } else {
            // ✨ إضافة مستخدم جديد (تم تنظيف الـ Query المكرر والغلط)
            $insert_sql = "INSERT INTO customers (full_name, email, phone, firebase_uid) 
                           VALUES ('$full_name', '$email', $phone_sql, '$firebase_uid')";
            
            if ($conn->query($insert_sql)) {
                echo json_encode(["status" => "success", "message" => "inserted"], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(["status" => "error", "message" => "فشل الإضافة"], JSON_UNESCAPED_UNICODE);
            }
        }
    } else {
        echo json_encode(["status" => "error", "message" => "بيانات ناقصة"], JSON_UNESCAPED_UNICODE);
    }
}
$conn->close();
?>