<?php
require 'db.php';
require_once 'session_bootstrap.php';
require_once 'collaboration_schema.php';
require_once 'event_status_schema.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header('Location: login.php');
    exit;
}

ensureCollaborationSchema($pdo);
ensureEventStatusSchema($pdo);

$user_id = (int)$_SESSION['user_id'];
$dashboardNotice = $_SESSION['organizer_notice'] ?? null;
unset($_SESSION['organizer_notice']);

function finalizeEventCancellation(PDO $pdo, int $eventId, int $actorOrganizerId, string $eventTitle, string $cancellationReason): void
{
    $cancelStmt = $pdo->prepare("
        UPDATE EventDetails
        SET event_status = 'cancelled',
            cancellation_reason = ?,
            cancellation_time = NOW(),
            cancelled_by_organizer_id = ?
        WHERE event_id = ?
    ");
    $cancelStmt->execute([$cancellationReason, $actorOrganizerId, $eventId]);

    $attendeeStmt = $pdo->prepare("
        SELECT DISTINCT u.user_id
        FROM Booking b
        JOIN Attendee a ON a.attendee_id = b.attendee_id
        JOIN Users u ON u.user_id = a.user_id
        WHERE b.event_id = ? AND b.status = 'confirmed'
    ");
    $attendeeStmt->execute([$eventId]);
    $attendeeUserIds = $attendeeStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($attendeeUserIds as $attendeeUserId) {
        createNotification(
            $pdo,
            (int) $attendeeUserId,
            'event_cancelled',
            sprintf('"%s" was cancelled by the organizer. Reason: %s', $eventTitle, $cancellationReason),
            $eventId
        );
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.email, up.bio, up.profile_picture,
               up.social_link1, up.social_link2, up.social_link3,
               o.organizer_id, o.is_admin
        FROM Users u
        JOIN User_Profile up ON u.user_id = up.user_id
        JOIN Organizer o ON u.user_id = o.user_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        die("Profile not found.");
    }

    $organizerId = (int)$profile['organizer_id'];
    $fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
    $socialLinks = array_values(array_filter([
        $profile['social_link1'] ?? '',
        $profile['social_link2'] ?? '',
        $profile['social_link3'] ?? '',
    ]));

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $bio = $_POST['bio'] ?? null;
        $link1 = $_POST['social_link1'] ?? null;
        $link2 = $_POST['social_link2'] ?? null;
        $link3 = $_POST['social_link3'] ?? null;

        $stmt = $pdo->prepare("
            UPDATE User_Profile
            SET bio = ?, social_link1 = ?, social_link2 = ?, social_link3 = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$bio, $link1, $link2, $link3, $user_id]);

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_request'], $_POST['request_id'], $_POST['decision'])) {
        $requestId = (int)$_POST['request_id'];
        $decision = $_POST['decision'] === 'accept' ? 'accepted' : 'declined';

        $pdo->beginTransaction();

        $requestStmt = $pdo->prepare("
            SELECT ecr.request_id, ecr.event_id, ecr.invited_organizer_id, ecr.invited_by_organizer_id, ecr.status,
                   e.title,
                   inviter.user_id AS inviter_user_id,
                   inviter_user.first_name AS inviter_first_name,
                   inviter_user.last_name AS inviter_last_name
            FROM event_collaboration_requests ecr
            JOIN EventDetails e ON e.event_id = ecr.event_id
            JOIN Organizer inviter ON inviter.organizer_id = ecr.invited_by_organizer_id
            JOIN Users inviter_user ON inviter_user.user_id = inviter.user_id
            WHERE ecr.request_id = ? AND ecr.invited_organizer_id = ?
            FOR UPDATE
        ");
        $requestStmt->execute([$requestId, $organizerId]);
        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

        if ($request && $request['status'] === 'pending') {
            if ($decision === 'accepted') {
                $linkStmt = $pdo->prepare("
                    INSERT IGNORE INTO create_event (organizer_id, event_id)
                    VALUES (?, ?)
                ");
                $linkStmt->execute([$organizerId, (int)$request['event_id']]);
            }

            $updateRequestStmt = $pdo->prepare("
                UPDATE event_collaboration_requests
                SET status = ?, responded_at = NOW()
                WHERE request_id = ?
            ");
            $updateRequestStmt->execute([$decision, $requestId]);

            $markReadStmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE user_id = ? AND related_request_id = ?
            ");
            $markReadStmt->execute([$user_id, $requestId]);

            $actorName = trim($profile['first_name'] . ' ' . $profile['last_name']);
            $decisionWord = $decision === 'accepted' ? 'accepted' : 'declined';

            createNotification(
                $pdo,
                (int)$request['inviter_user_id'],
                'collaboration_response',
                sprintf('%s %s your collaboration request for "%s".', $actorName, $decisionWord, $request['title']),
                (int)$request['event_id'],
                $requestId
            );
        }

        $pdo->commit();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_cancellation_request'], $_POST['approval_id'], $_POST['decision'])) {
        $approvalId = (int) $_POST['approval_id'];
        $decision = $_POST['decision'] === 'approve' ? 'approved' : 'declined';

        $pdo->beginTransaction();

        $approvalStmt = $pdo->prepare("
            SELECT
                eca.approval_id,
                eca.batch_id,
                eca.status AS approval_status,
                ecb.event_id,
                ecb.requested_by_organizer_id,
                ecb.cancellation_reason,
                ecb.status AS batch_status,
                e.title,
                requester.user_id AS requester_user_id,
                requester_user.first_name AS requester_first_name,
                requester_user.last_name AS requester_last_name
            FROM event_cancellation_approvals eca
            JOIN event_cancellation_batches ecb ON ecb.batch_id = eca.batch_id
            JOIN EventDetails e ON e.event_id = ecb.event_id
            JOIN Organizer requester ON requester.organizer_id = ecb.requested_by_organizer_id
            JOIN Users requester_user ON requester_user.user_id = requester.user_id
            WHERE eca.approval_id = ? AND eca.organizer_id = ?
            FOR UPDATE
        ");
        $approvalStmt->execute([$approvalId, $organizerId]);
        $approvalRequest = $approvalStmt->fetch(PDO::FETCH_ASSOC);

        if (!$approvalRequest || $approvalRequest['approval_status'] !== 'pending' || $approvalRequest['batch_status'] !== 'pending') {
            $pdo->rollBack();
            $_SESSION['organizer_notice'] = [
                'type' => 'warning',
                'message' => 'That cancellation request is no longer pending.'
            ];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        $updateApprovalStmt = $pdo->prepare("
            UPDATE event_cancellation_approvals
            SET status = ?, responded_at = NOW()
            WHERE approval_id = ?
        ");
        $updateApprovalStmt->execute([$decision, $approvalId]);

        $markReadStmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE user_id = ? AND related_event_id = ? AND type = 'event_cancellation_request'
        ");
        $markReadStmt->execute([$user_id, (int) $approvalRequest['event_id']]);

        if ($decision === 'declined') {
            $declineBatchStmt = $pdo->prepare("
                UPDATE event_cancellation_batches
                SET status = 'declined', resolved_at = NOW()
                WHERE batch_id = ?
            ");
            $declineBatchStmt->execute([(int) $approvalRequest['batch_id']]);

            createNotification(
                $pdo,
                (int) $approvalRequest['requester_user_id'],
                'event_cancellation_declined',
                sprintf(
                    '%s declined your cancellation request for "%s".',
                    trim($profile['first_name'] . ' ' . $profile['last_name']),
                    $approvalRequest['title']
                ),
                (int) $approvalRequest['event_id']
            );

            $pdo->commit();
            $_SESSION['organizer_notice'] = [
                'type' => 'info',
                'message' => sprintf('You declined the cancellation request for "%s".', $approvalRequest['title'])
            ];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        $remainingApprovalsStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM event_cancellation_approvals
            WHERE batch_id = ? AND status = 'pending'
        ");
        $remainingApprovalsStmt->execute([(int) $approvalRequest['batch_id']]);
        $remainingApprovals = (int) $remainingApprovalsStmt->fetchColumn();

        if ($remainingApprovals === 0) {
            finalizeEventCancellation(
                $pdo,
                (int) $approvalRequest['event_id'],
                (int) $approvalRequest['requested_by_organizer_id'],
                $approvalRequest['title'],
                $approvalRequest['cancellation_reason']
            );

            $completeBatchStmt = $pdo->prepare("
                UPDATE event_cancellation_batches
                SET status = 'completed', resolved_at = NOW()
                WHERE batch_id = ?
            ");
            $completeBatchStmt->execute([(int) $approvalRequest['batch_id']]);

            createNotification(
                $pdo,
                (int) $approvalRequest['requester_user_id'],
                'event_cancellation_completed',
                sprintf('Your cancellation request for "%s" was approved and the event is now cancelled.', $approvalRequest['title']),
                (int) $approvalRequest['event_id']
            );

            $pdo->commit();
            $_SESSION['organizer_notice'] = [
                'type' => 'success',
                'message' => sprintf('You approved the final cancellation request for "%s". The event is now cancelled.', $approvalRequest['title'])
            ];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        createNotification(
            $pdo,
            (int) $approvalRequest['requester_user_id'],
            'event_cancellation_approved',
            sprintf('One collaborator approved your cancellation request for "%s".', $approvalRequest['title']),
            (int) $approvalRequest['event_id']
        );

        $pdo->commit();
        $_SESSION['organizer_notice'] = [
            'type' => 'success',
            'message' => sprintf('You approved the cancellation request for "%s".', $approvalRequest['title'])
        ];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_event'], $_POST['event_id'])) {
        $eventId = (int) $_POST['event_id'];
        $cancellationReason = trim($_POST['cancellation_reason'] ?? '');

        if ($cancellationReason === '' || mb_strlen($cancellationReason) < 10) {
            $_SESSION['organizer_notice'] = [
                'type' => 'danger',
                'message' => 'Please provide a valid cancellation reason with at least 10 characters.'
            ];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        $pdo->beginTransaction();

        $eventStmt = $pdo->prepare("
            SELECT e.event_id, e.title, e.event_date, e.event_status, COUNT(ce_all.organizer_id) AS organizer_count
            FROM EventDetails e
            JOIN create_event ce ON ce.event_id = e.event_id
            JOIN create_event ce_all ON ce_all.event_id = e.event_id
            WHERE e.event_id = ? AND ce.organizer_id = ?
            GROUP BY e.event_id, e.title, e.event_date, e.event_status
            LIMIT 1
            FOR UPDATE
        ");
        $eventStmt->execute([$eventId, $organizerId]);
        $eventToCancel = $eventStmt->fetch(PDO::FETCH_ASSOC);

        if (!$eventToCancel) {
            $pdo->rollBack();
            $_SESSION['organizer_notice'] = [
                'type' => 'danger',
                'message' => 'You can only cancel events assigned to your organizer account.'
            ];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        if (($eventToCancel['event_status'] ?? 'scheduled') === 'cancelled') {
            $pdo->rollBack();
            $_SESSION['organizer_notice'] = [
                'type' => 'warning',
                'message' => 'This event has already been cancelled.'
            ];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        if (($eventToCancel['event_date'] ?? '') < date('Y-m-d')) {
            $pdo->rollBack();
            $_SESSION['organizer_notice'] = [
                'type' => 'danger',
                'message' => 'Past events cannot be cancelled.'
            ];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        if ((int) $eventToCancel['organizer_count'] > 1) {
            $existingPendingBatchStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM event_cancellation_batches
                WHERE event_id = ? AND status = 'pending'
            ");
            $existingPendingBatchStmt->execute([$eventId]);

            if ((int) $existingPendingBatchStmt->fetchColumn() > 0) {
                $pdo->rollBack();
                $_SESSION['organizer_notice'] = [
                    'type' => 'warning',
                    'message' => 'A cancellation approval request is already pending for this event.'
                ];
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }

            $batchStmt = $pdo->prepare("
                INSERT INTO event_cancellation_batches (event_id, requested_by_organizer_id, cancellation_reason)
                VALUES (?, ?, ?)
            ");
            $batchStmt->execute([$eventId, $organizerId, $cancellationReason]);
            $batchId = (int) $pdo->lastInsertId();

            $collaboratorStmt = $pdo->prepare("
                SELECT o.organizer_id, o.user_id, u.first_name, u.last_name
                FROM create_event ce
                JOIN Organizer o ON o.organizer_id = ce.organizer_id
                JOIN Users u ON u.user_id = o.user_id
                WHERE ce.event_id = ? AND ce.organizer_id != ?
            ");
            $collaboratorStmt->execute([$eventId, $organizerId]);
            $collaborators = $collaboratorStmt->fetchAll(PDO::FETCH_ASSOC);

            $approvalInsertStmt = $pdo->prepare("
                INSERT INTO event_cancellation_approvals (batch_id, organizer_id)
                VALUES (?, ?)
            ");

            foreach ($collaborators as $collaborator) {
                $approvalInsertStmt->execute([$batchId, (int) $collaborator['organizer_id']]);

                createNotification(
                    $pdo,
                    (int) $collaborator['user_id'],
                    'event_cancellation_request',
                    sprintf(
                        '%s requested cancellation approval for "%s". Reason: %s',
                        trim($profile['first_name'] . ' ' . $profile['last_name']),
                        $eventToCancel['title'],
                        $cancellationReason
                    ),
                    $eventId
                );
            }

            $pdo->commit();
            $_SESSION['organizer_notice'] = [
                'type' => 'info',
                'message' => sprintf('Cancellation approval requests were sent for "%s". The event will cancel after collaborators approve.', $eventToCancel['title'])
            ];
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        finalizeEventCancellation($pdo, $eventId, $organizerId, $eventToCancel['title'], $cancellationReason);

        $pdo->commit();
        $_SESSION['organizer_notice'] = [
            'type' => 'success',
            'message' => sprintf('"%s" has been cancelled and attendees have been notified.', $eventToCancel['title'])
        ];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    $eventsStmt = $pdo->prepare("
        SELECT e.event_id, e.title, e.event_date, e.location, e.event_status, e.cancellation_reason,
               COUNT(DISTINCT ce_all.organizer_id) AS organizer_count,
               (
                   SELECT GROUP_CONCAT(
                       DISTINCT CONCAT(u_team.first_name, ' ', u_team.last_name)
                       ORDER BY u_team.first_name, u_team.last_name
                       SEPARATOR ', '
                   )
                   FROM create_event ce_team
                   JOIN Organizer o_team ON o_team.organizer_id = ce_team.organizer_id
                   JOIN Users u_team ON u_team.user_id = o_team.user_id
                   WHERE ce_team.event_id = e.event_id
               ) AS organizer_names,
               MAX(CASE WHEN ecb.status = 'pending' THEN 1 ELSE 0 END) AS has_pending_cancellation_request,
               COUNT(DISTINCT CASE WHEN b.status = 'confirmed' THEN b.booking_id END) AS booking_count
        FROM EventDetails e
        LEFT JOIN Booking b ON e.event_id = b.event_id
        JOIN create_event ce ON e.event_id = ce.event_id
        JOIN create_event ce_all ON ce_all.event_id = e.event_id
        LEFT JOIN event_cancellation_batches ecb ON ecb.event_id = e.event_id
        WHERE ce.organizer_id = ?
        GROUP BY e.event_id, e.title, e.event_date, e.location, e.event_status, e.cancellation_reason
        ORDER BY e.event_date DESC
    ");
    $eventsStmt->execute([$organizerId]);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $cancelledEventsCount = 0;
    $unattributedCancelledEventsCount = 0;
    $recentCancellationAudit = [];

    if ((int)$profile['is_admin'] === 1) {
        $cancelledEventsCountStmt = $pdo->query("
            SELECT COUNT(*)
            FROM EventDetails
            WHERE event_status = 'cancelled'
        ");
        $cancelledEventsCount = (int) $cancelledEventsCountStmt->fetchColumn();

        $unattributedCancelledEventsCountStmt = $pdo->query("
            SELECT COUNT(*)
            FROM EventDetails
            WHERE event_status = 'cancelled'
              AND cancelled_by_organizer_id IS NULL
        ");
        $unattributedCancelledEventsCount = (int) $unattributedCancelledEventsCountStmt->fetchColumn();

        $recentCancellationAuditStmt = $pdo->query("
            SELECT
                e.event_id,
                e.title,
                e.event_date,
                e.cancellation_reason,
                e.cancellation_time,
                e.cancelled_by_organizer_id,
                o.organizer_id,
                u.first_name,
                u.last_name,
                u.email
            FROM EventDetails e
            LEFT JOIN Organizer o ON o.organizer_id = e.cancelled_by_organizer_id
            LEFT JOIN Users u ON u.user_id = o.user_id
            WHERE e.event_status = 'cancelled'
            ORDER BY e.cancellation_time DESC, e.event_id DESC
            LIMIT 8
        ");
        $recentCancellationAudit = $recentCancellationAuditStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $pendingRequestsStmt = $pdo->prepare("
        SELECT ecr.request_id, ecr.created_at, e.title, e.type, e.event_date, e.location,
               inviter_user.first_name, inviter_user.last_name,
               n.is_read
        FROM event_collaboration_requests ecr
        JOIN EventDetails e ON e.event_id = ecr.event_id
        JOIN Organizer inviter ON inviter.organizer_id = ecr.invited_by_organizer_id
        JOIN Users inviter_user ON inviter_user.user_id = inviter.user_id
        LEFT JOIN notifications n ON n.related_request_id = ecr.request_id AND n.user_id = ?
        WHERE ecr.invited_organizer_id = ? AND ecr.status = 'pending'
        ORDER BY ecr.created_at DESC
    ");
    $pendingRequestsStmt->execute([$user_id, $organizerId]);
    $pendingRequests = $pendingRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

    $pendingCancellationApprovalsStmt = $pdo->prepare("
        SELECT
            eca.approval_id,
            ecb.created_at,
            ecb.cancellation_reason,
            e.title,
            e.type,
            e.event_date,
            e.location,
            requester_user.first_name,
            requester_user.last_name,
            n.is_read
        FROM event_cancellation_approvals eca
        JOIN event_cancellation_batches ecb ON ecb.batch_id = eca.batch_id
        JOIN EventDetails e ON e.event_id = ecb.event_id
        JOIN Organizer requester ON requester.organizer_id = ecb.requested_by_organizer_id
        JOIN Users requester_user ON requester_user.user_id = requester.user_id
        LEFT JOIN notifications n ON n.related_event_id = e.event_id AND n.user_id = ? AND n.type = 'event_cancellation_request'
        WHERE eca.organizer_id = ? AND eca.status = 'pending' AND ecb.status = 'pending'
        ORDER BY ecb.created_at DESC
    ");
    $pendingCancellationApprovalsStmt->execute([$user_id, $organizerId]);
    $pendingCancellationApprovals = $pendingCancellationApprovalsStmt->fetchAll(PDO::FETCH_ASSOC);

    $sentRequestsStmt = $pdo->prepare("
        SELECT ecr.request_id, ecr.status, ecr.created_at, ecr.responded_at,
               e.title, invited_user.first_name, invited_user.last_name
        FROM event_collaboration_requests ecr
        JOIN EventDetails e ON e.event_id = ecr.event_id
        JOIN Organizer invited_org ON invited_org.organizer_id = ecr.invited_organizer_id
        JOIN Users invited_user ON invited_user.user_id = invited_org.user_id
        WHERE ecr.invited_by_organizer_id = ?
        ORDER BY ecr.created_at DESC
    ");
    $sentRequestsStmt->execute([$organizerId]);
    $sentRequests = $sentRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

    $sentCancellationRequestsStmt = $pdo->prepare("
        SELECT
            ecb.batch_id,
            ecb.status,
            ecb.created_at,
            ecb.resolved_at,
            e.title,
            SUM(CASE WHEN eca.status = 'approved' THEN 1 ELSE 0 END) AS approvals_granted,
            SUM(CASE WHEN eca.status = 'declined' THEN 1 ELSE 0 END) AS approvals_declined,
            SUM(CASE WHEN eca.status = 'pending' THEN 1 ELSE 0 END) AS approvals_pending
        FROM event_cancellation_batches ecb
        JOIN EventDetails e ON e.event_id = ecb.event_id
        LEFT JOIN event_cancellation_approvals eca ON eca.batch_id = ecb.batch_id
        WHERE ecb.requested_by_organizer_id = ?
        GROUP BY ecb.batch_id, ecb.status, ecb.created_at, ecb.resolved_at, e.title
        ORDER BY ecb.created_at DESC
    ");
    $sentCancellationRequestsStmt->execute([$organizerId]);
    $sentCancellationRequests = $sentCancellationRequestsStmt->fetchAll(PDO::FETCH_ASSOC);

    $notificationsStmt = $pdo->prepare("
        SELECT notification_id, type, message, is_read, created_at, related_event_id
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $notificationsStmt->execute([$user_id]);
    $notifications = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadCountStmt = $pdo->prepare("
        SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0
    ");
    $unreadCountStmt->execute([$user_id]);
    $unreadCount = (int)$unreadCountStmt->fetchColumn();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Database error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Organizer Profile</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body {
      background: linear-gradient(180deg, #f7f3ee 0%, #fffdfb 100%);
    }
    html {
      scroll-behavior: smooth;
    }
    .dashboard-shell {
      max-width: 1180px;
    }
    .navbar {
      position: sticky;
      top: 0;
      z-index: 1030;
      background: linear-gradient(90deg, #24313f 0%, #2f4052 100%) !important;
      box-shadow: 0 10px 24px rgba(31, 41, 51, 0.14);
    }
    .nav-panel {
      padding: 0.85rem 0;
    }
    .navbar-brand {
      color: #fff !important;
      font-weight: 700;
      letter-spacing: 0.01em;
    }
    .profile-nav-links {
      display: flex;
      flex-wrap: wrap;
      gap: 0.55rem;
      margin-top: 0.85rem;
    }
    .profile-nav-links a {
      padding: 0.4rem 0.8rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.08);
      color: #f6dfd2;
      text-decoration: none;
      font-size: 0.88rem;
      font-weight: 600;
      transition: background-color 0.2s ease, transform 0.2s ease;
    }
    .profile-nav-links a:hover {
      background: rgba(255, 255, 255, 0.16);
      transform: translateY(-1px);
    }
    #pfp-display {
      width: 158px;
      height: 158px;
      object-fit: cover;
      border-radius: 50%;
      border: 3px solid #f0ddd4;
      margin-bottom: 1rem;
      box-shadow: 0 14px 28px rgba(84, 52, 37, 0.12);
      background: #fffdfa;
    }
    .admin-badge {
      background-color: #dc3545;
      color: white;
      font-size: 0.75rem;
      padding: 0.2em 0.5em;
      border-radius: 0.25rem;
      margin-left: 0.5rem;
    }
    .section-card,
    .profile-card,
    .stat-card {
      border: 1px solid rgba(33, 29, 25, 0.08);
      border-radius: 1.25rem;
      background: rgba(255, 255, 255, 0.92);
      box-shadow: 0 16px 34px rgba(84, 52, 37, 0.08);
    }
    .profile-card {
      padding: 1.5rem;
      height: 100%;
      overflow: hidden;
    }
    .section-card {
      padding: 1.35rem;
      overflow: hidden;
    }
    .stat-card {
      padding: 1rem 1.1rem;
    }
    .stat-card strong {
      display: block;
      font-size: 1.6rem;
      line-height: 1;
      color: #c45b33;
    }
    .profile-info label {
      font-weight: 700;
      display: block;
      margin-bottom: 0.3rem;
    }
    .profile-info .info-box,
    .profile-info .links-box {
      background: #f8f5f1;
      padding: 0.7rem 0.85rem;
      border-radius: 1rem;
      margin-bottom: 0;
      overflow-wrap: anywhere;
      word-break: break-word;
      line-height: 1.45;
      white-space: pre-wrap;
    }
    .profile-info .links-box ul {
      margin-bottom: 0;
      padding-left: 1.1rem;
    }
    .profile-info .links-box li + li {
      margin-top: 0.45rem;
    }
    .request-card,
    .notification-card,
    .invite-card {
      border: 1px solid rgba(33, 29, 25, 0.08);
      border-radius: 1rem;
      background: #fffdfa;
      padding: 1rem;
    }
    .request-card.unread {
      border-color: rgba(217, 104, 65, 0.32);
      background: #fff6f1;
    }
    .notification-card.unread {
      background: #fff8f3;
      border-color: rgba(217, 104, 65, 0.26);
    }
    .mini-muted {
      color: #6f665e;
      font-size: 0.94rem;
    }
    .section-title {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1rem;
    }
    .list-stack {
      display: grid;
      gap: 0.9rem;
    }
    .table-wrap {
      overflow-x: auto;
    }
    .table thead th {
      white-space: nowrap;
    }
    .admin-spotlight {
      background:
        linear-gradient(135deg, rgba(220, 53, 69, 0.12), rgba(255, 255, 255, 0.94)),
        rgba(255, 255, 255, 0.94);
    }
    .navbar .btn {
      border-radius: 999px;
      font-weight: 600;
      padding-inline: 1rem;
    }
    .navbar .btn-light {
      color: #fff;
      background-color: #c45b33;
      border-color: #c45b33;
    }
    .navbar .btn-light:hover,
    .navbar .btn-light:focus {
      color: #fff;
      background-color: #9f4524;
      border-color: #9f4524;
    }
    .navbar .btn-warning {
      color: #4a3318;
      background-color: #f3c765;
      border-color: #f3c765;
    }
    .navbar .btn-warning:hover,
    .navbar .btn-warning:focus {
      color: #2f210f;
      background-color: #e9ba4d;
      border-color: #e9ba4d;
    }
    .navbar .btn-outline-light {
      color: #fff7f2;
      border-color: rgba(255, 255, 255, 0.2);
      background: rgba(255, 255, 255, 0.06);
    }
    .navbar .btn-outline-light:hover,
    .navbar .btn-outline-light:focus {
      color: #1f2933;
      background: #fff;
      border-color: #fff;
    }
    .nav-utilities {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }
    .notification-menu {
      position: relative;
    }
    .notification-toggle {
      position: relative;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 3rem;
      height: 3rem;
      border-radius: 999px;
      border: 1px solid rgba(255, 255, 255, 0.2);
      background: rgba(255, 255, 255, 0.06);
      color: #fff7f2;
      transition: background-color 0.2s ease, transform 0.2s ease;
      cursor: pointer;
    }
    .notification-toggle:hover,
    .notification-toggle:focus {
      background: rgba(255, 255, 255, 0.14);
      transform: translateY(-1px);
    }
    .notification-badge-dot {
      position: absolute;
      top: 0.35rem;
      right: 0.35rem;
      min-width: 1.2rem;
      height: 1.2rem;
      padding: 0 0.25rem;
      border-radius: 999px;
      background: #f3c765;
      color: #4a3318;
      font-size: 0.72rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
    }
    .notification-popover {
      position: absolute;
      top: calc(100% + 0.85rem);
      right: 0;
      width: min(360px, calc(100vw - 2rem));
      max-height: 430px;
      overflow: auto;
      padding: 1rem;
      border: 1px solid rgba(33, 29, 25, 0.08);
      border-radius: 1rem;
      background: rgba(255, 253, 250, 0.98);
      box-shadow: 0 18px 34px rgba(20, 18, 15, 0.18);
      backdrop-filter: blur(14px);
      display: none;
      z-index: 1080;
    }
    .notification-popover.open {
      display: block;
    }
    .notification-popover-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 0.85rem;
    }
    .notification-popover-head h5 {
      margin: 0;
      font-size: 1rem;
      color: #1f2933;
    }
    .notification-popover-head p {
      margin: 0.2rem 0 0;
      color: #6f665e;
      font-size: 0.9rem;
    }
    .notification-popover-list {
      display: grid;
      gap: 0.75rem;
    }
    .notification-empty {
      border: 1px dashed rgba(33, 29, 25, 0.12);
      border-radius: 0.9rem;
      padding: 0.95rem 1rem;
      color: #6f665e;
      background: #fffdfa;
    }
    .profile-info .mb-3 {
      min-width: 0;
      margin-bottom: 0.7rem !important;
    }
    .profile-fields {
      display: grid;
      grid-template-columns: 1fr;
      gap: 0.7rem;
    }
    .detail-item {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
    .profile-section-label {
      display: block;
      margin-bottom: 0.3rem;
      font-weight: 700;
    }
    .profile-upload-card {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start;
      text-align: center;
    }
    .profile-upload-card .upload-form {
      width: 100%;
      max-width: 420px;
    }
    .profile-upload-card .form-control,
    .profile-upload-card .btn {
      border-radius: 0.9rem;
    }
    .profile-upload-card .btn-primary {
      width: 100%;
      background-color: #c45b33;
      border-color: #c45b33;
    }
    .profile-upload-card .btn-primary:hover,
    .profile-upload-card .btn-primary:focus {
      background-color: #9f4524;
      border-color: #9f4524;
    }
    .profile-heading {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
      margin-bottom: 0.8rem;
    }
    .profile-heading > div {
      flex: 1 1 0;
      min-width: 0;
    }
    .profile-heading .btn {
      flex-shrink: 0;
    }
    .profile-details-copy {
      color: #6f665e;
      margin-bottom: 0;
    }
    .links-box.slim-links {
      min-height: 0;
      padding-top: 0.7rem;
      padding-bottom: 0.7rem;
    }
    .links-box.slim-links li {
      margin-bottom: 0.35rem;
    }
    .links-box.slim-links li:last-child {
      margin-bottom: 0;
    }
    .profile-info .btn-primary {
      background-color: #c45b33;
      border-color: #c45b33;
      border-radius: 0.7rem;
      padding: 0.55rem 1rem;
      font-weight: 600;
    }
    .profile-info .btn-primary:hover,
    .profile-info .btn-primary:focus {
      background-color: #9f4524;
      border-color: #9f4524;
    }
    .profile-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }
    @media (max-width: 767.98px) {
      .section-card,
      .profile-card {
        padding: 1.1rem;
      }
      .nav-panel {
        padding: 0.75rem 0;
      }
      .section-title {
        align-items: flex-start;
        flex-direction: column;
      }
      .profile-heading {
        align-items: flex-start;
        flex-direction: column;
      }
      .notification-popover {
        left: 0;
        right: auto;
        width: min(360px, calc(100vw - 2.5rem));
      }
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark bg-dark px-4 py-3">
    <div class="container-fluid px-0 nav-panel">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 w-100">
        <div>
          <a class="navbar-brand mb-0">Organizer Dashboard</a>
          <div class="profile-nav-links">
            <a href="#profile-overview">Profile</a>
            <a href="#collaboration-requests">Requests</a>
            <a href="#sent-invitations">Sent Invites</a>
            <a href="#my-events">My Events</a>
          </div>
        </div>
        <div class="nav-utilities">
          <div class="notification-menu">
            <button
              type="button"
              class="notification-toggle"
              id="notificationToggle"
              aria-label="Open notifications"
              aria-expanded="false"
              aria-controls="notificationPopover"
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 3.75a4.25 4.25 0 0 0-4.25 4.25v1.08c0 .83-.23 1.65-.66 2.36l-1.19 1.96a1.75 1.75 0 0 0 1.5 2.65h9.22a1.75 1.75 0 0 0 1.5-2.65l-1.19-1.96a4.52 4.52 0 0 1-.66-2.36V8A4.25 4.25 0 0 0 12 3.75Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M9.75 18a2.25 2.25 0 0 0 4.5 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              <?php if ($unreadCount > 0): ?>
                <span class="notification-badge-dot"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
              <?php endif; ?>
            </button>
            <div class="notification-popover" id="notificationPopover" role="dialog" aria-label="Notifications">
              <div class="notification-popover-head">
                <div>
                  <h5>Notifications</h5>
                  <p>Recent activity for collaboration and cancellation workflows.</p>
                </div>
                <span class="badge text-bg-primary rounded-pill"><?= $unreadCount ?> unread</span>
              </div>

              <?php if (count($notifications) === 0): ?>
                <div class="notification-empty">No notifications yet.</div>
              <?php else: ?>
                <div class="notification-popover-list">
                  <?php foreach ($notifications as $notification): ?>
                    <div class="notification-card <?= ((int)$notification['is_read'] === 0) ? 'unread' : '' ?>">
                      <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                          <div class="fw-semibold"><?= htmlspecialchars($notification['message']) ?></div>
                          <div class="mini-muted mt-1"><?= htmlspecialchars($notification['created_at']) ?></div>
                        </div>
                        <?php if ((int)$notification['is_read'] === 0): ?>
                          <span class="badge text-bg-danger">New</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
          <a href="index.html" class="btn btn-outline-light">Home</a>
          <a href="events.html" class="btn btn-outline-light">Events</a>
          <a href="create_event.html" class="btn btn-light me-2">Create Event</a>
      <?php if ((int)$profile['is_admin'] === 1): ?>
          <a href="manage_users.php" class="btn btn-warning me-2">Manage Users</a>
      <?php endif; ?>
          <a href="logout.php" class="btn btn-outline-light">Log Out</a>
          </div>
        </div>
      </div>
    </div>
  </nav>

  <div class="container dashboard-shell my-4" id="profile-overview">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
      <div>
        <h2 class="mb-1">
          My Profile
          <?php if ((int)$profile['is_admin'] === 1): ?>
            <span class="admin-badge">Admin</span>
          <?php endif; ?>
        </h2>
        <p class="text-muted mb-0">Accepted collaborations appear in your events table only after you accept the request.</p>
      </div>
      <div class="d-flex gap-3 flex-wrap">
        <div class="stat-card">
          <span class="mini-muted">My Events</span>
          <strong><?= count($events) ?></strong>
        </div>
        <div class="stat-card">
          <span class="mini-muted">Pending Requests</span>
          <strong><?= count($pendingRequests) + count($pendingCancellationApprovals) ?></strong>
        </div>
        <div class="stat-card">
          <span class="mini-muted">Unread Notifications</span>
          <strong><?= $unreadCount ?></strong>
        </div>
      </div>
    </div>

    <?php if ($dashboardNotice): ?>
      <div class="alert alert-<?= htmlspecialchars($dashboardNotice['type']) ?> mb-4" role="alert">
        <?= htmlspecialchars($dashboardNotice['message']) ?>
      </div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-4">
        <div class="profile-card profile-upload-card">
          <img id="pfp-display" src="<?= htmlspecialchars($profile['profile_picture'] ?: 'https://via.placeholder.com/150') ?>" alt="Profile Picture" />
          <h2 class="h4 mb-1"><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) ?></h2>
          <p class="mini-muted mb-3">Profile photo and identity</p>
          <form action="upload_profile_pic.php" method="POST" enctype="multipart/form-data" class="mt-2 upload-form">
            <input type="file" name="profile_pic" accept="image/*" class="form-control" required />
            <button type="submit" class="btn btn-primary mt-2">Upload New Photo</button>
          </form>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="profile-card profile-info">
          <div class="profile-heading">
            <div>
              <h2 class="h4 mb-1">Profile Details</h2>
              <p class="profile-details-copy">Everything other attendees and organizers need to recognize your account at a glance.</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
              Edit Profile
            </button>
          </div>

          <div class="profile-fields">
            <div class="detail-item">
              <label class="profile-section-label">Full Name</label>
              <div class="info-box"><?= htmlspecialchars($fullName) ?></div>
            </div>
            <div class="detail-item">
              <label class="profile-section-label">Email</label>
              <div class="info-box"><?= htmlspecialchars($profile['email']) ?></div>
            </div>
            <div class="detail-item">
              <label class="profile-section-label">Bio</label>
              <div class="info-box" id="bio-display"><?= $profile['bio'] ? nl2br(htmlspecialchars($profile['bio'])) : '<em>No bio set yet.</em>' ?></div>
            </div>
            <div class="detail-item">
              <label class="profile-section-label">Website and Social Links</label>
              <div class="links-box slim-links">
                <?php if ($socialLinks): ?>
                  <ul class="mb-0 ps-3">
                    <?php foreach ($socialLinks as $link): ?>
                      <li><a href="<?= htmlspecialchars($link) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($link) ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <em>No social links set.</em>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if ((int)$profile['is_admin'] === 1): ?>
      <div class="section-card admin-spotlight mt-4">
        <div class="section-title">
          <div>
            <h4 class="mb-1">Admin Controls</h4>
            <p class="mini-muted mb-0">You have elevated access to manage users and watch event-cancellation activity for unusual patterns.</p>
          </div>
          <span class="badge text-bg-danger rounded-pill">Admin access enabled</span>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="stat-card h-100">
              <span class="mini-muted">Cancelled Events</span>
              <strong><?= $cancelledEventsCount ?></strong>
            </div>
          </div>
          <div class="col-md-4">
            <div class="stat-card h-100">
              <span class="mini-muted">Legacy / Unattributed</span>
              <strong><?= $unattributedCancelledEventsCount ?></strong>
            </div>
          </div>
          <div class="col-md-4">
            <div class="stat-card h-100">
              <span class="mini-muted">Attributed Cancellations</span>
              <strong><?= max(0, $cancelledEventsCount - $unattributedCancelledEventsCount) ?></strong>
            </div>
          </div>
          <div class="col-12">
            <div class="section-card h-100 p-3">
              <div class="fw-semibold mb-2">Recent cancellation audit</div>
              <?php if (count($recentCancellationAudit) === 0): ?>
                <div class="mini-muted">No cancelled events have been recorded yet.</div>
              <?php else: ?>
                <div class="list-stack">
                  <?php foreach ($recentCancellationAudit as $auditItem): ?>
                    <div class="notification-card">
                      <div class="fw-semibold"><?= htmlspecialchars($auditItem['title']) ?></div>
                      <div class="mini-muted mt-1">
                        Cancelled by
                        <?= htmlspecialchars(trim(($auditItem['first_name'] ?? '') . ' ' . ($auditItem['last_name'] ?? '')) ?: 'Legacy cancellation') ?>
                        <?php if (!empty($auditItem['organizer_id'])): ?>
                          (Organizer #<?= (int) $auditItem['organizer_id'] ?>)
                        <?php elseif (empty($auditItem['cancelled_by_organizer_id'])): ?>
                          (not attributable from older data)
                        <?php endif; ?>
                        on <?= htmlspecialchars($auditItem['cancellation_time'] ?? 'Unknown time') ?>
                      </div>
                      <div class="mini-muted mt-1">Reason: <?= htmlspecialchars($auditItem['cancellation_reason'] ?? 'No reason saved') ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="profile-actions">
          <a href="manage_users.php" class="btn btn-danger">Open User Management</a>
          <a href="create_event.html" class="btn btn-outline-secondary">Create Another Event</a>
        </div>
      </div>
    <?php endif; ?>

    <div class="row g-4 mt-1">
      <div class="col-lg-7">
        <div class="section-card h-100" id="collaboration-requests">
          <div class="section-title">
            <div>
              <h4 class="mb-1">Collaboration Requests</h4>
              <p class="mini-muted mb-0">Accept a request to make that event appear in your organizer events list.</p>
            </div>
            <span class="badge text-bg-dark rounded-pill"><?= count($pendingRequests) ?> pending</span>
          </div>

          <div class="list-stack">
            <?php if (count($pendingRequests) === 0): ?>
              <div class="request-card">
                <div class="mini-muted">No pending collaboration requests right now.</div>
              </div>
            <?php else: ?>
              <?php foreach ($pendingRequests as $request): ?>
                <div class="request-card <?= ((int)($request['is_read'] ?? 0) === 0) ? 'unread' : '' ?>">
                  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                      <h5 class="mb-1"><?= htmlspecialchars($request['title']) ?></h5>
                      <div class="mini-muted mb-2">
                        Invited by <?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?>
                        - <?= htmlspecialchars($request['type']) ?>
                        - <?= htmlspecialchars($request['event_date']) ?>
                        - <?= htmlspecialchars($request['location']) ?>
                      </div>
                    </div>
                    <span class="badge text-bg-warning">Pending</span>
                  </div>
                  <form method="POST" class="d-flex gap-2 flex-wrap mt-2">
                    <input type="hidden" name="request_id" value="<?= (int)$request['request_id'] ?>" />
                    <input type="hidden" name="respond_request" value="1" />
                    <button type="submit" name="decision" value="accept" class="btn btn-success btn-sm">Accept</button>
                    <button type="submit" name="decision" value="decline" class="btn btn-outline-secondary btn-sm">Decline</button>
                  </form>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="section-card h-100">
          <div class="section-title">
            <div>
              <h4 class="mb-1">Cancellation Approval Requests</h4>
              <p class="mini-muted mb-0">Collaborative events need approval from the other organizers before cancellation can go through.</p>
            </div>
            <span class="badge text-bg-dark rounded-pill"><?= count($pendingCancellationApprovals) ?> pending</span>
          </div>

          <div class="list-stack">
            <?php if (count($pendingCancellationApprovals) === 0): ?>
              <div class="request-card">
                <div class="mini-muted">No cancellation approvals are waiting for you right now.</div>
              </div>
            <?php else: ?>
              <?php foreach ($pendingCancellationApprovals as $request): ?>
                <div class="request-card <?= ((int)($request['is_read'] ?? 0) === 0) ? 'unread' : '' ?>">
                  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                      <h5 class="mb-1"><?= htmlspecialchars($request['title']) ?></h5>
                      <div class="mini-muted mb-2">
                        Requested by <?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?>
                        - <?= htmlspecialchars($request['type']) ?>
                        - <?= htmlspecialchars($request['event_date']) ?>
                        - <?= htmlspecialchars($request['location']) ?>
                      </div>
                      <div class="mini-muted">Reason: <?= htmlspecialchars($request['cancellation_reason']) ?></div>
                    </div>
                    <span class="badge text-bg-warning">Approval needed</span>
                  </div>
                  <form method="POST" class="d-flex gap-2 flex-wrap mt-2">
                    <input type="hidden" name="approval_id" value="<?= (int)$request['approval_id'] ?>" />
                    <input type="hidden" name="respond_cancellation_request" value="1" />
                    <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm">Approve</button>
                    <button type="submit" name="decision" value="decline" class="btn btn-outline-secondary btn-sm">Decline</button>
                  </form>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4 mt-1">
      <div class="col-lg-12">
        <div class="section-card" id="sent-invitations">
          <div class="section-title">
            <div>
              <h4 class="mb-1">Invitations You Sent</h4>
              <p class="mini-muted mb-0">Track who has accepted, declined, or not answered yet.</p>
            </div>
          </div>

          <div class="list-stack">
            <?php if (count($sentRequests) === 0): ?>
              <div class="invite-card">
                <div class="mini-muted">You have not invited any collaborators yet.</div>
              </div>
            <?php else: ?>
              <?php foreach ($sentRequests as $invite): ?>
                <div class="invite-card d-flex justify-content-between align-items-start gap-3 flex-wrap">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($invite['title']) ?></div>
                    <div class="mini-muted">
                      Sent to <?= htmlspecialchars($invite['first_name'] . ' ' . $invite['last_name']) ?>
                      - <?= htmlspecialchars($invite['created_at']) ?>
                    </div>
                  </div>
                  <span class="badge <?= $invite['status'] === 'accepted' ? 'text-bg-success' : ($invite['status'] === 'declined' ? 'text-bg-secondary' : 'text-bg-warning') ?>">
                    <?= htmlspecialchars(ucfirst($invite['status'])) ?>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4 mt-1">
      <div class="col-lg-12">
        <div class="section-card">
          <div class="section-title">
            <div>
              <h4 class="mb-1">Cancellation Requests You Sent</h4>
              <p class="mini-muted mb-0">See whether collaborators still need to approve a shared-event cancellation.</p>
            </div>
          </div>

          <div class="list-stack">
            <?php if (count($sentCancellationRequests) === 0): ?>
              <div class="invite-card">
                <div class="mini-muted">You have not sent any cancellation approval requests yet.</div>
              </div>
            <?php else: ?>
              <?php foreach ($sentCancellationRequests as $request): ?>
                <div class="invite-card d-flex justify-content-between align-items-start gap-3 flex-wrap">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($request['title']) ?></div>
                    <div class="mini-muted">
                      Sent <?= htmlspecialchars($request['created_at']) ?>
                      <?php if (!empty($request['resolved_at'])): ?>
                        - Resolved <?= htmlspecialchars($request['resolved_at']) ?>
                      <?php endif; ?>
                    </div>
                    <div class="mini-muted">
                      Approvals: <?= (int) $request['approvals_granted'] ?> approved,
                      <?= (int) $request['approvals_pending'] ?> pending,
                      <?= (int) $request['approvals_declined'] ?> declined
                    </div>
                  </div>
                  <span class="badge <?= $request['status'] === 'completed' ? 'text-bg-success' : ($request['status'] === 'declined' ? 'text-bg-secondary' : 'text-bg-warning') ?>">
                    <?= htmlspecialchars(ucfirst($request['status'])) ?>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="section-card mt-4" id="my-events">
      <div class="section-title">
        <div>
          <h4 class="mb-1">My Events & Booking Counts</h4>
          <p class="mini-muted mb-0">This list contains your own events plus collaborations you already accepted.</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table table-striped mt-2 mb-0">
          <thead>
            <tr>
              <th>Event Name</th>
              <th>Organizers</th>
              <th>Date</th>
              <th>Location</th>
              <th>Status</th>
              <th>Total Bookings</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($events) === 0): ?>
              <tr><td colspan="7">No accepted events found.</td></tr>
            <?php else: ?>
              <?php foreach ($events as $event): ?>
                <tr>
                  <td><?= htmlspecialchars($event['title']) ?></td>
                  <td>
                    <div><?= htmlspecialchars($event['organizer_names'] ?? $fullName) ?></div>
                    <div class="mini-muted">
                      <?= (int) ($event['organizer_count'] ?? 1) > 1 ? 'Collaborative event' : 'Single-organizer event' ?>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($event['event_date']) ?></td>
                  <td><?= htmlspecialchars($event['location']) ?></td>
                  <td>
                    <?php if (($event['event_status'] ?? 'scheduled') === 'cancelled'): ?>
                      <span class="badge text-bg-danger">Cancelled</span>
                      <div class="mini-muted mt-1"><?= htmlspecialchars($event['cancellation_reason'] ?? '') ?></div>
                    <?php else: ?>
                      <span class="badge text-bg-success">Scheduled</span>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($event['booking_count']) ?></td>
                  <td>
                    <?php if (($event['event_status'] ?? 'scheduled') === 'cancelled' || ($event['event_date'] ?? '') < date('Y-m-d')): ?>
                      <div class="d-flex gap-2 flex-wrap">
                        <a
                          href="export_event_attendees.php?event_id=<?= (int) $event['event_id'] ?>"
                          class="btn btn-outline-secondary btn-sm"
                        >
                          Export Attendees CSV
                        </a>
                      </div>
                    <?php elseif ((int) ($event['has_pending_cancellation_request'] ?? 0) === 1): ?>
                      <div class="d-flex gap-2 flex-wrap">
                        <span class="mini-muted align-self-center">Cancellation approval pending</span>
                        <a
                          href="export_event_attendees.php?event_id=<?= (int) $event['event_id'] ?>"
                          class="btn btn-outline-secondary btn-sm"
                        >
                          Export Attendees CSV
                        </a>
                      </div>
                    <?php else: ?>
                      <div class="d-flex gap-2 flex-wrap">
                        <button
                          type="button"
                          class="btn btn-outline-danger btn-sm"
                          data-bs-toggle="modal"
                          data-bs-target="#cancelEventModal"
                          data-event-id="<?= (int) $event['event_id'] ?>"
                          data-event-title="<?= htmlspecialchars($event['title'], ENT_QUOTES) ?>"
                          data-organizer-count="<?= (int) $event['organizer_count'] ?>"
                        >
                          <?= (int) $event['organizer_count'] > 1 ? 'Request Cancellation' : 'Cancel Event' ?>
                        </button>
                        <a
                          href="export_event_attendees.php?event_id=<?= (int) $event['event_id'] ?>"
                          class="btn btn-outline-secondary btn-sm"
                        >
                          Export Attendees CSV
                        </a>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST" novalidate>
          <div class="modal-header">
            <h5 class="modal-title" id="editProfileModalLabel">Edit Profile</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label for="bio" class="form-label">Bio</label>
              <textarea id="bio" name="bio" class="form-control" rows="4"><?= htmlspecialchars($profile['bio']) ?></textarea>
            </div>
            <div class="mb-3">
              <label for="social_link1" class="form-label">Link 1</label>
              <input type="url" id="social_link1" name="social_link1" class="form-control" value="<?= htmlspecialchars($profile['social_link1']) ?>" />
            </div>
            <div class="mb-3">
              <label for="social_link2" class="form-label">Link 2</label>
              <input type="url" id="social_link2" name="social_link2" class="form-control" value="<?= htmlspecialchars($profile['social_link2']) ?>" />
            </div>
            <div class="mb-3">
              <label for="social_link3" class="form-label">Link 3</label>
              <input type="url" id="social_link3" name="social_link3" class="form-control" value="<?= htmlspecialchars($profile['social_link3']) ?>" />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="update_profile" class="btn btn-success">Save Changes</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="cancelEventModal" tabindex="-1" aria-labelledby="cancelEventModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="POST" novalidate>
          <div class="modal-header">
            <h5 class="modal-title" id="cancelEventModalLabel">Cancel Event</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="cancel_event" value="1" />
            <input type="hidden" name="event_id" id="cancelEventId" value="" />
            <p class="mb-2">You're cancelling <strong id="cancelEventName">this event</strong>.</p>
            <div class="alert alert-warning py-2 px-3" id="cancelEventFlowHint">
              This action will cancel the event immediately.
            </div>
            <div class="mb-3">
              <label for="cancellation_reason" class="form-label">Valid reason</label>
              <textarea
                id="cancellation_reason"
                name="cancellation_reason"
                class="form-control"
                rows="4"
                minlength="10"
                maxlength="500"
                required
                placeholder="Explain why the event needs to be cancelled."
              ></textarea>
              <div class="form-text">This reason is required and will be shown to affected attendees.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Event</button>
            <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const cancelEventModal = document.getElementById('cancelEventModal');
    const notificationToggle = document.getElementById('notificationToggle');
    const notificationPopover = document.getElementById('notificationPopover');

    if (cancelEventModal) {
      cancelEventModal.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;
        const eventIdInput = document.getElementById('cancelEventId');
        const eventNameLabel = document.getElementById('cancelEventName');
        const reasonField = document.getElementById('cancellation_reason');
        const flowHint = document.getElementById('cancelEventFlowHint');
        const organizerCount = Number(button?.getAttribute('data-organizer-count') || '1');

        eventIdInput.value = button?.getAttribute('data-event-id') || '';
        eventNameLabel.textContent = button?.getAttribute('data-event-title') || 'this event';
        reasonField.value = '';
        flowHint.textContent = organizerCount > 1
          ? 'This event has collaborators, so a cancellation approval request will be sent to the other organizers first.'
          : 'This action will cancel the event immediately.';
      });
    }

    if (notificationToggle && notificationPopover) {
      notificationToggle.addEventListener('click', () => {
        const isOpen = notificationPopover.classList.toggle('open');
        notificationToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });

      document.addEventListener('click', (event) => {
        if (!notificationPopover.classList.contains('open')) {
          return;
        }

        if (!notificationPopover.contains(event.target) && !notificationToggle.contains(event.target)) {
          notificationPopover.classList.remove('open');
          notificationToggle.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          notificationPopover.classList.remove('open');
          notificationToggle.setAttribute('aria-expanded', 'false');
        }
      });
    }
  </script>
</body>
</html>
