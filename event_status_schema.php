<?php

function ensureEventStatusSchema(PDO $pdo): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $columns = $pdo->query("SHOW COLUMNS FROM EventDetails")->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!in_array('event_status', $columns, true)) {
        $pdo->exec("
            ALTER TABLE EventDetails
            ADD COLUMN event_status ENUM('scheduled', 'cancelled') NOT NULL DEFAULT 'scheduled'
            AFTER location
        ");
    }

    if (!in_array('cancellation_reason', $columns, true)) {
        $pdo->exec("
            ALTER TABLE EventDetails
            ADD COLUMN cancellation_reason TEXT NULL
            AFTER event_status
        ");
    }

    if (!in_array('cancellation_time', $columns, true)) {
        $pdo->exec("
            ALTER TABLE EventDetails
            ADD COLUMN cancellation_time DATETIME NULL
            AFTER cancellation_reason
        ");
    }

    $ensured = true;
}
