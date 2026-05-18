<?php
require 'db.php';
require_once 'api_helpers.php';
require_once 'session_bootstrap.php';
require_once 'collaboration_schema.php';

ensureCollaborationSchema($pdo);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    jsonError('Unauthorized', 403);
}

// Return JSON list of organizers if fetch_organizers is requested ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fetch_organizers'])) {
    try {
        // Get current organizer_id to exclude from collaborators list
        $stmtSelf = $pdo->prepare("SELECT organizer_id FROM organizer WHERE user_id = ?");
        $stmtSelf->execute([$_SESSION['user_id']]);
        $self = $stmtSelf->fetch();

        if (!$self) {
            jsonError('Organizer not found', 403);
        }

        // Fetch all other organizers except the current logged-in organizer
        $stmt = $pdo->prepare("
            SELECT o.organizer_id, u.first_name, u.last_name, u.email
            FROM organizer o
            JOIN users u ON o.user_id = u.user_id
            WHERE u.role = 'organizer' AND o.organizer_id != ?
        ");
        $stmt->execute([$self['organizer_id']]);
        $organizers = $stmt->fetchAll();
        jsonResponse($organizers);
    } catch (Throwable $e) {
        reportServerException($e, 'Failed to fetch organizers');
    }
}

// Handle event creation when form is submitted ===
header('Content-Type: text/plain'); // Reset content-type for form POST

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo "Invalid request";
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
$stmtOrg = $pdo->prepare("SELECT organizer_id FROM organizer WHERE user_id = ?");
$stmtOrg->execute([$_SESSION['user_id']]);
$currentOrganizer = $stmtOrg->fetch();

if (!$currentOrganizer) {
    http_response_code(400);
    echo "Organizer not found";
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO eventdetails (title, type, event_date, location) VALUES (?, ?, ?, ?)");
    $stmt->execute([$title, $type, $date, $location]);
    $event_id = $pdo->lastInsertId();

    // Link current organizer to event
    $stmtLink = $pdo->prepare("INSERT INTO create_event (organizer_id, event_id) VALUES (?, ?)");
    $stmtLink->execute([$currentOrganizer['organizer_id'], $event_id]);

    // Create pending collaborator requests if any
    if (!empty($_POST['collaborators']) && is_array($_POST['collaborators'])) {
        $requestStmt = $pdo->prepare("
            INSERT INTO event_collaboration_requests (event_id, invited_organizer_id, invited_by_organizer_id)
            VALUES (?, ?, ?)
        ");

        $inviteeUserStmt = $pdo->prepare("SELECT user_id FROM organizer WHERE organizer_id = ?");

        foreach ($_POST['collaborators'] as $collaborator_id) {
            $collab_id = filter_var($collaborator_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($collab_id === false) {
                continue;
            }

            if ($collab_id !== (int)$currentOrganizer['organizer_id']) {
                $check = $pdo->prepare("SELECT 1 FROM organizer WHERE organizer_id = ?");
                $check->execute([$collab_id]);
                if ($check->fetch()) {
                    $requestStmt->execute([$event_id, $collab_id, $currentOrganizer['organizer_id']]);
                    $requestId = (int)$pdo->lastInsertId();

                    $inviteeUserStmt->execute([$collab_id]);
                    $inviteeUser = $inviteeUserStmt->fetch();

                    if ($inviteeUser) {
                        createNotification(
                            $pdo,
                            (int)$inviteeUser['user_id'],
                            'collaboration_request',
                            sprintf('You have been invited to collaborate on "%s".', $title),
                            (int)$event_id,
                            $requestId
                        );
                    }
                }
            }
        }
    }

    $pdo->commit();
    header("Location: events.html");
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Failed to create event: ' . $e->getMessage());
    http_response_code(500);
    echo "Failed to create event. Please try again.";
    exit;
}
