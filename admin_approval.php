<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireAdmin();

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';

// Handle approval/denial actions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    $request_type = $_POST['request_type'] ?? '';
    $request_id = $_POST['request_id'] ?? '';
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    if ($action && $request_type && $request_id) {
        $status = ($action === 'approve') ? 'approved' : 'denied';
        $table = '';
        
        switch ($request_type) {
            case 'facility_booking':
                $table = 'facility_bookings';
                break;
            case 'item_borrowing':
                $table = 'item_borrowings';
                break;
            case 'vehicle_request':
                $table = 'vehicle_requests';
                break;
        }
        
        if ($table) {
            $query = "UPDATE $table SET status = :status, admin_notes = :admin_notes WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':admin_notes', $admin_notes);
            $stmt->bindParam(':id', $request_id);
            
            if ($stmt->execute()) {
                // If approving item borrowing, update item quantity
                if ($request_type === 'item_borrowing' && $status === 'approved') {
                    $query = "SELECT item_id, quantity FROM item_borrowings WHERE id = :id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':id', $request_id);
                    $stmt->execute();
                    $borrowing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($borrowing) {
                        $query = "UPDATE items SET quantity = quantity - :quantity WHERE id = :item_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':quantity', $borrowing['quantity']);
                        $stmt->bindParam(':item_id', $borrowing['item_id']);
                        $stmt->execute();
                    }
                }
                
                $success = 'Request ' . ($status === 'approved' ? 'approved' : 'denied') . ' successfully!';
            } else {
                $error = 'Failed to update request status.';
            }
        }
    }
}

// Handle batch approval
if (isset($_POST['batch_action'])) {
    $batch_action = $_POST['batch_action'];
    $selected_requests = $_POST['selected_requests'] ?? [];
    $batch_notes = $_POST['batch_notes'] ?? '';
    
    if (!empty($selected_requests) && in_array($batch_action, ['approve', 'deny'])) {
        $status = ($batch_action === 'approve') ? 'approved' : 'denied';
        $success_count = 0;
        
        foreach ($selected_requests as $request_data) {
            list($type, $id) = explode('_', $request_data, 2);
            
            $table = '';
            switch ($type) {
                case 'facility': $table = 'facility_bookings'; break;
                case 'item': $table = 'item_borrowings'; break;
                case 'vehicle': $table = 'vehicle_requests'; break;
            }
            
            if ($table) {
                $query = "UPDATE $table SET status = :status, admin_notes = :admin_notes WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':admin_notes', $batch_notes);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $success_count++;
                    
                    // Handle item quantity for batch approval
                    if ($type === 'item' && $status === 'approved') {
                        $query = "SELECT item_id, quantity FROM item_borrowings WHERE id = :id";
                        $stmt2 = $conn->prepare($query);
                        $stmt2->bindParam(':id', $id);
                        $stmt2->execute();
                        $borrowing = $stmt2->fetch(PDO::FETCH_ASSOC);
                        
                        if ($borrowing) {
                            $query = "UPDATE items SET quantity = quantity - :quantity WHERE id = :item_id";
                            $stmt2 = $conn->prepare($query);
                            $stmt2->bindParam(':quantity', $borrowing['quantity']);
                            $stmt2->bindParam(':item_id', $borrowing['item_id']);
                            $stmt2->execute();
                        }
                    }
                }
            }
        }
        
        $success = "Batch action completed! $success_count requests " . ($status === 'approved' ? 'approved' : 'denied') . '.';
    }
}

