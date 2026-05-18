<?php
// get_attendee.php
header('Content-Type: application/json');
require 'db.php';

if (!isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing user ID"]);
    exit;
}

$user_id = intval($_GET['user_id']);

$stmt = $pdo->prepare("
    SELECT u.name, u.email, u.bio, u.profile_pic_url 
    FROM users u
    JOIN attendee a ON u.user_id = a.user_id
    WHERE a.user_id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    echo json_encode(["error" => "User not found"]);
    exit;
}

echo json_encode($user);
?>
