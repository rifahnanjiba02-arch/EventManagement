<?php
session_start();
header('Content-Type: application/json');

echo json_encode([
  'role' => $_SESSION['role'] ?? null,
  'attendee_id' => $_SESSION['attendee_id'] ?? null
]);
