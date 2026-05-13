<?php
// update_attendance.php
header('Content-Type: application/json');
require 'db.php';

// Get JSON POST input
$input = json_decode(file_get_contents('php://input'), true);

if (
    !$input ||
    !isset($input['attendee_id'], $input['event_id'], $input['attendance_status'])
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$attendee_id = intval($input['attendee_id']);
$event_id = intval($input['event_id']);
$attendance_status = $input['attendance_status'];

// Validate attendance status
$valid_statuses = ['pending', 'checked_in', 'no-show'];
if (!in_array($attendance_status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid attendance status']);
    exit;
}

try {
    // Check if booking exists and status is confirmed
    $stmt = $pdo->prepare("SELECT status FROM Booking WHERE attendee_id = ? AND event_id = ?");
    $stmt->execute([$attendee_id, $event_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Booking not found']);
        exit;
    }

    if ($booking['status'] !== 'confirmed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Booking not confirmed']);
        exit;
    }

    // Update attendance status
    $update = $pdo->prepare("UPDATE Booking SET attendance_status = ? WHERE attendee_id = ? AND event_id = ?");
    $update->execute([$attendance_status, $attendee_id, $event_id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
