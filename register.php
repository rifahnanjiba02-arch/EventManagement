<?php
require_once 'db.php'; // PDO connection
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Invalid request method.";
    exit;
}

// Sanitize input
$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name'] ?? '');
$email     = trim($_POST['email'] ?? '');
$password  = $_POST['password'] ?? '';
$role      = $_POST['role'] ?? '';

$phone1 = trim($_POST['phone_number1'] ?? '');
$phone2 = trim($_POST['phone_number2'] ?? '');
$phone3 = trim($_POST['phone_number3'] ?? '');

// Basic validations
if (!$firstName || !$lastName || !$email || !$password || !$role) {
    die("All fields are required.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Invalid email format.");
}

// Phone number validation: must be 11 digits if filled
$phones = [$phone1, $phone2, $phone3];
$validPhones = [];

foreach ($phones as $p) {
    if ($p !== '') {
        if (!preg_match('/^\d{11}$/', $p)) {
            die("Phone numbers must be exactly 11 digits.");
        }
        $validPhones[] = $p;
    }
}

if (count($validPhones) === 0) {
    die("At least one phone number is required.");
}

// Normalize phone values: set empty ones to null
$phone1 = $phone1 ?: null;
$phone2 = $phone2 ?: null;
$phone3 = $phone3 ?: null;

// Allowed roles: only attendee and organizer (no admin)
$allowedRoles = ['attendee', 'organizer'];
if (!in_array($role, $allowedRoles, true)) {
    die("Invalid role selected.");
}

// Hash password securely
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

try {
    // Check if email exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) {
        die("Error: This email is already registered.");
    }

    // Insert user with up to 3 phone numbers
    $stmt = $pdo->prepare("
        INSERT INTO Users (
            first_name, last_name, email, phone_number1, phone_number2, phone_number3, password_hash, role
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $firstName,
        $lastName,
        $email,
        $phone1,
        $phone2,
        $phone3,
        $hashedPassword,
        $role
    ]);

    $userId = $pdo->lastInsertId();

    // Insert empty profile row
    $stmt = $pdo->prepare("
        INSERT INTO User_Profile (user_id, bio, profile_picture, social_link1, social_link2, social_link3)
        VALUES (?, '', NULL, NULL, NULL, NULL)
    ");
    $stmt->execute([$userId]);

    // Insert role-specific record and set session IDs
    if ($role === 'organizer') {
        $stmt = $pdo->query("SELECT MAX(organizer_id) FROM Organizer");
        $maxId = $stmt->fetchColumn();
        $organizerId = $maxId ? $maxId + 1 : 1000;

        // is_admin always 0 for organizer on registration
        $stmt = $pdo->prepare("INSERT INTO Organizer (user_id, organizer_id, is_admin) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $organizerId, 0]);

        $_SESSION['user_id'] = $userId;
        $_SESSION['role'] = $role;
        $_SESSION['is_admin'] = 0;
        $_SESSION['organizer_id'] = $organizerId;
    } elseif ($role === 'attendee') {
        $stmt = $pdo->query("SELECT MAX(attendee_id) FROM Attendee");
        $maxId = $stmt->fetchColumn();
        $attendeeId = $maxId ? $maxId + 1 : 1000;

        $stmt = $pdo->prepare("INSERT INTO Attendee (user_id, attendee_id) VALUES (?, ?)");
        $stmt->execute([$userId, $attendeeId]);

        $_SESSION['user_id'] = $userId;
        $_SESSION['role'] = $role;
        $_SESSION['is_admin'] = 0;
        $_SESSION['attendee_id'] = $attendeeId;   // THIS IS THE IMPORTANT LINE!
    }

    // Redirect based on role
    if ($role === 'organizer') {
        header('Location: organizer_profile.php');
    } else {
        header('Location: attendee_profile.php');
    }
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo "Registration failed: " . htmlspecialchars($e->getMessage());
}
