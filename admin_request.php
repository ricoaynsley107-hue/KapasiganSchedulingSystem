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
if ($_POST && isset($_POST['action'])) {
    $request_id = $_POST['request_id'] ?? '';
    $request_type = $_POST['request_type'] ?? '';
    $action = $_POST['action'] ?? '';
    $admin_notes = $_POST['admin_notes'] ?? '';
    
    if ($request_id && $request_type && in_array($action, ['approve', 'deny'])) {
        $status = ($action === 'approve') ? 'approved' : 'denied';
        
        try {
            $conn->beginTransaction();
            
            // Update the appropriate table based on request type
            switch ($request_type) {
                case 'facility':
                    $query = "UPDATE facility_bookings SET status = :status, admin_notes = :admin_notes WHERE id = :id";
                    break;
                case 'item':
                    $query = "UPDATE item_borrowings SET status = :status, admin_notes = :admin_notes WHERE id = :id";
                    // If approved, update item quantity
                    if ($status === 'approved') {
                        $item_query = "SELECT item_id, quantity FROM item_borrowings WHERE id = :id";
                        $item_stmt = $conn->prepare($item_query);
                        $item_stmt->bindParam(':id', $request_id);
                        $item_stmt->execute();
                        $item_data = $item_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($item_data) {
                            $update_item = "UPDATE items SET quantity = quantity - :quantity WHERE id = :item_id";
                            $update_stmt = $conn->prepare($update_item);
                            $update_stmt->bindParam(':quantity', $item_data['quantity']);
                            $update_stmt->bindParam(':item_id', $item_data['item_id']);
                            $update_stmt->execute();
                        }
                    }
                    break;
                case 'vehicle':
                    $query = "UPDATE vehicle_requests SET status = :status, admin_notes = :admin_notes WHERE id = :id";
                    break;
                default:
                    throw new Exception("Invalid request type");
            }
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':admin_notes', $admin_notes);
            $stmt->bindParam(':id', $request_id);
            $stmt->execute();
            
            $conn->commit();
            $success = "Request has been " . ($action === 'approve' ? 'approved' : 'denied') . " successfully!";
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Error processing request: " . $e->getMessage();
        }
    }
}

// Get all pending requests
$pending_requests = [];

// Facility bookings
$query = "SELECT fb.id, 'facility' as type, 'Facility Booking' as type_name, 
                 u.full_name, u.email, f.name as item_name, 
                 fb.booking_date as date, fb.start_time, fb.end_time, 
                 fb.purpose, fb.status, fb.created_at
          FROM facility_bookings fb 
          JOIN users u ON fb.user_id = u.id 
          JOIN facilities f ON fb.facility_id = f.id 
          WHERE fb.status = 'pending'";
$stmt = $conn->prepare($query);
$stmt->execute();
$facility_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Item borrowings
$query = "SELECT ib.id, 'item' as type, 'Item Borrowing' as type_name,
                 u.full_name, u.email, i.name as item_name,
                 ib.borrow_date as date, ib.return_date, ib.quantity,
                 ib.purpose, ib.status, ib.created_at
          FROM item_borrowings ib 
          JOIN users u ON ib.user_id = u.id 
          JOIN items i ON ib.item_id = i.id 
          WHERE ib.status = 'pending'";
$stmt = $conn->prepare($query);
$stmt->execute();
$item_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Vehicle requests
$query = "SELECT vr.id, 'vehicle' as type, 'Vehicle Request' as type_name,
                 u.full_name, u.email, v.name as item_name,
                 vr.request_date as date, vr.start_time, vr.end_time,
                 vr.destination, vr.purpose, vr.passenger_count, vr.status, vr.created_at
          FROM vehicle_requests vr 
          JOIN users u ON vr.user_id = u.id 
          JOIN vehicles v ON vr.vehicle_id = v.id 
          WHERE vr.status = 'pending'";
$stmt = $conn->prepare($query);
$stmt->execute();
$vehicle_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Combine all requests
$pending_requests = array_merge($facility_requests, $item_requests, $vehicle_requests);

