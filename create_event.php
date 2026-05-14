<?php
require 'db.php';
require_once 'session_bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Return JSON list of organizers if fetch_organizers is requested ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_organizers'])) {
    try {
        // Get current organizer_id to exclude from collaborators list
        $stmtSelf = $pdo->prepare("SELECT organizer_id FROM Organizer WHERE user_id = ?");
        $stmtSelf->execute([$_SESSION['user_id']]);
        $self = $stmtSelf->fetch(PDO::FETCH_ASSOC);

        if (!$self) {
            http_response_code(403);
            echo json_encode(['error' => 'Organizer not found']);
            exit;
        }

        // Fetch all other organizers except the current logged-in organizer
        $stmt = $pdo->prepare("
            SELECT o.organizer_id, u.first_name, u.last_name, u.email
            FROM Organizer o
            JOIN Users u ON o.user_id = u.user_id
            WHERE u.role = 'organizer' AND o.organizer_id != ?
        ");
        $stmt->execute([$self['organizer_id']]);
        $organizers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($organizers);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        exit;
    }
}

// Handle event creation when form is submitted ===
header('Content-Type: text/plain'); // Reset content-type for form POST

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

if (!isset($_POST['title'], $_POST['type'], $_POST['date'], $_POST['location'])) {
    http_response_code(400);
    echo "Missing required fields";
    exit;
}

$title = trim($_POST['title']);
$type = trim($_POST['type']);
$date = $_POST['date'];
$location = trim($_POST['location']);

if (!strtotime($date)) {
    http_response_code(400);
    echo "Invalid date format";
    exit;
}

// Get current organizer_id
$stmtOrg = $pdo->prepare("SELECT organizer_id FROM Organizer WHERE user_id = ?");
$stmtOrg->execute([$_SESSION['user_id']]);
$currentOrganizer = $stmtOrg->fetch(PDO::FETCH_ASSOC);

if (!$currentOrganizer) {
    http_response_code(400);
    echo "Organizer not found";
    exit;
}

try {
    $pdo->beginTransaction();

    
    $stmt = $pdo->prepare("INSERT INTO EventDetails (title, type, event_date, location) VALUES (?, ?, ?, ?)");
    $stmt->execute([$title, $type, $date, $location]);
    $event_id = $pdo->lastInsertId();

    // Link current organizer to event
    $stmtLink = $pdo->prepare("INSERT INTO create_event (organizer_id, event_id) VALUES (?, ?)");
    $stmtLink->execute([$currentOrganizer['organizer_id'], $event_id]);

    // Link collaborators if any
    if (!empty($_POST['collaborators']) && is_array($_POST['collaborators'])) {
        foreach ($_POST['collaborators'] as $collaborator_id) {
            $collab_id = (int)$collaborator_id;
            if ($collab_id !== (int)$currentOrganizer['organizer_id']) {
                $check = $pdo->prepare("SELECT 1 FROM Organizer WHERE organizer_id = ?");
                $check->execute([$collab_id]);
                if ($check->fetch()) {
                    $stmtLink->execute([$collab_id, $event_id]);
                }
            }
        }
    }

    $pdo->commit();
    header("Location: events.html");
    exit;

} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Failed to create event: " . $e->getMessage();
    exit;
}
