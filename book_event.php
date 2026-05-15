<?php
// book_event.php
require_once 'session_bootstrap.php';
header('Content-Type: application/json');
require 'db.php';
require_once 'event_status_schema.php';

ensureEventStatusSchema($pdo);

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (($_SESSION['role'] ?? null) !== 'attendee' || !isset($_SESSION['attendee_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please log in as an attendee']);
    exit;
}

// Validate required fields
if (!$input || !isset($input['event_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing event_id']);
    exit;
}

$event_id = intval($input['event_id']);
$attendee_id = (int) $_SESSION['attendee_id'];

try {
    // Check if event exists and is not in the past
    $stmt = $pdo->prepare("SELECT event_date, event_status FROM EventDetails WHERE event_id = ?");
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

    if (($event['event_status'] ?? 'scheduled') === 'cancelled') {
        echo json_encode(['success' => false, 'error' => 'This event has been cancelled']);
        exit;
    }

    // Allow a cancelled booking to be restored, but block duplicate active bookings.
    $check = $pdo->prepare("
        SELECT status
        FROM Booking
        WHERE event_id = ? AND attendee_id = ?
        LIMIT 1
    ");
    $check->execute([$event_id, $attendee_id]);
    $existingBooking = $check->fetch(PDO::FETCH_ASSOC);

    if ($existingBooking && $existingBooking['status'] === 'confirmed') {
        echo json_encode(['success' => false, 'error' => 'Already booked for this event']);
        exit;
    }

    if ($existingBooking && $existingBooking['status'] === 'cancelled') {
        $restore = $pdo->prepare("
            UPDATE Booking
            SET status = 'confirmed',
                attendance_status = 'pending',
                booking_time = NOW(),
                cancellation_time = NULL
            WHERE event_id = ? AND attendee_id = ?
        ");
        $restore->execute([$event_id, $attendee_id]);

        echo json_encode(['success' => true, 'message' => 'Booking restored']);
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
