<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$user_id = $_SESSION['user_id'];

$query = "SELECT 
    (SELECT COUNT(*) FROM facility_bookings WHERE user_id = :user_id AND status = 'pending') as pending_bookings,
    (SELECT COUNT(*) FROM item_borrowings WHERE user_id = :user_id AND status = 'pending') as pending_borrowings,
    (SELECT COUNT(*) FROM vehicle_requests WHERE user_id = :user_id AND status = 'pending') as pending_vehicles,
    (SELECT COUNT(*) FROM facility_bookings WHERE user_id = :user_id AND status = 'approved') as approved_bookings,
    (SELECT COUNT(*) FROM item_borrowings WHERE user_id = :user_id AND status = 'approved') as approved_borrowings,
    (SELECT COUNT(*) FROM vehicle_requests WHERE user_id = :user_id AND status = 'approved') as approved_vehicles";

$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$query = "SELECT 'Facility Booking' as type, fb.id, f.name as item_name, fb.booking_date as date, fb.status, fb.created_at
          FROM facility_bookings fb 
          JOIN facilities f ON fb.facility_id = f.id 
          WHERE fb.user_id = :user_id
          UNION ALL
          SELECT 'Item Borrowing' as type, ib.id, i.name as item_name, ib.borrow_date as date, ib.status, ib.created_at
          FROM item_borrowings ib 
          JOIN items i ON ib.item_id = i.id 
          WHERE ib.user_id = :user_id
          UNION ALL
          SELECT 'Vehicle Request' as type, vr.id, v.name as item_name, vr.request_date as date, vr.status, vr.created_at
          FROM vehicle_requests vr 
          JOIN vehicles v ON vr.vehicle_id = v.id 
          WHERE vr.user_id = :user_id
          ORDER BY created_at DESC LIMIT 8";

