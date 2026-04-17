<?php
error_reporting(0); // 🛡️ حماية الـ JSON من أي تداخل
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. استلام وتأمين البيانات
    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    $description  = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $complaint_phone = mysqli_real_escape_string($conn, trim($_POST['complaint_phone'] ?? ''));
    $landline = mysqli_real_escape_string($conn, trim($_POST['landline'] ?? ''));
    $location     = mysqli_real_escape_string($conn, trim($_POST['location'] ?? ''));
    $governorate  = mysqli_real_escape_string($conn, trim($_POST['governorate'] ?? ''));
    $service_type = mysqli_real_escape_string($conn, trim($_POST['service_type'] ?? ''));
    $new_image_path = ""; 

    if ($complaint_id <= 0) {
        echo json_encode(["status" => "error", "message" => "رقم الشكوى غير صحيح"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. فحص حالة الشكوى (Security Check)
    $check = $conn->prepare("SELECT status, image_path FROM complaints WHERE id = ?");
    $check->bind_param("i", $complaint_id);
    $check->execute();
    $res = $check->get_result()->fetch_assoc();

    // لا يسمح بالتعديل إلا لو كانت "جديدة" أو "جاري المعالجة" أو "معلقة"
    if ($res && (preg_match('/(جديدة|جاري|معلقة|انتظار)/u', $res['status']))) {
        
        // 3. معالجة الصورة الجديدة (🛡️ تأمين شامل للرفع)
        if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
            $file_type = $_FILES['image']['type'];

            if (in_array($file_type, $allowed_types)) {
                $target_dir = "uploads/";
                if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

                $file_name = "upd_" . time() . "_" . basename($_FILES['image']['name']);
                $target_file = $target_dir . $file_name;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                    $new_image_path = $target_file; 
                    
                    // 🔥 مسح الصورة القديمة من السيرفر لتوفير المساحة
                    if (!empty($res['image_path']) && file_exists($res['image_path'])) {
                        unlink($res['image_path']);
                    }
                }
            }
        }

        // 4. بناء الـ Query ديناميكياً حسب وجود صورة جديدة
        if (!empty($new_image_path)) {
            $stmt = $conn->prepare("UPDATE complaints SET description = ?, location = ?, governorate = ?, complaint_phone = ?, landline = ?, service_type = ?, image_path = ? WHERE id = ?");
            $stmt->bind_param("sssssssi", $description, $location, $governorate, $complaint_phone, $landline, $service_type, $new_image_path, $complaint_id);
        } else {
            $stmt = $conn->prepare("UPDATE complaints SET description = ?, location = ?, governorate = ?, complaint_phone = ?, landline = ?, service_type = ? WHERE id = ?");
            $stmt->bind_param("ssssssi", $description, $location, $governorate, $complaint_phone, $landline, $service_type, $complaint_id);
        }

        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "تم تحديث بيانات الشكوى بنجاح"], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["status" => "error", "message" => "فشل التحديث في السيرفر"], JSON_UNESCAPED_UNICODE);
        }
        $stmt->close();

    } else {
        echo json_encode(["status" => "error", "message" => "عفواً، لا يمكن تعديل الشكوى لأنها في حالة (حل) أو (مرفوضة)"], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode(["status" => "error", "message" => "طلب غير مسموح به"], JSON_UNESCAPED_UNICODE);
} 
$conn->close();
?>