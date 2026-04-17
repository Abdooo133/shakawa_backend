<?php
error_reporting(0); 
// ini_set('display_errors', 1); 
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

// --- ١. دوال إرسال الإشعار للموبايل (FCM V1) ---
function sendFCMV1($token, $title, $body, $complaint_id) {
    $jsonKeyFile = 'firebase_key.json'; 
    if (!file_exists($jsonKeyFile)) return false;
    $data = json_decode(file_get_contents($jsonKeyFile), true);
    if (!$data) return false;

    $jwt = generateJWT($data);
    $url = 'https://fcm.googleapis.com/v1/projects/' . $data['project_id'] . '/messages:send';
    
    $message = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body
            ],
            'data' => [
                'complaint_id' => (string)$complaint_id 
            ],
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
    $response = curl_exec($ch); 
    curl_close($ch);
    return true;
}

function generateJWT($data) {
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $payload = json_encode([
        'iss' => $data['client_email'], 
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 
        'aud' => 'https://oauth2.googleapis.com/token', 
        'exp' => $now + 3600, 
        'iat' => $now
    ]);
    $b64H = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $b64P = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    openssl_sign($b64H . "." . $b64P, $signature, $data['private_key'], 'SHA256');
    $b64S = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 
        'assertion' => $b64H . "." . $b64P . "." . $b64S
    ]));
    $res = json_decode(curl_exec($ch), true);
    return $res['access_token'] ?? '';
}

// --- ٢. معالجة إضافة الشكوى ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    
    if (empty($_POST['firebase_uid'])) {
        $firebase_uid_sql = "NULL";
    } else {
        $uid_clean = mysqli_real_escape_string($conn, $_POST['firebase_uid']);
        $firebase_uid_sql = "'$uid_clean'";
    }

    $support_id = isset($_POST['support_id']) && !empty($_POST['support_id']) ? intval($_POST['support_id']) : 'NULL';
    
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name'] ?? 'مواطن');
    
    if (empty($_POST['phone'])) {
        $phone_sql = "NULL";
    } else {
        $phone_clean = mysqli_real_escape_string($conn, $_POST['phone']);
        $phone_sql = "'$phone_clean'";
    }

    $complaint_phone = mysqli_real_escape_string($conn, $_POST['complaint_phone'] ?? '');
    $landline = mysqli_real_escape_string($conn, $_POST['landline'] ?? '');
    $email = mysqli_real_escape_string($conn, $_POST['email'] ?? ''); 
    $gender = mysqli_real_escape_string($conn, $_POST['gender'] ?? 'ذكر'); 
    $location = mysqli_real_escape_string($conn, $_POST['location'] ?? 'غير محدد');
    $device_token = mysqli_real_escape_string($conn, $_POST['device_id'] ?? '');
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $company_name = mysqli_real_escape_string($conn, $_POST['company_name'] ?? 'عامة');
    $service_type = mysqli_real_escape_string($conn, $_POST['service_type'] ?? 'خدمة');
    $governorate = mysqli_real_escape_string($conn, $_POST['governorate'] ?? 'القاهرة');

    // --- الربط مع الذكاء الاصطناعي ---
    $ai_category = "غير مصنف"; 
    if (!empty($description)) {
        $data_to_python = json_encode(array("text" => $description,"customer_name"=>$full_name), JSON_UNESCAPED_UNICODE);
        $ch = curl_init("http://127.0.0.1:8000/chatbot");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_to_python);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $python_result = json_decode($response, true);
            if (isset($python_result['category'])) {
                $ai_category = mysqli_real_escape_string($conn, $python_result['category']);
            }
        }
    }

    // --- حماية رفع الصور وتوجيهها لمجلد uploads ---
    $image_path_db = 'NULL'; 
    // 👇 تم إضافة application/octet-stream لدعم فلاتر بشكل كامل 👇
    $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/octet-stream']; 
    
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $file_type = $_FILES['image']['type'];
        
        if (in_array($file_type, $allowed_types)) {
            $target_dir = "uploads/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            $file_name = time() . '_' . basename($_FILES['image']['name']);
            $target_file = $target_dir . $file_name;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_path_db = "'" . mysqli_real_escape_string($conn, $target_file) . "'";
            }
        }
    }

    if ($customer_id > 0) {
        $check_exists = $conn->query("SELECT id FROM customers WHERE id = $customer_id");
        if ($check_exists && $check_exists->num_rows == 0) {
            $customer_id = 0; 
        }
    }

    // 👇 اللوجيك الجديد لمنع تكرار العملاء (دمج الحسابات) 👇
    if ($customer_id > 0) {
        $conn->query("UPDATE customers SET full_name = '$full_name', phone = $phone_sql, email = '$email', gender = '$gender', device_id = '$device_token', firebase_uid = $firebase_uid_sql WHERE id = $customer_id");
    } else {
        // البحث بأي وسيلة (إيميل أو تليفون أو UID)
        $check_query = "SELECT id FROM customers WHERE 
                        (email = '$email' AND '$email' != '') OR 
                        (phone = $phone_sql AND $phone_sql IS NOT NULL) OR 
                        (firebase_uid = $firebase_uid_sql AND $firebase_uid_sql IS NOT NULL) 
                        LIMIT 1";
        $check = $conn->query($check_query);
        
        if ($check && $check->num_rows > 0) {
            // تحديث العميل الموجود
            $row = $check->fetch_assoc();
            $customer_id = $row['id'];
            $conn->query("UPDATE customers SET 
                full_name = '$full_name', 
                email = '$email', 
                gender = '$gender', 
                device_id = '$device_token', 
                firebase_uid = IFNULL(firebase_uid, $firebase_uid_sql),
                phone = IFNULL(phone, $phone_sql)
                WHERE id = $customer_id");
        } else {
            // إنشاء عميل جديد
            $conn->query("INSERT INTO customers (full_name, phone, email, gender, device_id, firebase_uid) VALUES ('$full_name', $phone_sql, '$email', '$gender', '$device_token', $firebase_uid_sql)");
            $customer_id = $conn->insert_id;
        }
    }

    if ($customer_id > 0) {
        // إضافة الشكوى
        $sql = "INSERT INTO complaints (location, customer_id, company_name, service_type, governorate, description, complaint_phone, landline, image_path, status, ai_category, support_id) 
                VALUES ('$location', '$customer_id', '$company_name', '$service_type', '$governorate', '$description', '$complaint_phone', '$landline', $image_path_db, 'جديدة', '$ai_category', $support_id)";

        if ($conn->query($sql)) {
            $complaint_id = $conn->insert_id;
            $msg = "تم استلام شكواك لشركة ($company_name) بنجاح برقم #$complaint_id";
            $conn->query("INSERT INTO notifications (customer_id, complaint_id, message) VALUES ($customer_id, $complaint_id, '$msg')");
            
            if (!empty($device_token) && $device_token !== 'NULL') {
                sendFCMV1($device_token, "تم استلام الشكوى ✅", $msg, $complaint_id);
            }
            echo json_encode(["status" => "success", "customer_id" => (int)$customer_id, "complaint_id" => $complaint_id, "ai_category" => $ai_category]);
        } else {
            echo json_encode(["status" => "error", "message" => "فشل إضافة الشكوى", "sql_error" => $conn->error]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "فشل تحديد العميل"]);
    }
}
?>