$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$recent_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Dashboard - Barangay Kapasigan</title>

    <!-- Bootstrap & Font Awesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        /* Reset & base */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: linear-gradient(180deg, #f5f9ff 0%, #ffffff 50%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1f2937;
        }

        /* SIDEBAR */
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

        /* MAIN CONTENT */
        .main-content {
            margin-left: 260px;
            padding: 34px;
            transition: margin 0.2s ease;
        }

        .page-header {
            background: rgba(255,255,255,0.98);
            padding: 26px;
            border-radius: 14px;
            box-shadow: 0 8px 30px rgba(14, 30, 70, 0.06);
            margin-bottom: 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .page-header h1 {
            font-size: 26px;
            color: #0f1724;
            font-weight: 700;
            margin: 0;
        }

        .user-menu {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.18);
        }

        /* GRID & CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 22px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(14, 30, 70, 0.04);
            transition: transform .28s ease, box-shadow .28s ease;
            position: relative;
            overflow: hidden;
            border-top: 4px solid rgba(37,99,235,0.12);
        }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 48px rgba(14, 30, 70, 0.08);
        }

        .stat-card-icon {
            width: 64px;
            height: 64px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 12px;
            float: right;
            opacity: 0.95;
        }

        .stat-card.pending { border-top-color: rgba(245,158,11,0.18); }
        .stat-card.approved { border-top-color: rgba(16,185,129,0.18); }

        .stat-card.pending .stat-card-icon {
            background: rgba(245,158,11,0.08);
            color: #d97706;
        }

        .stat-card.approved .stat-card-icon {
            background: rgba(16,185,129,0.06);
            color: #059669;
        }

        .stat-card-label {
            font-size: 12px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 8px;
        }

        .stat-card-value {
            font-size: 34px;
            font-weight: 800;
            color: #0f1724;
            margin-bottom: 6px;
        }

        .stat-card-subtitle {
            font-size: 13px;
            color: #6b7280;
        }

        /* QUICK ACTIONS */
        .quick-actions {
            background: rgba(255,255,255,0.98);
            border-radius: 14px;
            padding: 26px;
            box-shadow: 0 10px 30px rgba(14, 30, 70, 0.04);
            margin-bottom: 30px;
        }

        .quick-actions-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f1724;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
        }

        .action-btn {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 18px;
            text-decoration: none;
            transition: all 0.28s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 28px rgba(37,99,235,0.12);
            min-height: 96px;
            justify-content: center;
        }

        .action-btn i { font-size: 22px; opacity: 0.98; }

        .action-btn:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 48px rgba(30,64,175,0.16);
            text-decoration: none;
            color: white;
        }

        /* RECENT REQUESTS */
        .recent-requests {
            background: rgba(255,255,255,0.98);
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 10px 30px rgba(14, 30, 70, 0.04);
        }

        .recent-requests-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f1724;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .request-item {
            display: flex;
            align-items: center;
            padding: 14px;
            border-radius: 10px;
            background: linear-gradient(180deg, rgba(249,250,255,0.75), rgba(255,255,255,0.9));
            margin-bottom: 12px;
            border-left: 4px solid rgba(37,99,235,0.14);
            transition: all 0.22s;
            gap: 12px;
        }

        .request-item:hover {
            background: #f0f6ff;
            transform: translateX(6px);
            box-shadow: 0 10px 24px rgba(37,99,235,0.06);
        }

        .request-type-badge {
            background: linear-gradient(135deg, rgba(37,99,235,0.95), rgba(30,64,175,0.95));
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 800;
            min-width: 90px;
            text-align: center;
            flex-shrink: 0;
            box-shadow: 0 6px 18px rgba(37,99,235,0.12);
        }

        .request-info {
            flex: 1;
            margin: 0 12px;
            min-width: 0;
        }

        .request-name {
            font-weight: 700;
            color: #0f1724;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .request-date {
            font-size: 12px;
            color: #6b7280;
            display: block;
        }

        .request-status {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 800;
            flex-shrink: 0;
            text-transform: capitalize;
        }

        .request-status.pending {
            background: #fffbeb;
            color: #92400e;
        }

        .request-status.approved {
            background: #ecfdf5;
            color: #065f46;
        }

        .request-status.denied {
            background: #fff1f2;
            color: #991b1b;
        }

        .empty-state {
            text-align: center;
            padding: 28px 20px;
            color: #9ca3af;
        }

        .empty-state i {
            font-size: 44px;
            color: #cfe0ff;
            margin-bottom: 12px;
            display: block;
        }

        /* Responsive tweaks */
        @media (max-width: 992px) {
            .sidebar { width: 220px; }
            .main-content { margin-left: 220px; padding: 20px; }
        }

        @media (max-width: 768px) {
            .sidebar { position: relative; width: 100%; min-height: auto; padding-bottom: 12px; }
            .main-content { margin-left: 0; padding: 16px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .stats-grid { grid-template-columns: 1fr; gap: 14px; }
            .action-grid { grid-template-columns: 1fr; gap: 14px; }
            .request-item { flex-wrap: wrap; }
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
                <a class="nav-link active" href="resident_dashboard.php">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="book_facility.php">
                    <i class="fas fa-building"></i>
                    <span>Book Facility</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="borrow_item.php">
                    <i class="fas fa-box"></i>
                    <span>Borrow Items</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="request_vehicle.php">
                    <i class="fas fa-car"></i>
                    <span>Request Vehicle</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="resident calendar.php">
                    <i class="fas fa-calendar"></i>
                    <span>Calendar</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="my_requests.php">
                    <i class="fas fa-list"></i>
                    <span>My Requests</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="reminders.php">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <h1>Welcome, <?php echo explode(' ', $_SESSION['full_name'])[0]; ?>! 👋</h1>
            <div class="dropdown">
                <button class="user-menu" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-user-circle"></i>
                    <span style="white-space:nowrap;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card pending">
                <div class="stat-card-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-card-label">Pending Requests</div>
                <div class="stat-card-value">
                    <?php echo (intval($stats['pending_bookings']) + intval($stats['pending_borrowings']) + intval($stats['pending_vehicles'])); ?>
                </div>
                <div class="stat-card-subtitle">Waiting for approval</div>
            </div>

            <div class="stat-card approved">
                <div class="stat-card-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-card-label">Approved Requests</div>
                <div class="stat-card-value">
                    <?php echo (intval($stats['approved_bookings']) + intval($stats['approved_borrowings']) + intval($stats['approved_vehicles'])); ?>
                </div>
                <div class="stat-card-subtitle">Ready to use</div>
            </div>

            <div class="stat-card" style="border-top-color: rgba(139,92,246,0.12);">
                <div class="stat-card-icon" style="background: rgba(37,99,235,0.06); color: #2563eb;">
                    <i class="fas fa-list"></i>
                </div>
                <div class="stat-card-label">Total Requests</div>
                <div class="stat-card-value">
                    <?php echo (
                        intval($stats['pending_bookings']) + intval($stats['pending_borrowings']) + intval($stats['pending_vehicles']) +
                        intval($stats['approved_bookings']) + intval($stats['approved_borrowings']) + intval($stats['approved_vehicles'])
                    ); ?>
                </div>
                <div class="stat-card-subtitle">All time</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <div class="quick-actions-title">
                <i class="fas fa-bolt" style="background: -webkit-linear-gradient(#2563eb,#1e40af); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i>
                Quick Actions
            </div>
            <div class="action-grid">
                <a href="book_facility.php" class="action-btn">
                    <i class="fas fa-building"></i>
                    <span>Book Facility</span>
                </a>
                <a href="borrow_item.php" class="action-btn">
                    <i class="fas fa-box"></i>
                    <span>Borrow Items</span>
                </a>
                <a href="request_vehicle.php" class="action-btn">
                    <i class="fas fa-car"></i>
                    <span>Request Vehicle</span>
                </a>
            </div>
        </div>

        <!-- Recent Requests -->
        <div class="recent-requests">
            <div class="recent-requests-title">
                <i class="fas fa-history" style="background: -webkit-linear-gradient(#2563eb,#1e40af); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i>
                My Recent Requests
            </div>

            <?php if (empty($recent_requests)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No recent requests found</p>
                </div>
            <?php else: ?>
                <?php foreach ($recent_requests as $request):
                    $type_class = strtolower(str_replace(' ', '', $request['type'])); // e.g., facilitybooking
                    $status_class = strtolower($request['status']);
                ?>
                <div class="request-item" role="article" aria-label="<?php echo htmlspecialchars($request['type'] . ' - ' . $request['item_name']); ?>">
                    <span class="request-type-badge">
                        <?php
                            if ($request['type'] === 'Facility Booking') echo 'Facility';
                            elseif ($request['type'] === 'Item Borrowing') echo 'Item';
                            else echo 'Vehicle';
                        ?>
                    </span>
                    <div class="request-info">
                        <span class="request-name"><?php echo htmlspecialchars($request['item_name']); ?></span>
                        <span class="request-date"><?php echo date('M d, Y', strtotime($request['date'])); ?></span>
                    </div>
                    <span class="request-status <?php echo $status_class; ?>">
                        <?php echo ucfirst($request['status']); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
