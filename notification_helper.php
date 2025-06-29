<?php
// notification_helper.php

/**
 * Creates a notification for a user
 * 
 * @param PDO $pdo Database connection
 * @param int $userID The ID of the user to notify
 * @param string $message The notification message
 * @return bool True on success, false on failure
 */
function createNotification(PDO $pdo, int $userID, string $message): bool {
    try {
        // Validate input
        if ($userID <= 0 || empty(trim($message))) {
            throw new InvalidArgumentException("Invalid user ID or message");
        }

        // Prepare and execute the insert statement
        $stmt = $pdo->prepare("
            INSERT INTO notification (UserID, Message, CreatedAt) 
            VALUES (:userID, :message, NOW())
        ");
        
        $stmt->execute([
            'userID' => $userID,
            'message' => trim($message)
        ]);

        return true;
    } catch (PDOException $e) {
        error_log("Database error in createNotification: " . $e->getMessage());
        return false;
    } catch (InvalidArgumentException $e) {
        error_log("Validation error in createNotification: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("General error in createNotification: " . $e->getMessage());
        return false;
    }
}

/**
 * Marks all notifications as read for a user
 * 
 * @param PDO $pdo Database connection
 * @param int $userID The ID of the user
 * @return bool True on success, false on failure
 */
function markAllNotificationsAsRead(PDO $pdo, int $userID): bool {
    try {
        // Validate input
        if ($userID <= 0) {
            throw new InvalidArgumentException("Invalid user ID");
        }

        // Prepare and execute the delete statement
        $stmt = $pdo->prepare("
            DELETE FROM notification 
            WHERE UserID = :userID
        ");
        
        $stmt->execute(['userID' => $userID]);

        return true;
    } catch (PDOException $e) {
        error_log("Database error in markAllNotificationsAsRead: " . $e->getMessage());
        return false;
    } catch (InvalidArgumentException $e) {
        error_log("Validation error in markAllNotificationsAsRead: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("General error in markAllNotificationsAsRead: " . $e->getMessage());
        return false;
    }
}

/**
 * Gets all notifications for a user
 * 
 * @param PDO $pdo Database connection
 * @param int $userID The ID of the user
 * @param int $limit Maximum number of notifications to return (default: 50)
 * @return array Array of notifications or empty array on error
 */
function getUserNotifications(PDO $pdo, int $userID, int $limit = 50): array {
    try {
        // Validate input
        if ($userID <= 0) {
            throw new InvalidArgumentException("Invalid user ID");
        }
        if ($limit <= 0) {
            throw new InvalidArgumentException("Limit must be positive");
        }

        // Prepare and execute the select statement
        $stmt = $pdo->prepare("
            SELECT NotificationID, Message, CreatedAt 
            FROM notification 
            WHERE UserID = :userID 
            ORDER BY CreatedAt DESC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':userID', $userID, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error in getUserNotifications: " . $e->getMessage());
        return [];
    } catch (InvalidArgumentException $e) {
        error_log("Validation error in getUserNotifications: " . $e->getMessage());
        return [];
    } catch (Exception $e) {
        error_log("General error in getUserNotifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Gets the count of unread notifications for a user
 * 
 * @param PDO $pdo Database connection
 * @param int $userID The ID of the user
 * @return int Number of unread notifications or -1 on error
 */
function getUnreadNotificationCount(PDO $pdo, int $userID): int {
    try {
        // Validate input
        if ($userID <= 0) {
            throw new InvalidArgumentException("Invalid user ID");
        }

        // Prepare and execute the count statement
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM notification 
            WHERE UserID = :userID
        ");
        
        $stmt->execute(['userID' => $userID]);
        
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Database error in getUnreadNotificationCount: " . $e->getMessage());
        return -1;
    } catch (InvalidArgumentException $e) {
        error_log("Validation error in getUnreadNotificationCount: " . $e->getMessage());
        return -1;
    } catch (Exception $e) {
        error_log("General error in getUnreadNotificationCount: " . $e->getMessage());
        return -1;
    }
}

/**
 * Determines the notification type based on message content
 * 
 * @param string $message The notification message
 * @return string Notification type (appointment, payment, cancelled, completed)
 */
function getNotificationType(string $message): string {
    $message = strtolower($message);
    
    if (strpos($message, 'payment') !== false) {
        return 'payment';
    } elseif (strpos($message, 'cancelled') !== false) {
        return 'cancelled';
    } elseif (strpos($message, 'completed') !== false) {
        return 'completed';
    } elseif (strpos($message, 'rescheduled') !== false) {
        return 'rescheduled';
    } elseif (strpos($message, 'booked') !== false || strpos($message, 'appointment') !== false) {
        return 'appointment';
    }
    
    return 'general';
}

/**
 * Gets the appropriate icon for a notification type
 * 
 * @param string $type Notification type
 * @return string Font Awesome icon class
 */
function getNotificationIcon(string $type): string {
    switch ($type) {
        case 'payment':
            return 'fa-credit-card';
        case 'completed':
            return 'fa-check-circle';
        case 'cancelled':
            return 'fa-times-circle';
        case 'rescheduled':
            return 'fa-calendar-alt';
        case 'appointment':
            return 'fa-calendar-day';
        default:
            return 'fa-info-circle';
    }
}