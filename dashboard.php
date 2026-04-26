
<?php
session_start();
include "connect.php";
include "ocr_processor.php";

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$errorMessage = '';
$successMessage = '';

// Handle log actions
if (isset($_GET['close_log_id'])) {
    $closeLogId = intval($_GET['close_log_id']);
    $stmt = $conn->prepare("UPDATE vehicle_logs SET time_out = NOW() WHERE id = ? AND time_out IS NULL");
    if ($stmt) {
        $stmt->bind_param('i', $closeLogId);
        $stmt->execute();
        $stmt->close();
        $successMessage = 'บันทึกเวลาออกเรียบร้อย';
    }
}

if (isset($_GET['delete_log_id'])) {
    $deleteLogId = intval($_GET['delete_log_id']);
    $stmt = $conn->prepare("DELETE FROM vehicle_logs WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $deleteLogId);
        $stmt->execute();
        $stmt->close();
        $successMessage = 'ลบรายการบันทึกเรียบร้อย';
    }
}

$editLogId = intval($_GET['edit_log_id'] ?? 0);
$editLogPlate = '';
$editLogTimeIn = '';
$editLogTimeOut = '';

if ($editLogId) {
    $stmt = $conn->prepare("SELECT plate_number, time_in, time_out FROM vehicle_logs WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $editLogId);
        $stmt->execute();
        $stmt->bind_result($editLogPlate, $editLogTimeIn, $editLogTimeOut);
        $stmt->fetch();
        $stmt->close();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Change password via modal
    if (isset($_POST['action']) && $_POST['action'] === 'change_password_modal') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $username = $_SESSION['user'];
        
        if ($newPassword === $confirmPassword && !empty($newPassword)) {
            $stmt = $conn->prepare("SELECT password FROM users WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $stmt->bind_result($dbPassword);
                $stmt->fetch();
                
                if (password_verify($currentPassword, $dbPassword)) {
                    $hashedNewPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                    $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
                    if ($updateStmt) {
                        $updateStmt->bind_param('ss', $hashedNewPassword, $username);
                        $updateStmt->execute();
                        $updateStmt->close();
                        $successMessage = '✓ เปลี่ยนรหัสผ่านสำเร็จ';
                    }
                } else {
                    $errorMessage = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
                }
                $stmt->close();
            }
        } else {
            $errorMessage = 'รหัสผ่านไม่ตรงกันหรือว่าง';
        }
    }
    
    // Create user via modal
    if (isset($_POST['action']) && $_POST['action'] === 'create_user_modal') {
        $newUsername = trim($_POST['new_username'] ?? '');
        $newPassword = trim($_POST['new_password'] ?? '');
        
        if (!empty($newUsername) && !empty($newPassword) && strlen($newPassword) >= 6) {
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if ($checkStmt) {
                $checkStmt->bind_param('s', $newUsername);
                $checkStmt->execute();
                $checkStmt->store_result();
                
                if ($checkStmt->num_rows === 0) {
                    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                    $insertStmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                    if ($insertStmt) {
                        $insertStmt->bind_param('ss', $newUsername, $hashedPassword);
                        if ($insertStmt->execute()) {
                            $successMessage = '✓ สร้างผู้ใช้ใหม่สำเร็จ';
                        } else {
                            $errorMessage = 'เกิดข้อผิดพลาด: ' . $insertStmt->error;
                        }
                        $insertStmt->close();
                    }
                } else {
                    $errorMessage = 'ชื่อผู้ใช้นี้มีอยู่แล้ว';
                }
                $checkStmt->close();
            }
        } else {
            $errorMessage = 'กรอกข้อมูลให้ครบถ้วน (password อย่างน้อย 6 ตัวอักษร)';
        }
    }

    // Log scan (simulate entry/exit)
    if (isset($_POST['action']) && $_POST['action'] === 'log_scan') {
      $plateInput = trim($_POST['scan_plate'] ?? '');
      $plate = strtoupper(preg_replace('/[\s-]+/u', '', $plateInput));
      if ($plate === '') {
            $errorMessage = 'กรุณากรอกป้ายทะเบียนสำหรับบันทึกเข้า-ออก';
        } else {
            // Check if authorized
        $stmt = $conn->prepare("SELECT id FROM authorized_vehicles WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') = ? LIMIT 1");
            $authorized = false;
            if ($stmt) {
                $stmt->bind_param('s', $plate);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 1) {
                    $authorized = true;
                }
                $stmt->close();
            }

            // Determine whether to create a new entry or close an open entry
            $stmt = $conn->prepare(
              "SELECT id FROM vehicle_logs WHERE REPLACE(REPLACE(UPPER(plate_number), ' ', ''), '-', '') = ? AND time_out IS NULL ORDER BY id DESC LIMIT 1"
            );
            $openLogId = null;
            if ($stmt) {
                $stmt->bind_param('s', $plate);
                $stmt->execute();
                $stmt->bind_result($openLogId);
                $stmt->fetch();
                $stmt->close();
            }

            if ($openLogId) {
                $stmt = $conn->prepare("UPDATE vehicle_logs SET time_out = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $openLogId);
                    $stmt->execute();
                    $stmt->close();
                    $successMessage = ($authorized ? 'บันทึกเวลาออกสำเร็จ' : 'บันทึกเวลาออก (ป้ายไม่อยู่ในรายการอนุญาต)');
                }
            } else {
                // Handle plate image upload
                $plateImage = null;
                if (isset($_FILES['scan_plate_image']) && $_FILES['scan_plate_image']['size'] > 0) {
                    $uploadDir = __DIR__ . '/uploads/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileName = $_FILES['scan_plate_image']['name'];
                    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (in_array($fileExt, $allowedExts) && $_FILES['scan_plate_image']['size'] <= 5242880) { // 5MB
                        $newFileName = 'plate_' . time() . '_' . uniqid() . '.' . $fileExt;
                        if (move_uploaded_file($_FILES['scan_plate_image']['tmp_name'], $uploadDir . $newFileName)) {
                            $plateImage = $newFileName;
                        }
                    }
                }
                
                // เตรียมข้อมูลสำหรับบันทึก
                $brand = '';
                $color = '';
                $residentName = '';
                $roomNumber = '';
                $residentType = $authorized ? 'ผู้พักอาศัย' : 'ผู้เยี่ยมชม';

                // ดึงข้อมูลจาก authorized_vehicles และ residents (ถ้ามี)
                if ($authorized) {
                  $sql = "SELECT av.brand, av.color, r.name, r.room_number
                      FROM authorized_vehicles av
                      LEFT JOIN residents r ON av.resident_id = r.id
                      WHERE REPLACE(REPLACE(UPPER(av.plate_number), ' ', ''), '-', '') = ?";
                  $stmt = $conn->prepare($sql);
                  $stmt->bind_param('s', $plate);
                  $stmt->execute();
                  $stmt->bind_result($brand, $color, $residentName, $roomNumber);
                  $stmt->fetch();
                  $stmt->close();
                }

                // เพิ่มข้อมูลลง vehicle_logs
                $stmt = $conn->prepare("INSERT INTO vehicle_logs 
                  (plate_number, plate_image, time_in, brand, color, resident_name, room_number, resident_type)
                  VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssss', $plate, $plateImage, $brand, $color, $residentName, $roomNumber, $residentType);
                $stmt->execute();
                $stmt->close();
                $successMessage = ($authorized ? 'บันทึกเวลาเข้าเรียบร้อย' : 'บันทึกเวลาเข้า (ป้ายไม่อยู่ในรายการอนุญาต)');
                // Redirect to prevent duplicate form submission
                header('Location: dashboard.php?success=1');
                exit;
            }
        }
    }

    // Edit existing log
    if (isset($_POST['action']) && $_POST['action'] === 'edit_log') {
        $editLogId = intval($_POST['edit_log_id'] ?? 0);
        $plate = trim($_POST['edit_plate'] ?? '');
        $timeIn = trim($_POST['edit_time_in'] ?? '');
        $timeOut = trim($_POST['edit_time_out'] ?? '');

        if ($editLogId && $plate !== '') {
            $stmt = $conn->prepare("UPDATE vehicle_logs SET plate_number = ?, time_in = ?, time_out = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('sssi', $plate, $timeIn, $timeOut, $editLogId);
                if ($stmt->execute()) {
                    // Redirect back to the main dashboard to close the edit form
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $errorMessage = 'ไม่สามารถอัปเดตบันทึกได้: ' . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            $errorMessage = 'ข้อมูลไม่ครบถ้วนสำหรับการแก้ไข';
        }
    }
}

// Load recent logs
$logs = [];
$logError = '';
$result = $conn->query("SELECT *, CASE WHEN time_out IS NULL THEN 'จอดอยู่' ELSE 'ออกแล้ว' END AS status FROM vehicle_logs ORDER BY id ASC LIMIT 15");
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
  }
  $result->free();
} else {
  $logError = "ไม่พบตาราง \"vehicle_logs\" หรือเกิดข้อผิดพลาด: " . $conn->error;
}

