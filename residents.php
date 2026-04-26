<?php
session_start();
include "connect.php";

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$errorMessage = '';
$successMessage = '';

// ห้องทั้งหมด
$totalRooms = 100;
// ห้องที่ถูกใช้แล้ว
$usedRooms = 0;
$result = $conn->query("SELECT COUNT(DISTINCT room_number) AS used_rooms FROM residents");
if ($result) {
    $row = $result->fetch_assoc();
    $usedRooms = (int)$row['used_rooms'];
}
$availableRooms = $totalRooms - $usedRooms;

// Add resident (ไม่ให้เกิน 100 ห้อง)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_resident') {
    $name = trim($_POST['name'] ?? '');
    $room = trim($_POST['room_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($usedRooms >= $totalRooms) {
        $errorMessage = 'ไม่สามารถเพิ่มผู้พักอาศัยได้ ห้องครบ 100 ห้องแล้ว';
    } elseif ($name && $room) {
        $stmt = $conn->prepare("INSERT INTO residents (name, room_number, phone) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('sss', $name, $room, $phone);
            if ($stmt->execute()) {
                $successMessage = '✓ เพิ่มข้อมูลผู้พักอาศัยสำเร็จ';
            } else {
                $errorMessage = 'เลขห้องนี้มีอยู่แล้ว';
            }
            $stmt->close();
        }
    } else {
        $errorMessage = 'กรุณากรอกชื่อและเลขห้อง';
    }
}

// Delete resident
if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    $stmt = $conn->prepare("DELETE FROM residents WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $successMessage = '✓ ลบข้อมูลผู้พักอาศัยสำเร็จ';
    }
}

// Edit resident
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_resident') {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $room = trim($_POST['room_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    if ($id && $name && $room) {
        $stmt = $conn->prepare("UPDATE residents SET name = ?, room_number = ?, phone = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('sssi', $name, $room, $phone, $id);
            if ($stmt->execute()) {
                $successMessage = '✓ อัปเดตข้อมูลผู้พักอาศัยสำเร็จ';
            } else {
                $errorMessage = 'เกิดข้อผิดพลาด';
            }
            $stmt->close();
        }
    }
}

// Get all residents พร้อมจำนวนรถแต่ละคน
$residents = [];
$result = $conn->query("SELECT r.id, r.name, r.room_number, r.phone, COUNT(av.id) AS car_count FROM residents r LEFT JOIN authorized_vehicles av ON av.resident_id = r.id GROUP BY r.id ORDER BY r.room_number ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $residents[] = $row;
    }
}

// Get vehicles for a resident
$selectedResidentId = intval($_GET['resident_id'] ?? 0);
$resident_vehicles = [];
if ($selectedResidentId) {
    $stmt = $conn->prepare("SELECT id, plate_number, brand, color FROM authorized_vehicles WHERE resident_id = ? ORDER BY id DESC");
    if ($stmt) {
        $stmt->bind_param('i', $selectedResidentId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $resident_vehicles[] = $row;
        }
        $stmt->close();
    }
}

