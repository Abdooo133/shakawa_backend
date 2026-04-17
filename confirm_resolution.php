<?php
error_reporting(0); // 🛡️ كالعادة: قفل التحذيرات لحماية الـ JSON
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 🛡️ التأكد من المتغيرات قبل استخدامها
    $id = isset($_POST['complaint_id']) ? intval($_POST['complaint_id']) : 0;
    $decision = $_POST['decision'] ?? '';

    if ($id > 0 && !empty($decision)) {
        if ($decision == 'solved') {
            // العميل أكد الحل
            $new_status = 'تم الحل';
            $msg = "✅ تحديث آلي: قام العميل بتأكيد حل الشكوى بنجاح.";
        } else {
            // العميل رفض الحل
            $new_status = 'جاري المعالجة'; 
            $msg = "⚠️ تنبيه آلي: العميل أفاد بأن المشكلة لم تحل بعد، يرجى إعادة الفحص.";
        }

        // 🚀 التعديل الجوهري: استخدام CONCAT عشان نحتفظ بالرد القديم ونضيف عليه الجديد
        $stmt = $conn->prepare("UPDATE complaints SET status = ?, admin_reply = CONCAT(IFNULL(admin_reply, ''), '\n\n', ?) WHERE id = ?");
        $stmt->bind_param("ssi", $new_status, $msg, $id);

        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "تم التحديث بنجاح"], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["status" => "error", "message" => "فشل التحديث في السيرفر"], JSON_UNESCAPED_UNICODE);
        }
        $stmt->close();
    } else {
        echo json_encode(["status" => "error", "message" => "بيانات الشكوى غير مكتملة"], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode(["status" => "error", "message" => "يجب استخدام طريقة POST"], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>