// Summary widgets
$totalParkingSpots = 250;
$parkedCount = 0;
$todayEntries = 0;
$todayIn = 0;
$todayOut = 0;
$totalEntries = 0;
$totalIn = 0;
$totalOut = 0;

$result = $conn->query("SELECT COUNT(*) AS cnt FROM vehicle_logs WHERE time_out IS NULL");
if ($result) {
  $parkedCount = (int) $result->fetch_assoc()['cnt'];
  $result->free();
}


$result = $conn->query("SELECT COUNT(*) AS cnt FROM vehicle_logs WHERE DATE(time_in) = CURDATE()");
if ($result) {
  $todayIn = (int) $result->fetch_assoc()['cnt'];
  $result->free();
}
$result = $conn->query("SELECT COUNT(*) AS cnt FROM vehicle_logs WHERE DATE(time_out) = CURDATE()");
if ($result) {
  $todayOut = (int) $result->fetch_assoc()['cnt'];
  $result->free();
}

$result = $conn->query("SELECT COUNT(*) AS cnt FROM vehicle_logs");
if ($result) {
  $totalEntries = (int) $result->fetch_assoc()['cnt'];
  $result->free();
}

$result = $conn->query("SELECT COUNT(*) AS cnt FROM vehicle_logs WHERE time_in IS NOT NULL");
if ($result) {
  $totalIn = (int) $result->fetch_assoc()['cnt'];
  $result->free();
}
$result = $conn->query("SELECT COUNT(*) AS cnt FROM vehicle_logs WHERE time_out IS NOT NULL");
if ($result) {
  $totalOut = (int) $result->fetch_assoc()['cnt'];
  $result->free();
}
$availableParkingSpots = $totalParkingSpots - $parkedCount;
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard | ระบบตรวจจับป้ายทะเบียน</title>
  <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
