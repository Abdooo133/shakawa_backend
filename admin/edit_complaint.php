<?php
session_start();

// ١. حماية الصفحة
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

include '../connect.php'; // 👈 التعديل: مسار الاتصال

// --- دوال الإشعارات (FCM V1) ---
function sendFCMV1($token, $title, $body, $complaint_id) {
    $jsonKeyFile = '../firebase_key.json'; // 👈 التعديل: مسار المفتاح
    if (!file_exists($jsonKeyFile)) return false;
    $data = json_decode(file_get_contents($jsonKeyFile), true);
    if (!$data) return false;

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

$message_html = "";

// ٢. معالجة تحديث البيانات (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id']) && isset($_POST['status'])) {
    $id = intval($_POST['id']);
    $new_status = $_POST['status'];
    $admin_reply = $_POST['admin_reply'] ?? '';
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    
    // جلب بيانات العميل
    $stmt_data = $conn->prepare("SELECT cu.device_id, cu.id as cust_id, c.company_name FROM complaints c JOIN customers cu ON c.customer_id = cu.id WHERE c.id = ?");
    $stmt_data->bind_param("i", $id);
    $stmt_data->execute();
    $res = $stmt_data->get_result();

    if ($res && $res->num_rows > 0) {
        $data = $res->fetch_assoc();
        $msg = "";
        
        // 🛡️ التعديل: استخدام Prepared Statements للحماية
        if ($new_status == 'مرفوضة') {
            $stmt = $conn->prepare("UPDATE complaints SET status = 'مرفوضة', admin_reply = ?, rejection_reason = ?, escalation_level = 'management' WHERE id = ?");
            $stmt->bind_param("ssi", $admin_reply, $rejection_reason, $id);
            $msg = "تم رفض شكواك وتصعيدها للإدارة. السبب: $rejection_reason";
        } elseif ($new_status == 'تم الحل') {
            $stmt = $conn->prepare("UPDATE complaints SET status = 'بانتظار تأكيد العميل', admin_reply = ?, approval_requested_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $admin_reply, $id);
            $msg = "قام الدعم الفني بحل شكواك رقم #$id يرجى الدخول للتطبيق لتأكيد الحل أو رفضه.";
        } else {
            $stmt = $conn->prepare("UPDATE complaints SET status = ?, admin_reply = ? WHERE id = ?");
            $stmt->bind_param("ssi", $new_status, $admin_reply, $id);
            $msg = "تم تحديث شكواك لشركة ({$data['company_name']}) رقم #$id إلى ($new_status)";
        }
        
        if ($stmt->execute()) {
            // تنبيه داخلي
            $stmt_notif = $conn->prepare("INSERT INTO notifications (customer_id, complaint_id, message) VALUES (?, ?, ?)");
            $stmt_notif->bind_param("iis", $data['cust_id'], $id, $msg);
            $stmt_notif->execute();
            
            // إشعار موبايل
            if (!empty($data['device_id']) && $data['device_id'] !== 'NULL') {
                $fcm_status = sendFCMV1($data['device_id'], "تحديث الشكوى 🔔", $msg, $id);
                if ($fcm_status) {
                    $message_html = "<div class='alert alert-success text-center'><i class='fa-solid fa-check-circle me-2'></i> تم التحديث وإرسال الإشعار بنجاح!</div>";
                } else {
                    $message_html = "<div class='alert alert-warning text-center'><i class='fa-solid fa-exclamation-triangle me-2'></i> تم التحديث، لكن فشل إرسال الإشعار للفايربيز.</div>";
                }
            } else {
                $message_html = "<div class='alert alert-success text-center'><i class='fa-solid fa-check-circle me-2'></i> تم التحديث بنجاح! (العميل لا يمتلك توكن إشعار)</div>";
            }
        }
    }
}

// ٣. جلب بيانات الشكوى للعرض
$complaint = null;
$id_to_fetch = isset($_GET['id']) ? intval($_GET['id']) : (isset($_POST['id']) ? intval($_POST['id']) : 0); 

if ($id_to_fetch > 0) {
    $stmt_comp = $conn->prepare("SELECT c.*, cu.full_name, cu.phone FROM complaints c JOIN customers cu ON c.customer_id = cu.id WHERE c.id = ?");
    $stmt_comp->bind_param("i", $id_to_fetch);
    $stmt_comp->execute();
    $res_comp = $stmt_comp->get_result();
    
    if ($res_comp && $res_comp->num_rows > 0) {
        $complaint = $res_comp->fetch_assoc();
    }
}

if (!$complaint && empty($message_html)) {
    die("<div class='container mt-5 alert alert-danger text-center'>عذراً، الشكوى غير موجودة!</div>");
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>معالجة الشكوى | نظام الإدارة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, sans-serif; }
        .card { border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); border: none; }
        .card-header { background-color: #1e293b; color: white; border-radius: 15px 15px 0 0 !important; padding: 20px; }
        .info-box { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .btn-primary { background-color: #0ea5e9; border: none; border-radius: 8px; padding: 12px; font-weight: bold; }
        .btn-primary:hover { background-color: #0284c7; }
    </style>
</head>
<body> 
<div class="container mt-5 mb-5">
    <?= $message_html ?>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fa-solid fa-file-signature me-2"></i> معالجة شكوى رقم #<?= htmlspecialchars($complaint['id']) ?></h5>
                    <span class="badge bg-light text-dark fs-6"><?= date('Y-m-d', strtotime($complaint['created_at'])) ?></span>
                </div>
                
                <div class="card-body p-4">
                    <div class="info-box">
                        <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-user me-2"></i> بيانات مقدم الشكوى</h6>
                        <div class="row">
                            <div class="col-md-6"><p class="mb-1"><strong>الاسم:</strong> <?= htmlspecialchars($complaint['full_name']) ?></p></div>
                            <div class="col-md-6"><p class="mb-1"><strong>هاتف الحساب:</strong> <?= htmlspecialchars($complaint['phone']) ?></p></div>
                        </div>
                    </div>

                    <div class="info-box bg-white">
                        <h6 class="fw-bold text-danger mb-3"><i class="fa-solid fa-circle-exclamation me-2"></i> تفاصيل الشكوى</h6>
                        <div class="row mb-3">
                            <div class="col-md-6"><p class="mb-1"><strong>الجهة:</strong> <?= htmlspecialchars($complaint['company_name']) ?></p></div>
                            <div class="col-md-6"><p class="mb-1"><strong>الخدمة:</strong> <?= htmlspecialchars($complaint['service_type']) ?></p></div>
                        </div>
                        
                        <div class="row mb-3 pb-3 border-bottom">
                            <div class="col-md-6"><p class="mb-1"><strong>رقم الشكوى (الخاص):</strong> <span class="text-primary"><?= htmlspecialchars($complaint['complaint_phone'] ?? 'غير متوفر') ?></span></p></div>
                            <div class="col-md-6"><p class="mb-1"><strong>الرقم الأرضي:</strong> <span class="text-primary"><?= htmlspecialchars($complaint['landline'] ?? 'غير متوفر') ?></span></p></div>
                        </div>

                        <p class="mb-2 fw-bold">نص الشكوى:</p>
                        <p class="text-muted border p-3 rounded bg-light"><?= nl2br(htmlspecialchars($complaint['description'])) ?></p>  
                    </div>

                    <?php if (!empty($complaint['image_path']) && $complaint['image_path'] != 'NULL'): ?> 
                    <div class="mt-3">
                        <p class="mb-2 fw-bold"><i class="fa-solid fa-image me-2"></i> المرفقات:</p>
                        <div class="text-center bg-light border rounded p-2">
                            <a href="../<?= htmlspecialchars($complaint['image_path']) ?>" target="_blank">
                                <img src="../<?= htmlspecialchars($complaint['image_path']) ?>" alt="صورة الشكوى" style="max-width: 100%; max-height: 300px; border-radius: 8px;">
                            </a>
                            <p class="text-muted small mt-2 mb-0">اضغط على الصورة للتكبير</p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="mt-4">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($complaint['id']) ?>">
                        
                        <div class="row">
                            <div class="col-md-5 mb-3">
                                <label class="form-label fw-bold">تحديث الحالة:</label>
                                <select name="status" id="status_select" class="form-select border-primary">
                                    <option value="جديدة" <?= ($complaint['status'] == 'جديدة') ? 'selected' : '' ?>>🔴 جديدة</option>
                                    <option value="جاري المعالجة" <?= ($complaint['status'] == 'جاري المعالجة') ? 'selected' : '' ?>>⏳ جاري المعالجة</option>
                                    <option value="تم الحل" <?= ($complaint['status'] == 'تم الحل' || $complaint['status'] == 'بانتظار تأكيد العميل') ? 'selected' : '' ?>>✅ تم الحل (انتظار العميل)</option>
                                    <option value="مرفوضة" <?= ($complaint['status'] == 'مرفوضة') ? 'selected' : '' ?>>⛔ مرفوضة</option>
                                </select>
                            </div>
                            
                            <div class="col-md-7 mb-3">
                                <label class="form-label fw-bold">رد الإدارة (يظهر للمواطن):</label>
                                <textarea name="admin_reply" class="form-control" rows="2" placeholder="اكتب ردك هنا..."><?= htmlspecialchars($complaint['admin_reply'] ?? '') ?></textarea>
                            </div>                            
                            
                            <div class="col-md-12 mb-3" id="rejection_box" style="display: <?= ($complaint['status'] == 'مرفوضة') ? 'block' : 'none' ?>;">
                                <label class="form-label fw-bold text-danger"><i class="fa-solid fa-triangle-exclamation"></i> سبب الرفض (مطلوب للإدارة العليا):</label>
                                <textarea name="rejection_reason" id="rejection_reason" class="form-control border-danger" rows="2" placeholder="يرجى توضيح سبب الرفض لتصعيد الشكوى للإدارة العليا..."><?= htmlspecialchars($complaint['rejection_reason'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-4 border-top pt-3">
                            <a href="index.php" class="btn btn-light border">عودة للرئيسية (Dashboard)</a>
                            <button type="submit" class="btn btn-primary px-4">حفظ وإرسال الإشعار</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('status_select').addEventListener('change', function() {
        var rejectionBox = document.getElementById('rejection_box');
        var rejectionInput = document.getElementById('rejection_reason');
        
        if(this.value === 'مرفوضة') {
            rejectionBox.style.display = 'block';
            rejectionInput.setAttribute('required', 'required'); 
        } else {
            rejectionBox.style.display = 'none';
            rejectionInput.removeAttribute('required'); 
        }
    });
</script>
</body>
</html>