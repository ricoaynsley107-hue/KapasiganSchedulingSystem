<?php
// admin_reports.php
// Reports & Analytics with proper PDO integration
// Connects to manage_items, manage_vehicles, manage_facilities, and manage_users data

require_once 'includes/auth.php';
require_once 'config/database.php';


$auth = new Auth();
$auth->requireAdmin();

$database = new Database();
$conn = $database->getConnection();

// Simple helper to output JSON and exit
function json_response($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// If an action is requested, return JSON data for charts
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Helper: safe date handling
    $now = new DateTime();
    $currentYear = (int)$now->format('Y');
    $currentMonth = (int)$now->format('m');
    $monthStart = $now->format('Y-m-01');

    switch ($action) {
        case 'summary_stats':
            // Facility bookings this month
            $query1 = "SELECT COUNT(*) AS cnt FROM facility_bookings WHERE DATE(booking_date) >= :monthStart";
            $stmt1 = $conn->prepare($query1);
            $stmt1->bindParam(':monthStart', $monthStart);
            $stmt1->execute();
            $facBookings = (int)($stmt1->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

            // Item borrowings this month
            $query2 = "SELECT COUNT(*) AS cnt FROM item_borrowings WHERE DATE(borrow_date) >= :monthStart";
            $stmt2 = $conn->prepare($query2);
            $stmt2->bindParam(':monthStart', $monthStart);
            $stmt2->execute();
            $itemBorrows = (int)($stmt2->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

            // Vehicle requests this month
            $query3 = "SELECT COUNT(*) AS cnt FROM vehicle_requests WHERE DATE(request_date) >= :monthStart";
            $stmt3 = $conn->prepare($query3);
            $stmt3->bindParam(':monthStart', $monthStart);
            $stmt3->execute();
            $vehicleReqs = (int)($stmt3->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

            // Active users (total residents)
            $query4 = "SELECT COUNT(*) AS cnt FROM users WHERE role != 'admin'";
            $stmt4 = $conn->prepare($query4);
            $stmt4->execute();
            $activeUsers = (int)($stmt4->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

            json_response([
                'facility_bookings' => $facBookings,
                'item_borrowings'   => $itemBorrows,
                'vehicle_requests'  => $vehicleReqs,
                'active_users'      => $activeUsers
            ]);
            break;

        case 'most_borrowed_items':
            // Most borrowed items this month
            $query = "
                SELECT ib.item_id,
                       COALESCE(i.name, CAST(ib.item_id AS CHAR)) AS name,
                       COUNT(*) AS borrow_count
                FROM item_borrowings ib
                LEFT JOIN items i ON ib.item_id = i.id
                WHERE DATE(ib.borrow_date) >= :monthStart
                GROUP BY ib.item_id
                ORDER BY borrow_count DESC
                LIMIT 10
            ";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':monthStart', $monthStart);
            $stmt->execute();
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[] = ['name' => $row['name'], 'borrow_count' => (int)$row['borrow_count']];
            }
            json_response($out);
            break;

        case 'most_booked_facilities':
            // Most booked facilities this month
            $query = "
                SELECT fb.facility_id,
                       COALESCE(f.name, CAST(fb.facility_id AS CHAR)) AS name,
                       COUNT(*) AS booking_count
                FROM facility_bookings fb
                LEFT JOIN facilities f ON fb.facility_id = f.id
                WHERE DATE(fb.booking_date) >= :monthStart
                GROUP BY fb.facility_id
                ORDER BY booking_count DESC
                LIMIT 10
            ";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':monthStart', $monthStart);
            $stmt->execute();
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[] = ['name' => $row['name'], 'booking_count' => (int)$row['booking_count']];
            }
            json_response($out);
            break;

        case 'peak_hours':
            // Peak hours for facility bookings (current month)
            $month = $now->format('Y-m');
            $query = "
                SELECT HOUR(fb.start_time) AS hour, COUNT(*) AS request_count
                FROM facility_bookings fb
                WHERE DATE(fb.booking_date) >= :monthStart
                GROUP BY HOUR(fb.start_time)
                ORDER BY hour
            ";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':monthStart', $monthStart);
            $stmt->execute();
            
            $counts = [];
            // Initialize 0..23
            for ($h = 0; $h < 24; $h++) $counts[$h] = 0;
            
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $h = is_null($r['hour']) ? 0 : (int)$r['hour'];
                $counts[$h] = (int)$r['request_count'];
            }
            
            // Return array of {hour, request_count}
            $out = [];
            foreach ($counts as $h => $c) $out[] = ['hour' => $h, 'request_count' => $c];
            json_response($out);
            break;

        case 'vehicle_usage':
            // Vehicle usage counts this month
            $query = "
                SELECT v.name, COUNT(*) AS usage_count
                FROM vehicle_requests vr
                LEFT JOIN vehicles v ON vr.vehicle_id = v.id
                WHERE DATE(vr.request_date) >= :monthStart
                GROUP BY vr.vehicle_id
                ORDER BY usage_count DESC
                LIMIT 10
            ";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':monthStart', $monthStart);
            $stmt->execute();
            $out = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $out[] = ['name' => $row['name'] ?? 'Unknown Vehicle', 'usage_count' => (int)$row['usage_count']];
            }
            json_response($out);
            break;

        case 'monthly_trends':
            // Trends for last 6 months: facilities, items, vehicles
            $months = [];
            for ($i = 5; $i >= 0; $i--) {
                $m = new DateTime();
                $m->modify("-{$i} months");
                $months[] = $m->format('Y-m');
            }

            $out = [];
            foreach ($months as $monthStr) {
                $monthStart = $monthStr . '-01';
                $monthEnd = (new DateTime($monthStr . '-01'))->modify('last day of this month')->format('Y-m-d');

                $sqlF = "SELECT COUNT(*) AS cnt FROM facility_bookings WHERE DATE(booking_date) BETWEEN :start AND :end";
                $sqlI = "SELECT COUNT(*) AS cnt FROM item_borrowings WHERE DATE(borrow_date) BETWEEN :start AND :end";
                $sqlV = "SELECT COUNT(*) AS cnt FROM vehicle_requests WHERE DATE(request_date) BETWEEN :start AND :end";

                $stmtF = $conn->prepare($sqlF);
                $stmtF->bindParam(':start', $monthStart);
                $stmtF->bindParam(':end', $monthEnd);
                $stmtF->execute();
                $fi = (int)($stmtF->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

                $stmtI = $conn->prepare($sqlI);
                $stmtI->bindParam(':start', $monthStart);
                $stmtI->bindParam(':end', $monthEnd);
                $stmtI->execute();
                $ii = (int)($stmtI->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

                $stmtV = $conn->prepare($sqlV);
                $stmtV->bindParam(':start', $monthStart);
                $stmtV->bindParam(':end', $monthEnd);
                $stmtV->execute();
                $vi = (int)($stmtV->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

                $out[] = [
                    'month' => $monthStr,
                    'facilities' => $fi,
                    'items' => $ii,
                    'vehicles' => $vi
                ];
            }
            json_response($out);
            break;

        case 'status_summary':
            // Sum statuses across facility_bookings, item_borrowings, vehicle_requests
            $statuses = ['pending','approved','denied','completed'];
            $totals = array_fill_keys($statuses, 0);

            foreach ($statuses as $s) {
                // facility bookings
                $q1 = "SELECT COUNT(*) AS cnt FROM facility_bookings WHERE status = :status";
                $r1 = $conn->prepare($q1);
                $r1->bindParam(':status', $s);
                $r1->execute();
                $c1 = (int)($r1->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
                
                // item borrowings
                $q2 = "SELECT COUNT(*) AS cnt FROM item_borrowings WHERE status = :status";
                $r2 = $conn->prepare($q2);
                $r2->bindParam(':status', $s);
                $r2->execute();
                $c2 = (int)($r2->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
                
                // vehicle requests
                $q3 = "SELECT COUNT(*) AS cnt FROM vehicle_requests WHERE status = :status";
                $r3 = $conn->prepare($q3);
                $r3->bindParam(':status', $s);
                $r3->execute();
                $c3 = (int)($r3->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

                $totals[$s] = $c1 + $c2 + $c3;
            }

            json_response($totals);
            break;

        default:
            json_response(['error' => 'Unknown action']);
    }
}

// If no action = render the HTML page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Barangay Kapasigan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        body { background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%); }
        .sidebar .nav-link { color: rgba(255,255,255,0.9); padding: 12px 20px; margin: 2px 0; border-radius: 8px; transition: all .25s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background-color: rgba(255,255,255,0.08); }
        .chart-card { background: white; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.06); padding: 20px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); padding: 15px; }
        .admin-badge { background: linear-gradient(45deg,#dc3545,#fd7e14); color: #fff; padding: 5px 15px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        canvas { max-height: 420px; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- Admin Sidebar -->
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
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-chart-bar me-2"></i>Reports & Analytics</h1>
                <div>
                    <button class="btn btn-outline-danger me-2" onclick="window.print()"><i class="fas fa-print me-2"></i>Print</button>
                    <div class="btn-group">
                        <button class="btn btn-outline-danger dropdown-toggle" data-bs-toggle="dropdown"><i class="fas fa-user-shield me-2"></i><?php echo $_SESSION['full_name']; ?></button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="includes/auth.php?action=logout"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Summary Stats -->
            <div class="row mb-4" id="summary-stats">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stat-card h-100">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="text-uppercase small text-muted">Facility Bookings</div>
                                <div class="h4" id="stat-facility-bookings">—</div>
                                <small class="text-muted">This Month</small>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-building fa-2x text-primary"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stat-card h-100">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="text-uppercase small text-muted">Item Borrowings</div>
                                <div class="h4" id="stat-item-borrowings">—</div>
                                <small class="text-muted">This Month</small>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-box fa-2x text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stat-card h-100">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="text-uppercase small text-muted">Vehicle Requests</div>
                                <div class="h4" id="stat-vehicle-requests">—</div>
                                <small class="text-muted">This Month</small>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-car fa-2x text-info"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stat-card h-100">
                        <div class="d-flex justify-content-between">
                            <div>
                                <div class="text-uppercase small text-muted">Active Users</div>
                                <div class="h4" id="stat-active-users">—</div>
                                <small class="text-muted">Total Residents</small>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-users fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="chart-card">
                        <h5 class="mb-3"><i class="fas fa-box me-2 text-success"></i>Most Borrowed Items (This Month)</h5>
                        <canvas id="mostBorrowedItemsChart"></canvas>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="chart-card">
                        <h5 class="mb-3"><i class="fas fa-building me-2 text-primary"></i>Most Booked Facilities (This Month)</h5>
                        <canvas id="mostBookedFacilitiesChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="chart-card">
                        <h5 class="mb-3"><i class="fas fa-clock me-2 text-warning"></i>Peak Booking Hours (This Month)</h5>
                        <canvas id="peakHoursChart"></canvas>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="chart-card">
                        <h5 class="mb-3"><i class="fas fa-car me-2 text-info"></i>Vehicle Usage (This Month)</h5>
                        <canvas id="vehicleUsageChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Charts Row 3 -->
            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="chart-card">
                        <h5 class="mb-3"><i class="fas fa-chart-line me-2 text-danger"></i>Monthly Trends (Last 6 Months)</h5>
                        <canvas id="monthlyTrendsChart"></canvas>
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <div class="chart-card">
                        <h5 class="mb-3"><i class="fas fa-tasks me-2 text-secondary"></i>Request Status Summary</h5>
                        <canvas id="statusSummaryChart"></canvas>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
const apiBase = 'admin_reports.php?action=';

// Helpers
async function fetchJSON(action) {
    const res = await fetch(apiBase + encodeURIComponent(action));
    return await res.json();
}

// Render summary stats
async function loadSummaryStats() {
    const data = await fetchJSON('summary_stats');
    document.getElementById('stat-facility-bookings').innerText = data.facility_bookings ?? 0;
    document.getElementById('stat-item-borrowings').innerText = data.item_borrowings ?? 0;
    document.getElementById('stat-vehicle-requests').innerText = data.vehicle_requests ?? 0;
    document.getElementById('stat-active-users').innerText = data.active_users ?? 0;
}

// Charts
let chartMostBorrowed = null;
async function loadMostBorrowedItems() {
    const data = await fetchJSON('most_borrowed_items');
    const labels = data.map(d => d.name);
    const values = data.map(d => d.borrow_count);
    const ctx = document.getElementById('mostBorrowedItemsChart').getContext('2d');

    if (chartMostBorrowed) chartMostBorrowed.destroy();
    chartMostBorrowed = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{ label: 'Times Borrowed', data: values, backgroundColor: 'rgba(40,167,69,0.7)', borderColor: 'rgba(40,167,69,1)', borderWidth: 1 }]
        },
        options: { responsive: true, maintainAspectRatio: true, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });
}

let chartMostBooked = null;
async function loadMostBookedFacilities() {
    const data = await fetchJSON('most_booked_facilities');
    const labels = data.map(d => d.name);
    const values = data.map(d => d.booking_count);
    const ctx = document.getElementById('mostBookedFacilitiesChart').getContext('2d');

    if (chartMostBooked) chartMostBooked.destroy();
    chartMostBooked = new Chart(ctx, {
        type: 'doughnut',
        data: { labels, datasets: [{ label:'Bookings', data: values, backgroundColor: ['rgba(0,123,255,0.7)','rgba(220,53,69,0.7)','rgba(255,193,7,0.7)','rgba(40,167,69,0.7)'], borderWidth: 2 }]},
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
    });
}

let chartPeakHours = null;
async function loadPeakHours() {
    const data = await fetchJSON('peak_hours');
    const labels = data.map(d => {
        const hour = parseInt(d.hour,10);
        if (hour === 0) return '12 AM';
        if (hour < 12) return hour + ' AM';
        if (hour === 12) return '12 PM';
        return (hour - 12) + ' PM';
    });
    const values = data.map(d => d.request_count);
    const ctx = document.getElementById('peakHoursChart').getContext('2d');

    if (chartPeakHours) chartPeakHours.destroy();
    chartPeakHours = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets: [{ label:'Requests', data: values, backgroundColor: 'rgba(255,193,7,0.2)', borderColor: 'rgba(255,193,7,1)', borderWidth: 2, fill: true, tension: 0.3 }]},
        options: { responsive: true, maintainAspectRatio: true, scales: { y: { beginAtZero: true } } }
    });
}

