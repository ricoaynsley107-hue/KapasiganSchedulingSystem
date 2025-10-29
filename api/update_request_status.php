<?php
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../config/database.php';

$auth = new Auth();
$auth->requireAdmin();

$database = new Database();
$conn = $database->getConnection();

$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? '';
$type = $input['type'] ?? '';
$status = $input['status'] ?? '';
$admin_notes = $input['admin_notes'] ?? '';

if (!$id || !$type || !$status) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit();
}

try {
    $table_map = [
        'facility' => 'facility_bookings',
        'item' => 'item_borrowings',
        'vehicle' => 'vehicle_requests',
        'meeting' => 'meetings'
    ];
    
    if (!isset($table_map[$type])) {
        throw new Exception('Invalid request type');
    }
    
    $table = $table_map[$type];
    
    // Update status
    $query = "UPDATE {$table} SET status = :status, admin_notes = :admin_notes, updated_at = NOW() WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':admin_notes', $admin_notes);
    $stmt->bindParam(':id', $id);
    
    if ($stmt->execute()) {
        // If approved and it's an item, update inventory
        if ($status === 'approved' && $type === 'item') {
            $query = "SELECT item_id, quantity FROM item_borrowings WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $borrowing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($borrowing) {
                $query = "UPDATE items SET quantity = quantity - :quantity WHERE id = :item_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':quantity', $borrowing['quantity']);
                $stmt->bindParam(':item_id', $borrowing['item_id']);
                $stmt->execute();
            }
        }
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update status']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
