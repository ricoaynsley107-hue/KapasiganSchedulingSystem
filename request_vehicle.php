<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';
$conflicts = [];
$suggestions = [];

// Handle form submission
if ($_POST) {
    $vehicle_id = $_POST['vehicle_id'] ?? '';
    $request_date = $_POST['request_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $purpose = $_POST['purpose'] ?? '';
    $description = $_POST['description'] ?? '';
    $passengers = intval($_POST['passengers'] ?? 0);
    
    if ($vehicle_id && $request_date && $start_time && $end_time && $passengers > 0) {
        // Check for conflicts
        $query = "SELECT vr.*, u.full_name, v.name as vehicle_name 
                  FROM vehicle_requests vr 
                  JOIN users u ON vr.user_id = u.id 
                  JOIN vehicles v ON vr.vehicle_id = v.id
                  WHERE vr.vehicle_id = :vehicle_id 
                  AND vr.request_date = :request_date 
                  AND vr.status IN ('approved', 'pending')
                  AND ((vr.start_time <= :start_time AND vr.end_time > :start_time) 
                       OR (vr.start_time < :end_time AND vr.end_time >= :end_time)
                       OR (vr.start_time >= :start_time AND vr.end_time <= :end_time))";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':vehicle_id', $vehicle_id);
        $stmt->bindParam(':request_date', $request_date);
        $stmt->bindParam(':start_time', $start_time);
        $stmt->bindParam(':end_time', $end_time);
        $stmt->execute();
        
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also check vehicle capacity
        $vehicle_capacity_query = "SELECT capacity, name FROM vehicles WHERE id = :vehicle_id";
        $capacity_stmt = $conn->prepare($vehicle_capacity_query);
        $capacity_stmt->bindParam(':vehicle_id', $vehicle_id);
        $capacity_stmt->execute();
        $vehicle_info = $capacity_stmt->fetch(PDO::FETCH_ASSOC);
        $vehicle_capacity = $vehicle_info['capacity'] ?? 0;
        $vehicle_name = $vehicle_info['name'] ?? 'Vehicle';

        if ($passengers > $vehicle_capacity) {
            $error = "The selected vehicle ({$vehicle_name}) can only accommodate {$vehicle_capacity} passengers. You requested {$passengers}.";
            $conflicts[] = ['message' => $error];
        }
        
        if (!empty($conflicts)) {
            if (empty($error)) {
                $error = 'The vehicle is already requested during this time slot.';
            }
            
            // Get suggestions via API call
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/api/check_availability.php?type=vehicle&id={$vehicle_id}&date={$request_date}&start_time={$start_time}&end_time={$end_time}&passengers={$passengers}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $data = json_decode($response, true);
                if ($data['success'] && isset($data['suggestions'])) {
                    $suggestions = $data['suggestions'];
                }
            }
        } else {
            $query = "INSERT INTO vehicle_requests (user_id, vehicle_id, request_date, start_time, end_time, destination, purpose, description, passenger_count) 
                      VALUES (:user_id, :vehicle_id, :request_date, :start_time, :end_time, :destination, :purpose, :description, :passenger_count)";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->bindParam(':vehicle_id', $vehicle_id);
            $stmt->bindParam(':request_date', $request_date);
            $stmt->bindParam(':start_time', $start_time);
            $stmt->bindParam(':end_time', $end_time);
            $stmt->bindParam(':destination', $purpose);
            $stmt->bindParam(':purpose', $purpose);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':passenger_count', $passengers);
            
            if ($stmt->execute()) {
                $success = 'Vehicle request submitted successfully!';
                $_POST = array();
            } else {
                $error = 'Failed to submit vehicle request.';
            }
        }
    } else {
        $error = 'Please fill in all required fields and ensure passenger count is greater than 0.';
    }
}

