<?php
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
    // Check if booking exists and whether it is still eligible for cancellation.
    $check = $pdo->prepare("
        SELECT b.status, b.attendance_status, e.event_date, e.event_status
        FROM booking b
        JOIN eventdetails e ON e.event_id = b.event_id
        WHERE b.event_id = ? AND b.attendee_id = ?
    ");
    $check->execute([$event_id, $attendee_id]);
    $booking = $check->fetch();

    if (!$booking) {
        jsonResponse(['success' => false, 'error' => 'Booking not found'], 404);
    }

    if ($booking['status'] === 'cancelled') {
        jsonResponse(['success' => false, 'error' => 'Booking already cancelled'], 409);
    }

    if ($booking['status'] !== 'confirmed') {
        jsonResponse(['success' => false, 'error' => 'Only confirmed bookings can be cancelled'], 400);
    }

    if (($booking['attendance_status'] ?? 'pending') === 'checked_in') {
        jsonResponse(['success' => false, 'error' => 'Checked-in bookings cannot be cancelled'], 400);
    }

    if (($booking['event_status'] ?? 'scheduled') === 'cancelled') {
        jsonResponse(['success' => false, 'error' => 'This event has already been cancelled by the organizer'], 400);
    }

    $eventDate = new DateTime($booking['event_date']);
    $today = new DateTime('today');
    if ($eventDate->format('Y-m-d') <= $today->format('Y-m-d')) {
        jsonResponse(['success' => false, 'error' => 'Only future bookings can be cancelled'], 400);
    }

    $stmt = $pdo->prepare("
        UPDATE booking 
        SET status = 'cancelled', cancellation_time = NOW() 
        WHERE event_id = ? AND attendee_id = ?
    ");
    $stmt->execute([$event_id, $attendee_id]);

    jsonSuccess();
} catch (Throwable $e) {
    reportServerException($e, 'Unable to cancel the booking right now.');
}
