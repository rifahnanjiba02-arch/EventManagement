<?php
require 'db.php';
require_once 'api_helpers.php';
require_once 'event_status_schema.php';

ensureEventStatusSchema($pdo);

$today = date('Y-m-d');

try {
    $upcomingStmt = $pdo->prepare("
        SELECT event_id AS id, title, type, event_date, location
        FROM eventdetails
        WHERE event_date >= ? AND event_status = 'scheduled'
        ORDER BY event_date ASC
    ");
    $upcomingStmt->execute([$today]);
    $upcomingEvents = $upcomingStmt->fetchAll();

    $previousStmt = $pdo->prepare("
        SELECT event_id AS id, title, type, event_date, location
        FROM eventdetails
        WHERE event_date < ? AND event_status = 'scheduled'
        ORDER BY event_date DESC
    ");
    $previousStmt->execute([$today]);
    $previousEvents = $previousStmt->fetchAll();

    jsonResponse([
        "upcomingEvents" => $upcomingEvents,
        "previousEvents" => $previousEvents
    ]);
} catch (Throwable $e) {
    reportServerException($e, 'Failed to fetch events');
}
