<?php
session_start();
// 1. حماية الصفحة
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

// 🛠️ التعديل: تصحيح المسار لملف الاتصال
include '../connect.php';

// --- زتونة نظام الساعة (تحديث تلقائي للشكاوى المتأخرة) ---
$sql_auto_solve = "UPDATE complaints 
                   SET status = 'تم الحل', 
                       admin_reply = 'تم إغلاق الشكوى تلقائياً لعدم رد العميل خلال المهلة المحددة (ساعة)' 
                   WHERE status = 'بانتظار تأكيد العميل' 
                   AND approval_requested_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)";

$conn->query($sql_auto_solve);
// -------------------------------------------------------
$conn->set_charset("utf8mb4");

// ==========================================
// 1. الإحصائيات الأساسية (الصناديق الأربعة)
// ==========================================
$total = $conn->query("SELECT COUNT(*) as c FROM complaints")->fetch_assoc()['c'];
$new = $conn->query("SELECT COUNT(*) as c FROM complaints WHERE status IN ('جديدة', 'جاري المعالجة', 'بانتظار تأكيد العميل')")->fetch_assoc()['c'];
$resolved = $conn->query("SELECT COUNT(*) as c FROM complaints WHERE status = 'تم الحل'")->fetch_assoc()['c'];

// حساب التوقع الذكي (المربع الرابع) - تنقية حقيقية
$ai_stats = $conn->query("SELECT ai_category, COUNT(*) as count FROM complaints GROUP BY ai_category");
$ai_labels = []; $ai_counts = [];
$top_predicted = "مستقر";
$max_val = 0;

while($r = $ai_stats->fetch_assoc()) {
    $cat = $r['ai_category'] ?: 'غير مصنف';
    $count = (int)$r['count'];
    $ai_labels[] = $cat;
    $ai_counts[] = $count;
    if (strpos($cat, 'غير مصنف') === false && $count > $max_val) {
        $max_val = $count;
        $top_predicted = $cat;
    }
} 
$prediction_score = ($total > 0) ? round(($new / $total) * 100) : 0;

// ==========================================
// 2. التحليلات الزمنية (الداتا الحقيقية من الداتا بيز)
// ==========================================
// يومي
$days_ar = ["Sat"=>"السبت","Sun"=>"الأحد","Mon"=>"الاثنين","Tue"=>"الثلاثاء","Wed"=>"الأربعاء","Thu"=>"الخميس","Fri"=>"الجمعة"];
$daily_labels = []; $daily_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $daily_labels[] = $days_ar[date('D', strtotime($d))];
    $daily_data[] = (int)$conn->query("SELECT COUNT(*) as c FROM complaints WHERE DATE(created_at) = '$d'")->fetch_assoc()['c'];
}
// شهري
$monthly_labels = ["يناير","فبراير","مارس","أبريل","مايو","يونيو","يوليو","أغسطس","سبتمبر","أكتوبر","نوفمبر","ديسمبر"];
$monthly_data = array_fill(0, 12, 0);
$m_query = $conn->query("SELECT MONTH(created_at) as m, COUNT(*) as c FROM complaints WHERE YEAR(created_at) = YEAR(CURDATE()) GROUP BY MONTH(created_at)");
while($row = $m_query->fetch_assoc()) { $monthly_data[$row['m']-1] = (int)$row['c']; }
// سنوي
$yearly_labels = []; $yearly_data = [];
for ($i = 4; $i >= 0; $i--) {
    $y = date('Y') - $i;
    $yearly_labels[] = $y;
    $yearly_data[] = (int)$conn->query("SELECT COUNT(*) as c FROM complaints WHERE YEAR(created_at) = '$y'")->fetch_assoc()['c'];
}

