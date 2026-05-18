<?php

function ensureCollaborationSchema(PDO $pdo): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS event_collaboration_requests (
            request_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id INT(11) NOT NULL,
            invited_organizer_id INT(11) NOT NULL,
            invited_by_organizer_id INT(11) NOT NULL,
            status ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_event_invited_organizer (event_id, invited_organizer_id),
            KEY idx_invited_organizer_status (invited_organizer_id, status),
            KEY idx_invited_by_organizer (invited_by_organizer_id),
            CONSTRAINT ecr_event_fk FOREIGN KEY (event_id) REFERENCES eventdetails (event_id) ON DELETE CASCADE,
            CONSTRAINT ecr_invited_organizer_fk FOREIGN KEY (invited_organizer_id) REFERENCES organizer (organizer_id) ON DELETE CASCADE,
            CONSTRAINT ecr_invited_by_organizer_fk FOREIGN KEY (invited_by_organizer_id) REFERENCES organizer (organizer_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            notification_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            related_event_id INT(11) DEFAULT NULL,
            related_request_id INT(11) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_notifications_user (user_id, is_read, created_at),
            KEY idx_notifications_request (related_request_id),
            CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
            CONSTRAINT notifications_event_fk FOREIGN KEY (related_event_id) REFERENCES eventdetails (event_id) ON DELETE SET NULL,
            CONSTRAINT notifications_request_fk FOREIGN KEY (related_request_id) REFERENCES event_collaboration_requests (request_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $initialized = true;
}

function createNotification(
    PDO $pdo,
    int $userId,
    string $type,
    string $message,
    ?int $relatedEventId = null,
    ?int $relatedRequestId = null
): void {
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, message, related_event_id, related_request_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $type, $message, $relatedEventId, $relatedRequestId]);
}
