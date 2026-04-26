<?php
session_start();
include "connect.php";

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// Filters
$filterPlate = trim($_GET['plate'] ?? '');
$filterStatus = $_GET['status'] ?? 'all';
$filterFrom = trim($_GET['from'] ?? '');
$filterTo = trim($_GET['to'] ?? '');
$filterPlateNormalized = strtoupper(preg_replace('/[\s-]+/u', '', $filterPlate));

// Build query
$where = [];
$params = [];
$types = '';

if ($filterPlate !== '') {
  $where[] = "REPLACE(REPLACE(UPPER(vl.plate_number), ' ', ''), '-', '') = ?";
    $types .= 's';
  $params[] = $filterPlateNormalized;
}

if ($filterStatus === 'parked') {
  $where[] = 'vl.time_out IS NULL';
} elseif ($filterStatus === 'departed') {
  $where[] = 'vl.time_out IS NOT NULL';
}

if ($filterFrom !== '') {
  $where[] = 'DATE(vl.time_in) >= ?';
    $types .= 's';
    $params[] = $filterFrom;
}

if ($filterTo !== '') {
  $where[] = 'DATE(vl.time_in) <= ?';
    $types .= 's';
    $params[] = $filterTo;
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vehicle_logs.csv"');
    $out = fopen('php://output', 'w');
  // Add BOM so Excel detects UTF-8 correctly for Thai text.
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['ID', 'ป้ายทะเบียน', 'เจ้าของรถ', 'เลขห้อง', 'ยี่ห้อ', 'สี', 'เวลาเข้า', 'เวลาออก', 'สถานะ']);

  $sql = "SELECT 
    vl.id,
    vl.plate_number,
    vl.time_in,
    vl.time_out,
    av.owner_name,
    av.brand,
    av.color,
    r.name AS resident_name,
    r.room_number
  FROM vehicle_logs vl
  LEFT JOIN authorized_vehicles av ON REPLACE(REPLACE(UPPER(vl.plate_number), ' ', ''), '-', '') = REPLACE(REPLACE(UPPER(av.plate_number), ' ', ''), '-', '')
  LEFT JOIN residents r ON av.resident_id = r.id
  $whereSql
  ORDER BY vl.id DESC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $status = $row['time_out'] === null ? 'จอดอยู่' : 'ออกแล้ว';
      $ownerName = $row['resident_name'] ?: ($row['owner_name'] ?: '-');
      fputcsv($out, [
        $row['id'],
        $row['plate_number'],
        $ownerName,
        $row['room_number'] ?: '-',
        $row['brand'] ?: '-',
        $row['color'] ?: '-',
        $row['time_in'],
        $row['time_out'],
        $status
      ]);
        }
        $stmt->close();
    }
    exit;
}

$logs = [];
$logsError = '';

// Count currently parked vehicles (time_out is NULL)
$parkedCount = 0;
$result = $conn->query("SELECT COUNT(*) AS cnt FROM vehicle_logs WHERE time_out IS NULL");
if ($result) {
    $parkedCount = (int)$result->fetch_assoc()['cnt'];
    $result->free();
}

$sql = "SELECT 
  vl.id,
  vl.plate_number,
  vl.time_in,
  vl.time_out,
  av.owner_name,
  av.brand,
  av.color,
  r.name AS resident_name,
  r.room_number
