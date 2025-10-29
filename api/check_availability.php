<?php
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once 'ml_predict.php'; // ML Integration

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$conn = $database->getConnection();

$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';
$date = $_GET['date'] ?? '';
$start_time = $_GET['start_time'] ?? '';
$end_time = $_GET['end_time'] ?? '';
$quantity = $_GET['quantity'] ?? 1;

try {
    $conflicts = [];
    $suggestions = [];
    $ml_prediction = null;
    
    if ($type === 'facility') {
        // Check facility conflicts
        $query = "SELECT fb.*, u.full_name, f.name as facility_name 
                  FROM facility_bookings fb 
                  JOIN users u ON fb.user_id = u.id 
                  JOIN facilities f ON fb.facility_id = f.id
                  WHERE fb.facility_id = :id 
                  AND fb.booking_date = :date 
                  AND fb.status IN ('approved', 'pending')
                  AND ((fb.start_time <= :start_time AND fb.end_time > :start_time) 
                       OR (fb.start_time < :end_time AND fb.end_time >= :end_time)
                       OR (fb.start_time >= :start_time AND fb.end_time <= :end_time))";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':start_time', $start_time);
        $stmt->bindParam(':end_time', $end_time);
        $stmt->execute();
        
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 🤖 GET ML PREDICTION (even if no conflicts)
        if (empty($conflicts)) {
            $user_stats = get_user_booking_stats($_SESSION['user_id'], $conn);
            
            $hour_of_day = (int)date('H', strtotime($start_time));
            $day_of_week = (int)date('N', strtotime($date));
            $advance_days = (strtotime($date) - time()) / (60*60*24);
            $duration_hours = (strtotime($end_time) - strtotime($start_time)) / 3600;
            $is_weekend = in_array($day_of_week, [6, 7]) ? 1 : 0;
            
            $demand_query = "SELECT COUNT(*) as demand FROM facility_bookings 
                             WHERE facility_id = :id AND booking_date = :date";
            $stmt = $conn->prepare($demand_query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':date', $date);
            $stmt->execute();
            $same_day_demand = $stmt->fetchColumn();
            
            $ml_data = [
                'hour_of_day' => $hour_of_day,
                'day_of_week' => $day_of_week,
                'advance_booking_days' => $advance_days,
                'duration_hours' => $duration_hours,
                'user_approval_rate' => $user_stats['user_approval_rate'],
                'user_completion_rate' => $user_stats['user_completion_rate'],
                'same_day_facility_demand' => $same_day_demand,
                'is_weekend' => $is_weekend
            ];
            
            $ml_prediction = predict_approval($ml_data);
            
            // Also get no-show prediction
            $noshow_prediction = predict_noshow($ml_data);
            $ml_prediction['noshow_risk'] = $noshow_prediction;
        }
        
        // Find suggestions if conflicts exist
        if (!empty($conflicts)) {
            $suggestions = findNextAvailableSlots($conn, 'facility', $id, $date, $start_time, $end_time);
        }
        
    } elseif ($type === 'vehicle') {
        // Similar implementation for vehicles
        $query = "SELECT vr.*, u.full_name, v.name as vehicle_name 
                  FROM vehicle_requests vr 
                  JOIN users u ON vr.user_id = u.id 
                  JOIN vehicles v ON vr.vehicle_id = v.id
                  WHERE vr.vehicle_id = :id 
                  AND vr.request_date = :date 
                  AND vr.status IN ('approved', 'pending')
                  AND ((vr.start_time <= :start_time AND vr.end_time > :start_time) 
                       OR (vr.start_time < :end_time AND vr.end_time >= :end_time)
                       OR (vr.start_time >= :start_time AND vr.end_time <= :end_time))";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':start_time', $start_time);
        $stmt->bindParam(':end_time', $end_time);
        $stmt->execute();
        
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($type === 'item') {
        // Item availability check
        $query = "SELECT SUM(ib.quantity) as total_borrowed 
                  FROM item_borrowings ib 
                  WHERE ib.item_id = :id 
                  AND ib.status IN ('approved', 'pending')
                  AND (
                      (ib.borrow_date <= :date AND ib.return_date >= :date)
                      OR (ib.borrow_date = :date)
                  )";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        
        $borrowed_quantity = $stmt->fetchColumn() ?: 0;
        
        $query = "SELECT quantity, name FROM items WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $available_quantity = $item['quantity'] - $borrowed_quantity;
        
        if ($available_quantity < $quantity) {
            $conflicts[] = [
                'message' => "Only {$available_quantity} units available",
                'available_quantity' => $available_quantity,
                'requested_quantity' => $quantity
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'has_conflicts' => !empty($conflicts),
        'conflicts' => $conflicts,
        'suggestions' => $suggestions,
        'ml_prediction' => $ml_prediction // Include ML prediction in response
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function findNextAvailableSlots($conn, $type, $id, $date, $start_time, $end_time) {
    $suggestions = [];
    $table = $type === 'facility' ? 'facility_bookings' : 'vehicle_requests';
    $id_field = $type === 'facility' ? 'facility_id' : 'vehicle_id';
    $date_field = $type === 'facility' ? 'booking_date' : 'request_date';
    
    for ($i = 0; $i < 7; $i++) {
        $check_date = date('Y-m-d', strtotime($date . " +{$i} days"));
        
        $query = "SELECT start_time, end_time FROM {$table} 
                  WHERE {$id_field} = :id 
                  AND {$date_field} = :check_date 
                  AND status IN ('approved', 'pending')
                  ORDER BY start_time";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':check_date', $check_date);
        $stmt->execute();
        
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $available_slots = findAvailableTimeSlots($bookings, $start_time, $end_time);
        
        if (!empty($available_slots)) {
            $suggestions[] = [
                'date' => $check_date,
                'available_slots' => $available_slots
            ];
            
            if (count($suggestions) >= 3) break;
        }
    }
    
    return $suggestions;
}

function findAvailableTimeSlots($bookings, $desired_start, $desired_end) {
    $slots = [];
    $day_start = strtotime('08:00:00');
    $day_end = strtotime('20:00:00');
    $desired_duration = strtotime($desired_end) - strtotime($desired_start);
    
    for ($time = $day_start; $time < $day_end; $time += (30 * 60)) {
        $slot_start = date('H:i:s', $time);
        $slot_end = date('H:i:s', $time + $desired_duration);
        
        if (($time + $desired_duration) > $day_end) break;
        
        $is_conflict = false;
        foreach ($bookings as $booking) {
            if ($slot_start < $booking['end_time'] && $slot_end > $booking['start_time']) {
                $is_conflict = true;
                break;
            }
        }
        
        if (!$is_conflict) {
            $slots[] = ['start' => $slot_start, 'end' => $slot_end];
            if (count($slots) >= 3) break;
        }
    }
    
    return $slots;
}
?>