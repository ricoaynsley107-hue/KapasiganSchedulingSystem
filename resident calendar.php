<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Resident Calendar - Barangay Kapasigan</title>

    <!-- Bootstrap & icons & FullCalendar -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet"/>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(180deg, #f5f9ff 0%, #ffffff 50%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* Sidebar (resident theme) */
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
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            font-size: 28px;
            color: #2d3748;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .availability-indicator {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .available {
            background: #d1fae5;
            color: #065f46;
        }

        .busy {
            background: #fee2e2;
            color: #991b1b;
        }

        .after-hours {
            background: #e5e7eb;
            color: #374151;
        }

        .user-menu {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 8px;
            padding: 8px 12px;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }

        .filter-title {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            background: white;
            color: #718096;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-btn:hover {
            border-color: #667eea;
            color: #667eea;
        }

        .filter-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        .calendar-container {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 25px;
        }

        #calendar {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        /* FullCalendar tweaks */
        .fc .fc-button-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            box-shadow: 0 3px 10px rgba(102, 126, 234, 0.2);
        }

        .fc .fc-daygrid-day.fc-day-today {
            background-color: #f0f4ff !important;
        }

        .fc-event-facility {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
            border: none !important;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .fc-event-item {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
            border: none !important;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .fc-event-vehicle {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
            border: none !important;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }

        .fc-event-unavailable {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
            border: none !important;
            opacity: 0.8;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }

        .fc .fc-event-title {
            font-weight: 600;
            padding: 2px 0;
            color: white !important;
        }

        .legend {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            height: fit-content;
            position: sticky;
            top: 30px;
        }

        .legend-title {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 13px;
            color: #718096;
        }

        .legend-color {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        .legend-color.facility {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }

        .legend-color.item {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .legend-color.vehicle {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .legend-color.unavailable {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .schedule-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .schedule-title {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .schedule-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 8px;
            background: #f9fafb;
            border-left: 3px solid #667eea;
        }

        .schedule-badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            flex-shrink: 0;
            text-transform: capitalize;
        }

        .schedule-badge.facility {
            background: rgba(59,130,246,0.1);
            color: #2563eb;
        }

        .schedule-badge.item {
            background: rgba(16,185,129,0.1);
            color: #059669;
        }

        .schedule-badge.vehicle {
            background: rgba(245,158,11,0.1);
            color: #d97706;
        }

        .schedule-text {
            flex: 1;
            min-width: 0;
        }

        .empty-state {
            text-align: center;
            padding: 20px;
            color: #a0aec0;
        }

        .modal-content {
            border: none;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px 12px 0 0;
        }

        .detail-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: #2d3748;
            min-width: 120px;
        }

        .detail-value {
            color: #718096;
            flex: 1;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
            }

            .main-content {
                margin-left: 200px;
                padding: 20px;
            }

            .calendar-container {
                grid-template-columns: 1fr;
            }

            .legend {
                position: static;
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar (Resident only) -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <img src="kapasigan.png" alt="Logo">
            <h5>Barangay Kapasigan</h5>
        </div>

        <ul class="nav flex-column" style="padding: 0 10px;">
            <li class="nav-item">
                <a class="nav-link" href="resident_dashboard.php">
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
                <a class="nav-link active" href="calendar.php">
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
            <h1><i class="fas fa-calendar-alt"></i> Resident Calendar</h1>
            <div style="display: flex; align-items: center; gap: 20px;">
                <div class="availability-indicator available" id="availability-status">
                    <i class="fas fa-circle"></i>
                    <span>Available</span>
                </div>
                <div class="user-menu">
                    <i class="fas fa-user-circle"></i>
                    <span style="font-weight:600;"><?php echo isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : 'Resident'; ?></span>
                    <a href="logout.php" style="color: #fff; margin-left: 10px; text-decoration: none;">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter (Resident filters only) -->
        <div class="filter-card">
            <div class="filter-title">
                <i class="fas fa-filter"></i>Filter Events
            </div>
            <div class="filter-buttons">
                <button class="filter-btn active" data-filter="all">
                    <i class="fas fa-eye"></i>All Events
                </button>
                <button class="filter-btn" data-filter="facility">
                    <i class="fas fa-building"></i>Facilities
                </button>
                <button class="filter-btn" data-filter="item">
                    <i class="fas fa-box"></i>Items
                </button>
                <button class="filter-btn" data-filter="vehicle">
                    <i class="fas fa-car"></i>Vehicles
                </button>
            </div>
        </div>

        <!-- Calendar & Sidebar -->
        <div class="calendar-container">
            <div id="calendar"></div>

            <div>
                <!-- Legend -->
                <div class="legend">
                    <div class="legend-title">
                        <i class="fas fa-palette"></i>Event Types
                    </div>
                    <div class="legend-item">
                        <div class="legend-color facility"></div>
                        <span>Facility Bookings</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color item"></div>
                        <span>Item Borrowings</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color vehicle"></div>
                        <span>Vehicle Requests</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color unavailable"></div>
                        <span>Unavailable</span>
                    </div>
                </div>

                <!-- Today's Schedule -->
                <div class="schedule-card" style="margin-top: 25px;">
                    <div class="schedule-title">
                        <i class="fas fa-clock"></i>Today's Schedule
                    </div>
                    <div id="today-events">
                        <div class="empty-state">
                            <i class="fas fa-spinner fa-spin"></i>
                            <p>Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Details Modal -->
    <div class="modal fade" id="eventModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="eventModalTitle">Event Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="eventModalBody"></div>
                <div class="modal-footer" id="eventModalFooter">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
    <script>
        let calendar;
        let currentFilter = 'all';
        let allEvents = [];

        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');

            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                height: 'auto',
                events: function(fetchInfo, successCallback, failureCallback) {
                    fetch('api/calendar.php')
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Show all events (resident view) — we leave out meetings if your backend sends type 'meeting'
                                allEvents = data.events.filter(e => e.extendedProps ? e.extendedProps.type !== 'meeting' : true);
                                const filteredEvents = filterEvents(allEvents, currentFilter);
                                successCallback(filteredEvents);
                                updateSidebarInfo(allEvents);
                            } else {
                                failureCallback(data.error || 'Failed to load events.');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            failureCallback(error);
                        });
                },
                eventClick: function(info) {
                    showEventDetails(info.event);
                },
                eventDidMount: function(info) {
                    // add custom class for styling per type (if available)
                    const type = info.event.extendedProps && info.event.extendedProps.type ? info.event.extendedProps.type : 'item';
                    info.el.classList.add('fc-event-' + type);
                },
                nowIndicator: true,
                dayMaxEvents: true
            });

            calendar.render();

            // filter buttons
            document.querySelectorAll('[data-filter]').forEach(button => {
                button.addEventListener('click', function() {
                    document.querySelectorAll('[data-filter]').forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    currentFilter = this.dataset.filter;
                    calendar.refetchEvents();
                });
            });

            // availability status updater
            setInterval(updateAvailabilityStatus, 30000);
            updateAvailabilityStatus();
        });

        function filterEvents(events, filter) {
            if (filter === 'all') return events;
            return events.filter(event => {
                return event.extendedProps && event.extendedProps.type === filter;
            });
        }

        function showEventDetails(event) {
            const modal = new bootstrap.Modal(document.getElementById('eventModal'));
            const title = document.getElementById('eventModalTitle');
            const body = document.getElementById('eventModalBody');

            title.textContent = event.title || 'Event Details';

            const startStr = event.start ? new Date(event.start).toLocaleString() : '-';
            const endStr = event.end ? new Date(event.end).toLocaleString() : '-';

            const type = event.extendedProps && event.extendedProps.type ? event.extendedProps.type : 'N/A';
            const requester = event.extendedProps && event.extendedProps.requester ? event.extendedProps.requester : 'N/A';
            const status = event.extendedProps && event.extendedProps.status ? event.extendedProps.status : 'N/A';
            const description = event.extendedProps && event.extendedProps.description ? event.extendedProps.description : '';

            const statusBadgeClass = status === 'approved' ? 'success' : (status === 'pending' ? 'warning' : 'danger');

            let detailsHtml = `
                <div class="detail-row">
                    <div class="detail-label">Type:</div>
                    <div class="detail-value text-capitalize"><span class="badge bg-primary">${escapeHtml(type)}</span></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Start:</div>
                    <div class="detail-value">${escapeHtml(startStr)}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">End:</div>
                    <div class="detail-value">${escapeHtml(endStr)}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Requester:</div>
                    <div class="detail-value">${escapeHtml(requester)}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Status:</div>
                    <div class="detail-value"><span class="badge bg-${statusBadgeClass}">${escapeHtml(status)}</span></div>
                </div>
            `;

            if (description) {
                detailsHtml += `
                    <div class="detail-row">
                        <div class="detail-label">Details:</div>
                        <div class="detail-value">${escapeHtml(description)}</div>
                    </div>
                `;
            }

            body.innerHTML = detailsHtml;
            modal.show();
        }

        function updateSidebarInfo(events) {
            const today = new Date().toDateString();
            const todayEvents = events.filter(event => {
                return event.start && new Date(event.start).toDateString() === today;
            });

            const todayContainer = document.getElementById('today-events');
            if (todayEvents.length === 0) {
                todayContainer.innerHTML = '<div class="empty-state"><p>No events today</p></div>';
            } else {
                let html = '';
                todayEvents.forEach(event => {
                    const t = event.extendedProps && event.extendedProps.type ? event.extendedProps.type : 'item';
                    const timeStr = event.start ? new Date(event.start).toLocaleTimeString() : '-';
                    html += `
                        <div class="schedule-item">
                            <span class="schedule-badge ${t}">${escapeHtml(t)}</span>
                            <div class="schedule-text">
                                <small>${escapeHtml(event.title || 'Untitled')}</small>
                                <small>${escapeHtml(timeStr)}</small>
                            </div>
                        </div>
                    `;
                });
                todayContainer.innerHTML = html;
            }
        }

        function updateAvailabilityStatus() {
            const now = new Date();
            const currentHour = now.getHours();
            const statusEl = document.getElementById('availability-status');

            const currentEvents = allEvents.filter(event => {
                if (!event.start || !event.end) return false;
                return now >= new Date(event.start) && now <= new Date(event.end);
            });

            if (currentEvents.length > 0) {
                statusEl.className = 'availability-indicator busy';
                statusEl.innerHTML = '<i class="fas fa-circle"></i><span>Busy</span>';
            } else if (currentHour >= 8 && currentHour <= 17) {
                statusEl.className = 'availability-indicator available';
                statusEl.innerHTML = '<i class="fas fa-circle"></i><span>Available</span>';
            } else {
                statusEl.className = 'availability-indicator after-hours';
                statusEl.innerHTML = '<i class="fas fa-moon"></i><span>After Hours</span>';
            }
        }

        // Simple HTML escaper to avoid injecting raw HTML from event data
        function escapeHtml(text) {
            if (!text && text !== 0) return '';
            return String(text)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }
    </script>
</body>
</html>
