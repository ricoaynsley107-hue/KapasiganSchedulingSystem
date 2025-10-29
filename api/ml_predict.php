<?php
// api/ml_predict.php - Bridge between PHP and Python ML models (PDO VERSION)

function predict_approval($booking_data) {
    /**
     * Call Python ML model to predict if booking should be auto-approved
     * 
     * @param array $booking_data Array with booking features
     * @return array Prediction result
     */
    
    // Prepare data
    $json_data = json_encode($booking_data);
    
    // Path to Python script (adjust based on your setup)
    $python_path = 'python';  // or full path: 'C:\\Python313\\python.exe'
    $script_path = __DIR__ . '/../ml/scripts/3_predict.py';
    
    // Execute Python script
    $command = "$python_path \"$script_path\" approval " . escapeshellarg($json_data);
    $output = shell_exec($command . ' 2>&1');
    
    // Parse result
    $result = json_decode($output, true);
    
    if (!$result || isset($result['error'])) {
        // Fallback to manual review if ML fails
        return [
            'prediction' => 'manual_review',
            'confidence' => 0,
            'should_auto_approve' => false,
            'error' => $result['error'] ?? 'ML prediction failed'
        ];
    }
    
    return $result;
}

function predict_noshow($booking_data) {
    /**
     * Predict probability of no-show
     * 
     * @param array $booking_data Array with booking features
     * @return array No-show prediction
     */
    
    $json_data = json_encode($booking_data);
    
    $python_path = 'python';
    $script_path = __DIR__ . '/../ml/scripts/3_predict.py';
    
    $command = "$python_path \"$script_path\" noshow " . escapeshellarg($json_data);
    $output = shell_exec($command . ' 2>&1');
    
    $result = json_decode($output, true);
    
    if (!$result || isset($result['error'])) {
        return [
            'noshow_probability' => 0.5,
            'show_probability' => 0.5, // ADD THIS
            'send_extra_reminder' => true,
            'risk_level' => 'unknown',
            'error' => $result['error'] ?? 'ML prediction failed'
        ];
    }
    
    // Ensure show_probability exists
    if (!isset($result['show_probability']) && isset($result['noshow_probability'])) {
        $result['show_probability'] = 1 - $result['noshow_probability'];
    } else if (!isset($result['show_probability'])) {
        $result['show_probability'] = 0.5;
    }
    
    return $result;
}

// Helper function to calculate user stats (PDO VERSION)
function get_user_booking_stats($user_id, $conn) {
    /**
     * Calculate user booking statistics for ML features
     * 
     * @param int $user_id User ID
     * @param PDO $conn PDO database connection
     * @return array User statistics
     */
    
    $query = "
        SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM facility_bookings
        WHERE user_id = :user_id
    ";
    
    try {
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || $result['total_bookings'] == 0) {
            return [
                'user_approval_rate' => 0.5,
                'user_completion_rate' => 0.5,
                'total_bookings' => 0
            ];
        }
        
        $approval_rate = $result['total_bookings'] > 0 
            ? $result['approved_count'] / $result['total_bookings'] 
            : 0.5;
        
        $completion_rate = $result['approved_count'] > 0 
            ? $result['completed_count'] / $result['approved_count'] 
            : 0.5;
        
        return [
            'user_approval_rate' => $approval_rate,
            'user_completion_rate' => $completion_rate,
            'total_bookings' => $result['total_bookings']
        ];
        
    } catch (PDOException $e) {
        // Return default values on error
        error_log("ML Predict Error: " . $e->getMessage());
        return [
            'user_approval_rate' => 0.5,
            'user_completion_rate' => 0.5,
            'total_bookings' => 0
        ];
    }
}

// Helper function for item borrowing stats
function get_user_borrowing_stats($user_id, $conn) {
    /**
     * Calculate user borrowing statistics for ML features
     * 
     * @param int $user_id User ID
     * @param PDO $conn PDO database connection
     * @return array User statistics
     */
    
    $query = "
        SELECT 
            COUNT(*) as total_borrowings,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned_count
        FROM item_borrowings
        WHERE user_id = :user_id
    ";
    
    try {
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || $result['total_borrowings'] == 0) {
            return [
                'user_approval_rate' => 0.5,
                'user_completion_rate' => 0.5,
                'total_borrowings' => 0
            ];
        }
        
        $approval_rate = $result['total_borrowings'] > 0 
            ? $result['approved_count'] / $result['total_borrowings'] 
            : 0.5;
        
        $completion_rate = $result['approved_count'] > 0 
            ? $result['returned_count'] / $result['approved_count'] 
            : 0.5;
        
        return [
            'user_approval_rate' => $approval_rate,
            'user_completion_rate' => $completion_rate,
            'total_borrowings' => $result['total_borrowings']
        ];
        
    } catch (PDOException $e) {
        error_log("ML Predict Error: " . $e->getMessage());
        return [
            'user_approval_rate' => 0.5,
            'user_completion_rate' => 0.5,
            'total_borrowings' => 0
        ];
    }
}

// Helper function for vehicle request stats
function get_user_vehicle_stats($user_id, $conn) {
    /**
     * Calculate user vehicle request statistics for ML features
     * 
     * @param int $user_id User ID
     * @param PDO $conn PDO database connection
     * @return array User statistics
     */
    
    $query = "
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
        FROM vehicle_requests
        WHERE user_id = :user_id
    ";
    
    try {
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || $result['total_requests'] == 0) {
            return [
                'user_approval_rate' => 0.5,
                'user_completion_rate' => 0.5,
                'total_requests' => 0
            ];
        }
        
        $approval_rate = $result['total_requests'] > 0 
            ? $result['approved_count'] / $result['total_requests'] 
            : 0.5;
        
        $completion_rate = $result['approved_count'] > 0 
            ? $result['completed_count'] / $result['approved_count'] 
            : 0.5;
        
        return [
            'user_approval_rate' => $approval_rate,
            'user_completion_rate' => $completion_rate,
            'total_requests' => $result['total_requests']
        ];
        
    } catch (PDOException $e) {
        error_log("ML Predict Error: " . $e->getMessage());
        return [
            'user_approval_rate' => 0.5,
            'user_completion_rate' => 0.5,
            'total_requests' => 0
        ];
    }
}
?>