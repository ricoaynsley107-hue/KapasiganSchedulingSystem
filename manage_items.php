<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireAdmin();

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $quantity = intval($_POST['quantity'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        
        if ($name && $quantity > 0) {
            try {
                $query = "INSERT INTO items (name, description, quantity, status) 
                          VALUES (:name, :description, :quantity, :status)";
                $stmt = $conn->prepare($query);
                
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':quantity', $quantity);
                $stmt->bindParam(':status', $status);
                
                if ($stmt->execute()) {
                    $success = "Item added successfully!";
                } else {
                    $error = "Failed to add item.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields and ensure quantity is greater than 0.";
        }
    } elseif ($action === 'edit') {
        $item_id = $_POST['item_id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $quantity = intval($_POST['quantity'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        
        if ($item_id && $name && $quantity >= 0) {
            try {
                $query = "UPDATE items SET name = :name, description = :description, 
                          quantity = :quantity, status = :status WHERE id = :item_id";
                $stmt = $conn->prepare($query);
                
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':quantity', $quantity);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':item_id', $item_id);
                
                if ($stmt->execute()) {
                    $success = "Item updated successfully!";
                } else {
                    $error = "Failed to update item.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields and ensure quantity is valid.";
        }
    } elseif ($action === 'delete') {
        $item_id = $_POST['item_id'] ?? '';
        
        if ($item_id) {
            try {
                $check_query = "SELECT COUNT(*) FROM item_borrowings WHERE item_id = :item_id AND status IN ('pending', 'approved')";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':item_id', $item_id);
                $check_stmt->execute();
                
                if ($check_stmt->fetchColumn() > 0) {
                    $error = "Cannot delete item with active borrowings. Consider marking it as unavailable instead.";
                } else {
                    $query = "DELETE FROM items WHERE id = :item_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    
                    if ($stmt->execute()) {
                        $success = "Item deleted successfully!";
                    } else {
                        $error = "Failed to delete item.";
                    }
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid item ID.";
        }
    }
}

// Fetch items
$query = "SELECT 
            i.*,
            COALESCE(SUM(CASE WHEN ib.status IN ('approved', 'pending') THEN ib.quantity ELSE 0 END), 0) as borrowed_quantity,
            COALESCE(COUNT(CASE WHEN ib.status = 'approved' THEN 1 END), 0) as active_borrowings,
            (SELECT COUNT(*) FROM item_borrowings ib2 
             WHERE ib2.item_id = i.id 
             AND ib2.status = 'returned' 
             AND ib2.actual_return_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as recent_returns
          FROM items i
          LEFT JOIN item_borrowings ib ON i.id = ib.item_id AND ib.actual_return_date IS NULL
          GROUP BY i.id
          ORDER BY i.name ASC";
$stmt = $conn->prepare($query);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Items - Admin Panel</title>
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

        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-box me-2"></i>Manage Items</h1>
                <div>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addItemModal">
                        <i class="fas fa-plus me-2"></i>Add New Item
                    </button>
                    <div class="dropdown ms-2 d-inline-block">
                        <button class="btn btn-outline-danger dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fas fa-user-shield me-2"></i><?php echo $_SESSION['full_name']; ?>
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ✅ Fixed Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h3 class="text-primary mb-0"><?php echo count($items); ?></h3>
                            <p class="mb-0 text-muted">Total Items</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h3 class="text-success mb-0"><?php echo array_sum(array_column($items, 'quantity')); ?></h3>
                            <p class="mb-0 text-muted">Total Stock</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h3 class="text-warning mb-0"><?php echo array_sum(array_column($items, 'borrowed_quantity')); ?></h3>
                            <p class="mb-0 text-muted">Currently Borrowed</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center shadow-sm">
                        <div class="card-body">
                            <h3 class="text-info mb-0"><?php echo array_sum(array_column($items, 'recent_returns')); ?></h3>
                            <p class="mb-0 text-muted">Returned (Last 7 Days)</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <div class="card mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0">
                        All Items
                        <span class="badge bg-info ms-2">Live Inventory Tracking</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="itemsTable">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>Description</th>
                                    <th>Total Quantity</th>
                                    <th>Available</th>
                                    <th>Status</th>
                                    <th>Activity</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): 
                                    $available = $item['quantity'] - $item['borrowed_quantity'];
                                    $hasRecentReturns = $item['recent_returns'] > 0;
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td><?php echo $available; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $item['status'] === 'available' ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst($item['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $item['active_borrowings']; ?> active |
                                            <?php echo $item['recent_returns']; ?> returned
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-info" onclick="editItem(<?php echo $item['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#itemsTable').DataTable();
});
</script>
</body>
</html>