// Get all pending requests
$query = "SELECT 'facility' as type, 'facility_booking' as table_type, fb.id, u.full_name, u.email, f.name as item_name, 
                 fb.booking_date as date, fb.start_time, fb.end_time, fb.purpose, fb.created_at
          FROM facility_bookings fb 
          JOIN facilities f ON fb.facility_id = f.id 
          JOIN users u ON fb.user_id = u.id
          WHERE fb.status = 'pending'
          UNION ALL
          SELECT 'item' as type, 'item_borrowing' as table_type, ib.id, u.full_name, u.email, i.name as item_name,
                 ib.borrow_date as date, NULL as start_time, NULL as end_time, ib.purpose, ib.created_at
          FROM item_borrowings ib 
          JOIN items i ON ib.item_id = i.id 
          JOIN users u ON ib.user_id = u.id
          WHERE ib.status = 'pending'
          UNION ALL
          SELECT 'vehicle' as type, 'vehicle_request' as table_type, vr.id, u.full_name, u.email, v.name as item_name,
                 vr.request_date as date, vr.start_time, vr.end_time, vr.purpose, vr.created_at
          FROM vehicle_requests vr 
          JOIN vehicles v ON vr.vehicle_id = v.id 
          JOIN users u ON vr.user_id = u.id
          WHERE vr.status = 'pending'
          ORDER BY created_at ASC";