// Get available vehicles
$query = "SELECT * FROM vehicles WHERE status = 'available' ORDER BY name";
$stmt = $conn->prepare($query);
$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AI Recommender Logic
$recommended_vehicles = [];
if (!empty($vehicles)) {
    $query = "SELECT v.id, v.name, COUNT(*) as request_count 
              FROM vehicle_requests vr 
              JOIN vehicles v ON vr.vehicle_id = v.id 
              WHERE vr.user_id = :user_id 
              GROUP BY v.id, v.name 
              ORDER BY request_count DESC 
              LIMIT 2";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $recommended_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($recommended_vehicles)) {
        $query = "SELECT v.id, v.name, COUNT(vr.id) as popularity 
                  FROM vehicles v 
                  LEFT JOIN vehicle_requests vr ON v.id = vr.vehicle_id 
                  WHERE v.status = 'available'
                  GROUP BY v.id, v.name 
                  ORDER BY popularity DESC 
                  LIMIT 2";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $recommended_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Vehicle - Barangay Kapasigan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(180deg, #f5f9ff 0%, #ffffff 50%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .sidebar {
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            z-index: 1000;
            padding-top: 20px;
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            box-shadow: 2px 8px 30px rgba(14, 30, 70, 0.18);
        }

        .sidebar-header {
            padding: 22px 16px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .sidebar-header img {
            width: 56px;
            height: 56px;
            border-radius: 10px;
            object-fit: cover;
            box-shadow: 0 6px 18px rgba(0,0,0,0.15);
            border: 2px solid rgba(255,255,255,0.12);
        }

        .sidebar-header h5 {
            color: #fff;
            margin-top: 10px;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: 0.2px;
        }

        .sidebar .nav-link {
            color: rgba(255,255,255,0.92);
            padding: 12px 20px;
            margin: 6px 12px;
            border-radius: 10px;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            background: transparent;
        }

        .sidebar .nav-link i { width: 20px; text-align: center; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: rgba(255,255,255,0.08);
            transform: translateX(6px);
            box-shadow: 0 6px 18px rgba(18, 52, 99, 0.08);
            color: #fff;
            border-left: 3px solid rgba(255,255,255,0.18);
        }

        .main-content {
            margin-left: 260px;
            padding: 30px;
        }

        .page-header {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            font-size: 32px;
            color: #2d3748;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h1 i {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .recommendations-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .recommendations-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .recommendations-title i {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .recommended-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .rec-vehicle-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 2px solid #e5e7eb;
            position: relative;
            cursor: pointer;
            transition: all 0.3s;
        }

        .rec-vehicle-card:hover {
            border-color: #06b6d4;
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(6, 182, 212, 0.2);
        }

        .rec-vehicle-name {
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .rec-vehicle-desc {
            font-size: 13px;
            color: #a0aec0;
        }

        .request-container {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 30px;
        }

        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .form-title {
            font-size: 20px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-title i {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
            display: block;
            font-size: 14px;
        }

        .form-control, .form-select {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 15px;
            transition: all 0.3s;
            width: 100%;
        }

        .form-control:focus, .form-select:focus {
            border-color: #06b6d4;
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
            outline: none;
        }

        .time-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .time-slots {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
        }

        .time-slots-title {
            font-size: 13px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .time-slots-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .time-slot-btn {
            padding: 8px 12px;
            border-radius: 6px;
            border: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 140px;
        }

        .time-slot-btn.available {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .time-slot-btn.available:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);
        }

        .time-slot-btn.booked {
            background: #fee2e2;
            color: #991b1b;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .alert i {
            font-size: 18px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .suggestions-box {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .suggestions-title {
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .suggestion-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid white;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 8px;
            margin-bottom: 8px;
        }

        .suggestion-btn:hover {
            background: white;
            color: #0891b2;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .btn-submit {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            justify-content: center;
        }

        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(6, 182, 212, 0.3);
        }

        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-check {
            background: #e5e7eb;
            color: #4b5563;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-check:hover {
            background: #d1d5db;
        }

        .vehicles-sidebar {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 30px;
            max-height: calc(100vh - 60px);
            overflow-y: auto;
        }

        .vehicles-title {
            font-size: 16px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .vehicle-card {
            background: #f9fafb;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .vehicle-card:hover {
            background: white;
            border-color: #06b6d4;
            transform: translateX(5px);
        }

        .vehicle-card.selected {
            background: white;
            border-color: #06b6d4;
        }

        .vehicle-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .vehicle-description {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .vehicle-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
        }

        .vehicle-capacity {
            color: #a0aec0;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .vehicle-badge {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .sidebar { width: 200px; }
            .main-content { margin-left: 200px; padding: 20px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .request-container { grid-template-columns: 1fr; }
            .time-row { grid-template-columns: 1fr; }
            .vehicles-sidebar { position: static; max-height: none; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <img src="kapasigan.png" alt="Logo">
            <h5>Barangay Kapasigan</h5>
        </div>
        
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="resident_dashboard.php">
                    <i class="fas fa-home"></i><span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="book_facility.php">
                    <i class="fas fa-building"></i><span>Book Facility</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="borrow_item.php">
                    <i class="fas fa-box"></i><span>Borrow Items</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="request_vehicle.php">
                    <i class="fas fa-car"></i><span>Request Vehicle</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="resident calendar.php">
                    <i class="fas fa-calendar"></i><span>Calendar</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="my_requests.php">
                    <i class="fas fa-list"></i><span>My Requests</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="reminders.php">
                    <i class="fas fa-bell"></i><span>Notifications</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <h1><i class="fas fa-car"></i>Request a Vehicle</h1>
            <div class="dropdown">
                <button class="btn btn-light" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle me-2"></i><?php echo $_SESSION['full_name']; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong><?php echo $error; ?></strong>
                    <?php if (!empty($conflicts) && count($conflicts) > 1): ?>
                        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(153, 27, 27, 0.2);">
                            <small><strong>Time conflicts:</strong></small>
                            <?php foreach ($conflicts as $conflict): ?>
                                <?php if (isset($conflict['full_name'])): ?>
                                    <br><small><?php echo htmlspecialchars($conflict['full_name']); ?> - 
                                    <?php echo date('g:i A', strtotime($conflict['start_time'])); ?> to 
                                    <?php echo date('g:i A', strtotime($conflict['end_time'])); ?></small>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Suggestions -->
        <?php if (!empty($suggestions)): ?>
            <div class="suggestions-box">
                <div class="suggestions-title">
                    <i class="fas fa-lightbulb"></i>Smart Suggestions
                </div>
                
                <?php if (!empty($suggestions[0]['available_slots'])): ?>
                    <div style="margin-bottom: 15px;">
                        <strong style="display: block; margin-bottom: 10px;">Next available time slots:</strong>
                        <?php foreach (array_slice($suggestions, 0, 3) as $suggestion): ?>
                            <div style="margin-bottom: 10px;">
                                <strong><?php echo date('M d, Y', strtotime($suggestion['date'])); ?>:</strong>
                                <div style="margin-top: 5px;">
                                    <?php foreach ($suggestion['available_slots'] as $slot): ?>
                                        <button class="suggestion-btn" onclick="applySuggestion('<?php echo $suggestion['date']; ?>', '<?php echo $slot['start']; ?>', '<?php echo $slot['end']; ?>')">
                                            <?php echo date('g:i A', strtotime($slot['start'])); ?> - <?php echo date('g:i A', strtotime($slot['end'])); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($suggestions['similar_vehicles']) && !empty($suggestions['similar_vehicles'])): ?>
                    <div style="padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.3);">
                        <strong style="display: block; margin-bottom: 10px;">Similar available vehicles:</strong>
                        <?php foreach (array_slice($suggestions['similar_vehicles'], 0, 3) as $vehicle): ?>
                            <button class="suggestion-btn" onclick="selectAlternativeVehicle(<?php echo $vehicle['id']; ?>)">
                                <?php echo htmlspecialchars($vehicle['name']); ?> (Capacity: <?php echo $vehicle['capacity']; ?>)
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Recommendations -->
        <?php if (!empty($recommended_vehicles)): ?>
        <div class="recommendations-card">
            <div class="recommendations-title">
                <i class="fas fa-star"></i>Recommended for You
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <?php foreach ($recommended_vehicles as $rec_vehicle): ?>
                <div class="rec-vehicle-card" onclick="selectVehicle(<?php echo $rec_vehicle['id']; ?>)">
                    <div class="recommended-badge">
                        <i class="fas fa-star"></i>Recommended
                    </div>
                    <div class="rec-vehicle-name"><?php echo htmlspecialchars($rec_vehicle['name']); ?></div>
                    <div class="rec-vehicle-desc">Based on your request history</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Request Form & Sidebar -->
        <div class="request-container">
            <!-- Form -->
            <div class="form-card">
                <div class="form-title">
                    <i class="fas fa-file-alt"></i>Request Details
                </div>

                <form method="POST" id="requestForm">
                    <div class="form-group">
                        <label class="form-label">Select Vehicle *</label>
                        <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                            <option value="">Choose a vehicle...</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['id']; ?>" 
                                        data-capacity="<?php echo $vehicle['capacity']; ?>"
                                        <?php echo (isset($_POST['vehicle_id']) && $_POST['vehicle_id'] == $vehicle['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($vehicle['name']); ?> (Capacity: <?php echo $vehicle['capacity']; ?> passengers)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="time-row">
                        <div class="form-group">
                            <label class="form-label">Date *</label>
                            <input type="date" class="form-control" id="request_date" name="request_date"
                                   min="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo $_POST['request_date'] ?? ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Start Time *</label>
                            <input type="time" class="form-control" id="start_time" name="start_time"
                                   value="<?php echo $_POST['start_time'] ?? ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">End Time *</label>
                            <input type="time" class="form-control" id="end_time" name="end_time"
                                   value="<?php echo $_POST['end_time'] ?? ''; ?>" required>
                        </div>
                    </div>

                    <div class="time-slots" id="time-slot-picker" style="display: none;">
                        <div class="time-slots-title">
                            <i class="fas fa-info-circle"></i>Available Time Slots
                        </div>
                        <div class="time-slots-grid" id="time-slots-container"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Number of Passengers *</label>
                        <input type="number" class="form-control" id="passengers" name="passengers" 
                               min="1" value="<?php echo $_POST['passengers'] ?? 1; ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Purpose/Trip Details</label>
                        <textarea class="form-control" id="purpose" name="purpose" rows="3"
                                  placeholder="Describe the purpose of your trip..."><?php echo htmlspecialchars($_POST['purpose'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Additional Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                                  placeholder="Any additional notes or specific requirements?"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="alert alert-info" id="availability-status" style="display: none;"></div>

                    <div class="button-group">
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="fas fa-paper-plane"></i>Submit Request
                        </button>
                        <button type="button" class="btn-check" onclick="checkAvailability()">
                            <i class="fas fa-search"></i>Check
                        </button>
                    </div>
                </form>
            </div>

            <!-- Vehicles Sidebar -->
            <div class="vehicles-sidebar">
                <div class="vehicles-title">
                    <i class="fas fa-list"></i>Available Vehicles
                </div>

                <?php foreach ($vehicles as $vehicle): ?>
                <div class="vehicle-card" onclick="selectVehicle(<?php echo $vehicle['id']; ?>)" data-vehicle-id="<?php echo $vehicle['id']; ?>">
                    <div class="vehicle-name"><?php echo htmlspecialchars($vehicle['name']); ?></div>
                    <div class="vehicle-description"><?php echo htmlspecialchars($vehicle['description']); ?></div>
                    <div class="vehicle-info">
                        <span class="vehicle-capacity">
                            <i class="fas fa-users"></i><?php echo $vehicle['capacity']; ?> capacity
                        </span>
                        <span class="vehicle-badge">Available</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectVehicle(vehicleId) {
            document.getElementById('vehicle_id').value = vehicleId;
            
            // Remove previous selections
            document.querySelectorAll('.vehicle-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked card
            const selectedCard = document.querySelector(`[data-vehicle-id="${vehicleId}"]`);
            if (selectedCard) {
                selectedCard.classList.add('selected');
            }
            
            loadAvailableTimeSlots();
        }

        function loadAvailableTimeSlots() {
            const vehicleId = document.getElementById('vehicle_id').value;
            const date = document.getElementById('request_date').value;
            const picker = document.getElementById('time-slot-picker');
            const container = document.getElementById('time-slots-container');
            
            if (!vehicleId || !date) {
                picker.style.display = 'none';
                return;
            }
            
            console.log('Loading time slots for vehicle:', vehicleId, 'on', date);
            
            fetch(`api/get_available_slots.php?type=vehicle&id=${vehicleId}&date=${date}`)
                .then(response => response.json())
                .then(data => {
                    console.log('Time slots loaded:', data);
                    if (data.success && data.slots) {
                        container.innerHTML = '';
                        data.slots.forEach(slot => {
                            const slotBtn = document.createElement('button');
                            slotBtn.type = 'button';
                            slotBtn.className = `time-slot-btn ${slot.available ? 'available' : 'booked'}`;
                            slotBtn.textContent = slot.display;
                            
                            if (slot.available) {
                                slotBtn.onclick = () => selectTimeSlot(slot.start, slot.end);
                            } else {
                                slotBtn.disabled = true;
                            }
                            
                            container.appendChild(slotBtn);
                        });
                        picker.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error loading time slots:', error);
                });
        }

        function selectTimeSlot(startTime, endTime) {
            document.getElementById('start_time').value = startTime;
            document.getElementById('end_time').value = endTime;
            checkAvailability();
        }

        function checkAvailability() {
            const vehicleId = document.getElementById('vehicle_id').value;
            const date = document.getElementById('request_date').value;
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const passengers = document.getElementById('passengers').value;
            const statusDiv = document.getElementById('availability-status');
            
            if (!vehicleId || !date || !startTime || !endTime || !passengers) {
                statusDiv.style.display = 'none';
                return;
            }
            
            fetch(`api/check_availability.php?type=vehicle&id=${vehicleId}&date=${date}&start_time=${startTime}&end_time=${endTime}&passengers=${passengers}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.has_conflicts) {
                            let conflictMessage = "This vehicle is unavailable during the selected time or cannot accommodate the number of passengers.";
                            if (data.conflicts && data.conflicts.length > 0 && data.conflicts[0].message) {
                                conflictMessage = data.conflicts[0].message;
                            }
                            statusDiv.innerHTML = `
                                <i class="fas fa-exclamation-triangle"></i>
                                <span><strong>Unavailable:</strong> ${conflictMessage}</span>
                            `;
                            statusDiv.className = 'alert alert-danger';
                            document.getElementById('submitBtn').disabled = true;
                        } else {
                            statusDiv.innerHTML = `
                                <i class="fas fa-check-circle"></i>
                                <span><strong>Available!</strong> This time slot is free and the vehicle can accommodate your group.</span>
                            `;
                            statusDiv.className = 'alert alert-success';
                            document.getElementById('submitBtn').disabled = false;
                        }
                        statusDiv.style.display = 'flex';
                    }
                })
                .catch(error => {
                    console.error('Error checking availability:', error);
                });
        }

        function applySuggestion(date, startTime, endTime) {
            document.getElementById('request_date').value = date;
            document.getElementById('start_time').value = startTime;
            document.getElementById('end_time').value = endTime;
            checkAvailability();
            loadAvailableTimeSlots();
        }

        function selectAlternativeVehicle(vehicleId) {
            selectVehicle(vehicleId);
        }

        // Add event listeners
        document.getElementById('vehicle_id').addEventListener('change', function() {
            loadAvailableTimeSlots();
            checkAvailability();
        });
        document.getElementById('request_date').addEventListener('change', function() {
            loadAvailableTimeSlots();
            checkAvailability();
        });
        document.getElementById('start_time').addEventListener('change', checkAvailability);
        document.getElementById('end_time').addEventListener('change', checkAvailability);
        document.getElementById('passengers').addEventListener('change', checkAvailability);
        document.getElementById('passengers').addEventListener('input', checkAvailability);

        // Real-time availability checking with debounce
        let availabilityTimeout;
        ['vehicle_id', 'request_date', 'start_time', 'end_time', 'passengers'].forEach(id => {
            document.getElementById(id).addEventListener('input', function() {
                clearTimeout(availabilityTimeout);
                availabilityTimeout = setTimeout(checkAvailability, 500);
            });
        });
    </script>
</body>
</html>