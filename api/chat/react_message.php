<?php
/**
 * API: إضافة أو إزالة تفاعل على رسالة
 */

define('ACCESS_ALLOWED', true);

error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/chat.php';
} catch (Throwable $e) {
    error_log('chat/react_message bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Initialization error: ' . $e->getMessage()]);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $currentUser = getCurrentUser();
    $userId = (int) $currentUser['id'];

    $payload = json_decode(file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $messageId = isset($payload['message_id']) ? (int) $payload['message_id'] : 0;
    $reactionType = isset($payload['reaction_type']) ? (string) $payload['reaction_type'] : '';

    if ($messageId <= 0) {
        throw new InvalidArgumentException('Message ID is required');
    }

    if (!in_array($reactionType, ['thumbs_up', 'thumbs_down'], true)) {
        throw new InvalidArgumentException('Invalid reaction type');
    }

    $db = db();

    // Check if message exists
    $message = $db->queryOne(
        "SELECT id FROM messages WHERE id = ?",
        [$messageId]
    );

    if (!$message) {
        throw new InvalidArgumentException('Message not found');
    }

    // Check if user already reacted with this type
    $existingReaction = $db->queryOne(
        "SELECT id, reaction_type FROM message_reactions WHERE message_id = ? AND user_id = ?",
        [$messageId, $userId]
    );

    $db->beginTransaction();

    try {
        if ($existingReaction) {
            if ($existingReaction['reaction_type'] === $reactionType) {
                // Remove reaction (toggle off)
                $db->execute(
                    "DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND reaction_type = ?",
                    [$messageId, $userId, $reactionType]
                );

                // Update count
                if ($reactionType === 'thumbs_up') {
                    $db->execute(
                        "UPDATE messages SET thumbs_up_count = GREATEST(0, thumbs_up_count - 1) WHERE id = ?",
                        [$messageId]
                    );
                } else {
                    $db->execute(
                        "UPDATE messages SET thumbs_down_count = GREATEST(0, thumbs_down_count - 1) WHERE id = ?",
                        [$messageId]
                    );
                }
            } else {
                // Change reaction type
                $db->execute(
                    "UPDATE message_reactions SET reaction_type = ? WHERE message_id = ? AND user_id = ?",
                    [$reactionType, $messageId, $userId]
                );

                // Update counts
                if ($existingReaction['reaction_type'] === 'thumbs_up') {
                    $db->execute(
                        "UPDATE messages SET thumbs_up_count = GREATEST(0, thumbs_up_count - 1), thumbs_down_count = thumbs_down_count + 1 WHERE id = ?",
                        [$messageId]
                    );
                } else {
                    $db->execute(
                        "UPDATE messages SET thumbs_up_count = thumbs_up_count + 1, thumbs_down_count = GREATEST(0, thumbs_down_count - 1) WHERE id = ?",
                        [$messageId]
                    );
                }
            }
        } else {
            // Add new reaction
            $db->execute(
                "INSERT INTO message_reactions (message_id, user_id, reaction_type) VALUES (?, ?, ?)",
                [$messageId, $userId, $reactionType]
            );

            // Update count
            if ($reactionType === 'thumbs_up') {
                $db->execute(
                    "UPDATE messages SET thumbs_up_count = thumbs_up_count + 1 WHERE id = ?",
                    [$messageId]
                );
            } else {
                $db->execute(
                    "UPDATE messages SET thumbs_down_count = thumbs_down_count + 1 WHERE id = ?",
                    [$messageId]
                );
            }
        }

        $db->commit();

        // Get updated counts
        $updatedMessage = $db->queryOne(
            "SELECT thumbs_up_count, thumbs_down_count FROM messages WHERE id = ?",
            [$messageId]
        );

        echo json_encode([
            'success' => true,
            'data' => [
                'thumbs_up_count' => (int) ($updatedMessage['thumbs_up_count'] ?? 0),
                'thumbs_down_count' => (int) ($updatedMessage['thumbs_down_count'] ?? 0),
            ],
        ]);
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
} catch (InvalidArgumentException $invalid) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $invalid->getMessage()]);
} catch (Throwable $e) {
    error_log('chat/react_message error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

