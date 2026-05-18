<?php

function ensureEventStatusSchema(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $columns = $pdo->query("SHOW COLUMNS FROM eventdetails")->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!in_array('event_status', $columns, true)) {
        $pdo->exec("
            ALTER TABLE eventdetails
            ADD COLUMN event_status ENUM('scheduled', 'cancelled') NOT NULL DEFAULT 'scheduled'
            AFTER location
        ");
    }

    if (!in_array('cancellation_reason', $columns, true)) {
        $pdo->exec("
            ALTER TABLE eventdetails
            ADD COLUMN cancellation_reason TEXT NULL
            AFTER event_status
        ");
    }

    if (!in_array('cancellation_time', $columns, true)) {
        $pdo->exec("
            ALTER TABLE eventdetails
            ADD COLUMN cancellation_time DATETIME NULL
            AFTER cancellation_reason
        ");
    }

    if (!in_array('cancelled_by_organizer_id', $columns, true)) {
        $pdo->exec("
            ALTER TABLE eventdetails
            ADD COLUMN cancelled_by_organizer_id INT NULL
            AFTER cancellation_time
        ");
    }

    // Backfill legacy cancellations only when one organizer can be attributed confidently.
    $pdo->exec("
        UPDATE eventdetails e
        JOIN (
            SELECT ce.event_id, MIN(ce.organizer_id) AS organizer_id
            FROM create_event ce
            GROUP BY ce.event_id
            HAVING COUNT(*) = 1
        ) single_owner ON single_owner.event_id = e.event_id
        SET e.cancelled_by_organizer_id = single_owner.organizer_id
        WHERE e.event_status = 'cancelled'
          AND e.cancelled_by_organizer_id IS NULL
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS event_cancellation_batches (
            batch_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id INT(11) NOT NULL,
            requested_by_organizer_id INT(11) NOT NULL,
            cancellation_reason TEXT NOT NULL,
            status ENUM('pending', 'declined', 'completed') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            KEY idx_ecb_event_status (event_id, status),
            KEY idx_ecb_requester_status (requested_by_organizer_id, status),
            CONSTRAINT ecb_event_fk FOREIGN KEY (event_id) REFERENCES eventdetails (event_id) ON DELETE CASCADE,
            CONSTRAINT ecb_requester_fk FOREIGN KEY (requested_by_organizer_id) REFERENCES organizer (organizer_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS event_cancellation_approvals (
            approval_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            batch_id INT(11) NOT NULL,
            organizer_id INT(11) NOT NULL,
            status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
            responded_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_batch_organizer (batch_id, organizer_id),
            KEY idx_eca_organizer_status (organizer_id, status),
            CONSTRAINT eca_batch_fk FOREIGN KEY (batch_id) REFERENCES event_cancellation_batches (batch_id) ON DELETE CASCADE,
            CONSTRAINT eca_organizer_fk FOREIGN KEY (organizer_id) REFERENCES organizer (organizer_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ensured = true;
}
