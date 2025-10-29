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

if (!$id || !$type) {
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
    
    // If it's an approved item borrowing, restore inventory
    if ($type === 'item') {
        $query = "SELECT item_id, quantity, status FROM item_borrowings WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $borrowing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($borrowing && $borrowing['status'] === 'approved') {
            $query = "UPDATE items SET quantity = quantity + :quantity WHERE id = :item_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':quantity', $borrowing['quantity']);
            $stmt->bindParam(':item_id', $borrowing['item_id']);
            $stmt->execute();
        }
    }
    
    // Delete the record
    $query = "DELETE FROM {$table} WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete event']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
