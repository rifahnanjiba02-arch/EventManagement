<?php
header('Content-Type: application/json');
require 'db.php';
require_once 'session_bootstrap.php';
require_once 'event_status_schema.php';

ensureEventStatusSchema($pdo);

//  Ensure attendee is logged in
if (!isset($_SESSION['attendee_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$attendee_id = $_SESSION['attendee_id'];

//  GET: Fetch eligible booked events for this attendee
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT e.event_id AS id, e.title
            FROM EventDetails e
            INNER JOIN Booking b ON e.event_id = b.event_id
            LEFT JOIN Feedback f ON f.event_id = e.event_id AND f.attendee_id = b.attendee_id
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
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($events);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to fetch events"]);
    }
    exit;
}

//  POST: Submit feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['event_id'], $input['rating'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        exit;
    }

    $event_id = intval($input['event_id']);
    $rating = intval($input['rating']);
    $comment = isset($input['comment']) ? trim($input['comment']) : "";

    //  Validate input
    if ($event_id <= 0 || $rating < 1 || $rating > 5) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid input"]);
        exit;
    }

    if (strlen($comment) > 500) {
        http_response_code(400);
        echo json_encode(["error" => "Comment too long (max 500 characters)"]);
        exit;
    }

    try {
        $eligibilityStmt = $pdo->prepare("
            SELECT
                e.title,
                EXISTS(
                    SELECT 1
                    FROM Feedback f
                    WHERE f.attendee_id = b.attendee_id
                      AND f.event_id = b.event_id
                ) AS has_feedback
            FROM Booking b
            INNER JOIN EventDetails e ON e.event_id = b.event_id
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
        $eligibleBooking = $eligibilityStmt->fetch(PDO::FETCH_ASSOC);

        if (!$eligibleBooking) {
            http_response_code(400);
            echo json_encode(["error" => "This event is not eligible for feedback yet"]);
            exit;
        }

        if ((int) $eligibleBooking['has_feedback'] === 1) {
            http_response_code(400);
            echo json_encode(["error" => "Feedback already submitted for this event"]);
            exit;
        }

        // Insert feedback
        $stmt = $pdo->prepare("
            INSERT INTO Feedback (attendee_id, event_id, rating, comment) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$attendee_id, $event_id, $rating, $comment]);

        echo json_encode(["success" => true, "message" => "Feedback submitted successfully"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to save feedback"]);
    }
    exit;
}

// Invalid method
http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
exit;
