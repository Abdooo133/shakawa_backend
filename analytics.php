<?php
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
include 'connect.php';

$company = $_POST['company'] ?? 'فودافون';
$trend = $_POST['trend'] ?? 'أسبوع';

// 🛡️ التعديل الأمني: حماية اسم الشركة من الـ SQL Injection
$company_safe = mysqli_real_escape_string($conn, trim($company));

// دالة لترجمة الأيام
function getArabicDay($date) {
    $days = ['Sunday' => 'الأحد', 'Monday' => 'الإثنين', 'Tuesday' => 'الثلاثاء', 'Wednesday' => 'الأربعاء', 'Thursday' => 'الخميس', 'Friday' => 'الجمعة', 'Saturday' => 'السبت'];
    return $days[date('l', strtotime($date))];
}

// ١. ملخص عام من الجدول مباشر
$summary = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status LIKE '%حل%' OR status LIKE '%مغلق%' THEN 1 ELSE 0 END) as solved,
    SUM(CASE WHEN status LIKE '%جاري%' OR status LIKE '%معالج%' OR status LIKE '%قيد%' OR status LIKE '%انتظار%' OR status LIKE '%معلق%' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status LIKE '%جديد%' THEN 1 ELSE 0 END) as new_count
    FROM complaints")->fetch_assoc();

// ٢. إحصائيات الشركة من الجدول مباشر
$company_stats = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status LIKE '%حل%' OR status LIKE '%مغلق%' THEN 1 ELSE 0 END) as solved,
    SUM(CASE WHEN status LIKE '%جاري%' OR status LIKE '%معالج%' OR status LIKE '%قيد%' OR status LIKE '%انتظار%' OR status LIKE '%معلق%' THEN 1 ELSE 0 END) as pending
    FROM complaints WHERE TRIM(company_name) LIKE '%$company_safe%'")->fetch_assoc();

// ٣. توزيع المحافظات والخدمات (شيلنا الـ LIMIT عشان يظهروا كلهم)
$company_govs = $conn->query("SELECT governorate, COUNT(*) as count FROM complaints WHERE TRIM(company_name) LIKE '%$company_safe%' GROUP BY governorate ORDER BY count DESC")->fetch_all(MYSQLI_ASSOC);
$company_services = $conn->query("SELECT service_type, COUNT(*) as count FROM complaints WHERE TRIM(company_name) LIKE '%$company_safe%' GROUP BY service_type ORDER BY count DESC")->fetch_all(MYSQLI_ASSOC);

// ٤. توزيع الجنس (🛠️ تم تصليح الغلطة المطبعية هنا)
$gender_dist = $conn->query("SELECT cu.gender, COUNT(c.id) as count 
                             FROM customers cu 
                             JOIN complaints c ON cu.id = c.customer_id 
                             WHERE cu.gender IN ('ذكر', 'أنثى')
                             GROUP BY cu.gender")->fetch_all(MYSQLI_ASSOC);

// ٥. البيانات الدورية (محسوبة بالمللي عشان الرسم البياني)
$trend_data = [];
if ($trend == 'أسبوع') {
    // ٧ أيام (سبت وحد واتنين...)
    for($i=6; $i>=0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $trend_data[$d] = ["date" => getArabicDay($d), "count" => 0];
    }
    $res = $conn->query("SELECT DATE(created_at) as d, COUNT(*) as c FROM complaints WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at)");
    while($row = $res->fetch_assoc()) {
        if(isset($trend_data[$row['d']])) $trend_data[$row['d']]['count'] = $row['c'];
    }
    $trend_data = array_values($trend_data);
} elseif ($trend == 'شهر') {
    // ١٢ شهر
    $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    for($i=1; $i<=12; $i++) {
        $trend_data[$i] = ["date" => $months[$i-1], "count" => 0];
    }
    $res = $conn->query("SELECT MONTH(created_at) as m, COUNT(*) as c FROM complaints WHERE YEAR(created_at) = YEAR(NOW()) GROUP BY MONTH(created_at)");
    while($row = $res->fetch_assoc()) {
        if(isset($trend_data[$row['m']])) $trend_data[$row['m']]['count'] = $row['c'];
    }
    $trend_data = array_values($trend_data);
} else { 
    // سنة (من ٢٠٢٠ لـ ٢٠٢٦)
    for($i=2020; $i<=2026; $i++) {
        $trend_data[$i] = ["date" => (string)$i, "count" => 0];
    }
    $res = $conn->query("SELECT YEAR(created_at) as y, COUNT(*) as c FROM complaints WHERE YEAR(created_at) BETWEEN 2020 AND 2026 GROUP BY YEAR(created_at)");
    while($row = $res->fetch_assoc()) {
        if(isset($trend_data[$row['y']])) $trend_data[$row['y']]['count'] = $row['c'];
    }
    $trend_data = array_values($trend_data);
}

// الإرسال النهائي (مع تضبيط الأرقام ودعم العربي للـ JSON)
echo json_encode([
    "summary" => $summary,
    "company_stats" => $company_stats,
    "company_govs" => $company_govs,
    "company_services" => $company_services,
    "gender_dist" => $gender_dist,
    "trend_data" => $trend_data
], JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
?>