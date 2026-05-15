<?php
// get_events.php
header('Content-Type: application/json');
require 'db.php';  // Your PDO connection
require_once 'event_status_schema.php';

ensureEventStatusSchema($pdo);

$today = date('Y-m-d');

try {
    // Fetch upcoming events (event_date >= today)
    $upcomingStmt = $pdo->prepare("
        SELECT event_id AS id, title, type, event_date, location
        FROM EventDetails
        WHERE event_date >= ? AND event_status = 'scheduled'
        ORDER BY event_date ASC
    ");
    $upcomingStmt->execute([$today]);
    $upcomingEvents = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch previous events (event_date < today)
    $previousStmt = $pdo->prepare("
        SELECT event_id AS id, title, type, event_date, location
        FROM EventDetails
        WHERE event_date < ? AND event_status = 'scheduled'
        ORDER BY event_date DESC
    ");
    $previousStmt->execute([$today]);
    $previousEvents = $previousStmt->fetchAll(PDO::FETCH_ASSOC);

    // Return JSON object with both arrays
    echo json_encode([
        "upcomingEvents" => $upcomingEvents,
        "previousEvents" => $previousEvents
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to fetch events"]);
    exit;
}
?>
