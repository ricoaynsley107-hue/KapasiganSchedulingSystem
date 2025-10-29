<?php
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../config/database.php';

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

try {
    $available_slots = [];
    
    if ($type === 'facility' && $id && $date) {
        // Get all bookings for this facility on this date
        $query = "SELECT start_time, end_time FROM facility_bookings 
                  WHERE facility_id = :id 
                  AND booking_date = :date 
                  AND status IN ('approved', 'pending')
                  ORDER BY start_time";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate available time slots (8 AM to 8 PM in 30-minute increments)
        $day_start = strtotime('08:00:00');
        $day_end = strtotime('20:00:00');
        
        for ($time = $day_start; $time < $day_end; $time += (30 * 60)) {
            $slot_start = date('H:i:s', $time);
            $slot_end = date('H:i:s', $time + (30 * 60));
            
            // Check if this slot conflicts with any booking
            $is_available = true;
            foreach ($bookings as $booking) {
                if (($slot_start < $booking['end_time'] && $slot_end > $booking['start_time'])) {
                    $is_available = false;
                    break;
                }
            }
            
            $available_slots[] = [
                'start' => $slot_start,
                'end' => $slot_end,
                'available' => $is_available,
                'display' => date('g:i A', $time) . ' - ' . date('g:i A', $time + (30 * 60))
            ];
        }
        
    } elseif ($type === 'vehicle' && $id && $date) {
        // Get all requests for this vehicle on this date
        $query = "SELECT start_time, end_time FROM vehicle_requests 
                  WHERE vehicle_id = :id 
                  AND request_date = :date 
                  AND status IN ('approved', 'pending')
                  ORDER BY start_time";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate available time slots (8 AM to 8 PM in 30-minute increments)
        $day_start = strtotime('08:00:00');
        $day_end = strtotime('20:00:00');
        
        for ($time = $day_start; $time < $day_end; $time += (30 * 60)) {
            $slot_start = date('H:i:s', $time);
            $slot_end = date('H:i:s', $time + (30 * 60));
            
            // Check if this slot conflicts with any request
            $is_available = true;
            foreach ($requests as $request) {
                if (($slot_start < $request['end_time'] && $slot_end > $request['start_time'])) {
                    $is_available = false;
                    break;
                }
            }
            
            $available_slots[] = [
                'start' => $slot_start,
                'end' => $slot_end,
                'available' => $is_available,
                'display' => date('g:i A', $time) . ' - ' . date('g:i A', $time + (30 * 60))
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'slots' => $available_slots
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
