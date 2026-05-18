<?php
// upload_profile_pic.php
require_once 'session_bootstrap.php';
require 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$uploadDir = 'uploads/profile_pics/';

function isManagedProfilePicturePath(string $path, string $uploadDir): bool
{
    $normalizedPath = str_replace('\\', '/', $path);
    $normalizedUploadDir = rtrim(str_replace('\\', '/', $uploadDir), '/') . '/';

    return substr($normalizedPath, 0, strlen($normalizedUploadDir)) === $normalizedUploadDir;
}

function removeManagedProfilePicture(string $path, string $uploadDir): void
{
    if ($path === '' || !isManagedProfilePicturePath($path, $uploadDir)) {
        return;
    }

    if (is_file($path)) {
        @unlink($path);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    header('Location: error.php?msg=Invalid+request');
    exit;
}

// Check if file was uploaded correctly
if (!isset($_FILES['profile_pic']) || $_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {
    // Optional: Redirect back with error message (via session or query param)
    header('Location: error.php?msg=Upload failed');
    exit;
}

// Prepare upload directory
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$fileTmp = $_FILES['profile_pic']['tmp_name'];
$fileSize = (int) ($_FILES['profile_pic']['size'] ?? 0);
$maxFileSize = 5 * 1024 * 1024;

if ($fileSize <= 0 || $fileSize > $maxFileSize) {
    header('Location: error.php?msg=Invalid+file+size');
    exit;
}

$imageInfo = @getimagesize($fileTmp);
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($fileTmp) ?: '';
$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

if ($imageInfo === false || !isset($allowedMimeTypes[$mimeType])) {
    header('Location: error.php?msg=Invalid+file+type');
    exit;
}

$currentPictureStmt = $pdo->prepare("SELECT profile_picture FROM user_profile WHERE user_id = ? LIMIT 1");
$currentPictureStmt->execute([$user_id]);
$existingProfilePicture = (string) ($currentPictureStmt->fetchColumn() ?: '');

$newFileName = $user_id . '_' . bin2hex(random_bytes(16)) . '.' . $allowedMimeTypes[$mimeType];
$target = $uploadDir . $newFileName;

// Move uploaded file and update database
if (move_uploaded_file($fileTmp, $target)) {
    @chmod($target, 0644);
    $stmt = $pdo->prepare("UPDATE user_profile SET profile_picture = ? WHERE user_id = ?");
    $stmt->execute([$target, $user_id]);

    if ($existingProfilePicture !== $target) {
        removeManagedProfilePicture($existingProfilePicture, $uploadDir);
    }

    foreach (glob($uploadDir . $user_id . '_*') ?: [] as $staleFile) {
        if ($staleFile !== $target && is_file($staleFile)) {
            @unlink($staleFile);
        }
    }

    // Redirect to appropriate profile page based on role
    $role = $_SESSION['role'] ?? '';
    if ($role === 'organizer') {
        header('Location: organizer_profile.php');
    } elseif ($role === 'attendee') {
        header('Location: attendee_profile.php');
    } else {
        header('Location: login.php');
    }
    exit;
} else {
    header('Location: error.php?msg=Failed to move file');
    exit;
}
?>
