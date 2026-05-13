<?php
header('Content-Type: application/json');
require 'db.php';
session_start();

//  Ensure attendee is logged in
if (!isset($_SESSION['attendee_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$attendee_id = $_SESSION['attendee_id'];

//  GET: Fetch upcoming booked events for this attendee
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT e.event_id AS id, e.title
            FROM EventDetails e
            INNER JOIN Booking b ON e.event_id = b.event_id
            WHERE b.attendee_id = ?
              AND b.status = 'confirmed'
              AND e.event_date >= CURDATE()
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
        // Check if event exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM EventDetails WHERE event_id = ?");
        $stmt->execute([$event_id]);
        if ($stmt->fetchColumn() == 0) {
            http_response_code(400);
            echo json_encode(["error" => "Event does not exist"]);
            exit;
        }

        // Prevent duplicate feedback
        $checkFeedback = $pdo->prepare("
            SELECT COUNT(*) FROM Feedback 
            WHERE attendee_id = ? AND event_id = ?
        ");
        $checkFeedback->execute([$attendee_id, $event_id]);
        if ($checkFeedback->fetchColumn() > 0) {
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
