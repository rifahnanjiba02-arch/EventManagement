<?php
require 'db.php';
require_once 'api_helpers.php';
require_once 'session_bootstrap.php';
require_once 'event_status_schema.php';

ensureEventStatusSchema($pdo);

if (!isset($_SESSION['attendee_id'])) {
    jsonError('Unauthorized', 401);
}

$attendee_id = requirePositiveInt($_SESSION['attendee_id'], 'attendee_id');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT e.event_id AS id, e.title
            FROM eventdetails e
            INNER JOIN booking b ON e.event_id = b.event_id
            LEFT JOIN feedback f ON f.event_id = e.event_id AND f.attendee_id = b.attendee_id
            WHERE b.attendee_id = ?
              AND b.status = 'confirmed'
              AND COALESCE(e.event_status, 'scheduled') <> 'cancelled'
              AND f.feedback_id IS NULL
              AND (
                b.attendance_status = 'checked_in'
                OR e.event_date < CURDATE()
              )
            ORDER BY e.event_date ASC
        ");
        $stmt->execute([$attendee_id]);
        $events = $stmt->fetchAll();

        jsonResponse($events);
    } catch (Throwable $e) {
        reportServerException($e, 'Failed to fetch events');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = decodeJsonRequestBody();

    if (!isset($input['event_id'], $input['rating'])) {
        jsonError('Missing required fields', 400);
    }

    $event_id = requirePositiveInt($input['event_id'], 'event_id');
    $rating = filter_var($input['rating'], FILTER_VALIDATE_INT);
    $comment = isset($input['comment']) && is_string($input['comment']) ? trim($input['comment']) : '';

    if ($rating === false || $rating < 1 || $rating > 5) {
        jsonError('Invalid input', 400);
    }

    if (strlen($comment) > 500) {
        jsonError('Comment too long (max 500 characters)', 400);
    }

    try {
        $eligibilityStmt = $pdo->prepare("
            SELECT
                e.title,
                EXISTS(
                    SELECT 1
                    FROM feedback f
                    WHERE f.attendee_id = b.attendee_id
                      AND f.event_id = b.event_id
                ) AS has_feedback
            FROM booking b
            INNER JOIN eventdetails e ON e.event_id = b.event_id
            WHERE b.attendee_id = ?
              AND b.event_id = ?
              AND b.status = 'confirmed'
              AND COALESCE(e.event_status, 'scheduled') <> 'cancelled'
              AND (
                b.attendance_status = 'checked_in'
                OR e.event_date < CURDATE()
              )
            LIMIT 1
        ");
        $eligibilityStmt->execute([$attendee_id, $event_id]);
        $eligibleBooking = $eligibilityStmt->fetch();

        if (!$eligibleBooking) {
            jsonError('This event is not eligible for feedback yet', 400);
        }

        if ((int) $eligibleBooking['has_feedback'] === 1) {
            jsonError('Feedback already submitted for this event', 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO feedback (attendee_id, event_id, rating, comment) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$attendee_id, $event_id, $rating, $comment]);

        jsonSuccess(['message' => 'Feedback submitted successfully']);
    } catch (Throwable $e) {
        reportServerException($e, 'Failed to save feedback');
    }
}

jsonError('Method not allowed', 405);
