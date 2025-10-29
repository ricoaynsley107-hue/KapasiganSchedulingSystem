<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query conditions
$conditions = ["1=1"];
$params = [];

if ($filter_type !== 'all') {
    $type_condition = "";
    switch ($filter_type) {
        case 'facility':
            $type_condition = "type = 'Facility Booking'";
            break;
        case 'item':
            $type_condition = "type = 'Item Borrowing'";
            break;
        case 'vehicle':
            $type_condition = "type = 'Vehicle Request'";
            break;
    }
    if ($type_condition) {
        $conditions[] = "(" . $type_condition . ")";
    }
}

if ($filter_status !== 'all') {
    $conditions[] = "status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($search)) {
    $conditions[] = "(item_name LIKE :search OR purpose LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_clause = implode(" AND ", $conditions);

// Get all requests with union query
$query = "SELECT 'Facility Booking' as type, fb.id, f.name as item_name, fb.booking_date as date, 
                 fb.start_time, fb.end_time, fb.purpose, fb.status, fb.admin_notes, fb.created_at
          FROM facility_bookings fb 
          JOIN facilities f ON fb.facility_id = f.id 
          WHERE fb.user_id = :user_id
          UNION ALL
          SELECT 'Item Borrowing' as type, ib.id, i.name as item_name, ib.borrow_date as date,
                 NULL as start_time, NULL as end_time, ib.purpose, ib.status, ib.admin_notes, ib.created_at
          FROM item_borrowings ib 
          JOIN items i ON ib.item_id = i.id 
          WHERE ib.user_id = :user_id
          UNION ALL
          SELECT 'Vehicle Request' as type, vr.id, v.name as item_name, vr.request_date as date,
                 vr.start_time, vr.end_time, vr.purpose, vr.status, vr.admin_notes, vr.created_at
          FROM vehicle_requests vr 
          JOIN vehicles v ON vr.vehicle_id = v.id 
          WHERE vr.user_id = :user_id";

// Add filtering to the main query
if (count($conditions) > 1 || !empty($search)) {
    $query = "SELECT * FROM ($query) as all_requests WHERE $where_clause";
}

$query .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $user_id);

foreach ($params as $key => $value) {
    $stmt->bindParam($key, $value);
}

