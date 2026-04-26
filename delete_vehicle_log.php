<?php
// delete_vehicle_log.php
include 'connect.php';

if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $sql = "DELETE FROM vehicle_logs WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        echo 'success';
    } else {
        echo 'error';
    }
} else {
    echo 'invalid';
}
