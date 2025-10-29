<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Resident';

/**
 * Create a new message
 * @param PDO $conn Database connection
 * @param int $sender_id Sender user ID
 * @param int $recipient_id Recipient user ID
 * @param string $subject Message subject
 * @param string $message Message content
 * @return bool Success status
 */
function createMessage($conn, $sender_id, $recipient_id, $subject, $message) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO messages (sender_id, recipient_id, subject, message, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$sender_id, $recipient_id, $subject, $message]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Delete a specific message
 * @param PDO $conn Database connection
 * @param int $message_id Message ID to delete
 * @param int $user_id Current user ID (must be sender)
 * @return bool Success status
 */
function deleteMessage($conn, $message_id, $user_id) {
    try {
        $stmt = $conn->prepare("DELETE FROM messages WHERE id = ? AND sender_id = ?");
        return $stmt->execute([$message_id, $user_id]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Delete entire conversation between two users
 * @param PDO $conn Database connection
 * @param int $user_id Current user ID
 * @param int $contact_id Contact user ID
 * @return bool Success status
 */
function deleteConversation($conn, $user_id, $contact_id) {
    try {
        $stmt = $conn->prepare("
            DELETE FROM messages 
            WHERE (sender_id = ? AND recipient_id = ?) OR 
                  (sender_id = ? AND recipient_id = ?)
        ");
        return $stmt->execute([$user_id, $contact_id, $contact_id, $user_id]);
    } catch (Exception $e) {
        return false;
    }
}

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $contact_id = (int)$_POST['contact_id'] ?? 0;
    $message = trim($_POST['message']);
    $subject = trim($_POST['subject']) ?: 'Message from Resident';
    
    if ($contact_id > 0 && !empty($message)) {
        if (createMessage($conn, $user_id, $contact_id, $subject, $message)) {
            // Create notification for recipient
            $notif_stmt = $conn->prepare("
                INSERT INTO notifications (user_id, title, message, type, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $notif_stmt->execute([
                $contact_id,
                'New Message from ' . $user_name,
                'You have received a new message: ' . substr($message, 0, 50) . '...',
                'message'
            ]);
            
            $_SESSION['success_message'] = "Message sent successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to send message";
        }
    } else {
        $_SESSION['error_message'] = "Message cannot be empty";
    }
    
    header('Location: resident_messages.php?contact_id=' . $contact_id);
    exit;
}

// Handle deleting messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
    $message_id = (int)$_POST['message_id'];
    
    if (deleteMessage($conn, $message_id, $user_id)) {
        $_SESSION['success_message'] = "Message deleted successfully!";
    } else {
        $_SESSION['error_message'] = "Failed to delete message";
    }
    
    // Redirect back to the same conversation
    $contact_id = $_POST['contact_id'] ?? $selected_contact_id;
    header('Location: resident_messages.php?contact_id=' . $contact_id);
    exit;
}

// Handle deleting conversation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_conversation'])) {
    $contact_id = (int)$_POST['contact_id'];
    
    if (deleteConversation($conn, $user_id, $contact_id)) {
        $_SESSION['success_message'] = "Conversation deleted successfully!";
        header('Location: resident_messages.php');
        exit;
    } else {
        $_SESSION['error_message'] = "Failed to delete conversation";
        header('Location: resident_messages.php?contact_id=' . $contact_id);
        exit;
    }
}

// Get unread count
try {
    $unreadStmt = $conn->prepare("SELECT COUNT(*) as count FROM messages WHERE recipient_id = ? AND is_read = 0");
    $unreadStmt->execute([$user_id]);
    $unread_count = $unreadStmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (Exception $e) {
    $unread_count = 0;
}

// Fetch conversations (group by sender/recipient)
try {
    $convStmt = $conn->prepare("
        SELECT 
            CASE 
                WHEN sender_id = ? THEN recipient_id
                ELSE sender_id
            END as contact_id,
            CASE 
                WHEN sender_id = ? THEN u2.full_name
                ELSE u1.full_name
            END as contact_name,
            MAX(m.created_at) as last_message_date,
            (SELECT message FROM messages m2 WHERE 
                ((m2.sender_id = ? AND m2.recipient_id = contact_id) OR 
                 (m2.sender_id = contact_id AND m2.recipient_id = ?))
                ORDER BY m2.created_at DESC LIMIT 1) as last_message,
            COUNT(CASE WHEN m.recipient_id = ? AND m.is_read = 0 THEN 1 END) as unread
        FROM messages m
        JOIN users u1 ON m.sender_id = u1.id
        JOIN users u2 ON m.recipient_id = u2.id
        WHERE m.sender_id = ? OR m.recipient_id = ?
        GROUP BY contact_id
        ORDER BY last_message_date DESC
    ");
    $convStmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
    $conversations = $convStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $conversations = [];
}

// Get selected contact messages
$selected_contact_id = $_GET['contact_id'] ?? 0;
$selected_contact = null;
$selected_messages = [];

if ($selected_contact_id > 0) {
    try {
        // Get contact info
        $contactStmt = $conn->prepare("SELECT id, full_name FROM users WHERE id = ?");
        $contactStmt->execute([$selected_contact_id]);
        $selected_contact = $contactStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($selected_contact) {
            // Get all messages with this contact
            $msgStmt = $conn->prepare("
                SELECT m.*, 
                       CASE WHEN m.sender_id = ? THEN 'sent' ELSE 'received' END as direction,
                       u.full_name as sender_name
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                WHERE (m.sender_id = ? AND m.recipient_id = ?) OR
                      (m.sender_id = ? AND m.recipient_id = ?)
                ORDER BY m.created_at ASC
            ");
            $msgStmt->execute([$user_id, $user_id, $selected_contact_id, $selected_contact_id, $user_id]);
            $selected_messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark as read
            $readStmt = $conn->prepare("
                UPDATE messages SET is_read = 1 
                WHERE recipient_id = ? AND sender_id = ? AND is_read = 0
            ");
            $readStmt->execute([$user_id, $selected_contact_id]);
        }
    } catch (Exception $e) {
        // Silent fail
    }
}

// Fetch all users for create message modal
$all_users = [];
try {
    $usersStmt = $conn->prepare("SELECT id, full_name, email FROM users WHERE id != ? ORDER BY full_name ASC");
    $usersStmt->execute([$user_id]);
    $all_users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all_users = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Barangay Kapasigan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(180deg, #f5f9ff 0%, #ffffff 50%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            padding-top: 20px;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .sidebar-header h5 {
            color: white;
            margin-top: 12px;
            font-weight: 700;
            font-size: 16px;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.7);
            padding: 12px 20px;
            margin: 2px 8px;
            border-radius: 8px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.15);
            border-left: 3px solid #ffd89b;
        }

        .main-content {
            margin-left: 260px;
            padding: 30px;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .page-header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            font-size: 32px;
            color: #2d3748;
            font-weight: 700;
            margin: 0;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .create-message-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .create-message-btn:hover {
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            transform: translateY(-2px);
        }

        .user-menu {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .messages-container {
            flex: 1;
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 20px;
            overflow: hidden;
        }

        .conversations-panel {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .panel-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 18px;
        }

        .unread-badge {
            background: #ff4757;
            color: white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-left: auto;
        }

        .conversations-list {
            flex: 1;
            overflow-y: auto;
        }

        .conversation-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .conversation-item:hover {
            background: #f9fafb;
        }

        .conversation-item.active {
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-left: 4px solid #667eea;
        }

        .conversation-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }

        .contact-name {
            font-weight: 600;
            color: #2d3748;
            font-size: 15px;
        }

        .conversation-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn-delete-conv-item {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 4px 8px;
            font-size: 14px;
            opacity: 0.6;
            transition: all 0.3s;
            display: flex;
            align-items: center;
        }

        .btn-delete-conv-item:hover {
            opacity: 1;
            color: #c82333;
        }

        .message-preview {
            color: #718096;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 5px;
        }

        .message-time {
            color: #a0aec0;
            font-size: 12px;
        }

        .empty-conversations {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: #a0aec0;
            text-align: center;
        }

        .empty-conversations i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .chat-panel {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .chat-avatar {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .chat-info {
            flex: 1;
        }

        .chat-info h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .chat-info p {
            font-size: 13px;
            opacity: 0.9;
            margin: 0;
        }

        .messages-area {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 15px;
            background: #f9fafb;
        }

        .message-bubble {
            display: flex;
            gap: 10px;
            margin-bottom: 5px;
        }

        .message-bubble.sent {
            justify-content: flex-end;
        }

        .message-bubble.received {
            justify-content: flex-start;
        }

        .bubble {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 12px;
            line-height: 1.5;
            word-break: break-word;
            position: relative;
        }

        .bubble.sent {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .bubble.received {
            background: white;
            color: #2d3748;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 4px;
        }

        .bubble-meta {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .btn-delete-msg {
            background: none;
            border: none;
            color: inherit;
            opacity: 0.5;
            cursor: pointer;
            padding: 0;
            font-size: 11px;
            transition: opacity 0.3s;
        }

        .btn-delete-msg:hover {
            opacity: 1;
        }

        .message-bubble.sent .btn-delete-msg {
            color: rgba(255, 255, 255, 0.7);
        }

        .message-bubble.received .btn-delete-msg {
            color: #718096;
        }

        .chat-form {
            padding: 20px;
            border-top: 1px solid #e2e8f0;
            background: white;
            display: flex;
            gap: 10px;
        }

        .form-group {
            flex: 1;
        }

        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            resize: none;
            min-height: 50px;
            max-height: 120px;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .send-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            align-self: flex-end;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .send-btn:hover {
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            transform: translateY(-2px);
        }

        .empty-chat {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #a0aec0;
            text-align: center;
            padding: 40px;
        }

        .empty-chat i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left-color: #10b981;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left-color: #dc2626;
        }

        /* Modal Styles */
        .modal-content {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px 12px 0 0;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-body {
            padding: 30px;
        }

        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .form-control {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-select {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
        }

        .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #5568d3 0%, #6a3f8f 100%);
        }

        @media (max-width: 1024px) {
            .messages-container {
                grid-template-columns: 1fr;
            }

            .conversations-panel {
                max-height: 300px;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header-buttons {
                width: 100%;
                flex-direction: column;
            }

            .messages-container {
                grid-template-columns: 1fr;
            }

            .chat-panel {
                min-height: 500px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <img src="kapasigan.png" alt="Logo">
            <h5>Barangay Kapasigan</h5>
        </div>
        
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="resident_dashboard.php">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="book_facility.php">
                    <i class="fas fa-building"></i>
                    <span>Book Facility</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="borrow_item.php">
                    <i class="fas fa-box"></i>
                    <span>Borrow Items</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="request_vehicle.php">
                    <i class="fas fa-car"></i>
                    <span>Request Vehicle</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="calendar.php">
                    <i class="fas fa-calendar"></i>
                    <span>Calendar View</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="my_requests.php">
                    <i class="fas fa-list"></i>
                    <span>My Requests</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="reminders.php">
                    <i class="fas fa-bell"></i>
                    <span>Reminders</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="resident_messages.php">
                    <i class="fas fa-envelope"></i>
                    <span>Messages</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <h1><i class="fas fa-envelope me-2"></i>Messages</h1>
            <div class="header-buttons">
                <button class="create-message-btn" data-bs-toggle="modal" data-bs-target="#createMessageModal">
                    <i class="fas fa-plus"></i> Create Message
                </button>
                <div class="dropdown">
                    <button class="user-menu" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle"></i><?php echo $_SESSION['full_name']; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success_message']); 
                unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error_message']); 
                unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Messages Container -->
        <div class="messages-container">
            <!-- Conversations Panel -->
            <div class="conversations-panel">
                <div class="panel-header">
                    <i class="fas fa-comments"></i>
                    <span>Conversations</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="unread-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </div>
                <div class="conversations-list">
                    <?php if (empty($conversations)): ?>
                        <div class="empty-conversations">
                            <i class="fas fa-inbox"></i>
                            <p>No conversations yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $conv): ?>
                            <div class="conversation-item <?php echo $selected_contact_id == $conv['contact_id'] ? 'active' : ''; ?>">
                                <a href="?contact_id=<?php echo $conv['contact_id']; ?>" style="text-decoration: none; color: inherit; display: block;">
                                    <div class="conversation-header">
                                        <div class="contact-name"><?php echo htmlspecialchars($conv['contact_name']); ?></div>
                                        <div class="conversation-actions">
                                            <?php if ($conv['unread'] > 0): ?>
                                                <span class="unread-badge" style="width: 22px; height: 22px; font-size: 11px; margin: 0;">
                                                    <?php echo $conv['unread']; ?>
                                                </span>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline;" onclick="event.stopPropagation();">
                                                <input type="hidden" name="contact_id" value="<?php echo $conv['contact_id']; ?>">
                                                <button type="submit" name="delete_conversation" class="btn-delete-conv-item" 
                                                        onclick="return confirm('Delete this conversation? This action cannot be undone.')">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="message-preview">
                                        <?php echo htmlspecialchars(substr($conv['last_message'] ?? 'No messages', 0, 50)); ?>
                                    </div>
                                    <div class="message-time">
                                        <?php echo date('M d, g:i A', strtotime($conv['last_message_date'])); ?>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chat Panel -->
            <div class="chat-panel">
                <?php if ($selected_contact): ?>
                    <div class="chat-header">
                        <div class="chat-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="chat-info">
                            <h3><?php echo htmlspecialchars($selected_contact['full_name']); ?></h3>
                            <p>Barangay Administrator</p>
                        </div>
                    </div>

                    <div class="messages-area" id="messagesArea">
                        <?php if (empty($selected_messages)): ?>
                            <div class="empty-chat">
                                <i class="fas fa-comments"></i>
                                <h4>Start Conversation</h4>
                                <p>Send your first message below</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($selected_messages as $msg): ?>
                                <div class="message-bubble <?php echo $msg['direction']; ?>">
                                    <div class="bubble <?php echo $msg['direction']; ?>">
                                        <div><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                        <div class="bubble-meta">
                                            <span><?php echo date('M d, g:i A', strtotime($msg['created_at'])); ?></span>
                                            <?php if ($msg['direction'] === 'sent'): ?>
                                                <form method="POST" style="display: inline; margin-left: 10px;">
                                                    <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                                    <input type="hidden" name="contact_id" value="<?php echo $selected_contact_id; ?>">
                                                    <button type="submit" name="delete_message" class="btn-delete-msg" 
                                                            onclick="return confirm('Delete this message?')">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="chat-form">
                        <input type="hidden" name="contact_id" value="<?php echo $selected_contact_id; ?>">
                        <div class="form-group">
                            <textarea name="message" class="form-input" 
                                      placeholder="Type your message..." required></textarea>
                        </div>
                        <button type="submit" name="send_message" class="send-btn">
                            <i class="fas fa-paper-plane"></i> Send
                        </button>
                    </form>
                <?php else: ?>
                    <div class="empty-chat">
                        <i class="fas fa-inbox"></i>
                        <h3>Select a Conversation</h3>
                        <p>Choose a contact from the list to start messaging</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Message Modal -->
    <div class="modal fade" id="createMessageModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Create New Message</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="recipient" class="form-label">Recipient</label>
                            <select name="contact_id" id="recipient" class="form-select" required>
                                <option value="">Select a recipient...</option>
                                <?php foreach ($all_users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name']); ?> (<?php echo htmlspecialchars($user['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="subject" class="form-label">Subject</label>
                            <input type="text" name="subject" id="subject" class="form-control" 
                                   placeholder="Message subject (optional)">
                        </div>
                        <div class="mb-3">
                            <label for="message" class="form-label">Message</label>
                            <textarea name="message" id="message" class="form-control" rows="5" 
                                      placeholder="Type your message..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="send_message" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-2"></i>Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-scroll to bottom of messages
        const messagesArea = document.getElementById('messagesArea');
        if (messagesArea && messagesArea.children.length > 1) {
            messagesArea.scrollTop = messagesArea.scrollHeight;
        }

        // Auto-resize textarea
        const textarea = document.querySelector('.form-input');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });
        }
    </script>
</body>
</html>
        