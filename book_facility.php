<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'api/ml_predict.php'; // ML Integration


$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';
$conflicts = [];
$suggestions = [];
$ml_insight = null;

// Handle form submission
if ($_POST) {
    $facility_id = $_POST['facility_id'] ?? '';
    $booking_date = $_POST['booking_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $purpose = $_POST['purpose'] ?? '';
    
    if ($facility_id && $booking_date && $start_time && $end_time) {
        // Check conflicts
        $query = "SELECT fb.*, u.full_name, f.name as facility_name 
                  FROM facility_bookings fb 
                  JOIN users u ON fb.user_id = u.id 
                  JOIN facilities f ON fb.facility_id = f.id
                  WHERE fb.facility_id = :facility_id 
                  AND fb.booking_date = :booking_date 
                  AND fb.status IN ('approved', 'pending')
                  AND ((fb.start_time <= :start_time AND fb.end_time > :start_time) 
                       OR (fb.start_time < :end_time AND fb.end_time >= :end_time)
                       OR (fb.start_time >= :start_time AND fb.end_time <= :end_time))";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':facility_id', $facility_id);
        $stmt->bindParam(':booking_date', $booking_date);
        $stmt->bindParam(':start_time', $start_time);
        $stmt->bindParam(':end_time', $end_time);
        $stmt->execute();
        
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($conflicts)) {
            // 🤖 GET ML PREDICTION before inserting
            $user_stats = get_user_booking_stats($_SESSION['user_id'], $conn);
            
            $ml_data = [
                'hour_of_day' => (int)date('H', strtotime($start_time)),
                'day_of_week' => (int)date('N', strtotime($booking_date)),
                'advance_booking_days' => (strtotime($booking_date) - time()) / 86400,
                'duration_hours' => (strtotime($end_time) - strtotime($start_time)) / 3600,
                'user_approval_rate' => $user_stats['user_approval_rate'],
                'user_completion_rate' => $user_stats['user_completion_rate'],
                'same_day_facility_demand' => 0, // Calculate from DB
                'is_weekend' => in_array(date('N', strtotime($booking_date)), [6, 7]) ? 1 : 0
            ];
            
            $ml_prediction = predict_approval($ml_data);
            $noshow_prediction = predict_noshow($ml_data);
            
            // Insert booking
            $query = "INSERT INTO facility_bookings (user_id, facility_id, booking_date, start_time, end_time, purpose) 
                      VALUES (:user_id, :facility_id, :booking_date, :start_time, :end_time, :purpose)";
            
            $stmt = $conn->prepare($query);
            $user_id = $_SESSION['user_id']; // Store in variable first
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':facility_id', $facility_id);
            $stmt->bindParam(':booking_date', $booking_date);
            $stmt->bindParam(':start_time', $start_time);
            $stmt->bindParam(':end_time', $end_time);
            $stmt->bindParam(':purpose', $purpose);
            
            if ($stmt->execute()) {
                // Store ML prediction
                $booking_id = $conn->lastInsertId();
                
                $pred_query = "INSERT INTO ml_predictions (model_type, input_features, prediction_result, confidence_score)
                               VALUES ('decision_tree', :input, :result, :confidence)";
                $pred_stmt = $conn->prepare($pred_query);
                $input_json = json_encode($ml_data); // Store in variable
                $result_json = json_encode($ml_prediction); // Store in variable
                $confidence = $ml_prediction['confidence']; // Store in variable
                $pred_stmt->bindParam(':input', $input_json);
                $pred_stmt->bindParam(':result', $result_json);
                $pred_stmt->bindParam(':confidence', $confidence);
                $pred_stmt->execute();
                
                // Show success with ML insights
                $success = '✅ Facility booking submitted successfully!';
                $ml_insight = [
                    'approval_prediction' => $ml_prediction,
                    'noshow_prediction' => $noshow_prediction
                ];
            } else {
                $error = 'Failed to submit booking request.';
            }
        } else {
            $error = 'The facility is already booked during this time slot.';
        }
    } else {
        $error = 'Please fill in all required fields.';
    }
}

