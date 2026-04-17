<?php
session_start();

if (isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit();
}

// 🛠️ التعديل الأهم: تصحيح مسار ملف الاتصال بقاعدة البيانات
include '../connect.php';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, name, password FROM support_team WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // تنبيه المناقشة: في الأنظمة الحقيقية يفضل استخدام password_verify()
            if ($password === $row['password']) {
                // تجديد الـ Session ID كطبقة حماية إضافية ضد الـ Session Fixation
                session_regenerate_id(true);
                
                $_SESSION['admin_id'] = $row['id'];
                $_SESSION['admin_name'] = $row['name'];
                
                header("Location: index.php"); 
                exit();
            } else {
                $error = "كلمة المرور غير صحيحة ❌";
            }
        } else {
            $error = "البريد الإلكتروني غير مسجل ⚠️";
        }
        $stmt->close();      
    } else {
        $error = "برجاء ملء جميع الحقول";
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول | نظام الشكاوى</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            background: linear-gradient(135deg, #e0eafc 0%, #cfdef3 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, sans-serif;
            margin: 0;
        }
        /* حركة ظهور ناعمة للكارت */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .login-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 100%;
            max-width: 420px;
            background-color: #ffffff;
            animation: fadeInUp 0.6s ease-out;
        }         
        .card-header {
            background-color: #1e293b;
            color: white;
            padding: 30px 20px;
            text-align: center;
            border-bottom: none;
        }
        .card-header .icon-circle {
            width: 70px;
            height: 70px;
            background-color: rgba(255,255,255,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 2rem;
            color: #38bdf8;
        }
        .input-group-text {
            background-color: transparent;
            border-right: none;
            color: #64748b;
        }
        .form-control {
            border-left: none;
            padding: 12px 15px;
            box-shadow: none !important;
            border-color: #cbd5e1;
        }
        .form-control:focus {
            border-color: #cbd5e1;
        }
        .input-group {
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #cbd5e1;
            transition: 0.3s;
        }         
        .input-group:focus-within {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 0.2rem rgba(14, 165, 233, 0.15);
        }
        .btn-primary {
            background-color: #0ea5e9;
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-weight: bold;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #0284c7;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(2, 132, 199, 0.3);
        }
        .toggle-password {
            cursor: pointer;
            color: #94a3b8;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="card-header">
            <div class="icon-circle">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h4 class="mb-1 fw-bold">Shakawa Admin</h4>
            <p class="small mb-0 text-white-50">برجاء تسجيل الدخول للوصول للوحة التحكم</p>
        </div>
        
        <div class="card-body p-4 p-md-5 pt-4">
            <?php if ($error != ''): ?>
                <div class="alert alert-danger text-center small py-2 mb-4 rounded-3 border-0 bg-danger text-white">
                    <i class="fa-solid fa-circle-exclamation me-2"></i> <?= $error ?>
                </div>            
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold">البريد الإلكتروني</label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0"><i class="fa-regular fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control border-start-0 ps-0" placeholder="admin@example.com" required autofocus>
                    </div>
                </div>
                
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label text-muted small fw-bold mb-0">كلمة المرور</label>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text border-end-0"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="password" id="passwordInput" class="form-control border-start-0 border-end-0 px-0" placeholder="••••••••" required>
                        <span class="input-group-text border-start-0 toggle-password" onclick="togglePassword()">
                            <i class="fa-regular fa-eye" id="eyeIcon"></i>
                        </span>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 mt-2">
                    دخول للنظام <i class="fa-solid fa-arrow-left-long ms-2"></i>
                </button>
            </form>
        </div>
        
        <div class="card-footer bg-light border-0 text-center py-3">
            <p class="text-muted small mb-0">&copy; <?= date('Y') ?> نظام إدارة الشكاوى الذكي</p>
        </div>
    </div>

    <script>         
        function togglePassword() {
            const pwd = document.getElementById('passwordInput');
            const icon = document.getElementById('eyeIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>