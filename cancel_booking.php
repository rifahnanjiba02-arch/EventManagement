<?php
require_once 'session_bootstrap.php';
header('Content-Type: application/json');
require 'db.php';
require_once 'event_status_schema.php';

ensureEventStatusSchema($pdo);

// Decode JSON from request body
$input = json_decode(file_get_contents('php://input'), true);

if (($_SESSION['role'] ?? null) !== 'attendee' || !isset($_SESSION['attendee_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in as an attendee']);
    exit;
}

if (!$input || !isset($input['event_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing event_id']);
    exit;
}

$event_id = intval($input['event_id']);
$attendee_id = (int) $_SESSION['attendee_id'];

try {
    // Check if booking exists and whether it is still eligible for cancellation.
    $check = $pdo->prepare("
        SELECT b.status, b.attendance_status, e.event_date, e.event_status
        FROM Booking b
        JOIN EventDetails e ON e.event_id = b.event_id
        WHERE b.event_id = ? AND b.attendee_id = ?
    ");
    $check->execute([$event_id, $attendee_id]);
    $booking = $check->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Booking not found']);
        exit;
    }

    if ($booking['status'] === 'cancelled') {
        echo json_encode(['success' => false, 'error' => 'Booking already cancelled']);
        exit;
    }

    if ($booking['status'] !== 'confirmed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only confirmed bookings can be cancelled']);
        exit;
    }

    if (($booking['attendance_status'] ?? 'pending') === 'checked_in') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Checked-in bookings cannot be cancelled']);
        exit;
    }

    if (($booking['event_status'] ?? 'scheduled') === 'cancelled') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'This event has already been cancelled by the organizer']);
        exit;
    }

    $eventDate = new DateTime($booking['event_date']);
    $today = new DateTime('today');
    if ($eventDate->format('Y-m-d') <= $today->format('Y-m-d')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only future bookings can be cancelled']);
        exit;
    }

    // Update booking to cancelled
    $stmt = $pdo->prepare("
        UPDATE Booking 
        SET status = 'cancelled', cancellation_time = NOW() 
        WHERE event_id = ? AND attendee_id = ?
    ");
    $stmt->execute([$event_id, $attendee_id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>
