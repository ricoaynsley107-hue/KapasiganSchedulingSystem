<?php
/**
 * Get Available Time Slots for a Facility
 * 
 * @param PDO $conn Database connection
 * @param int $facility_id Facility ID
 * @param string $date Date in Y-m-d format
 * @return array Available and booked time slots
 */
function getTimeSlotAvailability($conn, $facility_id, $date) {
    // Define operating hours (8 AM to 6 PM in 30-minute intervals)
    $start_hour = 8;
    $end_hour = 18;
    $interval_minutes = 30;
    
    // Generate all possible time slots
    $all_slots = [];
    $current_time = strtotime("$date $start_hour:00:00");
    $end_time = strtotime("$date $end_hour:00:00");
    
    while ($current_time < $end_time) {
        $slot_start = date('H:i:00', $current_time);
        $slot_end = date('H:i:00', strtotime("+$interval_minutes minutes", $current_time));
        
        $all_slots[] = [
            'start' => $slot_start,
            'end' => $slot_end,
            'display' => date('g:i A', $current_time) . ' - ' . date('g:i A', strtotime("+$interval_minutes minutes", $current_time)),
            'available' => true
        ];
        
        $current_time = strtotime("+$interval_minutes minutes", $current_time);
    }
    
    // Get all bookings for this facility on this date
    $query = "SELECT start_time, end_time 
              FROM facility_bookings 
              WHERE facility_id = :facility_id 
              AND booking_date = :date 
              AND status IN ('approved', 'pending')
              ORDER BY start_time";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':facility_id', $facility_id);
    $stmt->bindParam(':date', $date);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark slots as unavailable if they conflict with bookings
    foreach ($all_slots as &$slot) {
        $slot_start_time = strtotime("$date {$slot['start']}");
        $slot_end_time = strtotime("$date {$slot['end']}");
        
        foreach ($bookings as $booking) {
            $booking_start = strtotime("$date {$booking['start_time']}");
            $booking_end = strtotime("$date {$booking['end_time']}");
            
            // Check if slot overlaps with booking
            if (($slot_start_time >= $booking_start && $slot_start_time < $booking_end) ||
                ($slot_end_time > $booking_start && $slot_end_time <= $booking_end) ||
                ($slot_start_time <= $booking_start && $slot_end_time >= $booking_end)) {
                $slot['available'] = false;
                break;
            }
        }
    }
    
    return [
        'slots' => $all_slots,
        'bookings' => $bookings,
        'date' => $date
    ];
}

/**
 * Check if a specific time range is available
 * 
 * @param PDO $conn Database connection
 * @param int $facility_id Facility ID
 * @param string $date Date in Y-m-d format
 * @param string $start_time Start time (H:i:s)
 * @param string $end_time End time (H:i:s)
 * @return array Result with availability status and conflicts
 */
function checkTimeRangeAvailability($conn, $facility_id, $date, $start_time, $end_time) {
    $query = "SELECT fb.*, u.full_name, f.name as facility_name 
              FROM facility_bookings fb 
              JOIN users u ON fb.user_id = u.id 
              JOIN facilities f ON fb.facility_id = f.id
              WHERE fb.facility_id = :facility_id 
              AND fb.booking_date = :date 
              AND fb.status IN ('approved', 'pending')
              AND (
                  (fb.start_time <= :start_time AND fb.end_time > :start_time) OR
                  (fb.start_time < :end_time AND fb.end_time >= :end_time) OR
                  (fb.start_time >= :start_time AND fb.end_time <= :end_time)
              )";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':facility_id', $facility_id);
    $stmt->bindParam(':date', $date);
    $stmt->bindParam(':start_time', $start_time);
    $stmt->bindParam(':end_time', $end_time);
    $stmt->execute();
    
    $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'available' => empty($conflicts),
        'conflicts' => $conflicts,
        'conflict_count' => count($conflicts)
    ];
}

/**
 * Get suggested alternative time slots
 * 
 * @param PDO $conn Database connection
 * @param int $facility_id Facility ID
 * @param string $date Date in Y-m-d format
 * @param int $duration_hours Desired duration in hours
 * @return array Suggested time slots
 */
function getSuggestedTimeSlots($conn, $facility_id, $date, $duration_hours = 2) {
    $availability = getTimeSlotAvailability($conn, $facility_id, $date);
    $slots = $availability['slots'];
    $suggested = [];
    
    $slots_needed = ($duration_hours * 60) / 30; // Convert hours to 30-min slots
    
    // Find consecutive available slots
    $consecutive_count = 0;
    $start_index = null;
    
    foreach ($slots as $index => $slot) {
        if ($slot['available']) {
            if ($consecutive_count === 0) {
                $start_index = $index;
            }
            $consecutive_count++;
            
            if ($consecutive_count >= $slots_needed) {
                $suggested[] = [
                    'start_time' => $slots[$start_index]['start'],
                    'end_time' => $slot['end'],
                    'display' => date('g:i A', strtotime($slots[$start_index]['start'])) . ' - ' . 
                                date('g:i A', strtotime($slot['end']))
                ];
                
                // Only suggest up to 5 options
                if (count($suggested) >= 5) {
                    break;
                }
            }
        } else {
            $consecutive_count = 0;
            $start_index = null;
        }
    }
    
    return $suggested;
}
?>