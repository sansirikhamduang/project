<?php
session_start();
include "connect.php";

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$searchPlate = trim($_GET['plate'] ?? '');
$searchResult = null;
$searchError = '';
$searchStatus = null;
$searchTimeIn = null;
$searchTimeOut = null;
$normalizedSearchPlate = '';

if ($searchPlate !== '') {
  $normalizedSearchPlate = strtoupper(preg_replace('/[\s-]+/u', '', $searchPlate));

  // Resolve authorized vehicle and resident information by normalized plate.
  $stmt = $conn->prepare("SELECT 
    av.id,
    av.plate_number,
    av.owner_name,
    av.brand,
    av.color,
    av.resident_id,
    r.name AS resident_name,
    r.room_number,
    r.phone
    FROM authorized_vehicles av
    LEFT JOIN residents r ON av.resident_id = r.id
    WHERE REPLACE(REPLACE(UPPER(av.plate_number), ' ', ''), '-', '') = ?
    LIMIT 1");
    if ($stmt) {
    $stmt->bind_param('s', $normalizedSearchPlate);
        $stmt->execute();
        $res = $stmt->get_result();
        $searchResult = $res->fetch_assoc();
        $stmt->close();
    } else {
        $searchError = 'ไม่สามารถค้นหาได้: ' . $conn->error;
    }

    // Check last log (for status)
  $stmt = $conn->prepare("SELECT time_in, time_out FROM vehicle_logs WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') = ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
    $stmt->bind_param('s', $normalizedSearchPlate);
        $stmt->execute();
        $res = $stmt->get_result();
        $lastLog = $res->fetch_assoc();
        if ($lastLog) {
            $searchTimeIn = $lastLog['time_in'];
            $searchTimeOut = $lastLog['time_out'];
        }
        $stmt->close();
    }

    if ($searchTimeIn === null) {
      $searchStatus = 'NoHistory';
    } elseif ($searchTimeOut === null) {
      $searchStatus = 'Parked';
    } else {
      $searchStatus = 'Departed';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Search | ระบบตรวจจับป้ายทะเบียน</title>
  <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
</head>

<body>

<div class="app">
  <aside class="sidebar">
    <div class="brand">ระบบตรวจจับป้ายทะเบียน</div>

    <nav>
      <a href="dashboard.php">
        <span class="icon">🏠</span>
        <span>Dashboard</span>
      </a>
      <a href="search.php" class="active">
        <span class="icon">🔎</span>
        <span>ค้นหาป้ายทะเบียน</span>
      </a>
      <a href="history.php">
        <span class="icon">📜</span>
        <span>ประวัติการเข้า-ออก</span>
      </a>
      <a href="residents.php">
        <span class="icon">👥</span>
        <span>ข้อมูลผู้พักอาศัย</span>
      </a>
      <a href="logout.php">
        <span class="icon">🚪</span>
        <span>ออกจากระบบ</span>
      </a>
    </nav>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="title">ค้นหา</div>
      <div class="actions">
        <span>ผู้ใช้งาน: <?php echo htmlspecialchars($_SESSION['user']); ?></span>
      </div>
    </header>

    <div class="content">
      <div class="card">
        <h2>ค้นหาป้ายทะเบียน</h2>

        <form method="GET" class="inline-form" data-loading-submit>
          <label>ป้ายทะเบียน</label>
          <input type="text" name="plate" value="<?php echo htmlspecialchars($searchPlate); ?>" placeholder="กข 1111">
          <button type="submit" data-loading-text="กำลังค้นหา...">ค้นหา</button>
        </form>

        <?php if ($searchError): ?>
          <div class="error"><?php echo $searchError; ?></div>
        <?php elseif ($searchPlate !== ''): ?>
          <div class="result-box">
            <table aria-label="ผลการค้นหาป้ายทะเบียน">
              <tr>
                <th>ลำดับ</th>
                <th>ชื่อ</th>
                <th>ห้อง</th>
                <th>ป้ายทะเบียน</th>
                <th>ยี่ห้อ</th>
                <th>สี</th>
                <th>เวลาเข้า</th>
                <th>เวลาออก</th>
                <th>สถานะ</th>
                <th>ประเภท</th>
              </tr>
              <tr>
                <td>1</td>
                <td><?php echo htmlspecialchars($searchResult['resident_name'] ?? $searchResult['owner_name'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($searchResult['room_number'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($searchResult['plate_number'] ?? $searchPlate); ?></td>
                <td><?php echo htmlspecialchars($searchResult['brand'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($searchResult['color'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($searchTimeIn ?: '-'); ?></td>
                <td><?php echo htmlspecialchars($searchTimeOut ?: '-'); ?></td>
                <td>
                  <?php if ($searchStatus === 'Parked'): ?>
                    <span class="tag tag-in">จอดอยู่</span>
                  <?php elseif ($searchStatus === 'NoHistory'): ?>
                    <span class="tag tag-blocked">ไม่พบประวัติ</span>
                  <?php else: ?>
                    <span class="tag tag-out">ออกแล้ว</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($searchResult && !empty($searchResult['resident_id'])): ?>
                    <span class="tag tag-in">ผู้พักอาศัย</span>
                  <?php elseif ($searchResult): ?>
                    <span class="tag tag-out">รถที่อนุญาต</span>
                  <?php else: ?>
                    <span class="tag tag-blocked">ผู้เยี่ยมชม</span>
                  <?php endif; ?>
                </td>
              </tr>
            </table>

            <div class="info" style="margin-top:12px;">
              เบอร์โทร: <strong><?php echo htmlspecialchars($searchResult['phone'] ?? '-'); ?></strong>
            </div>

            <?php if (!$searchResult): ?>
              <div class="info" style="margin-top:12px;">
                ไม่พบทะเบียนนี้ในรายการลูกบ้าน สามารถเพิ่มข้อมูลได้ที่หน้า <a href="residents.php">ข้อมูลผู้พักอาศัย</a>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var forms = document.querySelectorAll('form[data-loading-submit]');
  forms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
      }

      var submitter = event.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
      if (!submitter) {
        return;
      }

      form.dataset.submitting = '1';
      submitter.disabled = true;
      submitter.classList.add('is-loading');

      if (submitter.tagName === 'BUTTON') {
        submitter.dataset.originalText = submitter.textContent;
        submitter.textContent = submitter.getAttribute('data-loading-text') || 'กำลังดำเนินการ...';
      } else {
        submitter.dataset.originalText = submitter.value;
        submitter.value = submitter.getAttribute('data-loading-text') || 'กำลังดำเนินการ...';
      }
    });
  });
});
</script>

</body>
</html>
