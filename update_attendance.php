<?php
// update_attendance.php
require_once 'session_bootstrap.php';
require 'db.php';
require_once 'api_helpers.php';
require_once 'event_status_schema.php';

ensureEventStatusSchema($pdo);

$input = decodeJsonRequestBody();

if (($_SESSION['role'] ?? null) !== 'attendee' || !isset($_SESSION['attendee_id'])) {
    jsonResponse(['success' => false, 'error' => 'Please log in as an attendee'], 401);
}

if (!isset($input['event_id'], $input['attendance_status'])) {
    jsonResponse(['success' => false, 'error' => 'Missing parameters'], 400);
}

$attendee_id = (int) $_SESSION['attendee_id'];
$event_id = requirePositiveInt($input['event_id'], 'event_id');
$attendance_status = is_string($input['attendance_status']) ? trim($input['attendance_status']) : '';

$valid_statuses = ['pending', 'checked_in', 'no-show'];
if (!in_array($attendance_status, $valid_statuses, true)) {
    jsonResponse(['success' => false, 'error' => 'Invalid attendance status'], 400);
}

try {
    // Check if booking exists, is confirmed, and the attendance action fits the event date.
    $stmt = $pdo->prepare("
        SELECT b.status, b.attendance_status, e.event_date, e.event_status
        FROM booking b
        JOIN eventdetails e ON e.event_id = b.event_id
        WHERE b.attendee_id = ? AND b.event_id = ?
    ");
    $stmt->execute([$attendee_id, $event_id]);
    $booking = $stmt->fetch();

    if (!$booking) {
        jsonResponse(['success' => false, 'error' => 'Booking not found'], 404);
    }

    if ($booking['status'] !== 'confirmed') {
        jsonResponse(['success' => false, 'error' => 'Booking not confirmed'], 400);
    }

    if (($booking['event_status'] ?? 'scheduled') === 'cancelled') {
        jsonResponse(['success' => false, 'error' => 'Cancelled events cannot be checked into'], 400);
    }

    if ($booking['attendance_status'] === $attendance_status) {
        jsonResponse(['success' => false, 'error' => 'Attendance already updated'], 400);
    }

    $eventDate = new DateTime($booking['event_date']);
    $today = new DateTime('today');

    if ($attendance_status === 'checked_in' && $eventDate->format('Y-m-d') !== $today->format('Y-m-d')) {
        jsonResponse(['success' => false, 'error' => 'Check-in is only available on the event date'], 400);
    }

    $update = $pdo->prepare("UPDATE booking SET attendance_status = ? WHERE attendee_id = ? AND event_id = ?");
    $update->execute([$attendance_status, $attendee_id, $event_id]);

    jsonSuccess();
} catch (Throwable $e) {
    reportServerException($e, 'Unable to update attendance right now.');
}
