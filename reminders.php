<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Get upcoming events
$upcoming_events = [];
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));

try {
    $queries = [
        "SELECT fb.id, fb.booking_date, fb.start_time, fb.end_time, fb.purpose, fb.status,
                 f.name as item_name, 'facility' as type
         FROM facility_bookings fb
         JOIN facilities f ON fb.facility_id = f.id
         WHERE fb.booking_date BETWEEN :today AND :next_week
         AND fb.status = 'approved'
         AND fb.user_id = :user_id",

        "SELECT ib.id, ib.return_date as booking_date, NULL as start_time, NULL as end_time, 
                 CONCAT('Return: ', ib.purpose) as purpose, ib.status,
                 i.name as item_name, 'item_return' as type
         FROM item_borrowings ib
         JOIN items i ON ib.item_id = i.id
         WHERE ib.return_date BETWEEN :today AND :next_week
         AND ib.status = 'approved'
         AND ib.user_id = :user_id",

        "SELECT vr.id, vr.request_date as booking_date, vr.start_time, vr.end_time, vr.purpose, vr.status,
                 v.name as item_name, 'vehicle' as type
         FROM vehicle_requests vr
         JOIN vehicles v ON vr.vehicle_id = v.id
         WHERE vr.request_date BETWEEN :today AND :next_week
         AND vr.status = 'approved'
         AND vr.user_id = :user_id"
    ];

    foreach ($queries as $query) {
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':today', $today);
        $stmt->bindParam(':next_week', $next_week);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $upcoming_events[] = $row;
        }
    }

    // Sort by date
    usort($upcoming_events, function($a, $b) {
        return strtotime($a['booking_date']) - strtotime($b['booking_date']);
    });

} catch (Exception $e) {
    $error = "Error loading reminders: " . $e->getMessage();
}

