<?php
// book_event.php
require_once 'session_bootstrap.php';
require 'db.php';
require_once 'api_helpers.php';
require_once 'event_status_schema.php';

ensureEventStatusSchema($pdo);

$input = decodeJsonRequestBody();

if (($_SESSION['role'] ?? null) !== 'attendee' || !isset($_SESSION['attendee_id'])) {
    jsonResponse(['success' => false, 'error' => 'Please log in as an attendee'], 401);
}

if (!array_key_exists('event_id', $input)) {
    jsonResponse(['success' => false, 'error' => 'Missing event_id'], 400);
}

$event_id = requirePositiveInt($input['event_id'], 'event_id');
$attendee_id = (int) $_SESSION['attendee_id'];

try {
    $stmt = $pdo->prepare("SELECT event_date, event_status FROM eventdetails WHERE event_id = ?");
    $stmt->execute([$event_id]);
    $event = $stmt->fetch();

    if (!$event) {
        jsonResponse(['success' => false, 'error' => 'Event not found'], 404);
    }

    $eventDate = new DateTime($event['event_date']);
    $today = new DateTime('today');

    if ($eventDate < $today) {
        jsonResponse(['success' => false, 'error' => 'Cannot book past events'], 400);
    }

    if (($event['event_status'] ?? 'scheduled') === 'cancelled') {
        jsonResponse(['success' => false, 'error' => 'This event has been cancelled'], 400);
    }

    $check = $pdo->prepare("
        SELECT status
        FROM booking
        WHERE event_id = ? AND attendee_id = ?
        LIMIT 1
    ");
    $check->execute([$event_id, $attendee_id]);
    $existingBooking = $check->fetch();

    if ($existingBooking && $existingBooking['status'] === 'confirmed') {
        jsonResponse(['success' => false, 'error' => 'Already booked for this event'], 409);
    }

    if ($existingBooking && $existingBooking['status'] === 'cancelled') {
        $restore = $pdo->prepare("
            UPDATE booking
            SET status = 'confirmed',
                attendance_status = 'pending',
                booking_time = NOW(),
                cancellation_time = NULL
            WHERE event_id = ? AND attendee_id = ?
        ");
        $restore->execute([$event_id, $attendee_id]);

        jsonSuccess(['message' => 'Booking restored']);
    }

    $insert = $pdo->prepare("
        INSERT INTO booking (event_id, attendee_id, status)
        VALUES (?, ?, 'confirmed')
    ");
    $insert->execute([$event_id, $attendee_id]);

    jsonSuccess(['message' => 'Booking successful']);
} catch (Throwable $e) {
    reportServerException($e, 'Unable to complete the booking right now.');
}