// Get available facilities
$query = "SELECT * FROM facilities WHERE status = 'available' ORDER BY name";
$stmt = $conn->prepare($query);
$stmt->execute();
$facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 🤖 AI-POWERED RECOMMENDATIONS
$recommended_facilities = [];
if (!empty($facilities)) {
    // User's past preferences
    $query = "SELECT f.id, f.name, COUNT(*) as booking_count 
              FROM facility_bookings fb 
              JOIN facilities f ON fb.facility_id = f.id 
              WHERE fb.user_id = :user_id 
              GROUP BY f.id, f.name 
              ORDER BY booking_count DESC 
              LIMIT 2";
    
    $stmt = $conn->prepare($query);
    $user_id = $_SESSION['user_id'];
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $recommended_facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no history, recommend popular ones
    if (empty($recommended_facilities)) {
        $query = "SELECT f.id, f.name, COUNT(fb.id) as popularity 
                  FROM facilities f 
                  LEFT JOIN facility_bookings fb ON f.id = fb.facility_id 
                  WHERE f.status = 'available'
                  GROUP BY f.id, f.name 
                  ORDER BY popularity DESC 
                  LIMIT 2";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $recommended_facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get user's ML score
$user_stats = get_user_booking_stats($_SESSION['user_id'], $conn);
$user_ml_score = round(($user_stats['user_approval_rate'] + $user_stats['user_completion_rate']) / 2 * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Facility - Barangay Kapasigan</title>
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
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* 🤖 ML SCORE BADGE */
        .ml-score-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .ml-score-badge i {
            font-size: 20px;
        }

        /* 🤖 AI INSIGHTS CARD */
        .ai-insights-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .ai-insights-card h4 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            font-size: 20px;
        }

        .ai-insights-card h4 i {
            font-size: 24px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .insight-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            backdrop-filter: blur(10px);
        }

        .insight-item:last-child {
            margin-bottom: 0;
        }

        .insight-label {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .insight-value {
            font-size: 18px;
            font-weight: 700;
        }

        .confidence-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 8px;
        }

        .confidence-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #34d399 100%);
            transition: width 0.5s;
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

        .rec-facility-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 2px solid #e5e7eb;
            position: relative;
            cursor: pointer;
            transition: all 0.3s;
        }

        .rec-facility-card:hover {
            border-color: #3b82f6;
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.2);
        }

        .rec-facility-name {
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .rec-facility-desc {
            font-size: 13px;
            color: #a0aec0;
        }

        .booking-container {
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
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
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
        }

        .form-control:focus, .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }

        .time-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
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

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .alert i {
            font-size: 18px;
            flex-shrink: 0;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .btn-submit {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
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
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
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

        .facilities-sidebar {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 30px;
        }

        .facilities-title {
            font-size: 16px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .facility-item {
            background: #f9fafb;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .facility-item:hover {
            background: white;
            border-color: #3b82f6;
            transform: translateX(5px);
        }

        .facility-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .facility-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
        }

        .facility-capacity {
            color: #a0aec0;
        }

        .facility-badge {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        /* ML Prediction Display */
        .ml-prediction-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }

        .ml-prediction-box h5 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .prediction-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }

        .prediction-item {
            background: rgba(255, 255, 255, 0.15);
            padding: 12px;
            border-radius: 8px;
            backdrop-filter: blur(10px);
        }

        .prediction-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .prediction-value {
            font-size: 16px;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .sidebar { width: 200px; }
            .main-content { margin-left: 200px; padding: 20px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .booking-container { grid-template-columns: 1fr; }
            .time-row { grid-template-columns: 1fr; }
            .facilities-sidebar { position: static; }
            .prediction-grid { grid-template-columns: 1fr; }
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
                <a class="nav-link active" href="book_facility.php">
                    <i class="fas fa-building"></i><span>Book Facility</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="borrow_item.php">
                    <i class="fas fa-box"></i><span>Borrow Items</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="request_vehicle.php">
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
            <h1><i class="fas fa-building"></i>Book a Facility</h1>
            <div style="display: flex; align-items: center; gap: 15px;">
                <div class="ml-score-badge">
                    <i class="fas fa-robot"></i>
                    <span>AI Score: <?php echo $user_ml_score; ?>%</span>
                </div>
                <div class="dropdown">
                    <button class="btn btn-light" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-2"></i><?php echo $_SESSION['full_name']; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i><?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i><?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- 🤖 ML INSIGHTS AFTER BOOKING -->
        <?php if ($ml_insight): ?>
        <div class="ai-insights-card">
            <h4><i class="fas fa-brain"></i>AI Booking Insights</h4>
            
            <div class="insight-item">
                <div class="insight-label">Approval Prediction</div>
                <div class="insight-value">
                    <?php 
                    $prediction = $ml_insight['approval_prediction']['prediction'] ?? 'manual_review';
                    echo $prediction === 'approve' ? '✅ Likely to be Approved' : '⏳ May Need Review'; 
                    ?>
                </div>
                <div class="confidence-bar">
                    <div class="confidence-fill" style="width: <?php echo ($ml_insight['approval_prediction']['confidence'] ?? 0) * 100; ?>%;"></div>
                </div>
            </div>

            <div class="insight-item">
                <div class="insight-label">Show-Up Probability</div>
                <div class="insight-value">
                    <?php 
                    $show_probability = $ml_insight['noshow_prediction']['show_probability'] ?? 0;
                    echo round($show_probability * 100); 
                    ?>% likely to attend
                </div>
                <div class="insight-label" style="margin-top: 10px;">
                    <?php 
                    $send_reminder = $ml_insight['noshow_prediction']['send_extra_reminder'] ?? false;
                    if ($send_reminder): 
                    ?>
                        💡 Tip: We'll send you extra reminders to help ensure you don't miss this!
                    <?php else: ?>
                        ✅ Great! Your attendance history is excellent.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 🤖 AI RECOMMENDATIONS -->
        <?php if (!empty($recommended_facilities)): ?>
        <div class="recommendations-card">
            <div class="recommendations-title">
                <i class="fas fa-star"></i>AI-Recommended Facilities for You
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <?php foreach ($recommended_facilities as $facility): ?>
                <div class="rec-facility-card" onclick="selectFacility(<?php echo $facility['id']; ?>)">
                    <div class="recommended-badge">
                        <i class="fas fa-robot"></i>AI Pick
                    </div>
                    <div class="rec-facility-name"><?php echo htmlspecialchars($facility['name']); ?></div>
                    <div class="rec-facility-desc">Based on your booking history</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Booking Form & Sidebar -->
        <div class="booking-container">
            <!-- Form -->
            <div class="form-card">
                <div class="form-title">
                    <i class="fas fa-calendar-check"></i>Booking Details
                </div>

                <form method="POST" id="bookingForm">
                    <div class="form-group">
                        <label class="form-label">Select Facility *</label>
                        <select class="form-select" id="facility_id" name="facility_id" required>
                            <option value="">Choose a facility...</option>
                            <?php foreach ($facilities as $facility): ?>
                                <option value="<?php echo $facility['id']; ?>"
                                        <?php echo (isset($_POST['facility_id']) && $_POST['facility_id'] == $facility['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($facility['name']); ?> - FREE
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="time-row">
                        <div class="form-group">
                            <label class="form-label">Date *</label>
                            <input type="date" class="form-control" id="booking_date" name="booking_date"
                                   min="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo $_POST['booking_date'] ?? ''; ?>" required>
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

                    <div class="form-group">
                        <label class="form-label">Purpose/Event Details</label>
                        <textarea class="form-control" id="purpose" name="purpose" rows="4"
                                  placeholder="Describe the purpose of your booking..."><?php echo $_POST['purpose'] ?? ''; ?></textarea>
                    </div>

                    <!-- 🤖 ML PREDICTION DISPLAY (Live) -->
                    <div class="ml-prediction-box" id="ml-prediction" style="display: none;">
                        <h5><i class="fas fa-brain"></i>AI Analysis</h5>
                        <div class="prediction-grid">
                            <div class="prediction-item">
                                <div class="prediction-label">Approval Chance</div>
                                <div class="prediction-value" id="approval-chance">--</div>
                            </div>
                            <div class="prediction-item">
                                <div class="prediction-label">Risk Level</div>
                                <div class="prediction-value" id="risk-level">--</div>
                            </div>
                            <div class="prediction-item">
                                <div class="prediction-label">Best Time</div>
                                <div class="prediction-value" id="best-time">--</div>
                            </div>
                            <div class="prediction-item">
                                <div class="prediction-label">Attendance Score</div>
                                <div class="prediction-value" id="attendance-score">--</div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info" id="availability-status" style="display: none;"></div>

                    <div class="button-group">
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <i class="fas fa-paper-plane"></i>Submit Booking
                        </button>
                        <button type="button" class="btn-check" onclick="checkAvailability()">
                            <i class="fas fa-search"></i>Check
                        </button>
                    </div>
                </form>
            </div>

            <!-- Facilities Sidebar -->
            <div class="facilities-sidebar">
                <div class="facilities-title">
                    <i class="fas fa-list"></i>All Facilities
                </div>

                <?php foreach ($facilities as $facility): ?>
                <div class="facility-item" onclick="selectFacility(<?php echo $facility['id']; ?>)" data-facility-id="<?php echo $facility['id']; ?>">
                    <div class="facility-name"><?php echo htmlspecialchars($facility['name']); ?></div>
                    <div class="facility-info">
                        <span class="facility-capacity"><i class="fas fa-users me-1"></i><?php echo $facility['capacity']; ?> capacity</span>
                        <span class="facility-badge">FREE</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectFacility(facilityId) {
            document.getElementById('facility_id').value = facilityId;
            document.querySelectorAll('.facility-item').forEach(el => el.style.borderColor = 'transparent');
            const selectedItem = document.querySelector(`[data-facility-id="${facilityId}"]`);
            if (selectedItem) {
                selectedItem.style.borderColor = '#3b82f6';
            }
            checkAvailability();
        }

        function checkAvailability() {
            const facilityId = document.getElementById('facility_id').value;
            const date = document.getElementById('booking_date').value;
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const statusDiv = document.getElementById('availability-status');
            const mlPredictionBox = document.getElementById('ml-prediction');
            
            if (!facilityId || !date || !startTime || !endTime) {
                statusDiv.style.display = 'none';
                mlPredictionBox.style.display = 'none';
                return;
            }

            // Show loading
            statusDiv.style.display = 'flex';
            statusDiv.className = 'alert alert-info';
            statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>🤖 Checking availability and running AI analysis...</span>';

            fetch(`api/check_availability.php?type=facility&id=${facilityId}&date=${date}&start_time=${startTime}&end_time=${endTime}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.has_conflicts) {
                            statusDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i><strong>Unavailable:</strong> This time slot is already booked.';
                            statusDiv.className = 'alert alert-danger';
                            document.getElementById('submitBtn').disabled = true;
                            mlPredictionBox.style.display = 'none';
                        } else {
                            statusDiv.innerHTML = '<i class="fas fa-check-circle"></i><strong>Available!</strong> This time slot is free for booking.';
                            statusDiv.className = 'alert alert-success';
                            document.getElementById('submitBtn').disabled = false;
                            
                            // 🤖 Display ML Prediction
                            if (data.ml_prediction) {
                                mlPredictionBox.style.display = 'block';
                                
                                const prediction = data.ml_prediction;
                                const noshowRisk = prediction.noshow_risk || {};
                                
                                // Update approval chance
                                const approvalChance = Math.round((prediction.confidence || 0) * 100);
                                document.getElementById('approval-chance').textContent = approvalChance + '%';
                                
                                // Update risk level
                                const riskLevel = (noshowRisk.risk_level || 'unknown').toUpperCase();
                                const riskEmoji = riskLevel === 'LOW' ? '✅' : (riskLevel === 'MEDIUM' ? '⚠️' : '🔴');
                                document.getElementById('risk-level').textContent = riskEmoji + ' ' + riskLevel;
                                
                                // Update best time suggestion
                                const hour = parseInt(startTime.split(':')[0]);
                                const bestTime = (hour >= 9 && hour <= 16) ? '✅ Optimal' : '⏰ Off-Peak';
                                document.getElementById('best-time').textContent = bestTime;
                                
                                // Update attendance score
                                const attendanceScore = Math.round((noshowRisk.show_probability || 0.5) * 100);
                                document.getElementById('attendance-score').textContent = attendanceScore + '%';
                                
                                // Show prediction confidence
                                if (prediction.should_auto_approve) {
                                    statusDiv.innerHTML += '<br><small>🤖 <strong>AI Tip:</strong> High approval probability - your booking history is excellent!</small>';
                                } else {
                                    statusDiv.innerHTML += '<br><small>🤖 <strong>AI Tip:</strong> May require manual review, but you can still submit.</small>';
                                }
                                
                                if (noshowRisk.send_extra_reminder) {
                                    statusDiv.innerHTML += '<br><small>💡 <strong>Reminder:</strong> We\'ll send extra reminders to help you attend.</small>';
                                }
                            }
                        }
                        statusDiv.style.display = 'flex';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    statusDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i><strong>Error:</strong> Could not check availability.';
                    statusDiv.className = 'alert alert-danger';
                    statusDiv.style.display = 'flex';
                    mlPredictionBox.style.display = 'none';
                });
        }

        // Auto-check when inputs change
        document.getElementById('facility_id').addEventListener('change', checkAvailability);
        document.getElementById('booking_date').addEventListener('change', checkAvailability);
        document.getElementById('start_time').addEventListener('change', checkAvailability);
        document.getElementById('end_time').addEventListener('change', checkAvailability);

        // Debounced real-time checking
        let availabilityTimeout;
        ['facility_id', 'booking_date', 'start_time', 'end_time'].forEach(id => {
            document.getElementById(id).addEventListener('input', function() {
                clearTimeout(availabilityTimeout);
                availabilityTimeout = setTimeout(checkAvailability, 500);
            });
        });
    </script>
</body>
</html>