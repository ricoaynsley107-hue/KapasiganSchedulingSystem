<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireAdmin();

$database = new Database();
$conn = $database->getConnection();

// Get admin statistics
$query = "SELECT 
    (SELECT COUNT(*) FROM facility_bookings WHERE status = 'pending') as pending_bookings,
    (SELECT COUNT(*) FROM item_borrowings WHERE status = 'pending') as pending_borrowings,
    (SELECT COUNT(*) FROM vehicle_requests WHERE status = 'pending') as pending_vehicles,
    (SELECT COUNT(*) FROM users WHERE role = 'resident' AND status = 'active') as total_residents,
    (SELECT COUNT(*) FROM facility_bookings WHERE DATE(created_at) = CURDATE()) as today_bookings,
    (SELECT COUNT(*) FROM item_borrowings WHERE DATE(created_at) = CURDATE()) as today_borrowings,
    (SELECT COUNT(*) FROM vehicle_requests WHERE DATE(created_at) = CURDATE()) as today_vehicles";

$stmt = $conn->prepare($query);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent requests for approval
$query = "SELECT 'Facility Booking' as type, fb.id, u.full_name, f.name as item_name, fb.booking_date as date, fb.status, fb.created_at
          FROM facility_bookings fb 
          JOIN facilities f ON fb.facility_id = f.id 
          JOIN users u ON fb.user_id = u.id
          WHERE fb.status = 'pending'
          UNION ALL
          SELECT 'Item Borrowing' as type, ib.id, u.full_name, i.name as item_name, ib.borrow_date as date, ib.status, ib.created_at
          FROM item_borrowings ib 
          JOIN items i ON ib.item_id = i.id 
          JOIN users u ON ib.user_id = u.id
          WHERE ib.status = 'pending'
          UNION ALL
          SELECT 'Vehicle Request' as type, vr.id, u.full_name, v.name as item_name, vr.request_date as date, vr.status, vr.created_at
          FROM vehicle_requests vr 
          JOIN vehicles v ON vr.vehicle_id = v.id 
          JOIN users u ON vr.user_id = u.id 
          WHERE vr.status = 'pending'
          ORDER BY created_at DESC LIMIT 5";

