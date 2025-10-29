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

$type = $_GET['type'] ?? ''; // 'item', 'facility', or 'vehicle'
$date = $_GET['date'] ?? date('Y-m-d');
$quantity = intval($_GET['quantity'] ?? 1);
$passengers = intval($_GET['passengers'] ?? 1);
$start_time = $_GET['start_time'] ?? '08:00:00';
$end_time = $_GET['end_time'] ?? '17:00:00';
$user_id = $_SESSION['user_id'];

try {
    // Step 1: Analyze user's historical preferences
    $user_preferences = analyzeUserPreferences($conn, $user_id, $type);
    
    // Step 2: Get available resources
    $available_resources = getAvailableResources($conn, $type, $date, $start_time, $end_time, $quantity, $passengers);
    
    // Step 3: Check for barangay event conflicts
    $event_conflicts = checkBarangayEventConflicts($conn, $date, $start_time, $end_time);
    
    // Step 4: Score and rank recommendations
    $recommendations = scoreAndRankRecommendations(
        $conn,
        $available_resources,
        $user_preferences,
        $event_conflicts,
        $type,
        $date,
        $start_time,
        $end_time,
        $quantity,
        $passengers
    );
    
    // Step 5: Return top 3 recommendations with justifications
    $top_recommendations = array_slice($recommendations, 0, 3);
    
    echo json_encode([
        'success' => true,
        'recommendations' => $top_recommendations,
        'user_preferences' => $user_preferences,
        'event_conflicts' => $event_conflicts
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Analyze user's historical preferences and patterns
 */
function analyzeUserPreferences($conn, $user_id, $type) {
    $preferences = [
        'preferred_time_slot' => 'morning',
        'frequent_items' => [],
        'frequent_facilities' => [],
        'frequent_vehicles' => [],
        'avg_duration' => 120, // default 2 hours
        'booking_count' => 0
    ];
    
    // Check if user preferences exist in database
    $query = "SELECT * FROM user_preferences WHERE user_id = :user_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $stored_prefs = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stored_prefs) {
        $preferences['preferred_time_slot'] = $stored_prefs['preferred_time_slot'];
        $preferences['avg_duration'] = $stored_prefs['avg_booking_duration'];
    }
    
    // Analyze booking patterns based on type
    if ($type === 'item') {
        // Get most borrowed items
        $query = "SELECT i.id, i.name, i.category, COUNT(*) as borrow_count 
                  FROM item_borrowings ib 
                  JOIN items i ON ib.item_id = i.id 
                  WHERE ib.user_id = :user_id 
                  GROUP BY i.id, i.name, i.category 
                  ORDER BY borrow_count DESC 
                  LIMIT 5";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $preferences['frequent_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total booking count
        $query = "SELECT COUNT(*) FROM item_borrowings WHERE user_id = :user_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $preferences['booking_count'] = $stmt->fetchColumn();
        
    } elseif ($type === 'facility') {
        // Get most booked facilities and time preferences
        $query = "SELECT f.id, f.name, COUNT(*) as booking_count,
                  AVG(TIMESTAMPDIFF(MINUTE, fb.start_time, fb.end_time)) as avg_duration,
                  CASE 
                      WHEN HOUR(fb.start_time) < 12 THEN 'morning'
                      WHEN HOUR(fb.start_time) < 17 THEN 'afternoon'
                      ELSE 'evening'
                  END as time_slot
                  FROM facility_bookings fb 
                  JOIN facilities f ON fb.facility_id = f.id 
                  WHERE fb.user_id = :user_id 
                  GROUP BY f.id, f.name, time_slot 
                  ORDER BY booking_count DESC 
                  LIMIT 5";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $preferences['frequent_facilities'] = $facilities;
        
        if (!empty($facilities)) {
            $preferences['preferred_time_slot'] = $facilities[0]['time_slot'];
            $preferences['avg_duration'] = round($facilities[0]['avg_duration']);
        }
        
        // Get total booking count
        $query = "SELECT COUNT(*) FROM facility_bookings WHERE user_id = :user_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $preferences['booking_count'] = $stmt->fetchColumn();
        
    } elseif ($type === 'vehicle') {
        // Get most requested vehicles
        $query = "SELECT v.id, v.name, v.type, COUNT(*) as request_count 
                  FROM vehicle_requests vr 
                  JOIN vehicles v ON vr.vehicle_id = v.id 
                  WHERE vr.user_id = :user_id 
                  GROUP BY v.id, v.name, v.type 
                  ORDER BY request_count DESC 
                  LIMIT 5";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $preferences['frequent_vehicles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total request count
        $query = "SELECT COUNT(*) FROM vehicle_requests WHERE user_id = :user_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $preferences['booking_count'] = $stmt->fetchColumn();
    }
    
    return $preferences;
}

/**
 * Get available resources based on type
 */
function getAvailableResources($conn, $type, $date, $start_time, $end_time, $quantity, $passengers) {
    $resources = [];
    
    if ($type === 'item') {
        // Get all available items
        $query = "SELECT * FROM items WHERE status = 'available' ORDER BY name";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as $item) {
            // Check availability for this item
            $query = "SELECT SUM(ib.quantity) as total_borrowed 
                      FROM item_borrowings ib 
                      WHERE ib.item_id = :item_id 
                      AND ib.status IN ('approved', 'pending')
                      AND (
                          (ib.borrow_date <= :date AND ib.return_date >= :date)
                          OR (ib.borrow_date = :date)
                      )";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':item_id', $item['id']);
            $stmt->bindParam(':date', $date);
            $stmt->execute();
            $borrowed = $stmt->fetchColumn() ?: 0;
            
            $available = $item['quantity'] - $borrowed;
            
            if ($available >= $quantity) {
                $item['available_quantity'] = $available;
                $resources[] = $item;
            }
        }
        
    } elseif ($type === 'facility') {
        // Get all available facilities
        $query = "SELECT * FROM facilities WHERE status = 'available' ORDER BY name";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($facilities as $facility) {
            // Check if facility is available at this time
            $query = "SELECT COUNT(*) FROM facility_bookings 
                      WHERE facility_id = :facility_id 
                      AND booking_date = :date 
                      AND status IN ('approved', 'pending')
                      AND ((start_time <= :start_time AND end_time > :start_time) 
                           OR (start_time < :end_time AND end_time >= :end_time)
                           OR (start_time >= :start_time AND end_time <= :end_time))";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':facility_id', $facility['id']);
            $stmt->bindParam(':date', $date);
            $stmt->bindParam(':start_time', $start_time);
            $stmt->bindParam(':end_time', $end_time);
            $stmt->execute();
            $conflicts = $stmt->fetchColumn();
            
            if ($conflicts == 0) {
                $resources[] = $facility;
            }
        }
        
    } elseif ($type === 'vehicle') {
        // Get all available vehicles
        $query = "SELECT * FROM vehicles WHERE status = 'available' ORDER BY name";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($vehicles as $vehicle) {
            // Check if vehicle is available at this time and has capacity
            if ($vehicle['capacity'] >= $passengers) {
                $query = "SELECT COUNT(*) FROM vehicle_requests 
                          WHERE vehicle_id = :vehicle_id 
                          AND request_date = :date 
                          AND status IN ('approved', 'pending')
                          AND ((start_time <= :start_time AND end_time > :start_time) 
                               OR (start_time < :end_time AND end_time >= :end_time)
                               OR (start_time >= :start_time AND end_time <= :end_time))";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':vehicle_id', $vehicle['id']);
                $stmt->bindParam(':date', $date);
                $stmt->bindParam(':start_time', $start_time);
                $stmt->bindParam(':end_time', $end_time);
                $stmt->execute();
                $conflicts = $stmt->fetchColumn();
                
                if ($conflicts == 0) {
                    $resources[] = $vehicle;
                }
            }
        }
    }
    
    return $resources;
}

/**
 * Check for barangay event conflicts
 */
function checkBarangayEventConflicts($conn, $date, $start_time, $end_time) {
    $query = "SELECT * FROM barangay_events 
              WHERE event_date = :date 
              AND status = 'scheduled'
              AND ((start_time <= :start_time AND end_time > :start_time) 
                   OR (start_time < :end_time AND end_time >= :end_time)
                   OR (start_time >= :start_time AND end_time <= :end_time))";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':date', $date);
    $stmt->bindParam(':start_time', $start_time);
    $stmt->bindParam(':end_time', $end_time);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Score and rank recommendations based on multiple criteria
 */
function scoreAndRankRecommendations($conn, $resources, $preferences, $event_conflicts, $type, $date, $start_time, $end_time, $quantity, $passengers) {
    $recommendations = [];
    
    foreach ($resources as $resource) {
        $score = 0;
        $justifications = [];
        
        // Base score: 50 points for being available
        $score += 50;
        $justifications[] = "Available on your requested date and time";
        
        // User preference matching (up to 30 points)
        if ($type === 'item') {
            // Check if item matches user's frequent categories
            foreach ($preferences['frequent_items'] as $freq_item) {
                if ($freq_item['category'] === $resource['category']) {
                    $score += 15;
                    $justifications[] = "Matches your frequently borrowed category ({$resource['category']})";
                    break;
                }
                if ($freq_item['id'] === $resource['id']) {
                    $score += 15;
                    $justifications[] = "You've borrowed this item before";
                    break;
                }
            }
            
            // Bonus for auto-approve items
            if ($resource['auto_approve']) {
                $score += 10;
                $justifications[] = "Auto-approved - no waiting for admin approval";
            }
            
        } elseif ($type === 'facility') {
            // Check if facility matches user's frequent bookings
            foreach ($preferences['frequent_facilities'] as $freq_facility) {
                if ($freq_facility['id'] === $resource['id']) {
                    $score += 20;
                    $justifications[] = "Your most frequently booked facility";
                    break;
                }
            }
            
            // Time slot preference matching
            $requested_hour = intval(substr($start_time, 0, 2));
            $time_slot = $requested_hour < 12 ? 'morning' : ($requested_hour < 17 ? 'afternoon' : 'evening');
            
            if ($time_slot === $preferences['preferred_time_slot']) {
                $score += 10;
                $justifications[] = "Aligns with your usual {$preferences['preferred_time_slot']} schedule";
            }
            
        } elseif ($type === 'vehicle') {
            // Check if vehicle matches user's frequent requests
            foreach ($preferences['frequent_vehicles'] as $freq_vehicle) {
                if ($freq_vehicle['id'] === $resource['id']) {
                    $score += 20;
                    $justifications[] = "Your most frequently requested vehicle";
                    break;
                }
            }
            
            // Capacity efficiency bonus
            if ($resource['capacity'] >= $passengers && $resource['capacity'] <= $passengers + 3) {
                $score += 10;
                $justifications[] = "Optimal capacity for your passenger count";
            }
        }
        
        // Barangay event conflict penalty (deduct 20 points)
        if (!empty($event_conflicts)) {
            $score -= 20;
            $justifications[] = "Note: Barangay event scheduled during this time - {$event_conflicts[0]['title']}";
        }
        
        // Demand analysis (up to 20 points for low demand)
        $demand_score = analyzeDemand($conn, $type, $resource['id'], $date);
        $score += $demand_score;
        if ($demand_score >= 15) {
            $justifications[] = "Low booking demand on this date";
        } elseif ($demand_score >= 10) {
            $justifications[] = "Moderate booking demand on this date";
        }
        
        // Popularity bonus for new users (if booking count < 3)
        if ($preferences['booking_count'] < 3) {
            $popularity = getResourcePopularity($conn, $type, $resource['id']);
            if ($popularity > 5) {
                $score += 10;
                $justifications[] = "Popular choice among residents";
            }
        }
        
        $recommendations[] = [
            'resource' => $resource,
            'score' => $score,
            'justifications' => $justifications,
            'rank' => 0 // Will be set after sorting
        ];
    }
    
    // Sort by score (highest first)
    usort($recommendations, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    // Assign ranks
    foreach ($recommendations as $index => &$rec) {
        $rec['rank'] = $index + 1;
    }
    
    return $recommendations;
}

/**
 * Analyze demand for a resource on a specific date
 */
function analyzeDemand($conn, $type, $resource_id, $date) {
    $score = 20; // Start with max score
    
    if ($type === 'item') {
        $query = "SELECT COUNT(*) FROM item_borrowings 
                  WHERE item_id = :id 
                  AND borrow_date = :date 
                  AND status IN ('approved', 'pending')";
    } elseif ($type === 'facility') {
        $query = "SELECT COUNT(*) FROM facility_bookings 
                  WHERE facility_id = :id 
                  AND booking_date = :date 
                  AND status IN ('approved', 'pending')";
    } elseif ($type === 'vehicle') {
        $query = "SELECT COUNT(*) FROM vehicle_requests 
                  WHERE vehicle_id = :id 
                  AND request_date = :date 
                  AND status IN ('approved', 'pending')";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $resource_id);
    $stmt->bindParam(':date', $date);
    $stmt->execute();
    $demand = $stmt->fetchColumn();
    
    // Reduce score based on demand
    $score -= ($demand * 5); // Deduct 5 points per existing booking
    
    return max(0, $score); // Ensure non-negative
}

/**
 * Get overall popularity of a resource
 */
function getResourcePopularity($conn, $type, $resource_id) {
    if ($type === 'item') {
        $query = "SELECT COUNT(*) FROM item_borrowings WHERE item_id = :id";
    } elseif ($type === 'facility') {
        $query = "SELECT COUNT(*) FROM facility_bookings WHERE facility_id = :id";
    } elseif ($type === 'vehicle') {
        $query = "SELECT COUNT(*) FROM vehicle_requests WHERE vehicle_id = :id";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $resource_id);
    $stmt->execute();
    
    return $stmt->fetchColumn();
}
?>
