<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireAdmin();

$database = new Database();
$conn = $database->getConnection();

$error = null;

$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));

$facility_notifications = [];
$item_notifications = [];
$vehicle_notifications = [];

try {
    // Facility bookings (newest first)
    $q = "SELECT fb.id, fb.booking_date, fb.start_time, fb.end_time, fb.purpose, fb.status, fb.created_at,
                 f.name as facility_name, u.full_name as resident_name
          FROM facility_bookings fb
          JOIN facilities f ON fb.facility_id = f.id
          JOIN users u ON fb.user_id = u.id
          ORDER BY fb.created_at DESC
          LIMIT 100";
    $stmt = $conn->prepare($q);
    $stmt->execute();
    $facility_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $error ? $error . ' | ' . $e->getMessage() : $e->getMessage();
    $facility_notifications = [];
}

try {
    // Item borrowings (newest first)
    $q = "SELECT ib.id, ib.borrow_date, ib.return_date, ib.purpose, ib.status, ib.created_at,
                 i.name as item_name, u.full_name as resident_name
          FROM item_borrowings ib
          JOIN items i ON ib.item_id = i.id
          JOIN users u ON ib.user_id = u.id
          ORDER BY ib.created_at DESC
          LIMIT 100";
    $stmt = $conn->prepare($q);
    $stmt->execute();
    $item_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $error ? $error . ' | ' . $e->getMessage() : $e->getMessage();
    $item_notifications = [];
}

try {
    // Vehicle requests (newest first)
    $q = "SELECT vr.id, vr.request_date, vr.start_time, vr.end_time, vr.purpose, vr.status, vr.created_at,
                 v.name as vehicle_name, u.full_name as resident_name
          FROM vehicle_requests vr
          JOIN vehicles v ON vr.vehicle_id = v.id
          JOIN users u ON vr.user_id = u.id
          ORDER BY vr.created_at DESC
          LIMIT 100";
    $stmt = $conn->prepare($q);
    $stmt->execute();
    $vehicle_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $error ? $error . ' | ' . $e->getMessage() : $e->getMessage();
    $vehicle_notifications = [];
}

function status_badge($status) {
    $s = strtolower($status);
    switch ($s) {
        case 'approved': return '<span class="badge bg-success">Approved</span>';
        case 'declined': return '<span class="badge bg-danger">Declined</span>';
        case 'cancelled': return '<span class="badge bg-secondary">Cancelled</span>';
        case 'pending': return '<span class="badge bg-warning text-dark">Pending</span>';
        default: return '<span class="badge bg-light text-dark">'.htmlspecialchars($status).'</span>';
    }
}