// Sort by creation date (newest first)
usort($pending_requests, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Requests - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
            border-left: 4px solid #dc3545;
            transition: transform 0.2s;
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
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Admin Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <div class="text-center mb-4">
                        <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                        <img src="kapasigan.png" alt="Barangay Kapasigan Logo" style="width: 60px; height: 60px; object-fit: cover;">
                        </div>
                        <h5 class="text-white mt-2">Admin Panel</h5>
                        <span class="admin-badge">Administrator</span>
                    </div>
                    
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="admin_dashboard.php">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="admin_requests.php">
                                <i class="fas fa-check-circle me-2"></i>Pending Requests
                            </a>
                        </li>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="calendar.php">
                                <i class="fas fa-calendar me-2"></i>Calendar View
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#" data-bs-toggle="collapse" data-bs-target="#manageSubmenu">
                                <i class="fas fa-cogs me-2"></i>Manage
                                <i class="fas fa-chevron-down ms-auto"></i>
                            </a>
                            <div class="collapse" id="manageSubmenu">
                                <ul class="nav flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link" href="manage_facilities.php"><i class="fas fa-building me-2"></i>Facilities</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="manage_items.php"><i class="fas fa-box me-2"></i>Items</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="manage_vehicles.php"><i class="fas fa-car me-2"></i>Vehicles</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="manage_users.php"><i class="fas fa-users me-2"></i>Users</a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reminders.php">
                                <i class="fas fa-bell me-2"></i>Notifications
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-check-circle me-2"></i>Pending Requests</h1>
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

                <!-- Summary Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-primary"><?php echo count($facility_requests); ?></h3>
                                <p class="mb-0">Facility Bookings</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success"><?php echo count($item_requests); ?></h3>
                                <p class="mb-0">Item Borrowings</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info"><?php echo count($vehicle_requests); ?></h3>
                                <p class="mb-0">Vehicle Requests</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Requests Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">All Pending Requests (<?php echo count($pending_requests); ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($pending_requests)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                <h4>All Caught Up!</h4>
                                <p class="text-muted">No pending requests at the moment.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="requestsTable">
                                    <thead>
                                        <tr>
                                            <th>Type</th>
                                            <th>Resident</th>
                                            <th>Item/Facility</th>
                                            <th>Date</th>
                                            <th>Details</th>
                                            <th>Submitted</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_requests as $request): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $request['type'] === 'facility' ? 'primary' : 
                                                            ($request['type'] === 'item' ? 'success' : 'info'); 
                                                    ?>">
                                                        <?php echo $request['type_name']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($request['full_name']); ?></strong><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($request['email']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($request['item_name']); ?></td>
                                                <td>
                                                    <?php echo date('M d, Y', strtotime($request['date'])); ?>
                                                    <?php if (isset($request['start_time'])): ?>
                                                        <br><small class="text-muted">
                                                            <?php echo date('g:i A', strtotime($request['start_time'])); ?>
                                                            <?php if (isset($request['end_time'])): ?>
                                                                - <?php echo date('g:i A', strtotime($request['end_time'])); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (isset($request['quantity'])): ?>
                                                        <small>Qty: <?php echo $request['quantity']; ?></small><br>
                                                    <?php endif; ?>
                                                    <?php if (isset($request['passenger_count'])): ?>
                                                        <small>Passengers: <?php echo $request['passenger_count']; ?></small><br>
                                                    <?php endif; ?>
                                                    <?php if (isset($request['destination'])): ?>
                                                        <small>To: <?php echo htmlspecialchars($request['destination']); ?></small><br>
                                                    <?php endif; ?>
                                                    <?php if ($request['purpose']): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars(substr($request['purpose'], 0, 50)); ?>...</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo date('M d, Y g:i A', strtotime($request['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-success me-1" 
                                                            onclick="showApprovalModal(<?php echo $request['id']; ?>, '<?php echo $request['type']; ?>', 'approve', '<?php echo htmlspecialchars($request['full_name']); ?>', '<?php echo htmlspecialchars($request['item_name']); ?>')">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" 
                                                            onclick="showApprovalModal(<?php echo $request['id']; ?>, '<?php echo $request['type']; ?>', 'deny', '<?php echo htmlspecialchars($request['full_name']); ?>', '<?php echo htmlspecialchars($request['item_name']); ?>')">
                                                        <i class="fas fa-times"></i> Deny
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Approval Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="approvalModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="request_id" id="modal_request_id">
                        <input type="hidden" name="request_type" id="modal_request_type">
                        <input type="hidden" name="action" id="modal_action">
                        
                        <div class="mb-3">
                            <p id="approval_message"></p>
                        </div>
                        
                        <div class="mb-3">
                            <label for="admin_notes" class="form-label">Admin Notes (Optional)</label>
                            <textarea class="form-control" name="admin_notes" id="admin_notes" rows="3" 
                                      placeholder="Add any notes or comments..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="modal_submit_btn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#requestsTable').DataTable({
                order: [[5, 'desc']], // Sort by submitted date
                pageLength: 25,
                responsive: true
            });
        });

        function showApprovalModal(requestId, requestType, action, residentName, itemName) {
            document.getElementById('modal_request_id').value = requestId;
            document.getElementById('modal_request_type').value = requestType;
            document.getElementById('modal_action').value = action;
            
            const title = action === 'approve' ? 'Approve Request' : 'Deny Request';
            const message = `Are you sure you want to ${action} the request from <strong>${residentName}</strong> for <strong>${itemName}</strong>?`;
            const btnClass = action === 'approve' ? 'btn-success' : 'btn-danger';
            const btnText = action === 'approve' ? 'Approve Request' : 'Deny Request';
            
            document.getElementById('approvalModalTitle').textContent = title;
            document.getElementById('approval_message').innerHTML = message;
            document.getElementById('modal_submit_btn').className = `btn ${btnClass}`;
            document.getElementById('modal_submit_btn').textContent = btnText;
            
            new bootstrap.Modal(document.getElementById('approvalModal')).show();
        }
    </script>
</body>
</html>