$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests - Barangay Kapasigan</title>
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
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .filter-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-title i {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
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
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
            outline: none;
        }

        .btn-filter {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.3);
        }

        .request-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.3s;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .request-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }

        .request-card-header {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e5e7eb;
        }

        .type-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            color: white;
        }

        .type-facility {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        .type-item {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .type-vehicle {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }

        .request-card-body {
            padding: 20px;
            flex: 1;
        }

        .request-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 15px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .info-item i {
            width: 20px;
            color: #8b5cf6;
        }

        .purpose-box {
            background: #f9fafb;
            border-left: 3px solid #8b5cf6;
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .purpose-box strong {
            color: #2d3748;
            font-size: 13px;
            display: block;
            margin-bottom: 5px;
        }

        .purpose-box p {
            color: #4b5563;
            font-size: 14px;
            margin: 0;
            line-height: 1.6;
        }

        .admin-notes-box {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border-left: 3px solid #3b82f6;
            padding: 12px;
            border-radius: 8px;
            margin-top: 12px;
        }

        .admin-notes-box strong {
            color: #1e40af;
            font-size: 13px;
            display: block;
            margin-bottom: 5px;
        }

        .admin-notes-box p {
            color: #1e3a8a;
            font-size: 13px;
            margin: 0;
        }

        .request-card-footer {
            background: #f9fafb;
            padding: 12px 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
        }

        .empty-state {
            background: white;
            border-radius: 15px;
            padding: 60px 30px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .empty-state i {
            font-size: 80px;
            color: #e5e7eb;
            margin-bottom: 20px;
        }

        .empty-state h5 {
            color: #6b7280;
            font-size: 20px;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .empty-state p {
            color: #9ca3af;
            margin-bottom: 25px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.3);
            color: white;
        }

        .summary-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-top: 30px;
        }

        .summary-header {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            padding: 20px 25px;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-body {
            padding: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            text-align: center;
        }

        .stat-item {
            padding: 20px;
            border-radius: 10px;
            background: #f9fafb;
            transition: all 0.3s;
        }

        .stat-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            .sidebar { width: 200px; }
            .main-content { margin-left: 200px; padding: 20px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
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
                <a class="nav-link active" href="my_requests.php">
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
            <h1><i class="fas fa-list"></i>My Requests</h1>
            <div class="dropdown">
                <button class="btn btn-light" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle me-2"></i><?php echo $_SESSION['full_name']; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <div class="filter-title">
                <i class="fas fa-filter"></i>Filter Requests
            </div>
            <form method="GET">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Request Type</label>
                        <select class="form-select" name="type">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="facility" <?php echo $filter_type === 'facility' ? 'selected' : ''; ?>>Facility Booking</option>
                            <option value="item" <?php echo $filter_type === 'item' ? 'selected' : ''; ?>>Item Borrowing</option>
                            <option value="vehicle" <?php echo $filter_type === 'vehicle' ? 'selected' : ''; ?>>Vehicle Request</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="denied" <?php echo $filter_status === 'denied' ? 'selected' : ''; ?>>Denied</option>
                            <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search by item or purpose...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-search me-2"></i>Apply
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Requests Grid -->
        <div class="row">
            <?php if (empty($requests)): ?>
                <div class="col-12">
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h5>No requests found</h5>
                        <p>You haven't made any requests yet, or no requests match your filters.</p>
                        <a href="resident_dashboard.php" class="btn-primary">
                            <i class="fas fa-plus"></i>Make a Request
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($requests as $request): ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="request-card">
                            <div class="request-card-header">
                                <?php
                                $type_class = '';
                                switch ($request['type']) {
                                    case 'Facility Booking': $type_class = 'type-facility'; break;
                                    case 'Item Borrowing': $type_class = 'type-item'; break;
                                    case 'Vehicle Request': $type_class = 'type-vehicle'; break;
                                }
                                ?>
                                <span class="type-badge <?php echo $type_class; ?>">
                                    <?php echo $request['type']; ?>
                                </span>
                                <?php
                                $status_class = '';
                                switch ($request['status']) {
                                    case 'pending': $status_class = 'bg-warning text-dark'; break;
                                    case 'approved': $status_class = 'bg-success text-white'; break;
                                    case 'denied': $status_class = 'bg-danger text-white'; break;
                                    case 'completed': $status_class = 'bg-info text-white'; break;
                                    case 'returned': $status_class = 'bg-success text-white'; break;
                                    case 'overdue': $status_class = 'bg-danger text-white'; break;
                                    default: $status_class = 'bg-secondary text-white';
                                }
                                ?>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo ucfirst($request['status']); ?>
                                </span>
                            </div>
                            
                            <div class="request-card-body">
                                <h6 class="request-title"><?php echo htmlspecialchars($request['item_name']); ?></h6>
                                
                                <div class="info-item">
                                    <i class="fas fa-calendar"></i>
                                    <span><?php echo date('M d, Y', strtotime($request['date'])); ?></span>
                                </div>
                                
                                <?php if ($request['start_time'] && $request['end_time']): ?>
                                    <div class="info-item">
                                        <i class="fas fa-clock"></i>
                                        <span>
                                            <?php echo date('g:i A', strtotime($request['start_time'])); ?> - 
                                            <?php echo date('g:i A', strtotime($request['end_time'])); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($request['purpose']): ?>
                                    <div class="purpose-box">
                                        <strong>Purpose:</strong>
                                        <p>
                                            <?php echo htmlspecialchars(substr($request['purpose'], 0, 100)); ?>
                                            <?php if (strlen($request['purpose']) > 100): ?>...<?php endif; ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($request['admin_notes']): ?>
                                    <div class="admin-notes-box">
                                        <strong>Admin Notes:</strong>
                                        <p><?php echo htmlspecialchars($request['admin_notes']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="request-card-footer">
                                <i class="fas fa-clock me-2"></i>
                                <?php echo date('M d, Y g:i A', strtotime($request['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Summary Statistics -->
        <?php if (!empty($requests)): ?>
            <div class="summary-card">
                <div class="summary-header">
                    <i class="fas fa-chart-bar"></i>Request Summary
                </div>
                <div class="summary-body">
                    <div class="stats-grid">
                        <?php
                        $stats = [
                            'total' => count($requests),
                            'pending' => 0,
                            'approved' => 0,
                            'denied' => 0,
                            'completed' => 0
                        ];
                        
                        foreach ($requests as $request) {
                            if (isset($stats[$request['status']])) {
                                $stats[$request['status']]++;
                            }
                        }
                        ?>
                        <div class="stat-item">
                            <div class="stat-number" style="color: #8b5cf6;"><?php echo $stats['total']; ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number" style="color: #f59e0b;"><?php echo $stats['pending']; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number" style="color: #10b981;"><?php echo $stats['approved']; ?></div>
                            <div class="stat-label">Approved</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number" style="color: #ef4444;"><?php echo $stats['denied']; ?></div>
                            <div class="stat-label">Denied</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number" style="color: #06b6d4;"><?php echo $stats['completed']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number" style="color: #6b7280;">
                                <?php echo round(($stats['approved'] / max($stats['total'], 1)) * 100); ?>%
                            </div>
                            <div class="stat-label">Approval Rate</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>