<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$item_id = $_GET['item_id'] ?? 0;

if (!$item_id) {
    echo json_encode(['success' => false, 'error' => 'Item ID required']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

try {
    $query = "SELECT 
                ib.id,
                ib.status,
                ib.quantity,
                ib.borrow_date,
                ib.return_date,
                ib.actual_return_date,
                ib.condition_after,
                ib.purpose,
                ib.created_at,
                ib.updated_at,
                u.full_name as borrower,
                CASE 
                    WHEN ib.status = 'returned' THEN ib.actual_return_date
                    WHEN ib.status = 'approved' THEN ib.borrow_date
                    ELSE ib.created_at
                END as event_date
              FROM item_borrowings ib
              JOIN users u ON ib.user_id = u.id
              WHERE ib.item_id = :item_id
              ORDER BY event_date DESC
              LIMIT 50";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
    $stmt->execute();
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formatted_history = [];
    foreach ($history as $record) {
        $formatted_history[] = [
            'id' => $record['id'],
            'status' => ucfirst($record['status']),
            'quantity' => $record['quantity'],
            'borrower' => $record['borrower'],
            'date' => date('M d, Y', strtotime($record['event_date'])),
            'condition' => $record['condition_after'] ? ucfirst(str_replace('_', ' ', $record['condition_after'])) : null,
            'purpose' => $record['purpose'],
            'borrow_date' => date('M d, Y', strtotime($record['borrow_date'])),
            'return_date' => date('M d, Y', strtotime($record['return_date'])),
            'actual_return_date' => $record['actual_return_date'] ? date('M d, Y', strtotime($record['actual_return_date'])) : null
        ];
    }
    
    echo json_encode([
        'success' => true,
        'history' => $formatted_history
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>