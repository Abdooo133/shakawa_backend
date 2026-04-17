<?php
session_start();

// 1. التأكد من صلاحية الأدمن (الأمان)
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => "error", "message" => "عفواً، غير مسموح بالدخول بدون تسجيل"]);
    exit();
}

error_reporting(0); 
header('Content-Type: application/json; charset=utf-8');
include '../connect.php'; // 👈 التعديل: رجعنا خطوة لورا عشان الملف جوه فولدر admin

// --- ٢. دوال FCM V1 (النسخة المطورة للظهور الخارجي) ---
function sendFCMV1($token, $title, $body, $complaint_id) {
    $jsonKeyFile = '../firebase_key.json'; // 👈 التعديل: المسار الصحيح للكي
    if (!file_exists($jsonKeyFile)) return false;
    $data = json_decode(file_get_contents($jsonKeyFile), true);
    
    $jwt = generateJWT($data);
    $url = 'https://fcm.googleapis.com/v1/projects/' . $data['project_id'] . '/messages:send';
    
    $message = [
        'message' => [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => ['complaint_id' => (string)$complaint_id], 
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'high_importance_channel', 
                    'sound' => 'default',
                    'notification_priority' => 'PRIORITY_MAX'
                ]
            ]
        ]
    ];
    
    $headers = ['Authorization: Bearer ' . $jwt, 'Content-Type: application/json'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url); 
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    curl_exec($ch); 
    curl_close($ch);
    return true;
}

function generateJWT($data) {
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $payload = json_encode(['iss' => $data['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'exp' => $now + 3600, 'iat' => $now]);
    $b64H = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $b64P = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    openssl_sign($b64H . "." . $b64P, $signature, $data['private_key'], 'SHA256');
    $b64S = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $b64H . "." . $b64P . "." . $b64S]));
    $res = json_decode(curl_exec($ch), true);
    return $res['access_token'] ?? '';
}

// --- ٣. معالجة البيانات ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    $new_status   = mysqli_real_escape_string($conn, $_POST['status'] ?? ''); 
    $admin_reply  = mysqli_real_escape_string($conn, $_POST['admin_reply'] ?? ''); 
    $support_id   = isset($_POST['support_id']) ? intval($_POST['support_id']) : NULL;
    $rejection_reason = $_POST['rejection_reason'] ?? NULL;

    if ($complaint_id == 0 || empty($new_status)) {
        echo json_encode(["status" => "error", "message" => "بيانات الشكوى غير مكتملة"]);
        exit;
    }

    // جلب بيانات العميل للرسالة
    $sql = "SELECT c.customer_id, cu.device_id FROM complaints c JOIN customers cu ON c.customer_id = cu.id WHERE c.id = ?";
    $get_data = $conn->prepare($sql);
    $get_data->bind_param("i", $complaint_id);
    $get_data->execute();
    $row = $get_data->get_result()->fetch_assoc();
    
    if ($row) {
        $customer_id = $row['customer_id'];
        $device_token = $row['device_id'];
        $actual_status = $new_status;
        $escalation = NULL;

        // منطق الحالات (الدمج بين الملفين)
        if ($new_status == 'مرفوضة') {
            $escalation = 'management';
            $admin_reply = "سبب الرفض: " . ($rejection_reason ?? $admin_reply);
        } elseif ($new_status == 'تم الحل') {
            $actual_status = 'بانتظار تأكيد العميل';
            // تحديث وقت طلب التأكيد عشان نظام "الزتونة" (الساعة) يشتغل
            $conn->query("UPDATE complaints SET approval_requested_at = NOW() WHERE id = $complaint_id");
        }

        // التحديث النهائي
        $update_stmt = $conn->prepare("UPDATE complaints SET status = ?, admin_reply = ?, support_id = ?, escalation_level = ? WHERE id = ?");
        $update_stmt->bind_param("ssisi", $actual_status, $admin_reply, $support_id, $escalation, $complaint_id);
        
        if ($update_stmt->execute()) {
            $message = "تحديث لشكواك رقم #$complaint_id: $actual_status. الرد: $admin_reply";
            
            // تسجيل الإشعار في الداتابيز
            $notify_stmt = $conn->prepare("INSERT INTO notifications (customer_id, complaint_id, message) VALUES (?, ?, ?)");
            $notify_stmt->bind_param("iis", $customer_id, $complaint_id, $message);
            $notify_stmt->execute();

            // إرسال الإشعار الخارجي
            if (!empty($device_token) && $device_token !== 'NULL') {
                sendFCMV1($device_token, "تحديث الشكوى 🔔", $message, $complaint_id);
            }
            echo json_encode(["status" => "success", "message" => "تم تحديث الحالة وإشعار المواطن"]);
        } else {
            echo json_encode(["status" => "error", "message" => "فشل تحديث قاعدة البيانات"]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "الشكوى غير موجودة"]);
    }
}
$conn->close();
?>