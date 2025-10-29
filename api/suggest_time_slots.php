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

$type = $_GET['type'] ?? 'facility'; // 'facility', 'vehicle', or 'item'
$id = $_GET['id'] ?? '';
$date = $_GET['date'] ?? '';
$start_time = $_GET['start_time'] ?? '';
$end_time = $_GET['end_time'] ?? '';

try {
    if (!$id || !$date || !$start_time || !$end_time) {
        throw new Exception('Missing required parameters');
    }

    $suggestions = [];
    
    if ($type === 'facility') {
        $suggestions = suggestFacilityTimeSlots($conn, $id, $date, $start_time, $end_time);
    } elseif ($type === 'vehicle') {
        $suggestions = suggestVehicleTimeSlots($conn, $id, $date, $start_time, $end_time);
    } elseif ($type === 'item') {
        $suggestions = suggestItemDates($conn, $id, $date);
    }
    
    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Suggest alternative time slots for facilities using frequency-based algorithm
 * Analyzes last 6 months of booking history to find least busy slots
 */
function suggestFacilityTimeSlots($conn, $facility_id, $date, $start_time, $end_time) {
    $suggestions = [];
    $desired_duration = strtotime($end_time) - strtotime($start_time);
    
    // Get facility info
    $query = "SELECT * FROM facilities WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $facility_id);
    $stmt->execute();
    $facility = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facility) {
        return $suggestions;
    }
    
    // Analyze last 6 months of booking frequency
    $six_months_ago = date('Y-m-d', strtotime('-6 months'));
    $query = "SELECT 
                DATE(booking_date) as booking_date,
                start_time,
                end_time,
                COUNT(*) as frequency
              FROM facility_bookings
              WHERE facility_id = :facility_id
              AND booking_date >= :six_months_ago
              AND status IN ('approved', 'pending', 'completed')
              GROUP BY DATE(booking_date), start_time, end_time
              ORDER BY frequency ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':facility_id', $facility_id);
    $stmt->bindParam(':six_months_ago', $six_months_ago);
    $stmt->execute();
    $frequency_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build frequency map for quick lookup
    $frequency_map = [];
    foreach ($frequency_data as $record) {
        $key = $record['start_time'] . '-' . $record['end_time'];
        $frequency_map[$key] = $record['frequency'];
    }
    
    // Strategy 1: Find same-day alternative slots
    $same_day_slots = findAvailableSlotsOnDate($conn, $facility_id, $date, $desired_duration, $frequency_map);
    
    if (!empty($same_day_slots)) {
        foreach (array_slice($same_day_slots, 0, 3) as $slot) {
            $suggestions[] = [
                'type' => 'same_day_alternative',
                'date' => $date,
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'reason' => 'Alternative time slot on your requested date',
                'busy_level' => $slot['busy_level']
            ];
        }
    }
    
    // Strategy 2: Find next available dates with same time
    if (count($suggestions) < 3) {
        $next_date_slots = findNextAvailableDatesWithSameTime($conn, $facility_id, $date, $start_time, $end_time, $frequency_map);
        
        foreach (array_slice($next_date_slots, 0, 3 - count($suggestions)) as $slot) {
            $suggestions[] = [
                'type' => 'next_date_same_time',
                'date' => $slot['date'],
                'start_time' => $start_time,
                'end_time' => $end_time,
                'reason' => 'Same time on next available date',
                'busy_level' => $slot['busy_level']
            ];
        }
    }
    
    // Strategy 3: Find least busy slots across next 7 days
    if (count($suggestions) < 3) {
        $least_busy_slots = findLeastBusySlots($conn, $facility_id, $date, $desired_duration, $frequency_map);
        
        foreach (array_slice($least_busy_slots, 0, 3 - count($suggestions)) as $slot) {
            $suggestions[] = [
                'type' => 'least_busy_slot',
                'date' => $slot['date'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'reason' => 'Least busy time slot available',
                'busy_level' => $slot['busy_level']
            ];
        }
    }
    
    return $suggestions;
}

/**
 * Find available time slots on the same date
 */