</head>

<body>

<!-- Simple User Management Panel -->
<div id="userPanel" class="user-panel">
  <div class="panel-header">
    <h2>⚙️ จัดการผู้ใช้</h2>
    <button onclick="document.getElementById('userPanel').style.display='none'" class="btn-close">✕</button>
  </div>
  
  <div class="panel-content">
    <div class="section">
      <h3>🔐 เปลี่ยนรหัสผ่าน</h3>
      <form method="POST" onsubmit="document.getElementById('userPanel').style.display='none'">
        <input type="hidden" name="action" value="change_password_modal">
        <input type="password" name="current_password" placeholder="รหัสผ่านปัจจุบัน" required>
        <input type="password" name="new_password" placeholder="รหัสผ่านใหม่" required>
        <input type="password" name="confirm_password" placeholder="ยืนยันรหัสผ่าน" required>
        <button type="submit">อัปเดต</button>
      </form>
    </div>

    <div class="section">
      <h3>➕ สร้างผู้ใช้ใหม่</h3>
      <form method="POST" onsubmit="document.getElementById('userPanel').style.display='none'">
        <input type="hidden" name="action" value="create_user_modal">
        <input type="text" name="new_username" placeholder="ชื่อผู้ใช้" required>
        <input type="password" name="new_password" placeholder="รหัสผ่าน" required>
        <button type="submit">สร้าง</button>
      </form>
    </div>
  </div>
