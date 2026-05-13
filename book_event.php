<?php
// book_event.php
header('Content-Type: application/json');
require 'db.php';

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!$input || !isset($input['event_id'], $input['attendee_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing event_id or attendee_id']);
    exit;
}

$event_id = intval($input['event_id']);
$attendee_id = intval($input['attendee_id']);

try {
    // Check if event exists and is not in the past
    $stmt = $pdo->prepare("SELECT event_date FROM EventDetails WHERE event_id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        echo json_encode(['success' => false, 'error' => 'Event not found']);
        exit;
    }

    $eventDate = new DateTime($event['event_date']);
    $today = new DateTime('today');

    if ($eventDate < $today) {
        echo json_encode(['success' => false, 'error' => 'Cannot book past events']);
        exit;
    }

    //  Prevent duplicate bookings
    $check = $pdo->prepare("SELECT COUNT(*) FROM Booking WHERE event_id = ? AND attendee_id = ?");
    $check->execute([$event_id, $attendee_id]);

    if ($check->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'Already booked for this event']);
        exit;
    }

    // Insert booking
    $insert = $pdo->prepare("
        INSERT INTO Booking (event_id, attendee_id, status)
        VALUES (?, ?, 'confirmed')
    ");
    $insert->execute([$event_id, $attendee_id]);

    echo json_encode(['success' => true, 'message' => 'Booking successful']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
