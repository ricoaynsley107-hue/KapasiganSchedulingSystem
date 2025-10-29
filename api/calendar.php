<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

try {
    $events = [];
    
    // Facility Bookings
    $query = "SELECT 
                fb.id,
                fb.booking_date as event_date,
                fb.start_time,
                fb.end_time,
                fb.status,
                fb.purpose as description,
                f.name as title,
                u.full_name as requester,
                'facility' as type,
                fb.facility_id as resource_id
              FROM facility_bookings fb
              JOIN facilities f ON fb.facility_id = f.id
              JOIN users u ON fb.user_id = u.id
              WHERE fb.booking_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
              AND fb.booking_date <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($facilities as $facility) {
        $events[] = [
            'id' => 'facility_' . $facility['id'],
            'title' => $facility['title'],
            'start' => $facility['event_date'] . 'T' . $facility['start_time'],
            'end' => $facility['event_date'] . 'T' . $facility['end_time'],
            'extendedProps' => [
                'type' => 'facility',
                'status' => $facility['status'],
                'requester' => $facility['requester'],
                'description' => $facility['description'],
                'resource_id' => $facility['resource_id']
            ]
        ];
    }
    
    // Item Borrowings - Show active and recently returned
    $query = "SELECT 
                ib.id,
                ib.borrow_date,
                ib.return_date,
                ib.actual_return_date,
                ib.status,
                ib.purpose as description,
                ib.quantity,
                ib.condition_after,
                i.name as title,
                u.full_name as requester,
                'item' as type,
                ib.item_id as resource_id
              FROM item_borrowings ib
              JOIN items i ON ib.item_id = i.id
              JOIN users u ON ib.user_id = u.id
              WHERE (
                  (ib.borrow_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                  AND ib.return_date <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH))
                  OR
                  (ib.actual_return_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))
              )";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        // Determine the color and status based on return status
        $displayStatus = $item['status'];
        $itemType = 'item';
        
        if ($item['status'] === 'returned' && $item['actual_return_date']) {
            $displayStatus = 'returned';
            $itemType = 'item-returned';
            
            // Show as a single-day event on return date
            $events[] = [
                'id' => 'item_return_' . $item['id'],
                'title' => '✓ ' . $item['title'] . ' (Returned)',
                'start' => $item['actual_return_date'],
                'allDay' => true,
                'extendedProps' => [
                    'type' => 'item',
                    'status' => 'returned',
                    'requester' => $item['requester'],
                    'description' => 'Returned: ' . $item['description'] . ' | Condition: ' . ($item['condition_after'] ?? 'N/A'),
                    'resource_id' => $item['resource_id'],
                    'quantity' => $item['quantity'],
                    'condition' => $item['condition_after']
                ],
                'backgroundColor' => '#10b981',
                'borderColor' => '#059669'
            ];
        } else {
            // Show active borrowing period
            $events[] = [
                'id' => 'item_' . $item['id'],
                'title' => $item['title'] . ' (Qty: ' . $item['quantity'] . ')',
                'start' => $item['borrow_date'],
                'end' => $item['return_date'],
                'extendedProps' => [
                    'type' => 'item',
                    'status' => $item['status'],
                    'requester' => $item['requester'],
                    'description' => $item['description'],
                    'resource_id' => $item['resource_id'],
                    'quantity' => $item['quantity']
                ]
            ];
        }
    }
    
    // Vehicle Requests
    $query = "SELECT 
                vr.id,
                vr.request_date as event_date,
                vr.start_time,
                vr.end_time,
                vr.status,
                vr.destination,
                vr.purpose as description,
                v.name as title,
                u.full_name as requester,
                'vehicle' as type,
                vr.vehicle_id as resource_id
              FROM vehicle_requests vr
              JOIN vehicles v ON vr.vehicle_id = v.id
              JOIN users u ON vr.user_id = u.id
              WHERE vr.request_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
              AND vr.request_date <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($vehicles as $vehicle) {
        $events[] = [
            'id' => 'vehicle_' . $vehicle['id'],
            'title' => $vehicle['title'],
            'start' => $vehicle['event_date'] . 'T' . $vehicle['start_time'],
            'end' => $vehicle['event_date'] . 'T' . $vehicle['end_time'],
            'extendedProps' => [
                'type' => 'vehicle',
                'status' => $vehicle['status'],
                'requester' => $vehicle['requester'],
                'description' => 'Destination: ' . $vehicle['destination'] . ' | ' . $vehicle['description'],
                'resource_id' => $vehicle['resource_id']
            ]
        ];
    }
    
    echo json_encode([
        'success' => true,
        'events' => $events
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>