let chartVehicleUsage = null;
async function loadVehicleUsage() {
    const data = await fetchJSON('vehicle_usage');
    const labels = data.map(d => d.name);
    const values = data.map(d => d.usage_count);
    const ctx = document.getElementById('vehicleUsageChart').getContext('2d');

    if (chartVehicleUsage) chartVehicleUsage.destroy();
    chartVehicleUsage = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets: [{ label:'Usage Count', data: values, backgroundColor:'rgba(23,162,184,0.7)', borderColor:'rgba(23,162,184,1)', borderWidth:1 }]},
        options: { responsive:true, maintainAspectRatio:true, indexAxis:'y', scales: { x: { beginAtZero:true } } }
    });
}

let chartMonthlyTrends = null;
async function loadMonthlyTrends() {
    const data = await fetchJSON('monthly_trends');
    const labels = data.map(d => {
        const dt = new Date(d.month + '-01');
        return dt.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    });
    const facilities = data.map(d => d.facilities);
    const items = data.map(d => d.items);
    const vehicles = data.map(d => d.vehicles);
    const ctx = document.getElementById('monthlyTrendsChart').getContext('2d');

    if (chartMonthlyTrends) chartMonthlyTrends.destroy();
    chartMonthlyTrends = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label:'Facilities', data: facilities, borderColor:'rgba(0,123,255,1)', backgroundColor:'rgba(0,123,255,0.1)', fill:true, tension:0.3, borderWidth:2 },
                { label:'Items',      data: items,      borderColor:'rgba(40,167,69,1)', backgroundColor:'rgba(40,167,69,0.1)', fill:true, tension:0.3, borderWidth:2 },
                { label:'Vehicles',   data: vehicles,   borderColor:'rgba(23,162,184,1)', backgroundColor:'rgba(23,162,184,0.1)', fill:true, tension:0.3, borderWidth:2 }
            ]
        },
        options: { responsive:true, maintainAspectRatio:true, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true } } }
    });
}

let chartStatusSummary = null;
async function loadStatusSummary() {
    const data = await fetchJSON('status_summary');
    const labels = Object.keys(data);
    const values = Object.values(data);
    const ctx = document.getElementById('statusSummaryChart').getContext('2d');

    if (chartStatusSummary) chartStatusSummary.destroy();
    chartStatusSummary = new Chart(ctx, {
        type: 'pie',
        data: {
            labels,
            datasets: [{ data: values, backgroundColor:['rgba(255,193,7,0.7)','rgba(40,167,69,0.7)','rgba(220,53,69,0.7)','rgba(108,117,125,0.7)'], borderWidth:2 }]
        },
        options: { responsive:true, maintainAspectRatio:true, plugins:{ legend:{ position:'bottom' } } }
    });
}

async function loadAll() {
    try {
        await loadSummaryStats();
        await Promise.all([
            loadMostBorrowedItems(),
            loadMostBookedFacilities(),
            loadPeakHours(),
            loadVehicleUsage(),
            loadMonthlyTrends(),
            loadStatusSummary()
        ]);
    } catch (err) {
        console.error('Error loading data', err);
    }
}

document.addEventListener('DOMContentLoaded', loadAll);

</script>
</body>
</html>