$stmt = $conn->prepare($query);
$stmt->execute();
$pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Requests - Barangay Kapasigan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 12px 20px;
            margin: 2px 0;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .request-card {
            transition: transform 0.2s;
            border-left: 4px solid #ffc107;
        }
        .request-card:hover {
            transform: translateY(-2px);
        }
        .admin-badge {
            background: linear-gradient(45deg, #dc3545, #fd7e14);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .batch-actions {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
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
            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-check-circle me-2"></i>Approve/Deny Requests</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="dropdown">
                            <button class="btn btn-outline-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user-shield me-2"></i><?php echo $_SESSION['full_name']; ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="includes/auth.php?action=logout"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($pending_requests)): ?>
                    <!-- Batch Actions -->
                    <div class="batch-actions">
                        <h5 class="text-white mb-3"><i class="fas fa-tasks me-2"></i>Batch Actions</h5>
                        <form method="POST" id="batchForm">
                            <div class="row align-items-end">
                                <div class="col-md-6 mb-2">
                                    <label for="batch_notes" class="form-label text-white">Admin Notes (Optional)</label>
                                    <input type="text" class="form-control" id="batch_notes" name="batch_notes" 
                                           placeholder="Add notes for selected requests...">
                                </div>
                                <div class="col-md-3 mb-2">
                                    <button type="submit" name="batch_action" value="approve" class="btn btn-success w-100">
                                        <i class="fas fa-check me-1"></i>Approve Selected
                                    </button>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <button type="submit" name="batch_action" value="deny" class="btn btn-warning w-100">
                                        <i class="fas fa-times me-1"></i>Deny Selected
                                    </button>
                                </div>
                            </div>
                            <small class="text-white-50">
                                <i class="fas fa-info-circle me-1"></i>
                                Select requests below using checkboxes, then use batch actions to approve or deny multiple requests at once.
                            </small>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- Pending Requests -->
                <div class="row">
                    <?php if (empty($pending_requests)): ?>
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="fas fa-check-circle fa-4x text-success mb-4"></i>
                                    <h4 class="text-success">All Caught Up!</h4>
                                    <p class="text-muted">There are no pending requests requiring approval at the moment.</p>
                                    <a href="admin_dashboard.php" class="btn btn-primary">
                                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_requests as $request): ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card request-card h-100">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div class="form-check">
                                            <input class="form-check-input batch-checkbox" type="checkbox" 
                                                   name="selected_requests[]" value="<?php echo $request['type'] . '_' . $request['id']; ?>" 
                                                   form="batchForm">
                                            <label class="form-check-label">
                                                <span class="badge bg-warning text-dark">
                                                    <?php echo ucfirst($request['type']); ?> Request
                                                </span>
                                            </label>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y', strtotime($request['created_at'])); ?>
                                        </small>
                                    </div>
                                    <div class="card-body">
                                        <h6 class="card-title"><?php echo htmlspecialchars($request['item_name']); ?></h6>
                                        
                                        <div class="mb-2">
                                            <strong>Resident:</strong> <?php echo htmlspecialchars($request['full_name']); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($request['email']); ?></small>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <strong>Date:</strong> <?php echo date('M d, Y', strtotime($request['date'])); ?>
                                            <?php if ($request['start_time'] && $request['end_time']): ?>
                                                <br><strong>Time:</strong> 
                                                <?php echo date('g:i A', strtotime($request['start_time'])); ?> - 
                                                <?php echo date('g:i A', strtotime($request['end_time'])); ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($request['purpose']): ?>
                                            <div class="mb-3">
                                                <strong>Purpose:</strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($request['purpose']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Individual Action Form -->
                                        <form method="POST" class="individual-action-form">
                                            <input type="hidden" name="request_type" value="<?php echo $request['table_type']; ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                            
                                            <div class="mb-2">
                                                <textarea class="form-control form-control-sm" name="admin_notes" 
                                                          placeholder="Add admin notes..." rows="2"></textarea>
                                            </div>
                                            
                                            <div class="d-grid gap-2">
                                                <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">
                                                    <i class="fas fa-check me-1"></i>Approve
                                                </button>
                                                <button type="submit" name="action" value="deny" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-times me-1"></i>Deny
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($pending_requests)): ?>
                    <!-- Summary -->
                    <div class="card mt-4">
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <h4 class="text-primary"><?php echo count($pending_requests); ?></h4>
                                    <small class="text-muted">Total Pending</small>
                                </div>
                                <div class="col-md-3">
                                    <h4 class="text-warning">
                                        <?php echo count(array_filter($pending_requests, function($r) { return $r['type'] === 'facility'; })); ?>
                                    </h4>
                                    <small class="text-muted">Facility Bookings</small>
                                </div>
                                <div class="col-md-3">
                                    <h4 class="text-success">
                                        <?php echo count(array_filter($pending_requests, function($r) { return $r['type'] === 'item'; })); ?>
                                    </h4>
                                    <small class="text-muted">Item Borrowings</small>
                                </div>
                                <div class="col-md-3">
                                    <h4 class="text-info">
                                        <?php echo count(array_filter($pending_requests, function($r) { return $r['type'] === 'vehicle'; })); ?>
                                    </h4>
                                    <small class="text-muted">Vehicle Requests</small>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select all checkbox functionality
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllBtn = document.createElement('button');
            selectAllBtn.type = 'button';
            selectAllBtn.className = 'btn btn-outline-light btn-sm me-2';
            selectAllBtn.innerHTML = '<i class="fas fa-check-square me-1"></i>Select All';
            
            const clearAllBtn = document.createElement('button');
            clearAllBtn.type = 'button';
            clearAllBtn.className = 'btn btn-outline-light btn-sm';
            clearAllBtn.innerHTML = '<i class="fas fa-square me-1"></i>Clear All';
            
            const batchActions = document.querySelector('.batch-actions .row');
            if (batchActions) {
                const buttonContainer = document.createElement('div');
                buttonContainer.className = 'col-12 mb-2';
                buttonContainer.appendChild(selectAllBtn);
                buttonContainer.appendChild(clearAllBtn);
                batchActions.insertBefore(buttonContainer, batchActions.firstChild);
            }
            
            selectAllBtn.addEventListener('click', function() {
                document.querySelectorAll('.batch-checkbox').forEach(checkbox => {
                    checkbox.checked = true;
                });
            });
            
            clearAllBtn.addEventListener('click', function() {
                document.querySelectorAll('.batch-checkbox').forEach(checkbox => {
                    checkbox.checked = false;
                });
            });
            
            // Confirm batch actions
            document.querySelectorAll('button[name="batch_action"]').forEach(button => {
                button.addEventListener('click', function(e) {
                    const selectedCount = document.querySelectorAll('.batch-checkbox:checked').length;
                    if (selectedCount === 0) {
                        e.preventDefault();
                        alert('Please select at least one request.');
                        return;
                    }
                    
                    const action = this.value === 'approve' ? 'approve' : 'deny';
                    if (!confirm(`Are you sure you want to ${action} ${selectedCount} selected request(s)?`)) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>
