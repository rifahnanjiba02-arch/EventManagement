<?php
require_once 'session_bootstrap.php';
header('Content-Type: application/json');

echo json_encode([
  'role' => $_SESSION['role'] ?? null,
  'attendee_id' => $_SESSION['attendee_id'] ?? null,
  'csrf_token' => getCsrfToken()
]);