// 3. الخريطة والجدول
$geo_stats = $conn->query("SELECT governorate, ai_category, COUNT(*) as count FROM complaints GROUP BY governorate, ai_category");
$map_details = [];
while($r = $geo_stats->fetch_assoc()) {
    $gov = $r['governorate']; $cat = $r['ai_category'] ?: 'غير مصنف'; $count = (int)$r['count'];
    if(!isset($map_details[$gov])) $map_details[$gov] = ['total' => 0, 'top_cat' => '', 'max' => 0];
    $map_details[$gov]['total'] += $count;
    if($count > $map_details[$gov]['max']) { $map_details[$gov]['max'] = $count; $map_details[$gov]['top_cat'] = $cat; }
}
$complaints = $conn->query("SELECT c.*, cu.full_name, cu.phone FROM complaints c LEFT JOIN customers cu ON c.customer_id = cu.id ORDER BY c.created_at DESC");
?> 
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة القيادة الذكية | نظام شكاوى</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f4f7f6; font-family: 'Segoe UI', sans-serif; font-size: 1.1rem; }
        .main-card { border: none; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); background: #fff; margin-bottom: 25px; padding: 25px; }
        .stat-card { border: none; border-radius: 15px; padding: 30px; color: #fff; }
        .predictive-box { background: linear-gradient(45deg, #2c3e50, #4ca1af); color: white; border-radius: 15px; }
        #map { height: 500px; border-radius: 20px; }
        .table-scrollable { max-height: 500px; overflow-y: auto; border-radius: 15px; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark p-3 mb-4 shadow">
    <div class="container-fluid px-4"><a class="navbar-brand fw-bold fs-3" href="#">🚀 نظام شكاوى الذكي</a></div>
</nav>

<div class="container-fluid px-5">
    <div class="row g-4 mb-4 text-center">
        <div class="col-md-3"><div class="stat-card bg-primary"><h5>إجمالي الشكاوى</h5><h2><?= $total ?></h2></div></div>
        <div class="col-md-3"><div class="stat-card bg-danger"><h5>بانتظار الإجراء</h5><h2><?= $new ?></h2></div></div>
        <div class="col-md-3"><div class="stat-card bg-success"><h5>شكاوى تم حلها</h5><h2><?= $resolved ?></h2></div></div>
        <div class="col-md-3"><div class="stat-card predictive-box"><h5>التوقع الذكي 🔮</h5><h4>زيادة: <?= $top_predicted ?></h4></div></div>
    </div>
 
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="main-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="fw-bold m-0">📈 اتجاهات الشكاوى</h4>
                    <div class="btn-group shadow-sm">
                        <button onclick="updateTrend('daily')" class="btn btn-primary">يومي</button>
                        <button onclick="updateTrend('monthly')" class="btn btn-dark">شهري</button>
                        <button onclick="updateTrend('yearly')" class="btn btn-secondary">سنوي</button>
                    </div>
                </div>
                <canvas id="trendChart" height="120"></canvas>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="main-card text-center">
                <h4 class="fw-bold mb-4">🎯 تحليل التصنيفات</h4>
                <canvas id="aiPieChart"></canvas>
                <div class="mt-4 p-3 bg-light rounded-pill fw-bold">ضغط العمل: <?= $prediction_score ?>%</div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="main-card">
                <h4 class="fw-bold mb-4">📍 خريطة مصر التفاعلية</h4>
                <div id="map"></div>
            </div>
        </div>
    </div>

    <div class="main-card shadow-lg">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold m-0"> السجل العام</h4>
            <div class="d-flex gap-3">
                <input type="text" id="searchInput" class="form-control rounded-pill px-4" placeholder="🔍 ابحث..." style="width: 250px;">
                <button onclick="exportToExcel()" class="btn btn-success rounded-pill px-4">Excel</button>             
            </div>
        </div>
        <button onclick="window.location.reload();" style="margin-bottom: 15px; padding: 8px 15px; background-color: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-family: inherit;">
            🔄 تحديث الشكاوى
        </button>
        <div class="table-scrollable">
            <table class="table table-hover align-middle">
                <thead class="bg-dark text-white sticky-top">
                    <tr><th>رقم</th><th>المواطن</th><th>الشركة</th><th>تصنيف AI</th><th>الحالة</th><th>التاريخ</th><th>إدارة</th></tr>
                </thead>
                <tbody>
                    <?php while($row = $complaints->fetch_assoc()): ?>
                    <tr class="fw-bold">
                        <td>#<?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><span class="badge bg-light text-dark border p-2"><?= $row['company_name'] ?></span></td>
                        <td>
                            <?php 
                                $cat = $row['ai_category'] ?: 'غير مصنف';
                                $c = (strpos($cat, 'أعطال')!==false)?'danger':((strpos($cat,'حسابات')!==false)?'success':'info');
                            ?>
                            <span class="badge bg-<?= $c ?> p-2">✨ <?= $cat ?></span>
                        </td>
                        <td><span class="badge rounded-pill p-2 bg-<?= $row['status']=='تم الحل'?'success' : ($row['status']=='جديدة'?'danger':'warning') ?>"><?= $row['status'] ?></span></td>
                        <td><small><?= date('Y-m-d', strtotime($row['created_at'])) ?></small></td>
                        <td><a href="edit_complaint.php?id=<?= $row['id'] ?>" class="btn btn-dark btn-sm rounded-pill px-3">فتح</a></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
Chart.defaults.font.size = 16;
// 1. الدائرة
new Chart(document.getElementById('aiPieChart'), {
    type: 'doughnut',
    data: { labels: <?= json_encode($ai_labels) ?>, datasets: [{ data: <?= json_encode($ai_counts) ?>, backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b'] }] }
});

// 2. التريند
let trendChart = new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($daily_labels) ?>,
        datasets: [{ label: 'الشكاوى', data: <?= json_encode($daily_data) ?>, borderColor: '#4e73df', backgroundColor: 'rgba(78, 115, 223, 0.1)', fill: true, tension: 0.3 }]
    }
});

function updateTrend(type) {
    if(type === 'daily') { trendChart.data.labels = <?= json_encode($daily_labels) ?>; trendChart.data.datasets[0].data = <?= json_encode($daily_data) ?>; }
    else if(type === 'monthly') { trendChart.data.labels = <?= json_encode($monthly_labels) ?>; trendChart.data.datasets[0].data = <?= json_encode($monthly_data) ?>; }
    else if(type === 'yearly') { trendChart.data.labels = <?= json_encode($yearly_labels) ?>; trendChart.data.datasets[0].data = <?= json_encode($yearly_data) ?>; }
    trendChart.update();
}
// 3. الخريطة (٢٧ محافظة)
const mapData = <?= json_encode($map_details) ?>;
const coords = {
    "القاهرة": [30.0444, 31.2357], "الإسكندرية": [31.2001, 29.9187], "الجيزة": [30.0131, 31.2089],
    "الدقهلية": [31.0413, 31.3785], "الشرقية": [30.5877, 31.502], "المنوفية": [30.5972, 30.9876],
    "الغربية": [30.7865, 31.0004], "القليوبية": [30.411, 31.110], "البحيرة": [31.037, 30.47],
    "كفر الشيخ": [31.1107, 30.9388], "دمياط": [31.4175, 31.8144], "بورسعيد": [31.2653, 32.3019],
    "الإسماعيلية": [30.5965, 32.2715], "السويس": [29.9668, 32.5498], "الفيوم": [29.3084, 30.8428],
    "بني سويف": [29.0744, 31.0979], "المنيا": [28.0991, 30.7636], "أسيوط": [27.1783, 31.1859],
    "سوهاج": [26.5591, 31.6957], "قنا": [26.1551, 32.716], "الأقصر": [25.6872, 32.6396],
    "أسوان": [24.0889, 32.8998], "البحر الأحمر": [27.2579, 33.8116], "الوادي الجديد": [25.4453, 30.5517],
    "مطروح": [31.3543, 27.2373], "شمال سيناء": [31.1316, 33.8033], "جنوب سيناء": [28.5091, 34.0135]
};

var map = L.map('map').setView([26.8206, 30.8025], 6);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

for (let city in coords) {
    if (mapData[city]) {
        let d = mapData[city];
        L.circle(coords[city], { color: '#e74a3b', radius: Math.min(d.total * 800, 70000), fillOpacity: 0.6 })
        .addTo(map).bindPopup(`<div style="text-align:right; font-size:18px;"><b>📍 محافظة ${city}</b><br>إجمالي الشكاوى: <b>${d.total}</b><br><span style="color:red">الأكثر تكراراً: ${d.top_cat}</span></div>`);
    }
}

document.getElementById('searchInput').addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    document.querySelectorAll('.table tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
    });
});
function exportToExcel() {
    let table = document.querySelector('.table');
    // 🛠️ التعديل: تصحيح اسم الملف لـ "تقرير_شكاوى"
    let uri = 'data:application/vnd.ms-excel;charset=utf-8,%EF%BB%BF' + encodeURIComponent(table.outerHTML);
    let link = document.createElement("a"); link.href = uri; link.download = "تقرير_شكاوى.xls"; link.click();
}
</script> 
</body>
</html>