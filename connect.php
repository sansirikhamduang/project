<?php

mysqli_report(MYSQLI_REPORT_OFF);

// ใช้ค่ามาตรฐานของ XAMPP
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = '';
$dbName = 'parking_db'; // ถ้าชื่อฐานข้อมูลไม่ใช่ parking_db ให้เปลี่ยนตรงนี้
$dbPort = 3307; // หรือจะไม่ใส่ก็ได้

$conn = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

if(!$conn){
    die("Connection failed: " . mysqli_connect_error());
}

// Ensure required tables exist
@$conn->query(
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

@$conn->query(
    "CREATE TABLE IF NOT EXISTS authorized_vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plate_number VARCHAR(100) NOT NULL UNIQUE,
        owner_name VARCHAR(255) DEFAULT NULL,
        brand VARCHAR(255) DEFAULT NULL,
        color VARCHAR(100) DEFAULT NULL,
        resident_id INT DEFAULT NULL,
        note TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

@$conn->query(
    "CREATE TABLE IF NOT EXISTS vehicle_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plate_number VARCHAR(100) NOT NULL,
        plate_image VARCHAR(255) DEFAULT NULL,
        time_in DATETIME NOT NULL,
        time_out DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Create residents table if not exists
@$conn->query(
    "CREATE TABLE IF NOT EXISTS residents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        room_number VARCHAR(50) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Ensure columns exist for legacy schemas
@$conn->query("ALTER TABLE authorized_vehicles ADD COLUMN IF NOT EXISTS brand VARCHAR(255) DEFAULT NULL");
@$conn->query("ALTER TABLE authorized_vehicles ADD COLUMN IF NOT EXISTS color VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE authorized_vehicles ADD COLUMN IF NOT EXISTS resident_id INT DEFAULT NULL");
@$conn->query("ALTER TABLE authorized_vehicles ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
@$conn->query("ALTER TABLE vehicle_logs ADD COLUMN IF NOT EXISTS plate_image VARCHAR(255) DEFAULT NULL");
@$conn->query("ALTER TABLE vehicle_logs ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");

?>