function findAvailableSlotsOnDate($conn, $facility_id, $date, $desired_duration, $frequency_map) {
    $slots = [];
    $operating_start = strtotime('08:00:00');
    $operating_end = strtotime('20:00:00');
    
    // Get all bookings for this date
    $query = "SELECT start_time, end_time FROM facility_bookings
              WHERE facility_id = :facility_id
              AND booking_date = :date
              AND status IN ('approved', 'pending')
              ORDER BY start_time";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':facility_id', $facility_id);
    $stmt->bindParam(':date', $date);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate potential slots in 30-minute increments
    for ($time = $operating_start; $time < $operating_end; $time += (30 * 60)) {
        $slot_start = date('H:i:s', $time);
        $slot_end_time = $time + $desired_duration;
        $slot_end = date('H:i:s', $slot_end_time);
        
        if ($slot_end_time > $operating_end) {
            break;
        }
        
        // Check for conflicts
        $has_conflict = false;
        foreach ($bookings as $booking) {
            if (!(strtotime($slot_end) <= strtotime($booking['start_time']) || 
                  strtotime($slot_start) >= strtotime($booking['end_time']))) {
                $has_conflict = true;
                break;
            }
        }
        
        if (!$has_conflict) {
            $frequency_key = $slot_start . '-' . $slot_end;
            $frequency = $frequency_map[$frequency_key] ?? 0;
            
            $slots[] = [
                'start_time' => $slot_start,
                'end_time' => $slot_end,
                'frequency' => $frequency,
                'busy_level' => getBusyLevel($frequency)
            ];
        }
    }
    
    // Sort by frequency (least busy first)
    usort($slots, function($a, $b) {
        return $a['frequency'] - $b['frequency'];
    });
    
    return $slots;
}

/**
 * Find next available dates with the same requested time
 */
function findNextAvailableDatesWithSameTime($conn, $facility_id, $date, $start_time, $end_time, $frequency_map) {
    $slots = [];
    
    // Check next 14 days
    for ($i = 1; $i <= 14; $i++) {
        $check_date = date('Y-m-d', strtotime($date . " +{$i} days"));
        
        // Check if this time slot is available
        $query = "SELECT COUNT(*) FROM facility_bookings
                  WHERE facility_id = :facility_id
                  AND booking_date = :date
                  AND status IN ('approved', 'pending')
                  AND ((start_time <= :start_time AND end_time > :start_time)
                       OR (start_time < :end_time AND end_time >= :end_time)
                       OR (start_time >= :start_time AND end_time <= :end_time))";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':facility_id', $facility_id);
        $stmt->bindParam(':date', $check_date);
        $stmt->bindParam(':start_time', $start_time);
        $stmt->bindParam(':end_time', $end_time);
        $stmt->execute();
        $conflicts = $stmt->fetchColumn();
        
        if ($conflicts == 0) {
            $frequency_key = $start_time . '-' . $end_time;
            $frequency = $frequency_map[$frequency_key] ?? 0;
            
            $slots[] = [
                'date' => $check_date,
                'frequency' => $frequency,
                'busy_level' => getBusyLevel($frequency)
            ];
            
            if (count($slots) >= 3) break;
        }
    }
    
    return $slots;
}

/**
 * Find least busy slots across next 7 days
 */
function findLeastBusySlots($conn, $facility_id, $date, $desired_duration, $frequency_map) {
    $all_slots = [];
    $operating_start = strtotime('08:00:00');
    $operating_end = strtotime('20:00:00');
    
    // Check next 7 days
    for ($day = 0; $day < 7; $day++) {
        $check_date = date('Y-m-d', strtotime($date . " +{$day} days"));
        
        // Get all bookings for this date
        $query = "SELECT start_time, end_time FROM facility_bookings
                  WHERE facility_id = :facility_id
                  AND booking_date = :date
                  AND status IN ('approved', 'pending')
                  ORDER BY start_time";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':facility_id', $facility_id);
        $stmt->bindParam(':date', $check_date);
        $stmt->execute();
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate potential slots
        for ($time = $operating_start; $time < $operating_end; $time += (30 * 60)) {
            $slot_start = date('H:i:s', $time);
            $slot_end_time = $time + $desired_duration;
            $slot_end = date('H:i:s', $slot_end_time);
            
            if ($slot_end_time > $operating_end) {
                break;
            }
            
            // Check for conflicts
            $has_conflict = false;
            foreach ($bookings as $booking) {
                if (!(strtotime($slot_end) <= strtotime($booking['start_time']) || 
                      strtotime($slot_start) >= strtotime($booking['end_time']))) {
                    $has_conflict = true;
                    break;
                }
            }
            
            if (!$has_conflict) {
                $frequency_key = $slot_start . '-' . $slot_end;
                $frequency = $frequency_map[$frequency_key] ?? 0;
                
                $all_slots[] = [
                    'date' => $check_date,
                    'start_time' => $slot_start,
                    'end_time' => $slot_end,
                    'frequency' => $frequency,
                    'busy_level' => getBusyLevel($frequency)
                ];
            }
        }
    }
    
    // Sort by frequency (least busy first)
    usort($all_slots, function($a, $b) {
        return $a['frequency'] - $b['frequency'];
    });
    
    return $all_slots;
}

/**
 * Suggest alternative dates for vehicle requests
 */