// Add vehicle to resident (สูงสุดห้องละ 2 คัน)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_vehicle') {
    $residentId = intval($_POST['resident_id'] ?? 0);
    $plate = trim($_POST['plate_number'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $color = trim($_POST['color'] ?? '');
    if ($residentId && $plate) {
        // ตรวจสอบจำนวนรถของห้องนี้
        $stmt = $conn->prepare("SELECT r.room_number, COUNT(av.id) FROM residents r LEFT JOIN authorized_vehicles av ON av.resident_id = r.id WHERE r.id = ? GROUP BY r.room_number");
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $stmt->bind_result($roomNumber, $carCount);
        $stmt->fetch();
        $stmt->close();
        if ($carCount >= 3) {
            $errorMessage = '🚫 เพิ่มรถไม่สำเร็จ: ห้องนี้มีรถครบ 3 คันตามที่กำหนดแล้ว';
        } else {
            $stmt = $conn->prepare("INSERT INTO authorized_vehicles (resident_id, plate_number, brand, color) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('isss', $residentId, $plate, $brand, $color);
                if ($stmt->execute()) {
                    $successMessage = '✓ เพิ่มรถสำเร็จ';
                    header("Location: residents.php?resident_id=$residentId");
                    exit;
                } else {
                    $errorMessage = 'ไม่สามารถเพิ่มรถได้';
                }
                $stmt->close();
            }
        }
    } else {
        $errorMessage = 'กรุณากรอกป้ายทะเบียน';
    }
}
// --- Parking summary ---
$totalParkingSpots = 250;
$carsParkedNow = 0;
$result = $conn->query("SELECT COUNT(*) AS cnt FROM vehicle_logs WHERE time_out IS NULL");
if ($result) {
    $carsParkedNow = (int)$result->fetch_assoc()['cnt'];
}
$availableParkingSpots = $totalParkingSpots - $carsParkedNow;

// --- For selected resident: count vehicle_logs (เข้า-ออกแยก) และทะเบียนรถที่ยังจอดอยู่ ---
$roomEntryInCount = 0;
$roomEntryOutCount = 0;
$roomParkedPlates = [];
if ($selectedResidentId) {
    // ดึงเลขห้องของ resident นี้
    $stmt = $conn->prepare("SELECT room_number FROM residents WHERE id = ?");
    $stmt->bind_param('i', $selectedResidentId);
    $stmt->execute();
    $stmt->bind_result($selRoomNumber);
    $stmt->fetch();
    $stmt->close();
    if ($selRoomNumber) {
        // นับจำนวนเข้า
        $stmt = $conn->prepare("SELECT COUNT(*) FROM vehicle_logs WHERE room_number = ? AND time_in IS NOT NULL");
        $stmt->bind_param('s', $selRoomNumber);
        $stmt->execute();
        $stmt->bind_result($roomEntryInCount);
        $stmt->fetch();
        $stmt->close();
        // นับจำนวนออก
        $stmt = $conn->prepare("SELECT COUNT(*) FROM vehicle_logs WHERE room_number = ? AND time_out IS NOT NULL");
        $stmt->bind_param('s', $selRoomNumber);
        $stmt->execute();
        $stmt->bind_result($roomEntryOutCount);
        $stmt->fetch();
        $stmt->close();
        // ทะเบียนรถที่ยังจอดอยู่
        $stmt = $conn->prepare("SELECT plate_number FROM vehicle_logs WHERE room_number = ? AND time_out IS NULL");
        $stmt->bind_param('s', $selRoomNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $roomParkedPlates[] = $row['plate_number'];
        }
        $stmt->close();
    }
}

// Delete vehicle
if (isset($_GET['delete_vehicle_id'])) {
    $vehicleId = intval($_GET['delete_vehicle_id']);
    $stmt = $conn->prepare("SELECT resident_id FROM authorized_vehicles WHERE id = ?");
    $stmt->bind_param('i', $vehicleId);
    $stmt->execute();
    $stmt->bind_result($resId);
    $stmt->fetch();
    $stmt->close();
    
    $delStmt = $conn->prepare("DELETE FROM authorized_vehicles WHERE id = ?");
    $delStmt->bind_param('i', $vehicleId);
    $delStmt->execute();
    $delStmt->close();
    
    $successMessage = '✓ ลบรถสำเร็จ';
    header("Location: residents.php?resident_id=$resId");
    exit;
}

// Get edit data if needed
$editId = intval($_GET['edit_id'] ?? 0);
$editData = null;
if ($editId) {
    $stmt = $conn->prepare("SELECT id, name, room_number, phone FROM residents WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $editData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ข้อมูลผู้พักอาศัย | ระบบตรวจจับป้ายทะเบียน</title>
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
      <a href="history.php">
        <span class="icon">📜</span>
        <span>ประวัติการเข้า-ออก</span>
      </a>
            <a href="residents.php" class="active">
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
    <div class="title">ข้อมูลผู้พักอาศัย</div>
      <div class="actions">
        <span>ผู้ใช้งาน: <?php echo htmlspecialchars($_SESSION['user']); ?></span>
      </div>
    </header>

    <div class="content residents-page">

            <div class="notice-stack">
                <?php if ($successMessage): ?>
                    <div class="success"><?php echo $successMessage; ?></div>
                <?php endif; ?>
                <?php if ($errorMessage): ?>
                    <div class="error"><?php echo $errorMessage; ?></div>
                    <script>setTimeout(function(){ alert(<?php echo json_encode($errorMessage); ?>); }, 200);</script>
                <?php endif; ?>
            </div>

            <!-- Add/Edit Resident Form -->
            <div class="card residents-card">
        <div class="section-header">
          <h2><?php echo $editData ? '✏️ แก้ไขข้อมูลผู้พักอาศัย' : '➕ เพิ่มผู้พักอาศัยใหม่'; ?></h2>
          <?php if ($editData): ?>
            <a href="residents.php" class="btn btn-neutral btn-close-inline">ปิดฟอร์ม</a>
          <?php endif; ?>
        </div>
        
        <form method="POST" class="resident-form">
            <input type="hidden" name="action" value="<?php echo $editData ? 'edit_resident' : 'add_resident'; ?>">
            
            <?php if ($editData): ?>
                <input type="hidden" name="id" value="<?php echo $editData['id']; ?>">
            <?php endif; ?>

            <div class="resident-form-grid">
                <div class="form-group">
                    <label>ชื่อผู้พักอาศัย</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($editData['name'] ?? ''); ?>" placeholder="เช่น สมชาย ใจดี" required>
                </div>

                <div class="form-group">
                    <label>เลขห้อง</label>
                    <input type="text" name="room_number" value="<?php echo htmlspecialchars($editData['room_number'] ?? ''); ?>" placeholder="เช่น 101" required>
                </div>

                <div class="form-group">
                    <label>เบอร์โทรศัพท์</label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($editData['phone'] ?? ''); ?>" placeholder="เช่น 0812345678">
                </div>
            </div>

            <div class="resident-actions">
                <button type="submit" class="btn btn-enter">
                    <?php echo $editData ? '💾 บันทึกการแก้ไข' : '➕ เพิ่มผู้พักอาศัย'; ?>
                </button>
                <?php if ($editData): ?>
                    <a href="residents.php" class="btn btn-neutral">ยกเลิก</a>
                <?php endif; ?>
            </div>
        </form>
      </div>

      <!-- Residents Summary & List -->
      <div class="card residents-card">
        <h2>📋 รายชื่อผู้พักอาศัย</h2>
        <div style="margin-bottom: 16px;">
          <strong>จำนวนห้องทั้งหมด:</strong> <?php echo $totalRooms; ?> |
          <strong>ห้องที่มีผู้พักอาศัย:</strong> <?php echo $usedRooms; ?> |
          <strong>ห้องว่าง:</strong> <?php echo $availableRooms; ?> 
        </div>
        <?php if (empty($residents)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">👤</div>
                <p>ยังไม่มีข้อมูลผู้พักอาศัย</p>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ชื่อ</th>
                        <th>เลขห้อง</th>
                        <th>เบอร์โทร</th>
                        <th>จำนวนรถ</th>
                        <th style="text-align: center;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($residents as $resident): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($resident['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($resident['room_number']); ?></td>
                            <td><?php echo htmlspecialchars($resident['phone'] ?: '-'); ?></td>
                            <td style="text-align:center;">🚗 <?php echo (int)$resident['car_count']; ?></td>
                            <td class="resident-table-actions">
                                <a href="?resident_id=<?php echo $resident['id']; ?>" class="action-pill action-vehicle">🚗 จัดการรถ</a>
                                <a href="?edit_id=<?php echo $resident['id']; ?>" class="action-pill action-edit">✏️ แก้ไข</a>
                                <a href="?delete_id=<?php echo $resident['id']; ?>" class="action-pill action-delete" onclick="return confirm('ยืนยันการลบหรือไม่?')">🗑️ ลบ</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
      </div>

      <!-- Vehicle Management Section -->
      <?php if ($selectedResidentId): ?>
        <?php 
            $selectedResident = null;
            foreach ($residents as $residentItem) {
                if ((int)$residentItem['id'] === (int)$selectedResidentId) {
                    $selectedResident = $residentItem;
                    break;
                }
            }
        ?>
        
        <div class="card residents-card">
          <div class="section-header">
            <h2>🚗 จัดการรถของผู้พักอาศัย</h2>
            <a href="residents.php" class="btn btn-neutral btn-close-inline">ปิดฟอร์ม</a>
          </div>
          <div class="resident-focus">
            <h3>🚗 รถของผู้พักอาศัย</h3>
            <div class="resident-focus-name"><?php echo htmlspecialchars($selectedResident['name'] ?? ''); ?></div>
            <div class="resident-focus-meta">ห้อง <?php echo htmlspecialchars($selectedResident['room_number'] ?? ''); ?> • โทร <?php echo htmlspecialchars($selectedResident['phone'] ?? '-'); ?></div>
          </div>

                    <div style="margin-bottom: 12px;">
                        <strong>ที่จอดทั้งหมด:</strong> <?php echo $totalParkingSpots; ?> |
                        <strong>ที่จอดว่าง:</strong> <?php echo $availableParkingSpots; ?> |
                        <strong>รถที่จอดอยู่:</strong> <?php echo $carsParkedNow; ?> 
                        <?php if ($selectedResidentId): ?>|
                            🚗 <strong>รถของห้องนี้เข้าแล้ว:</strong> <?php echo $roomEntryInCount; ?> ครั้ง |
                            🚙 <strong>รถของห้องนี้ออกแล้ว:</strong> <?php echo $roomEntryOutCount; ?> ครั้ง |
                            <?php if (count($roomParkedPlates) > 0): ?>
                                🚘 <strong>รถที่ยังจอดอยู่:</strong> <?php echo htmlspecialchars(implode(', ', $roomParkedPlates)); ?>
                            <?php else: ?>
                                🅿️ <strong>ขณะนี้ไม่มีรถของห้องนี้จอดอยู่ในลานจอด</strong>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <h3 class="subheading">➕ เพิ่มรถใหม่</h3>
          <form method="POST" class="resident-form vehicle-form">
              <input type="hidden" name="action" value="add_vehicle">
              <input type="hidden" name="resident_id" value="<?php echo $selectedResidentId; ?>">

              <div class="resident-form-grid">
                <div class="form-group">
                    <label>ป้ายทะเบียน *</label>
                    <input type="text" name="plate_number" placeholder="เช่น กข 1234" required>
                </div>

                <div class="form-group">
                    <label>ยี่ห้อ/รุ่น</label>
                    <input type="text" name="brand" placeholder="เช่น Honda Civic">
                </div>

                <div class="form-group">
                    <label>สี</label>
                    <input type="text" name="color" placeholder="เช่น ขาว">
                </div>
              </div>
              
              <button type="submit" class="btn btn-enter">เพิ่มรถ</button>
          </form>

          <h3 class="subheading">📊 รถทั้งหมด</h3>
          <?php if (empty($resident_vehicles)): ?>
              <div class="empty-state small">
                  <p>ยังไม่มีรถลงทะเบียน</p>
              </div>
          <?php else: ?>
              <table class="data-table">
                  <thead>
                      <tr>
                          <th>ป้ายทะเบียน</th>
                          <th>ยี่ห้อ/รุ่น</th>
                          <th>สี</th>
                          <th style="text-align: center;">จัดการ</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php foreach ($resident_vehicles as $vehicle): ?>
                          <tr>
                              <td><strong><?php echo htmlspecialchars($vehicle['plate_number']); ?></strong></td>
                              <td><?php echo htmlspecialchars($vehicle['brand'] ?: '-'); ?></td>
                              <td><?php echo htmlspecialchars($vehicle['color'] ?: '-'); ?></td>
                              <td class="resident-table-actions">
                                  <a href="?resident_id=<?php echo $selectedResidentId; ?>&delete_vehicle_id=<?php echo $vehicle['id']; ?>" class="action-pill action-delete" onclick="return confirm('ยืนยันการลบหรือไม่?')">🗑️ ลบ</a>
                              </td>
                          </tr>
                      <?php endforeach; ?>
                  </tbody>
              </table>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

</body>
</html>