function human_date($dateStr, $format = 'M d, Y') {
    if (!$dateStr) return '';
    $ts = strtotime($dateStr);
    if ($ts === false) return htmlspecialchars($dateStr);
    return date($format, $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Admin Notifications - Barangay Kapasigan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8f9fa;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: rgba(255,255,255,0.9);
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.9);
            padding: 12px 20px;
            margin: 2px 0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255,255,255,0.08);
        }
        .admin-badge {
            background: linear-gradient(45deg, #dc3545, #fd7e14);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            display: inline-block;
        }
        
        /* Tab Navigation */
        .tab-navigation {
            background: white;
            border-radius: 12px;
            padding: 8px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .tab-btn {
            flex: 1;
            min-width: 200px;
            padding: 16px 20px;
            border: 2px solid transparent;
            border-radius: 10px;
            background: #f9fafb;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .tab-btn:hover {
            background: #f3f4f6;
            transform: translateY(-2px);
        }
        
        .tab-btn.active {
            background: white;
            border-color: currentColor;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .tab-btn.facility-tab {
            color: #2563eb;
        }
        
        .tab-btn.facility-tab.active {
            border-color: #2563eb;
        }
        
        .tab-btn.item-tab {
            color: #059669;
        }
        
        .tab-btn.item-tab.active {
            border-color: #059669;
        }
        
        .tab-btn.vehicle-tab {
            color: #d97706;
        }
        
        .tab-btn.vehicle-tab.active {
            border-color: #d97706;
        }
        
        .tab-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            background: currentColor;
            color: white;
            opacity: 0.9;
        }
        
        .tab-btn.active .tab-icon {
            opacity: 1;
        }
        
        .tab-content-wrapper {
            display: flex;
            flex-direction: column;
        }
        
        .tab-info h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }
        
        .tab-info small {
            color: #6b7280;
            font-size: 13px;
        }
        
        .tab-count {
            margin-left: auto;
            background: currentColor;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            opacity: 0.9;
        }
        
        .tab-btn.active .tab-count {
            opacity: 1;
        }
        
        /* Content Panel */
        .content-panel {
            display: none;
        }
        
        .content-panel.active {
            display: block;
        }
        
        .notification-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            padding: 24px;
        }
        
        .notification-item {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 12px;
            border: 1px solid #e5e7eb;
            transition: all 0.2s ease;
        }
        
        .notification-item:hover {
            border-color: #d1d5db;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .notif-title {
            font-weight: 600;
            color: #111827;
            font-size: 15px;
            margin-bottom: 8px;
        }
        
        .notif-meta {
            color: #6b7280;
            font-size: 13px;
            margin-bottom: 8px;
        }
        
        .notif-purpose {
            color: #374151;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .empty-state {
            text-align: center;
            color: #9ca3af;
            padding: 64px 0;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        
        .empty-state h4 {
            font-size: 18px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        
        /* Search Filter */
        .search-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .search-icon {
            position: absolute;
            left: 16px;
            color: #9ca3af;
            font-size: 16px;
            z-index: 1;
        }
        
        .search-input {
            padding: 12px 48px 12px 48px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        .clear-search {
            position: absolute;
            right: 12px;
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .clear-search:hover {
            background: #dc2626;
            transform: scale(1.1);
        }
        
        .no-results {
            text-align: center;
            padding: 48px 20px;
            color: #9ca3af;
        }
        
        .no-results i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .no-results h4 {
            font-size: 18px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .tab-btn {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse" id="sidebarMenu">
            <div class="position-sticky pt-3">
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white" style="width: 60px; height: 60px; overflow: hidden;">
                        <img src="kapasigan.png" alt="Logo" class="img-fluid">
                    </div>
                    <h5 class="text-white mt-2">Admin Panel</h5>
                    <span class="admin-badge">Administrator</span>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link active" href="admin_dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_approval.php"><i class="fas fa-check-circle me-2"></i>Approve Requests</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_reports.php"><i class="fas fa-chart-bar me-2"></i>Reports & Analytics</a></li>
                    <li class="nav-item"><a class="nav-link" href="calendar.php"><i class="fas fa-calendar me-2"></i>Calendar View</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin notif.php"><i class="fas fa-bell me-2"></i>Notifications</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_messages.php"><i class="fas fa-envelope me-2"></i>Messages</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_ml_dashboard.php"><i class="fas fa-envelope me-2"></i>ML Analytics</a></li>

                    <li class="nav-item">
                        <a class="nav-link" href="#" data-bs-toggle="collapse" data-bs-target="#manageSubmenu"><i class="fas fa-cogs me-2"></i>Manage<i class="fas fa-chevron-down ms-auto"></i></a>
                        <div class="collapse" id="manageSubmenu">
                            <ul class="nav flex-column ms-3">
                                <li class="nav-item"><a class="nav-link" href="manage_facilities.php"><i class="fas fa-building me-2"></i>Facilities</a></li>
                                <li class="nav-item"><a class="nav-link" href="manage_items.php"><i class="fas fa-box me-2"></i>Items</a></li>
                                <li class="nav-item"><a class="nav-link" href="manage_vehicles.php"><i class="fas fa-car me-2"></i>Vehicles</a></li>
                                <li class="nav-item"><a class="nav-link" href="manage_users.php"><i class="fas fa-users me-2"></i>Users</a></li>
                            </ul>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-3">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3"><i class="fas fa-bell me-2"></i>Notifications</h1>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Search Filter -->
                <div class="search-container mb-3">
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="form-control search-input" placeholder="Search notifications by name, purpose, status, or date...">
                        <button class="clear-search" id="clearSearch" style="display: none;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <!-- Tab Navigation -->
                <div class="tab-navigation">
                    <div class="tab-btn facility-tab active" onclick="showTab('facilities')">
                        <div class="tab-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="tab-content-wrapper">
                            <div class="tab-info">
                                <h3>Facility Bookings</h3>
                                <small>Recent booking requests</small>
                            </div>
                        </div>
                        <span class="tab-count"><?php echo count($facility_notifications); ?></span>
                    </div>

                    <div class="tab-btn item-tab" onclick="showTab('items')">
                        <div class="tab-icon">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="tab-content-wrapper">
                            <div class="tab-info">
                                <h3>Item Borrowings</h3>
                                <small>Recent borrowing requests</small>
                            </div>
                        </div>
                        <span class="tab-count"><?php echo count($item_notifications); ?></span>
                    </div>

                    <div class="tab-btn vehicle-tab" onclick="showTab('vehicles')">
                        <div class="tab-icon">
                            <i class="fas fa-car"></i>
                        </div>
                        <div class="tab-content-wrapper">
                            <div class="tab-info">
                                <h3>Vehicle Requests</h3>
                                <small>Recent vehicle requests</small>
                            </div>
                        </div>
                        <span class="tab-count"><?php echo count($vehicle_notifications); ?></span>
                    </div>
                </div>

                <!-- Facility Bookings Content -->
                <div id="facilities-content" class="content-panel active">
                    <div class="notification-container">
                        <?php if (empty($facility_notifications)): ?>
                            <div class="empty-state">
                                <i class="fas fa-building"></i>
                                <h4>No Facility Bookings</h4>
                                <p>There are no facility booking notifications at this time.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($facility_notifications as $fb): ?>
                                <div class="notification-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="notif-title">
                                                <?php echo htmlspecialchars($fb['facility_name']); ?>
                                                <span class="text-muted" style="font-weight: 400;"> • <?php echo htmlspecialchars($fb['resident_name']); ?></span>
                                            </div>
                                            <div class="notif-meta">
                                                <i class="fas fa-calendar-alt me-1"></i> <?php echo human_date($fb['booking_date']); ?>
                                                <?php if (!empty($fb['start_time']) && !empty($fb['end_time'])): ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="fas fa-clock me-1"></i> <?php echo date('g:i A', strtotime($fb['start_time'])); ?> - <?php echo date('g:i A', strtotime($fb['end_time'])); ?>
                                                <?php endif; ?>
                                                <span class="mx-2">•</span>
                                                <small>Created: <?php echo human_date($fb['created_at'], 'M d, Y g:i A'); ?></small>
                                            </div>
                                            <div class="notif-purpose"><?php echo htmlspecialchars($fb['purpose'] ?? 'No purpose provided'); ?></div>
                                        </div>
                                        <div class="ms-3">
                                            <?php echo status_badge($fb['status']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Item Borrowings Content -->
                <div id="items-content" class="content-panel">
                    <div class="notification-container">
                        <?php if (empty($item_notifications)): ?>
                            <div class="empty-state">
                                <i class="fas fa-box"></i>
                                <h4>No Item Borrowings</h4>
                                <p>There are no item borrowing notifications at this time.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($item_notifications as $ib): ?>
                                <div class="notification-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="notif-title">
                                                <?php echo htmlspecialchars($ib['item_name']); ?>
                                                <span class="text-muted" style="font-weight: 400;"> • <?php echo htmlspecialchars($ib['resident_name']); ?></span>
                                            </div>
                                            <div class="notif-meta">
                                                <i class="fas fa-calendar-alt me-1"></i> Borrow: <?php echo human_date($ib['borrow_date']); ?>
                                                <?php if (!empty($ib['return_date'])): ?>
                                                    <span class="mx-2">•</span> Return: <?php echo human_date($ib['return_date']); ?>
                                                <?php endif; ?>
                                                <span class="mx-2">•</span>
                                                <small>Created: <?php echo human_date($ib['created_at'], 'M d, Y g:i A'); ?></small>
                                            </div>
                                            <div class="notif-purpose"><?php echo htmlspecialchars($ib['purpose'] ?? 'No purpose provided'); ?></div>
                                        </div>
                                        <div class="ms-3">
                                            <?php echo status_badge($ib['status']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Vehicle Requests Content -->
                <div id="vehicles-content" class="content-panel">
                    <div class="notification-container">
                        <?php if (empty($vehicle_notifications)): ?>
                            <div class="empty-state">
                                <i class="fas fa-car"></i>
                                <h4>No Vehicle Requests</h4>
                                <p>There are no vehicle request notifications at this time.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($vehicle_notifications as $vr): ?>
                                <div class="notification-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="notif-title">
                                                <?php echo htmlspecialchars($vr['vehicle_name']); ?>
                                                <span class="text-muted" style="font-weight: 400;"> • <?php echo htmlspecialchars($vr['resident_name']); ?></span>
                                            </div>
                                            <div class="notif-meta">
                                                <i class="fas fa-calendar-alt me-1"></i> <?php echo human_date($vr['request_date']); ?>
                                                <?php if (!empty($vr['start_time']) && !empty($vr['end_time'])): ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="fas fa-clock me-1"></i> <?php echo date('g:i A', strtotime($vr['start_time'])); ?> - <?php echo date('g:i A', strtotime($vr['end_time'])); ?>
                                                <?php endif; ?>
                                                <span class="mx-2">•</span>
                                                <small>Created: <?php echo human_date($vr['created_at'], 'M d, Y g:i A'); ?></small>
                                            </div>
                                            <div class="notif-purpose"><?php echo htmlspecialchars($vr['purpose'] ?? 'No purpose provided'); ?></div>
                                        </div>
                                        <div class="ms-3">
                                            <?php echo status_badge($vr['status']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showTab(tabName) {
            // Hide all content panels
            document.querySelectorAll('.content-panel').forEach(panel => {
                panel.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected content panel
            document.getElementById(tabName + '-content').classList.add('active');
            
            // Add active class to selected tab
            if (tabName === 'facilities') {
                document.querySelector('.facility-tab').classList.add('active');
            } else if (tabName === 'items') {
                document.querySelector('.item-tab').classList.add('active');
            } else if (tabName === 'vehicles') {
                document.querySelector('.vehicle-tab').classList.add('active');
            }
            
            // Re-apply search filter
            filterNotifications();
        }
        
        // Search functionality
        const searchInput = document.getElementById('searchInput');
        const clearSearch = document.getElementById('clearSearch');
        
        searchInput.addEventListener('input', function() {
            if (this.value.length > 0) {
                clearSearch.style.display = 'flex';
            } else {
                clearSearch.style.display = 'none';
            }
            filterNotifications();
        });
        
        clearSearch.addEventListener('click', function() {
            searchInput.value = '';
            clearSearch.style.display = 'none';
            filterNotifications();
        });
        
        function filterNotifications() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            const activePanel = document.querySelector('.content-panel.active');
            const items = activePanel.querySelectorAll('.notification-item');
            const emptyState = activePanel.querySelector('.empty-state');
            let visibleCount = 0;
            
            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    item.style.display = 'block';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Show "no results" message if search is active and no items match
            if (searchTerm && visibleCount === 0 && items.length > 0) {
                if (!activePanel.querySelector('.no-results')) {
                    const noResults = document.createElement('div');
                    noResults.className = 'no-results';
                    noResults.innerHTML = `
                        <i class="fas fa-search"></i>
                        <h4>No Results Found</h4>
                        <p>No notifications match your search for "<strong>${searchTerm}</strong>"</p>
                        <p class="text-muted small">Try different keywords or clear your search</p>
                    `;
                    activePanel.querySelector('.notification-container').appendChild(noResults);
                }
            } else {
                // Remove "no results" message if it exists
                const noResults = activePanel.querySelector('.no-results');
                if (noResults) {
                    noResults.remove();
                }
            }
            
            // Handle original empty state
            if (emptyState) {
                emptyState.style.display = searchTerm ? 'none' : 'block';
            }
        }
    </script>
</body>
</html>