FROM vehicle_logs vl
LEFT JOIN authorized_vehicles av ON REPLACE(REPLACE(UPPER(vl.plate_number), ' ', ''), '-', '') = REPLACE(REPLACE(UPPER(av.plate_number), ' ', ''), '-', '')
LEFT JOIN residents r ON av.resident_id = r.id
$whereSql
ORDER BY vl.id ASC
LIMIT 200";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
} else {
    $logsError = "ไม่พบตาราง \"vehicle_logs\" หรือเกิดข้อผิดพลาด: " . $conn->error;
}
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>History | ระบบตรวจจับป้ายทะเบียน</title>
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
      <a href="search.php">
        <span class="icon">🔎</span>
        <span>ค้นหาป้ายทะเบียน</span>
      </a>
      <a href="history.php" class="active">
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
      <div class="title">ประวัติการเข้า-ออก</div>
      <div class="actions">
        <span>ผู้ใช้งาน: <?php echo htmlspecialchars($_SESSION['user']); ?></span>
      </div>
    </header>

    <div class="content">
      <div class="card">
        <h2>ประวัติการเข้า-ออก</h2>
        <div class="info" style="margin-bottom:16px;">
          รถค้างอยู่ปัจจุบัน: <strong><?php echo number_format($parkedCount); ?></strong> คัน
        </div>

        <form method="GET" class="inline-form">
          <label>ป้ายทะเบียน</label>
          <input type="text" name="plate" value="<?php echo htmlspecialchars($filterPlate); ?>" placeholder="กข 1234">

          <label>สถานะ</label>
          <select name="status">
            <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>ทั้งหมด</option>
            <option value="parked" <?php echo $filterStatus === 'parked' ? 'selected' : ''; ?>>ยังไม่ออก</option>
            <option value="departed" <?php echo $filterStatus === 'departed' ? 'selected' : ''; ?>>ออกแล้ว</option>
          </select>

          <label>จาก</label>
          <input type="date" name="from" value="<?php echo htmlspecialchars($filterFrom); ?>">

          <label>ถึง</label>
          <input type="date" name="to" value="<?php echo htmlspecialchars($filterTo); ?>">

          <button type="submit">กรอง</button>
          <a href="history.php" class="btn btn-edit" style="margin-left:10px;">รีเซ็ต</a>
          <a href="history.php?export=1&plate=<?php echo urlencode($filterPlate); ?>&status=<?php echo urlencode($filterStatus); ?>&from=<?php echo urlencode($filterFrom); ?>&to=<?php echo urlencode($filterTo); ?>" class="btn btn-check" style="margin-left:10px;">ส่งออก CSV</a>
        </form>

        <?php if ($logsError): ?>
          <div class="error"><?php echo $logsError; ?></div>
          <div class="info">
            หากยังไม่มีตารางให้รัน SQL ต่อไปนี้ในฐานข้อมูล <code>parking_db</code>:
            <pre>
CREATE TABLE vehicle_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plate_number VARCHAR(100) NOT NULL,
  time_in DATETIME NOT NULL,
  time_out DATETIME NULL
);
            </pre>
          </div>
        <?php else: ?>
          <table>
            <tr>
              <th>ลำดับ</th>
              <th>ป้ายทะเบียน</th>
              <th>เจ้าของรถ</th>
              <th>เลขห้อง</th>
              <th>ยี่ห้อ / สี</th>
              <th>สถานะ</th>
              <th>เวลาเข้า</th>
              <th>เวลาออก</th>
            </tr>

            <?php if (empty($logs)): ?>
              <tr>
                <td colspan="8">ยังไม่มีข้อมูล</td>
              </tr>
            <?php else: ?>
              <?php $displayIndex = 1; ?>
              <?php foreach ($logs as $log): ?>
                <tr>
                  <td><?php echo $displayIndex++; ?></td>
                  <td><?php echo htmlspecialchars($log['plate_number']); ?></td>
                  <td><?php echo htmlspecialchars($log['resident_name'] ?: ($log['owner_name'] ?: '-')); ?></td>
                  <td><?php echo htmlspecialchars($log['room_number'] ?: '-'); ?></td>
                  <td><?php echo htmlspecialchars(($log['brand'] ?: '-') . ' / ' . ($log['color'] ?: '-')); ?></td>
                  <td>
                    <?php if ($log['time_out']): ?>
                      <span class="tag tag-out">ออกแล้ว</span>
                    <?php else: ?>
                      <span class="tag tag-in">จอดอยู่</span>
                    <?php endif; ?>
                  </td>
                  <td><?php echo htmlspecialchars($log['time_in']); ?></td>
                  <td><?php echo htmlspecialchars($log['time_out'] ?: '-'); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </table>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

</body>
</html>