$stmt = $conn->prepare($query);
$stmt->execute();
$pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Calendar - Barangay Kapasigan (Smart)</title>

    <!-- CSS libs -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet"/>

    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }

        /* Sidebar styles */
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.12);
            padding-top: 20px;
            overflow-y: auto;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.9);
            padding: 10px 15px;
            margin: 2px 0;
            border-radius: 8px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
        }

        .sidebar .nav-link i {
            width: 22px;
            text-align: center;
        }

        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.08);
        }

        .sidebar-header {
            text-align: center;
            padding: 10px 15px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .sidebar-header img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 50%;
        }

        .sidebar-header h5 {
            margin-top: 10px;
            color: white;
            font-weight: 600;
        }

        .admin-badge {
            background: linear-gradient(45deg, #dc3545, #fd7e14);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
            margin-top: 6px;
        }

        /* Main content */
        .main-content {
            margin-left: 260px;
            padding: 20px;
        }

        .page-header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.06);
            margin-bottom: 18px;
            display:flex;
            justify-content:space-between;
            align-items:center;
        }

        /* Top Alerts Banner - Full width above filter */
        .alerts-banner {
            background: white;
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 18px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.06);
            border-left: 4px solid #ef4444;
        }

        .alerts-banner h6 {
            color: #ef4444;
            margin-bottom: 12px;
        }

        .alert-item {
            background: #fff5f5;
            border-left: 3px solid #ef4444;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .filter-card {
            background:white;
            padding:16px;
            border-radius:10px;
            margin-bottom:18px;
            box-shadow:0 6px 18px rgba(0,0,0,0.06);
        }

        /* New grid layout: calendar + insights side by side, pending requests below */
        .calendar-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 18px;
            margin-bottom: 18px;
        }

        #calendar {
            background:white;
            border-radius:12px;
            padding:18px;
            box-shadow:0 8px 30px rgba(0,0,0,0.06);
        }

        .insights-sidebar {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .insights, .legend {
            background:white;
            border-radius:12px;
            padding:16px;
            box-shadow:0 8px 30px rgba(0,0,0,0.06);
        }

        /* Pending requests - full width below calendar */
        .pending-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.06);
        }

        .pending-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .pending-card {
            background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
            border-left: 4px solid #f97316;
            padding: 16px;
            border-radius: 8px;
            transition: transform 0.2s;
        }

        .pending-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .badge-priority { padding:6px 8px; border-radius:6px; font-weight:600; font-size:12px; color:white; }
        .prio-emergency { background:#991b1b; }
        .prio-high { background:#c2410c; }
        .prio-normal { background:#037bfc; }
        .risk-high { background: linear-gradient(135deg,#ef4444,#b91c1c); color:white; padding:4px 8px; border-radius:6px; font-weight:700; font-size:11px; }
        .conflict-warning { border-left:4px solid #ef4444; background:#fff5f5; padding:8px; border-radius:8px; margin-bottom:8px; }

        /* FullCalendar tweaks */
        .fc .fc-event { border:none; color:white; font-weight:700; padding:4px 6px; }
        .fc-event-facility { background: linear-gradient(135deg,#ef4444 0%,#b91c1c 100%) !important; }
        .fc-event-item { background: linear-gradient(135deg,#f97316 0%,#ea580c 100%) !important; }
        .fc-event-vehicle { background: linear-gradient(135deg,#f59e0b 0%,#d97706 100%) !important; }
        .fc-event-unavailable { background: linear-gradient(135deg,#6b7280 0%,#374151 100%) !important; opacity:0.9; }

        @media (max-width: 1200px) {
            .calendar-grid { grid-template-columns: 1fr; }
            .insights-sidebar { flex-direction: row; flex-wrap: wrap; }
            .insights, .legend { flex: 1; min-width: 280px; }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 12px; }
            .sidebar { position: relative; width: 100%; height: auto; }
            .pending-grid { grid-template-columns: 1fr; }
        }
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

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content">
                <div class="page-header">
                    <h1><i class="fas fa-calendar-alt me-2" style="color:#dc3545"></i>Admin Calendar – Smart</h1>
                    <div style="display:flex; gap:12px; align-items:center;">
                        <div class="availability-indicator available" id="availability-status" style="padding:8px 12px; border-radius:20px; background:#d1fae5; color:#065f46;">
                            <i class="fas fa-circle"></i>
                            <span>Available</span>
                        </div>
                        <div class="user-menu" style="background:rgba(0,0,0,0.02); padding:8px 12px; border-radius:8px;">
                            <i class="fas fa-user-circle me-2"></i><?php echo htmlentities($_SESSION['full_name']); ?>
                            <a href="logout.php" style="color:#dc3545; margin-left:10px;"><i class="fas fa-sign-out-alt"></i></a>
                        </div>
                    </div>
                </div>

                <!-- Top Alerts Banner - Prominent position -->
                <div class="alerts-banner" id="alerts-banner" style="display:none;">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Critical Alerts</h6>
                    <div id="top-alerts-content"></div>
                </div>

                <div class="filter-card d-flex justify-content-between align-items-center">
                    <div>
                        <button class="btn btn-outline-dark me-2 filter-btn active" data-filter="all"><i class="fas fa-eye me-1"></i>All</button>
                        <button class="btn btn-outline-dark me-2 filter-btn" data-filter="facility"><i class="fas fa-building me-1"></i>Facilities</button>
                        <button class="btn btn-outline-dark me-2 filter-btn" data-filter="item"><i class="fas fa-box me-1"></i>Items</button>
                        <button class="btn btn-outline-dark me-2 filter-btn" data-filter="vehicle"><i class="fas fa-car me-1"></i>Vehicles</button>
                    </div>
                    <div>
                        <small class="text-muted"><i class="fas fa-robot me-1"></i>Auto-detect conflicts & suggestions enabled</small>
                    </div>
                </div>

                <!-- Calendar + Insights Side by Side -->
                <div class="calendar-grid">
                    <div id="calendar"></div>

                    <div class="insights-sidebar">
                        <div class="insights">
                            <h6><i class="fas fa-lightbulb me-2" style="color:#dc3545"></i>Smart Insights</h6>
                            <div id="insights-content">
                                <p class="text-muted small">Loading insights...</p>
                            </div>
                            <hr/>
                            <div>
                                <h6 class="mb-1">Quick Stats</h6>
                                <div id="stat-counts" class="small text-muted">—</div>
                            </div>
                        </div>

                        <div class="legend">
                            <h6><i class="fas fa-palette me-2"></i>Legend</h6>
                            <div class="mt-2">
                                <div class="d-flex align-items-center gap-2 mb-2"><div style="width:18px;height:18px;background:linear-gradient(135deg,#ef4444,#b91c1c);border-radius:4px"></div><small>Facility</small></div>
                                <div class="d-flex align-items-center gap-2 mb-2"><div style="width:18px;height:18px;background:linear-gradient(135deg,#f97316,#ea580c);border-radius:4px"></div><small>Item</small></div>
                                <div class="d-flex align-items-center gap-2 mb-2"><div style="width:18px;height:18px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:4px"></div><small>Vehicle</small></div>
                                <div class="d-flex align-items-center gap-2"><div style="width:18px;height:18px;background:#6b7280;border-radius:4px"></div><small>Unavailable</small></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Pending Requests - Full Width Below -->
                <div class="pending-section">
                    <h5><i class="fas fa-clock me-2" style="color:#f97316"></i>Recent Pending Requests (<?php echo count($pending_requests); ?>)</h5>
                    
                    <?php if (count($pending_requests) === 0): ?>
                        <p class="text-muted text-center mt-4">No pending requests at the moment.</p>
                    <?php else: ?>
                        <div class="pending-grid">
                            <?php foreach ($pending_requests as $req): ?>
                                <div class="pending-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge bg-warning text-dark"><?php echo htmlentities($req['type']); ?></span>
                                        <small class="text-muted"><?php echo date('M d, g:i A', strtotime($req['created_at'])); ?></small>
                                    </div>
                                    <h6 class="mb-1"><?php echo htmlentities($req['item_name']); ?></h6>
                                    <p class="mb-2 small"><i class="fas fa-user me-1"></i><?php echo htmlentities($req['full_name']); ?></p>
                                    <p class="mb-2 small"><i class="fas fa-calendar me-1"></i><?php echo date('M d, Y', strtotime($req['date'])); ?></p>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-success flex-fill" onclick="quickApprove(<?php echo $req['id']; ?>, '<?php echo strtolower(str_replace(' ', '_', $req['type'])); ?>')">
                                            <i class="fas fa-check me-1"></i>Approve
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger flex-fill" onclick="quickDecline(<?php echo $req['id']; ?>, '<?php echo strtolower(str_replace(' ', '_', $req['type'])); ?>')">
                                            <i class="fas fa-times me-1"></i>Decline
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Event Details Modal -->
    <div class="modal fade" id="eventModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#ef4444,#b91c1c);color:white;">
                    <h5 class="modal-title" id="eventModalTitle">Event Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="eventModalBody"></div>
                <div class="modal-footer" id="eventModalFooter"></div>
            </div>
        </div>
    </div>

    <!-- Required scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

    <script>
        let calendar;
        let allEvents = [];
        let currentFilter = 'all';

        document.addEventListener('DOMContentLoaded', function () {
            const calendarEl = document.getElementById('calendar');

            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                height: 'auto',
                events: fetchEvents,
                eventDidMount: function(info) {
                    const type = info.event.extendedProps.type || 'unknown';
                    info.el.classList.add('fc-event-' + type);

                    const prio = info.event.extendedProps.priority || 'normal';
                    const prioBadge = document.createElement('span');
                    prioBadge.className = 'badge badge-priority ' + (prio === 'emergency' ? 'prio-emergency' : prio === 'high' ? 'prio-high' : 'prio-normal');
                    prioBadge.style.marginLeft = '8px';
                    prioBadge.style.fontSize = '10px';
                    prioBadge.innerText = prio.toUpperCase();
                    const titleEl = info.el.querySelector('.fc-event-title');
                    if (titleEl) titleEl.appendChild(prioBadge);

                    const risk = computeNoShowRisk(info.event.extendedProps);
                    if (risk >= 0.6) {
                        const riskLabel = document.createElement('span');
                        riskLabel.className = 'ms-2 risk-high';
                        riskLabel.style.fontSize = '11px';
                        riskLabel.innerText = 'HIGH RISK';
                        if (titleEl) titleEl.appendChild(riskLabel);
                    }
                },
                eventClick: function(info) {
                    showEventDetails(info.event);
                }
            });

            calendar.render();

            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function(){
                    document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
                    this.classList.add('active');
                    currentFilter = this.getAttribute('data-filter');
                    calendar.refetchEvents();
                });
            });

            setInterval(() => {
                calendar.refetchEvents();
                updateAvailabilityStatus();
            }, 30000);

            updateAvailabilityStatus();
        });

        function fetchEvents(fetchInfo, successCallback, failureCallback) {
            fetch('api/calendar.php')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        failureCallback(data.error || 'API error');
                        return;
                    }
                    allEvents = data.events || [];
                    let events = allEvents.slice();

                    if (currentFilter && currentFilter !== 'all') {
                        events = events.filter(e => {
                            if (currentFilter === 'pending' || currentFilter === 'approved') {
                                return e.extendedProps && e.extendedProps.status === currentFilter;
                            }
                            return e.extendedProps && e.extendedProps.type === currentFilter;
                        });
                    }

                    events.forEach(e => {
                        if (!e.end && e.start) {
                            let st = new Date(e.start);
                            st.setHours(st.getHours() + 1);
                            e.end = st.toISOString();
                        }
                    });

                    processSmartInsights(allEvents);
                    successCallback(events);
                })
                .catch(err => {
                    console.error(err);
                    failureCallback(err);
                });
        }

        function computeNoShowRisk(props = {}) {
            const pastNoShows = Number(props.requester_past_no_shows || 0);
            const totalBookings = Number(props.requester_total_bookings || 1);
            const leadHours = Number(props.booking_lead_hours || 48);
            const complexity = Number(props.event_complexity || 1);

            let baseRisk = totalBookings > 0 ? (pastNoShows / totalBookings) : 0;

            if (leadHours >= 168) baseRisk *= 0.6;
            else if (leadHours >= 48) baseRisk *= 0.85;
            else baseRisk *= 1.1;

            baseRisk *= 1 + (complexity - 1) * 0.05;

            return Math.min(1, Math.max(0, baseRisk));
        }

        function detectConflicts(events) {
            const conflicts = [];
            const groups = {};
            events.forEach(ev => {
                const resId = (ev.extendedProps && (ev.extendedProps.resource_id || ev.extendedProps.facility_id)) || ('type:' + (ev.extendedProps.type || 'unknown'));
                if (!groups[resId]) groups[resId] = [];
                groups[resId].push(ev);
            });

            Object.keys(groups).forEach(key => {
                const arr = groups[key].map(e => ({ ...e, startDate: new Date(e.start), endDate: new Date(e.end) })).sort((a,b)=>a.startDate - b.startDate);
                for (let i=0;i<arr.length;i++){
                    for (let j=i+1;j<arr.length;j++){
                        if (arr[j].startDate < arr[i].endDate) {
                            conflicts.push({ a: arr[i], b: arr[j], resource:key });
                        } else {
                            break;
                        }
                    }
                }
            });
            return conflicts;
        }

        function showEventDetails(ev) {
            const modal = new bootstrap.Modal(document.getElementById('eventModal'));
            const title = document.getElementById('eventModalTitle');
            const body = document.getElementById('eventModalBody');
            const footer = document.getElementById('eventModalFooter');

            title.textContent = ev.title || 'Event';

            let html = `<div class="row">`;
            html += `<div class="col-md-8"><p><strong>Type:</strong> ${ev.extendedProps.type || 'N/A'}</p>`;
            html += `<p><strong>Requester:</strong> ${ev.extendedProps.requester || 'N/A'}</p>`;
            html += `<p><strong>Date:</strong> ${new Date(ev.start).toLocaleDateString()}</p>`;
            html += `<p><strong>Time:</strong> ${new Date(ev.start).toLocaleTimeString()} - ${new Date(ev.end).toLocaleTimeString()}</p>`;
            if (ev.extendedProps.description) html += `<p><strong>Details:</strong> ${ev.extendedProps.description}</p>`;
            html += `</div>`;

            const risk = computeNoShowRisk(ev.extendedProps);
            html += `<div class="col-md-4"><p><strong>No-show risk:</strong> <span style="font-weight:700">${(risk*100).toFixed(0)}%</span></p>`;
            if (risk >= 0.6) html += `<p class="conflict-warning"><i class="fas fa-triangle-exclamation me-2"></i>High no-show probability.</p>`;
            html += `</div></div>`;

            body.innerHTML = html;

            footer.innerHTML = '';
            const btnClose = document.createElement('button');
            btnClose.className = 'btn btn-secondary';
            btnClose.setAttribute('data-bs-dismiss','modal');
            btnClose.innerText = 'Close';
            footer.appendChild(btnClose);

            modal.show();
        }

        function processSmartInsights(events) {
            const conflicts = detectConflicts(events);
            const highRisk = events.filter(e => computeNoShowRisk(e.extendedProps) >= 0.6);
            const upcoming = events.filter(e => new Date(e.start) > new Date()).sort((a,b)=>new Date(a.start)-new Date(b.start)).slice(0,6);

            // Update top alerts banner
            const alertsBanner = document.getElementById('alerts-banner');
            const alertsContent = document.getElementById('top-alerts-content');
            
            if (conflicts.length > 0 || highRisk.length > 0) {
                alertsBanner.style.display = 'block';
                let alertsHtml = '';
                
                // Show up to 3 most critical alerts
                conflicts.slice(0, 2).forEach(c => {
                    alertsHtml += `<div class="alert-item"><strong><i class="fas fa-exclamation-circle me-2"></i>Scheduling Conflict:</strong> ${c.a.title} overlaps with ${c.b.title}<br/><small class="text-muted">Resource: ${c.resource}</small></div>`;
                });
                
                highRisk.slice(0, 2).forEach(h => {
                    alertsHtml += `<div class="alert-item"><strong><i class="fas fa-user-clock me-2"></i>High No-Show Risk:</strong> ${h.title}<br/><small class="text-muted">Requester: ${h.extendedProps.requester || 'N/A'} • Risk: ${(computeNoShowRisk(h.extendedProps)*100).toFixed(0)}%</small></div>`;
                });
                
                alertsContent.innerHTML = alertsHtml;
            } else {
                alertsBanner.style.display = 'none';
            }

            // Update insights sidebar
            const insights = document.getElementById('insights-content');
            let html = '';
            html += `<p><i class="fas fa-exclamation-triangle me-2" style="color:#ef4444"></i><strong>${conflicts.length}</strong> conflict(s) detected</p>`;
            html += `<p><i class="fas fa-user-times me-2" style="color:#f59e0b"></i><strong>${highRisk.length}</strong> high-risk booking(s)</p>`;
            html += `<hr/>`;
            html += `<h6 class="mb-2">Upcoming Events</h6>`;
            if (upcoming.length === 0) {
                html += `<p class="text-muted small">No upcoming bookings.</p>`;
            } else {
                upcoming.forEach(u => {
                    const statusBadge = u.extendedProps.status === 'pending' 
                        ? '<span class="badge bg-warning text-dark">PENDING</span>' 
                        : u.extendedProps.status === 'approved' 
                        ? '<span class="badge bg-success">APPROVED</span>' 
                        : '';
                    html += `<div class="d-flex justify-content-between align-items-start small mb-2 pb-2" style="border-bottom:1px solid #f1f1f1"><div><strong>${new Date(u.start).toLocaleDateString()}</strong><br/><span class="text-muted">${u.title}</span></div><div>${statusBadge}</div></div>`;
                });
            }
            insights.innerHTML = html;

            // Update stats
            const statCounts = document.getElementById('stat-counts');
            const total = events.length;
            const pending = events.filter(e => e.extendedProps && e.extendedProps.status === 'pending').length;
            const approved = events.filter(e => e.extendedProps && e.extendedProps.status === 'approved').length;
            statCounts.innerHTML = `Total: <strong>${total}</strong> • Pending: <strong>${pending}</strong> • Approved: <strong>${approved}</strong>`;
        }

        function updateAvailabilityStatus() {
            const now = new Date();
            const nowEvents = allEvents.filter(e => new Date(e.start) <= now && new Date(e.end) >= now);
            const statusEl = document.getElementById('availability-status');
            if (nowEvents.length > 0) {
                statusEl.innerHTML = '<i class="fas fa-circle" style="color:#ef4444"></i><span>Busy</span>';
                statusEl.style.background = '#fee2e2';
                statusEl.style.color = '#991b1b';
            } else {
                statusEl.innerHTML = '<i class="fas fa-circle" style="color:#10b981"></i><span>Available</span>';
                statusEl.style.background = '#d1fae5';
                statusEl.style.color = '#065f46';
            }
        }

        // Quick approve/decline functions for pending requests cards
        function quickApprove(id, type) {
            if (!confirm('Approve this request?')) return;
            fetch('api/approve_request.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: id, type: type })
            }).then(r => r.json()).then(j => {
                if (j.success) {
                    alert('Request approved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + (j.error || 'Unknown error'));
                }
            }).catch(e => {
                alert('Network error');
                console.error(e);
            });
        }

        function quickDecline(id, type) {
            const reason = prompt('Reason for declining (optional):');
            if (reason === null) return; // User cancelled
            
            fetch('api/decline_request.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: id, type: type, reason: reason })
            }).then(r => r.json()).then(j => {
                if (j.success) {
                    alert('Request declined.');
                    location.reload();
                } else {
                    alert('Error: ' + (j.error || 'Unknown error'));
                }
            }).catch(e => {
                alert('Network error');
                console.error(e);
            });
        }
    </script>
</body>
</html>