function suggestVehicleTimeSlots($conn, $vehicle_id, $date, $start_time, $end_time) {
    // Similar logic to facility suggestions
    $suggestions = [];
    $desired_duration = strtotime($end_time) - strtotime($start_time);
    
    // Get vehicle info
    $query = "SELECT * FROM vehicles WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $vehicle_id);
    $stmt->execute();
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vehicle) {
        return $suggestions;
    }
    
    // Analyze last 6 months of request frequency
    $six_months_ago = date('Y-m-d', strtotime('-6 months'));
    $query = "SELECT 
                DATE(request_date) as request_date,
                start_time,
                end_time,
                COUNT(*) as frequency
              FROM vehicle_requests
              WHERE vehicle_id = :vehicle_id
              AND request_date >= :six_months_ago
              AND status IN ('approved', 'pending', 'completed')
              GROUP BY DATE(request_date), start_time, end_time
              ORDER BY frequency ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':vehicle_id', $vehicle_id);
    $stmt->bindParam(':six_months_ago', $six_months_ago);
    $stmt->execute();
    $frequency_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $frequency_map = [];
    foreach ($frequency_data as $record) {
        $key = $record['start_time'] . '-' . $record['end_time'];
        $frequency_map[$key] = $record['frequency'];
    }
    
    // Find next available dates with same time
    for ($i = 1; $i <= 14; $i++) {
        $check_date = date('Y-m-d', strtotime($date . " +{$i} days"));
        
        $query = "SELECT COUNT(*) FROM vehicle_requests
                  WHERE vehicle_id = :vehicle_id
                  AND request_date = :date
                  AND status IN ('approved', 'pending')
                  AND ((start_time <= :start_time AND end_time > :start_time)
                       OR (start_time < :end_time AND end_time >= :end_time)
                       OR (start_time >= :start_time AND end_time <= :end_time))";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':vehicle_id', $vehicle_id);
        $stmt->bindParam(':date', $check_date);
        $stmt->bindParam(':start_time', $start_time);
        $stmt->bindParam(':end_time', $end_time);
        $stmt->execute();
        $conflicts = $stmt->fetchColumn();
        
        if ($conflicts == 0) {
            $frequency_key = $start_time . '-' . $end_time;
            $frequency = $frequency_map[$frequency_key] ?? 0;
            
            $suggestions[] = [
                'type' => 'next_date_same_time',
                'date' => $check_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'reason' => 'Same time on next available date',
                'busy_level' => getBusyLevel($frequency)
            ];
            
            if (count($suggestions) >= 3) break;
        }
    }
    
    return $suggestions;
}

/**
 * Suggest alternative dates for item borrowing
 */
function suggestItemDates($conn, $item_id, $date) {
    $suggestions = [];
    
    // Get item info
    $query = "SELECT * FROM items WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $item_id);
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        return $suggestions;
    }
    
    // Analyze last 6 months of borrowing frequency
    $six_months_ago = date('Y-m-d', strtotime('-6 months'));
    $query = "SELECT 
                borrow_date,
                COUNT(*) as frequency
              FROM item_borrowings
              WHERE item_id = :item_id
              AND borrow_date >= :six_months_ago
              AND status IN ('approved', 'pending', 'returned')
              GROUP BY borrow_date
              ORDER BY frequency ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':item_id', $item_id);
    $stmt->bindParam(':six_months_ago', $six_months_ago);
    $stmt->execute();
    $frequency_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $frequency_map = [];
    foreach ($frequency_data as $record) {
        $frequency_map[$record['borrow_date']] = $record['frequency'];
    }
    
    // Find next available dates
    for ($i = 1; $i <= 14; $i++) {
        $check_date = date('Y-m-d', strtotime($date . " +{$i} days"));
        
        $query = "SELECT SUM(quantity) as total_borrowed FROM item_borrowings
                  WHERE item_id = :item_id
                  AND borrow_date = :date
                  AND status IN ('approved', 'pending')";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':item_id', $item_id);
        $stmt->bindParam(':date', $check_date);
        $stmt->execute();
        $borrowed = $stmt->fetchColumn() ?: 0;
        
        if ($borrowed < $item['quantity']) {
            $frequency = $frequency_map[$check_date] ?? 0;
            
            $suggestions[] = [
                'type' => 'next_available_date',
                'date' => $check_date,
                'available_quantity' => $item['quantity'] - $borrowed,
                'reason' => 'Next available date for this item',
                'busy_level' => getBusyLevel($frequency)
            ];
            
            if (count($suggestions) >= 3) break;
        }
    }
    
    return $suggestions;
}

/**
 * Get busy level label based on frequency
 */
function getBusyLevel($frequency) {
    if ($frequency == 0) {
        return 'Not booked';
    } elseif ($frequency <= 2) {
        return 'Low demand';
    } elseif ($frequency <= 5) {
        return 'Moderate demand';
    } else {
        return 'High demand';
    }
}
?>
