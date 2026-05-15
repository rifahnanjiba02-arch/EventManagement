<?php
require_once 'session_bootstrap.php';
require 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'organizer' || !isset($_SESSION['organizer_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$organizerId = (int) $_SESSION['organizer_id'];

if ($eventId <= 0) {
    http_response_code(400);
    exit('Missing event_id');
}

$eventStmt = $pdo->prepare("
    SELECT e.event_id, e.title
    FROM EventDetails e
    JOIN create_event ce ON ce.event_id = e.event_id
    WHERE e.event_id = ? AND ce.organizer_id = ?
    LIMIT 1
");
$eventStmt->execute([$eventId, $organizerId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(404);
    exit('Event not found');
}

$attendeesStmt = $pdo->prepare("
    SELECT
        a.attendee_id,
        u.first_name,
        u.last_name,
        u.email,
        b.status AS booking_status,
        b.attendance_status,
        b.booking_time
    FROM Booking b
    JOIN Attendee a ON a.attendee_id = b.attendee_id
    JOIN Users u ON u.user_id = a.user_id
    WHERE b.event_id = ?
    ORDER BY
        CASE WHEN b.status = 'confirmed' THEN 0 ELSE 1 END,
        u.first_name ASC,
        u.last_name ASC,
        u.email ASC
");
$attendeesStmt->execute([$eventId]);
$attendees = $attendeesStmt->fetchAll(PDO::FETCH_ASSOC);

$safeTitle = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($event['title'] ?? 'event'));
$filename = sprintf('event_%d_%s_attendees.csv', $eventId, trim($safeTitle, '_'));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Event ID', 'Event Title', 'Attendee ID', 'First Name', 'Last Name', 'Email', 'Booking Status', 'Attendance Status', 'Booking Time']);

foreach ($attendees as $attendee) {
    fputcsv($output, [
        $event['event_id'],
        $event['title'],
        $attendee['attendee_id'],
        $attendee['first_name'],
        $attendee['last_name'],
        $attendee['email'],
        $attendee['booking_status'],
        $attendee['attendance_status'],
        $attendee['booking_time'],
    ]);
}

fclose($output);
exit;
