<?php
// update_attendance.php
require_once 'session_bootstrap.php';
header('Content-Type: application/json');
require 'db.php';

// Get JSON POST input
$input = json_decode(file_get_contents('php://input'), true);

if (($_SESSION['role'] ?? null) !== 'attendee' || !isset($_SESSION['attendee_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in as an attendee']);
    exit;
}

if (!$input || !isset($input['event_id'], $input['attendance_status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$attendee_id = (int) $_SESSION['attendee_id'];
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
    // Check if booking exists, is confirmed, and the attendance action fits the event date.
    $stmt = $pdo->prepare("
        SELECT b.status, b.attendance_status, e.event_date
        FROM Booking b
        JOIN EventDetails e ON e.event_id = b.event_id
        WHERE b.attendee_id = ? AND b.event_id = ?
    ");
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

    if ($booking['attendance_status'] === $attendance_status) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Attendance already updated']);
        exit;
    }

    $eventDate = new DateTime($booking['event_date']);
    $today = new DateTime('today');

    if ($attendance_status === 'checked_in' && $eventDate->format('Y-m-d') !== $today->format('Y-m-d')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Check-in is only available on the event date']);
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
