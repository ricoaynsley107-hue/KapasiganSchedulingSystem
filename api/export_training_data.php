<?php
/**
 * Export Training Data for ML Models
 * Exports booking, borrowing, and request data in CSV format for ML model training
 */

require_once '../includes/auth.php';
require_once '../config/database.php';

$auth = new Auth();
$auth->requireAdmin();

$database = new Database();
$conn = $database->getConnection();

// Get export type (default: all)
$export_type = $_GET['type'] ?? 'all';

try {
    // Set headers for CSV download
    $filename = "training_data_" . date('Y-m-d_His') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    if ($export_type === 'all' || $export_type === 'facility_bookings') {
        // Export Facility Bookings Data
        fputcsv($output, ['=== FACILITY BOOKINGS DATA ===']);
        fputcsv($output, [
            'booking_id',
            'user_id',
            'facility_id',
            'booking_date',
            'start_time',
            'end_time',
            'hour_of_day',
            'day_of_week',
            'advance_booking_days',
            'duration_hours',
            'purpose',
            'request_type',
            'status',
            'expected_attendees',
            'actual_attendees',
            'satisfaction_rating',
            'budget_allocated',
            'actual_cost',
            'created_at',
            'is_weekend',
            'is_evening',
            'is_holiday'
        ]);
        
        $query = "SELECT 
            fb.id as booking_id,
            fb.user_id,
            fb.facility_id,
            fb.booking_date,
            fb.start_time,
            fb.end_time,
            HOUR(fb.start_time) as hour_of_day,
            DAYOFWEEK(fb.booking_date) as day_of_week,
            DATEDIFF(fb.booking_date, fb.created_at) as advance_booking_days,
            TIMESTAMPDIFF(HOUR, fb.start_time, fb.end_time) as duration_hours,
            fb.purpose,
            fb.request_type,
            fb.status,
            fb.expected_attendees,
            fb.actual_attendees,
            fb.satisfaction_rating,
            fb.budget_allocated,
            fb.actual_cost,
            fb.created_at,
            CASE WHEN DAYOFWEEK(fb.booking_date) IN (1, 7) THEN 1 ELSE 0 END as is_weekend,
            CASE WHEN HOUR(fb.start_time) >= 18 THEN 1 ELSE 0 END as is_evening,
            0 as is_holiday
        FROM facility_bookings fb
        WHERE fb.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        ORDER BY fb.created_at DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        
        fputcsv($output, []); // Empty line separator
    }
    
    if ($export_type === 'all' || $export_type === 'vehicle_requests') {
        // Export Vehicle Requests Data
        fputcsv($output, ['=== VEHICLE REQUESTS DATA ===']);
        fputcsv($output, [
            'request_id',
            'user_id',
            'vehicle_id',
            'request_date',
            'start_time',
            'end_time',
            'hour_of_day',
            'day_of_week',
            'advance_request_days',
            'duration_hours',
            'destination',
            'purpose',
            'passenger_count',
            'request_type',
            'status',
            'estimated_cost',
            'actual_cost',
            'satisfaction_rating',
            'created_at',
            'is_weekend',
            'is_emergency'
        ]);
        
        $query = "SELECT 
            vr.id as request_id,
            vr.user_id,
            vr.vehicle_id,
            vr.request_date,
            vr.start_time,
            vr.end_time,
            HOUR(vr.start_time) as hour_of_day,
            DAYOFWEEK(vr.request_date) as day_of_week,
            DATEDIFF(vr.request_date, vr.created_at) as advance_request_days,
            TIMESTAMPDIFF(HOUR, vr.start_time, vr.end_time) as duration_hours,
            vr.destination,
            vr.purpose,
            vr.passenger_count,
            vr.request_type,
            vr.status,
            vr.estimated_cost,
            vr.actual_cost,
            vr.satisfaction_rating,
            vr.created_at,
            CASE WHEN DAYOFWEEK(vr.request_date) IN (1, 7) THEN 1 ELSE 0 END as is_weekend,
            CASE WHEN vr.purpose LIKE '%emergency%' THEN 1 ELSE 0 END as is_emergency
        FROM vehicle_requests vr
        WHERE vr.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        ORDER BY vr.created_at DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        
        fputcsv($output, []); // Empty line separator
    }
    
    if ($export_type === 'all' || $export_type === 'item_borrowings') {
        // Export Item Borrowings Data
        fputcsv($output, ['=== ITEM BORROWINGS DATA ===']);
        fputcsv($output, [
            'borrowing_id',
            'user_id',
            'item_id',
            'quantity',
            'borrow_date',
            'return_date',
            'actual_return_date',
            'day_of_week',
            'advance_booking_days',
            'duration_days',
            'purpose',
            'request_type',
            'status',
            'item_value',
            'damage_cost',
            'condition_before',
            'condition_after',
            'satisfaction_rating',
            'created_at',
            'is_weekend',
            'is_overdue'
        ]);
        
        $query = "SELECT 
            ib.id as borrowing_id,
            ib.user_id,
            ib.item_id,
            ib.quantity,
            ib.borrow_date,
            ib.return_date,
            ib.actual_return_date,
            DAYOFWEEK(ib.borrow_date) as day_of_week,
            DATEDIFF(ib.borrow_date, ib.created_at) as advance_booking_days,
            DATEDIFF(ib.return_date, ib.borrow_date) as duration_days,
            ib.purpose,
            ib.request_type,
            ib.status,
            ib.item_value,
            ib.damage_cost,
            ib.condition_before,
            ib.condition_after,
            ib.satisfaction_rating,
            ib.created_at,
            CASE WHEN DAYOFWEEK(ib.borrow_date) IN (1, 7) THEN 1 ELSE 0 END as is_weekend,
            CASE WHEN ib.actual_return_date > ib.return_date THEN 1 ELSE 0 END as is_overdue
        FROM item_borrowings ib
        WHERE ib.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        ORDER BY ib.created_at DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        
        fputcsv($output, []); // Empty line separator
    }
    
    if ($export_type === 'all' || $export_type === 'user_stats') {
        // Export User Statistics
        fputcsv($output, ['=== USER STATISTICS ===']);
        fputcsv($output, [
            'user_id',
            'full_name',
            'role',
            'total_facility_bookings',
            'approved_facility_bookings',
            'completed_facility_bookings',
            'facility_approval_rate',
            'facility_completion_rate',
            'total_vehicle_requests',
            'approved_vehicle_requests',
            'vehicle_approval_rate',
            'total_item_borrowings',
            'approved_item_borrowings',
            'returned_items',
            'item_return_rate',
            'avg_satisfaction',
            'account_age_days',
            'last_activity'
        ]);
        
        $query = "SELECT 
            u.id as user_id,
            u.full_name,
            u.role,
            COUNT(DISTINCT fb.id) as total_facility_bookings,
            SUM(CASE WHEN fb.status = 'approved' THEN 1 ELSE 0 END) as approved_facility_bookings,
            SUM(CASE WHEN fb.status = 'completed' THEN 1 ELSE 0 END) as completed_facility_bookings,
            CASE WHEN COUNT(DISTINCT fb.id) > 0 
                THEN SUM(CASE WHEN fb.status = 'approved' THEN 1 ELSE 0 END) / COUNT(DISTINCT fb.id)
                ELSE 0 END as facility_approval_rate,
            CASE WHEN SUM(CASE WHEN fb.status = 'approved' THEN 1 ELSE 0 END) > 0
                THEN SUM(CASE WHEN fb.status = 'completed' THEN 1 ELSE 0 END) / SUM(CASE WHEN fb.status = 'approved' THEN 1 ELSE 0 END)
                ELSE 0 END as facility_completion_rate,
            COUNT(DISTINCT vr.id) as total_vehicle_requests,
            SUM(CASE WHEN vr.status = 'approved' THEN 1 ELSE 0 END) as approved_vehicle_requests,
            CASE WHEN COUNT(DISTINCT vr.id) > 0
                THEN SUM(CASE WHEN vr.status = 'approved' THEN 1 ELSE 0 END) / COUNT(DISTINCT vr.id)
                ELSE 0 END as vehicle_approval_rate,
            COUNT(DISTINCT ib.id) as total_item_borrowings,
            SUM(CASE WHEN ib.status = 'approved' THEN 1 ELSE 0 END) as approved_item_borrowings,
            SUM(CASE WHEN ib.status = 'returned' THEN 1 ELSE 0 END) as returned_items,
            CASE WHEN SUM(CASE WHEN ib.status = 'approved' THEN 1 ELSE 0 END) > 0
                THEN SUM(CASE WHEN ib.status = 'returned' THEN 1 ELSE 0 END) / SUM(CASE WHEN ib.status = 'approved' THEN 1 ELSE 0 END)
                ELSE 0 END as item_return_rate,
            AVG(COALESCE(fb.satisfaction_rating, vr.satisfaction_rating, ib.satisfaction_rating)) as avg_satisfaction,
            DATEDIFF(NOW(), u.created_at) as account_age_days,
            GREATEST(
                COALESCE(MAX(fb.created_at), '1970-01-01'),
                COALESCE(MAX(vr.created_at), '1970-01-01'),
                COALESCE(MAX(ib.created_at), '1970-01-01')
            ) as last_activity
        FROM users u
        LEFT JOIN facility_bookings fb ON u.id = fb.user_id
        LEFT JOIN vehicle_requests vr ON u.id = vr.user_id
        LEFT JOIN item_borrowings ib ON u.id = ib.user_id
        WHERE u.role = 'resident'
        GROUP BY u.id, u.full_name, u.role, u.created_at
        ORDER BY u.id";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        
        fputcsv($output, []); // Empty line separator
    }
    
    if ($export_type === 'all' || $export_type === 'ml_predictions') {
        // Export ML Predictions History
        fputcsv($output, ['=== ML PREDICTIONS HISTORY ===']);
        fputcsv($output, [
            'prediction_id',
            'model_type',
            'confidence_score',
            'prediction_result',
            'actual_outcome',
            'created_at'
        ]);
        
        $query = "SELECT 
            id as prediction_id,
            model_type,
            confidence_score,
            prediction_result,
            actual_outcome,
            created_at
        FROM ml_predictions
        ORDER BY created_at DESC
        LIMIT 1000";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    }
    
    // Add metadata footer
    fputcsv($output, []);
    fputcsv($output, ['=== EXPORT METADATA ===']);
    fputcsv($output, ['Export Date', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Export Type', $export_type]);
    fputcsv($output, ['Exported By', $_SESSION['full_name']]);
    fputcsv($output, ['System Version', '1.0.0']);
    
    fclose($output);
    exit();
    
} catch (Exception $e) {
    error_log("Export Training Data Error: " . $e->getMessage());
    
    // Clear any output
    if (ob_get_length()) ob_clean();
    
    // Send error response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Failed to export training data: ' . $e->getMessage()
    ]);
    exit();
}
?>