$today_count = count(array_filter($upcoming_events, fn($e) => $e['booking_date'] === $today));
$tomorrow_count = count(array_filter($upcoming_events, fn($e) => $e['booking_date'] === date('Y-m-d', strtotime('+1 day'))));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reminders - Barangay Kapasigan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:linear-gradient(180deg,#f5f9ff 0%,#ffffff 50%); font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; min-height:100vh; }
        .sidebar { min-height:100vh; background:linear-gradient(135deg,#2563eb 0%,#1e40af 100%); width:260px; position:fixed; top:0; left:0; padding-top:20px; }
        .sidebar-header { text-align:center; padding:20px; }
        .sidebar-header img { width:50px; height:50px; border-radius:50%; }
        .sidebar-header h5 { color:white; margin-top:10px; }
        .sidebar .nav-link { color:rgba(255,255,255,0.8); padding:10px 20px; display:flex; align-items:center; gap:10px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background:rgba(255,255,255,0.2); color:white; }
        .main-content { margin-left:260px; padding:30px; }
        .page-header { background:white; border-radius:12px; padding:25px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 3px 10px rgba(0,0,0,0.1); }
        .user-menu { background:linear-gradient(135deg,#667eea,#764ba2); color:white; border:none; padding:10px 20px; border-radius:8px; font-weight:600; }
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:20px; margin-top:30px; }
        .stat-card { background:white; border-radius:12px; padding:25px; box-shadow:0 3px 10px rgba(0,0,0,0.1); position:relative; }
        .stat-card.total { border-top:4px solid #667eea; }
        .stat-card.today { border-top:4px solid #f59e0b; }
        .stat-card.tomorrow { border-top:4px solid #10b981; }
        .reminders-section { background:white; border-radius:15px; padding:30px; box-shadow:0 5px 20px rgba(0,0,0,0.08); margin-top:30px; }
        .reminder-card { border-left:4px solid; padding:20px; border-radius:8px; background:#f9fafb; margin-bottom:15px; }
        .reminder-card.facility { border-left-color:#3b82f6; }
        .reminder-card.item_return { border-left-color:#f59e0b; }
        .reminder-card.vehicle { border-left-color:#10b981; }
        .live-notif { position:fixed; top:20px; right:20px; background:#2563eb; color:white; padding:15px 20px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.2); font-weight:600; z-index:2000; animation:fadeIn 0.5s, fadeOut 0.5s 3.5s; }
        @keyframes fadeIn { from{opacity:0;transform:translateY(-10px);} to{opacity:1;transform:translateY(0);} }
        @keyframes fadeOut { from{opacity:1;} to{opacity:0;} }
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
            <li><a class="nav-link" href="resident_dashboard.php"><i class="fas fa-home"></i>Dashboard</a></li>
            <li><a class="nav-link" href="book_facility.php"><i class="fas fa-building"></i>Book Facility</a></li>
            <li><a class="nav-link" href="borrow_item.php"><i class="fas fa-box"></i>Borrow Items</a></li>
            <li><a class="nav-link" href="request_vehicle.php"><i class="fas fa-car"></i>Request Vehicle</a></li>
            <li><a class="nav-link" href="resident_calendar.php"><i class="fas fa-calendar"></i>Calendar</a></li>
            <li><a class="nav-link" href="my_requests.php"><i class="fas fa-list"></i>My Requests</a></li>
            <li><a class="nav-link active" href="reminders.php"><i class="fas fa-bell"></i>Reminders</a></li>
            <li><a class="nav-link" href="resident_messages.php"><i class="fas fa-envelope"></i>Messages</a></li>
        </ul>
    </nav>

    <!-- Main -->
    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-bell me-2"></i>Reminders & Notifications</h1>
            <div class="dropdown">
                <button class="user-menu" data-bs-toggle="dropdown">
                    <i class="fas fa-user-circle"></i> <?php echo $_SESSION['full_name']; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                    <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card total">
                <h5>Total Upcoming</h5>
                <h2><?php echo count($upcoming_events); ?></h2>
            </div>
            <div class="stat-card today">
                <h5>Today's Events</h5>
                <h2><?php echo $today_count; ?></h2>
            </div>
            <div class="stat-card tomorrow">
                <h5>Tomorrow's Events</h5>
                <h2><?php echo $tomorrow_count; ?></h2>
            </div>
        </div>

        <div class="reminders-section mt-4">
            <h4><i class="fas fa-clock me-2"></i>Upcoming Events (Next 7 Days)</h4>
            <?php if (empty($upcoming_events)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-calendar-check fa-3x mb-3"></i>
                    <p>No upcoming events found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($upcoming_events as $event): ?>
                    <?php
                        $icon = $event['type'] === 'vehicle' ? 'car' : ($event['type'] === 'item_return' ? 'undo' : 'building');
                    ?>
                    <div class="reminder-card <?php echo $event['type']; ?>">
                        <div class="d-flex justify-content-between">
                            <strong><i class="fas fa-<?php echo $icon; ?> me-2"></i><?php echo htmlspecialchars($event['item_name']); ?></strong>
                            <span class="badge bg-secondary text-capitalize"><?php echo str_replace('_',' ',$event['type']); ?></span>
                        </div>
                        <div class="text-muted">
                            <i class="fas fa-calendar me-1"></i><?php echo date('M d, Y', strtotime($event['booking_date'])); ?>
                            <?php if ($event['start_time']): ?>
                                <i class="fas fa-clock ms-2 me-1"></i><?php echo date('g:i A', strtotime($event['start_time'])); ?> - <?php echo date('g:i A', strtotime($event['end_time'])); ?>
                            <?php endif; ?>
                        </div>
                        <p class="mt-2 mb-0"><?php echo htmlspecialchars($event['purpose']); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let lastCount = <?php echo count($upcoming_events); ?>;

    function fetchReminders() {
        fetch('fetch_reminders.php')
            .then(res => res.json())
            .then(data => {
                if (!Array.isArray(data)) return;
                if (data.length > lastCount) {
                    let newCount = data.length - lastCount;
                    showLiveNotification(`🔔 You have ${newCount} new reminder${newCount>1?'s':''}!`);
                    playNotificationSound();
                }
                lastCount = data.length;
            })
            .catch(err => console.error('Error fetching reminders:', err));
    }

    function showLiveNotification(msg) {
        const notif = document.createElement('div');
        notif.className = 'live-notif';
        notif.textContent = msg;
        document.body.appendChild(notif);
        setTimeout(() => notif.remove(), 4000);
    }

    function playNotificationSound() {
        const audio = new Audio('notification.mp3');
        audio.play();
    }

    setInterval(fetchReminders, 10000); // every 10 seconds
    </script>
</body>
</html>
