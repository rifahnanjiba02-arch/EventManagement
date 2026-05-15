<?php

require_once 'session_bootstrap.php';
header('Content-Type: application/json');
require 'db.php';

if (($_SESSION['role'] ?? null) !== 'attendee' || !isset($_SESSION['attendee_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Please log in as an attendee"]);
    exit;
}

$attendee_id = (int) $_SESSION['attendee_id'];

$sql = "
SELECT 
    e.event_id,
    e.title AS event, 
    e.event_date AS date, 
    e.location, 
    b.booking_time,
    b.cancellation_time,
    b.status, 
    b.attendance_status AS attendance,
    (SELECT COUNT(*) FROM Booking WHERE event_id = e.event_id) AS total_participants
FROM Booking b
JOIN EventDetails e ON b.event_id = e.event_id
WHERE b.attendee_id = ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$attendee_id]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($bookings);
?>
