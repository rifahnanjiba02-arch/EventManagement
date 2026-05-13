<?php

header('Content-Type: application/json');
require 'db.php';

if (!isset($_GET['attendee_id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing attendee ID"]);
    exit;
}

$attendee_id = intval($_GET['attendee_id']);

$sql = "
SELECT 
    e.event_id,
    e.title AS event, 
    e.event_date AS date, 
    e.location, 
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
