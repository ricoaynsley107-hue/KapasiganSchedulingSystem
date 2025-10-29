<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireAdmin();

$database = new Database();
$conn = $database->getConnection();

$type = $_GET['type'] ?? '';

try {
    switch ($type) {
        case 'most_borrowed_items':
            // Get most borrowed items this month
            $query = "SELECT i.name, i.category, COUNT(ib.id) as borrow_count, 
                             SUM(ib.quantity) as total_quantity
                      FROM item_borrowings ib
                      JOIN items i ON ib.item_id = i.id
                      WHERE MONTH(ib.borrow_date) = MONTH(CURRENT_DATE())
                        AND YEAR(ib.borrow_date) = YEAR(CURRENT_DATE())
                        AND ib.status IN ('approved', 'returned')
                      GROUP BY ib.item_id
                      ORDER BY borrow_count DESC
                      LIMIT 10";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'most_booked_facilities':
            // Get most booked facilities
            $query = "SELECT f.name, f.capacity, COUNT(fb.id) as booking_count,
                             SUM(TIMESTAMPDIFF(HOUR, fb.start_time, fb.end_time)) as total_hours
                      FROM facility_bookings fb
                      JOIN facilities f ON fb.facility_id = f.id
                      WHERE MONTH(fb.booking_date) = MONTH(CURRENT_DATE())
                        AND YEAR(fb.booking_date) = YEAR(CURRENT_DATE())
                        AND fb.status IN ('approved', 'completed')
                      GROUP BY fb.facility_id
                      ORDER BY booking_count DESC
                      LIMIT 10";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'peak_borrowing_hours':
            // Get peak borrowing hours
            $query = "SELECT HOUR(created_at) as hour, COUNT(*) as request_count
                      FROM (
                          SELECT created_at FROM facility_bookings WHERE status IN ('approved', 'completed')
                          UNION ALL
                          SELECT created_at FROM item_borrowings WHERE status IN ('approved', 'returned')
                          UNION ALL
                          SELECT created_at FROM vehicle_requests WHERE status IN ('approved', 'completed')
                      ) as all_requests
                      WHERE MONTH(created_at) = MONTH(CURRENT_DATE())
                        AND YEAR(created_at) = YEAR(CURRENT_DATE())
                      GROUP BY hour
                      ORDER BY hour";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'vehicle_usage':
            // Get vehicle usage statistics
            $query = "SELECT v.name, v.type, COUNT(vr.id) as usage_count,
                             SUM(vr.passenger_count) as total_passengers
                      FROM vehicle_requests vr
                      JOIN vehicles v ON vr.vehicle_id = v.id
                      WHERE MONTH(vr.request_date) = MONTH(CURRENT_DATE())
                        AND YEAR(vr.request_date) = YEAR(CURRENT_DATE())
                        AND vr.status IN ('approved', 'completed')
                      GROUP BY vr.vehicle_id
                      ORDER BY usage_count DESC";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'monthly_trends':
            // Get monthly request trends for the past 6 months
            $query = "SELECT 
                        DATE_FORMAT(date, '%Y-%m') as month,
                        SUM(facility_count) as facilities,
                        SUM(item_count) as items,
                        SUM(vehicle_count) as vehicles
                      FROM (
                          SELECT booking_date as date, COUNT(*) as facility_count, 0 as item_count, 0 as vehicle_count
                          FROM facility_bookings 
                          WHERE status IN ('approved', 'completed')
                            AND booking_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
                          GROUP BY booking_date
                          UNION ALL
                          SELECT borrow_date as date, 0 as facility_count, COUNT(*) as item_count, 0 as vehicle_count
                          FROM item_borrowings 
                          WHERE status IN ('approved', 'returned')
                            AND borrow_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
                          GROUP BY borrow_date
                          UNION ALL
                          SELECT request_date as date, 0 as facility_count, 0 as item_count, COUNT(*) as vehicle_count
                          FROM vehicle_requests 
                          WHERE status IN ('approved', 'completed')
                            AND request_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
                          GROUP BY request_date
                      ) as all_data
                      GROUP BY month
                      ORDER BY month";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'request_status_summary':
            // Get summary of request statuses
            $query = "SELECT 
                        'Facilities' as category,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'denied' THEN 1 ELSE 0 END) as denied,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                      FROM facility_bookings
                      WHERE MONTH(created_at) = MONTH(CURRENT_DATE())
                        AND YEAR(created_at) = YEAR(CURRENT_DATE())
                      UNION ALL
                      SELECT 
                        'Items' as category,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'denied' THEN 1 ELSE 0 END) as denied,
                        SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as completed
                      FROM item_borrowings
                      WHERE MONTH(created_at) = MONTH(CURRENT_DATE())
                        AND YEAR(created_at) = YEAR(CURRENT_DATE())
                      UNION ALL
                      SELECT 
                        'Vehicles' as category,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'denied' THEN 1 ELSE 0 END) as denied,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                      FROM vehicle_requests
                      WHERE MONTH(created_at) = MONTH(CURRENT_DATE())
                        AND YEAR(created_at) = YEAR(CURRENT_DATE())";
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid analytics type']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