</div>

<style>
.user-panel {
  display: none;
  position: fixed;
  right: 0;
  top: 0;
  width: 400px;
  height: 100vh;
  background: white;
  box-shadow: -5px 0 20px rgba(0,0,0,0.2);
  z-index: 1000;
  overflow-y: auto;
}

.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  position: sticky;
  top: 0;
}

.panel-header h2 {
  margin: 0;
}

.btn-close {
  background: none;
  border: none;
  color: white;
  font-size: 24px;
  cursor: pointer;
}

.panel-content {
  padding: 20px;
}

.section {
  margin-bottom: 25px;
  padding-bottom: 20px;
  border-bottom: 1px solid #eee;
}

.section h3 {
  margin: 0 0 15px 0;
  font-size: 16px;
  color: #333;
}

.section form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.section input {
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 14px;
}

.section input:focus {
  outline: none;
  border-color: #667eea;
  box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.section button {
  padding: 10px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 600;
}

.section button:hover {
  transform: translateY(-2px);
  box-shadow: 0 3px 10px rgba(102, 126, 234, 0.3);
}

@media (max-width: 600px) {
  .user-panel {
    width: 100%;
  }
}
</style>

<div class="app">
  <aside class="sidebar">
    <div class="brand">ระบบตรวจจับป้ายทะเบียน</div>

    <nav>
      <a href="dashboard.php" class="active">
        <span class="icon">🏠</span>
        <span>Dashboard</span>
      </a>
      <a href="search.php">
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
      <div class="title">Dashboard</div>
      <div class="actions">
        <span>ผู้ใช้งาน: <?php echo htmlspecialchars($_SESSION['user']); ?></span>
      </div>
    </header>


    <div class="content">
      <div class="dashboard-grid">
        <div class="card main-card">
          <div style="margin-bottom: 18px;">
            <strong>ที่จอดทั้งหมด:</strong> <?php echo $totalParkingSpots; ?> |
            <strong>ที่จอดว่าง:</strong> <?php echo $availableParkingSpots; ?> |
            <strong>รถที่จอดอยู่:</strong> <?php echo $parkedCount; ?> |
            🚗 <strong>รถเข้าแล้วทั้งหมด:</strong> <?php echo $totalIn; ?> ครั้ง |
            🚙 <strong>รถออกแล้วทั้งหมด:</strong> <?php echo $totalOut; ?> ครั้ง
          </div>
          <div style="margin-bottom: 18px; font-size: 1em;">
            🚗 <strong>วันนี้รถเข้า:</strong> <?php echo isset($todayIn) ? ($todayIn > 0 ? $todayIn.' คัน' : '0 คัน') : '0 คัน'; ?> |
            🚙 <strong>วันนี้รถออก:</strong> <?php echo isset($todayOut) ? ($todayOut > 0 ? $todayOut.' คัน' : '0 คัน') : '0 คัน'; ?>
          </div>


          <form method="POST" class="inline-form" data-loading-submit enctype="multipart/form-data">
            <input type="hidden" name="action" value="log_scan">
            <label>ป้ายทะเบียน</label>
            <div style="display: flex; gap: 10px;">
              <input type="text" name="scan_plate" id="scan_plate_input" placeholder="กข 1234" required style="flex: 1;">
              <button type="button" class="btn btn-info" onclick="openOCRModal()">📷 อ่านจากรูป</button>
            </div>
            <input type="file" name="scan_plate_image" id="scan_plate_image_input" accept="image/*" style="display:none;">
            <!-- ปุ่มบันทึกถูกลบออก -->
          </form>

          <?php if ($editLogId): ?>
            <div class="info">แก้ไขบันทึก ID <?php echo $editLogId; ?></div>
            <form method="POST" class="inline-form" data-loading-submit>
              <input type="hidden" name="action" value="edit_log">
              <input type="hidden" name="edit_log_id" value="<?php echo $editLogId; ?>">
              <label>ป้ายทะเบียน</label>
              <input type="text" name="edit_plate" value="<?php echo htmlspecialchars($editLogPlate); ?>" required>
              <label>เวลาเข้า</label>
              <input type="text" name="edit_time_in" value="<?php echo htmlspecialchars($editLogTimeIn); ?>">
              <label>เวลาออก</label>
              <input type="text" name="edit_time_out" value="<?php echo htmlspecialchars($editLogTimeOut); ?>">
              <button type="submit" data-loading-text="กำลังบันทึก...">บันทึกการแก้ไข</button>
            </form>
          <?php endif; ?>

          <?php if ($logError): ?>
            <div class="error"><?php echo $logError; ?></div>
          <?php else: ?>
            <table>
              <tr>
                <th>ลำดับ</th>
                <th>รูปทะเบียน</th>
                <th class="name-col-nowrap">ชื่อ</th>
                <th>ห้อง</th>
                <th>ป้ายทะเบียน</th>
                <th>ยี่ห้อ</th>
                <th>สี</th>
                <th>เวลาเข้า</th>
                <th>เวลาออก</th>
                <th>สถานะ</th>
                <th>ประเภท</th>
                <th>จัดการ</th>
              </tr>

              <?php if (empty($logs)): ?>
                <tr>
                  <td colspan="12">ยังไม่มีบันทึก</td>
                </tr>
              <?php else: ?>
                <?php $displayIndex = 1; ?>
                <?php foreach ($logs as $log): ?>
                  <?php
                    if ($log['resident_type'] === 'ผู้เยี่ยมชม' && empty($log['brand']) && empty($log['color'])) {
                        $visitorBrands = ['Toyota', 'Honda', 'Isuzu', 'Mitsubishi', 'Nissan', 'Mazda', 'Suzuki', 'Ford'];
                        $visitorColors = ['ขาว', 'ดำ', 'เทา', 'เงิน', 'แดง', 'น้ำเงิน', 'เขียว', 'น้ำตาล'];
                        $seed = hexdec(substr(md5(($log['plate_number'] ?? '') . '|' . ($log['id'] ?? '')), 0, 8));
                        $brandDisplay = $visitorBrands[$seed % count($visitorBrands)];
                        $colorDisplay = $visitorColors[$seed % count($visitorColors)];
                    } else {
                        $brandDisplay = $log['brand'] ?: '-';
                        $colorDisplay = $log['color'] ?: '-';
                    }
                  ?>
                  <tr>
                    <td><?php echo $displayIndex++; ?></td>
                    <td>
                      <?php if ($log['plate_image']): ?>
                        <img src="uploads/<?php echo htmlspecialchars($log['plate_image']); ?>" alt="plate" style="max-width: 60px; max-height: 40px; cursor: pointer;" onclick="window.open(this.src, '_blank')">
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td class="name-col-nowrap"><?php echo htmlspecialchars($log['resident_name'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($log['room_number'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($log['plate_number']); ?></td>
                    <td><?php echo htmlspecialchars($brandDisplay); ?></td>
                    <td><?php echo htmlspecialchars($colorDisplay); ?></td>
                    <td><?php echo htmlspecialchars($log['time_in']); ?></td>
                    <td><?php echo htmlspecialchars($log['time_out'] ?: '-'); ?></td>
                    <td>
                      <?php if (empty($log['time_out'])): ?>
                        <span class="tag tag-in">จอดอยู่</span>
                      <?php else: ?>
                        <span class="tag tag-out">ออกแล้ว</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="tag <?php echo ($log['resident_type'] === 'ผู้พักอาศัย') ? 'tag-in' : 'tag-out'; ?>">
                        <?php echo htmlspecialchars($log['resident_type']); ?>
                      </span>
                    </td>
                    <td class="table-actions">
                      <div class="action-stack">
                        <form method="GET" action="dashboard.php" class="inline-action-form" data-loading-submit>
                          <input type="hidden" name="edit_log_id" value="<?php echo $log['id']; ?>">
                          <button type="submit" class="btn btn-edit" data-loading-text="กำลังเปิด...">แก้ไข</button>
                        </form>
                        <button type="button" class="btn btn-delete" onclick="deleteLogAJAX(<?php echo $log['id']; ?>, this)">ลบ</button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </table>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var forms = document.querySelectorAll('form[data-loading-submit]');
  forms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var confirmMessage = form.getAttribute('data-confirm-message');
      if (confirmMessage && !window.confirm(confirmMessage)) {
        event.preventDefault();
        return;
      }

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

// OCR Modal Functions
function openOCRModal() {
  document.getElementById('ocrModal').style.display = 'flex';
  document.getElementById('ocrPreview').innerHTML = '';
  document.getElementById('ocrImageInput').value = '';
  document.getElementById('ocrResult').innerHTML = '';
}

function closeOCRModal() {
  document.getElementById('ocrModal').style.display = 'none';
}

function previewImage(event) {
  var file = event.target.files[0];
  if (!file) return;

  syncOcrImageToMainForm(file);

  var reader = new FileReader();
  reader.onload = function(e) {
    document.getElementById('ocrPreview').innerHTML = '<img src="' + e.target.result + '" style="max-width: 100%; max-height: 300px; border-radius: 8px;">';
  };
  reader.readAsDataURL(file);
}

function syncOcrImageToMainForm(file) {
  var mainInput = document.getElementById('scan_plate_image_input');
  if (!mainInput || !file) return;

  try {
    var dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    mainInput.files = dataTransfer.files;
  } catch (e) {
    // Some browsers block programmatic file assignment; user can still choose manually.
  }
}

function extractPlateFromImage() {
  var imageInput = document.getElementById('ocrImageInput');
  var resultDiv = document.getElementById('ocrResult');
  
  if (!imageInput.files || imageInput.files.length === 0) {
    resultDiv.innerHTML = '<div class="error">กรุณาเลือกรูปก่อน</div>';
    return;
  }

  var formData = new FormData();
  formData.append('action', 'extract_plate');
  formData.append('image', imageInput.files[0]);
  formData.append('_ts', Date.now().toString());

  resultDiv.innerHTML = '<div class="info">กำลังอ่านป้าย...</div>';

  fetch('dashboard.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      var plateInput = document.getElementById('scan_plate_input');
      plateInput.value = data.plate;
      if (imageInput.files && imageInput.files[0]) {
        syncOcrImageToMainForm(imageInput.files[0]);
      }
      var versionText = data.version ? ' <span style="font-size:12px; opacity:.8;">(' + htmlEscape(data.version) + ')</span>' : '';
      resultDiv.innerHTML = '<div class="success">✓ พบป้าย: <strong>' + htmlEscape(data.plate) + '</strong>' + versionText + '<br><span style="font-size:12px; opacity:.9;">รูปถูกแนบเข้าฟอร์มบันทึกแล้ว</span></div>';
      setTimeout(() => {
        closeOCRModal();
        // ส่งฟอร์มบันทึกเข้า-ออกอัตโนมัติ
        var logForm = document.querySelector('form[data-loading-submit][method="POST"]');
        // ป้องกัน submit ซ้ำหลัง redirect: ใช้ sessionStorage
        if (logForm) {
          sessionStorage.setItem('logScanSubmitted', '1');
          logForm.submit();
        }
      // ป้องกัน auto-submit log_scan ซ้ำหลัง redirect
      window.addEventListener('DOMContentLoaded', function() {
        if (sessionStorage.getItem('logScanSubmitted') === '1') {
          sessionStorage.removeItem('logScanSubmitted');
          // redirect ไปหน้า dashboard.php (clean URL, no POST)
          if (!window.location.search.includes('success=1')) {
            window.location.href = window.location.pathname + '?success=1';
          }
        }
      });
      }, 1500);
    } else {
      var debugText = '';
      if (data.version) {
        debugText += '<div style="margin-top:8px; font-size:12px; opacity:.85;">OCR Version: ' + htmlEscape(data.version) + '</div>';
      }
      if (data.debug && data.debug.attempt_texts && data.debug.attempt_texts.length) {
        var attemptsJoined = data.debug.attempt_texts.join(' | ');
        if (attemptsJoined.length > 180) {
          attemptsJoined = attemptsJoined.substring(0, 180) + '...';
        }
        debugText += '<div style="margin-top:8px; font-size:12px; opacity:.9;">OCR อ่านได้: ' + htmlEscape(attemptsJoined) + '</div>';
      }
      if (data.debug && data.debug.cmd_output) {
        var cmdOut = data.debug.cmd_output;
        if (cmdOut.length > 220) {
          cmdOut = cmdOut.substring(0, 220) + '...';
        }
        debugText += '<div style="margin-top:8px; font-size:12px; opacity:.85; white-space:pre-wrap;">' + htmlEscape(cmdOut) + '</div>';
      }
      resultDiv.innerHTML = '<div class="error">❌ ' + htmlEscape(data.error || 'ไม่สามารถอ่านป้ายได้') + debugText + '</div>';
    }
  })
  .catch(error => {
    resultDiv.innerHTML = '<div class="error">ข้อผิดพลาด: ' + htmlEscape(error.message) + '</div>';
  });
}

function htmlEscape(text) {
  var map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Close modal when clicking outside
window.onclick = function(event) {
  var modal = document.getElementById('ocrModal');
  if (event.target === modal) {
    closeOCRModal();
  }
}

// AJAX ลบ vehicle_log
function deleteLogAJAX(id, btn) {
  if (!confirm('ยืนยันการลบบันทึกหรือไม่?')) return;
  btn.disabled = true;
  btn.textContent = 'กำลังลบ...';
  fetch('delete_vehicle_log.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'id=' + encodeURIComponent(id)
  })
  .then(res => res.text())
  .then(txt => {
    if (txt.trim() === 'success') {
      // หาแถว <tr> ที่ปุ่มนี้อยู่ แล้วลบออก
      let tr = btn.closest('tr');
      if (tr) tr.remove();
    } else {
      alert('เกิดข้อผิดพลาดในการลบ');
      btn.disabled = false;
      btn.textContent = 'ลบ';
    }
  })
  .catch(() => {
    alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
    btn.disabled = false;
    btn.textContent = 'ลบ';
  });
}
</script>

<!-- OCR Modal -->
<div id="ocrModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 500px;">
    <div class="modal-header">
      <h2>อ่านป้ายทะเบียนจากรูป</h2>
      <button type="button" class="btn-close" onclick="closeOCRModal()">✕</button>
    </div>
    
    <div class="modal-body">
      <label style="display: block; margin-bottom: 10px; font-weight: bold;">เลือกรูปป้ายทะเบียน</label>
      <input type="file" id="ocrImageInput" accept="image/*" onchange="previewImage(event)" style="margin-bottom: 15px;">
      
      <div id="ocrPreview" style="margin: 15px 0; text-align: center;"></div>
      
      <button type="button" class="btn btn-primary" onclick="extractPlateFromImage()" style="width: 100%; margin-bottom: 10px;">
        🔍 อ่านป้ายจากรูป
      </button>
      
      <div id="ocrResult" style="margin-top: 15px;"></div>
    </div>
  </div>
</div>

<!-- Modal Styles -->
<style>
.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.5);
  align-items: center;
  justify-content: center;
}

.modal-content {
  background-color: var(--bg-color, #1f1f1f);
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
  overflow: hidden;
  animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-50px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px;
  border-bottom: 1px solid var(--border-color, #333);
}

.modal-header h2 {
  margin: 0;
  font-size: 18px;
}

.modal-body {
  padding: 20px;
}

.btn-primary {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border: none;
  padding: 10px 20px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.3s ease;
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.btn-info {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border: none;
  padding: 8px 16px;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 500;
  white-space: nowrap;
}

.btn-info:hover {
  opacity: 0.9;
}
</style>

</body>
</html>
