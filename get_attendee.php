<?php
require 'db.php';
require_once 'api_helpers.php';

if (!isset($_GET['user_id'])) {
    jsonError('Missing user ID', 400);
}

$user_id = requirePositiveInt($_GET['user_id'], 'user_id');

try {
    $stmt = $pdo->prepare("
        SELECT
            u.first_name,
            u.last_name,
            u.email,
            up.bio,
            up.profile_picture,
            a.attendee_id
        FROM users u
        INNER JOIN attendee a ON u.user_id = a.user_id
        LEFT JOIN user_profile up ON up.user_id = u.user_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    reportServerException($e, 'Failed to fetch attendee');
}

if (!$user) {
    jsonError('User not found', 404);
}

jsonResponse($user);
