<?php
header('Content-Type: application/json');
require 'db.php';

// Decode JSON from request body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['event_id'], $input['attendee_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing event_id or attendee_id']);
    exit;
}

$event_id = intval($input['event_id']);
$attendee_id = intval($input['attendee_id']);

try {
    // Check if booking exists and is currently confirmed
    $check = $pdo->prepare("SELECT status FROM Booking WHERE event_id = ? AND attendee_id = ?");
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
