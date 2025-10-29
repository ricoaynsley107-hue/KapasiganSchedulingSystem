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
        $capacity = intval($_POST['capacity'] ?? 0);
        $plate_number = trim($_POST['plate_number'] ?? '');
        $status = $_POST['status'] ?? 'available';
        
        if ($name && $capacity > 0) {
            try {
                $query = "INSERT INTO vehicles (name, description, capacity, plate_number, status) 
                          VALUES (:name, :description, :capacity, :plate_number, :status)";
                $stmt = $conn->prepare($query);
                
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':capacity', $capacity);
                $stmt->bindParam(':plate_number', $plate_number);
                $stmt->bindParam(':status', $status);
                
                if ($stmt->execute()) {
                    $success = "Vehicle added successfully!";
                } else {
                    $error = "Failed to add vehicle.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields and ensure capacity is greater than 0.";
        }
    } elseif ($action === 'edit') {
        $vehicle_id = $_POST['vehicle_id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $capacity = intval($_POST['capacity'] ?? 0);
        $plate_number = trim($_POST['plate_number'] ?? '');
        $status = $_POST['status'] ?? 'available';
        
        if ($vehicle_id && $name && $capacity > 0) {
            try {
                $query = "UPDATE vehicles SET name = :name, description = :description, 
                          capacity = :capacity, plate_number = :plate_number, status = :status 
                          WHERE id = :vehicle_id";
                $stmt = $conn->prepare($query);
                
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':capacity', $capacity);
                $stmt->bindParam(':plate_number', $plate_number);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':vehicle_id', $vehicle_id);
                
                if ($stmt->execute()) {
                    $success = "Vehicle updated successfully!";
                } else {
                    $error = "Failed to update vehicle.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields and ensure capacity is greater than 0.";
        }
    } elseif ($action === 'delete') {
        $vehicle_id = $_POST['vehicle_id'] ?? '';
        
        if ($vehicle_id) {
            try {
                // Check if vehicle has any active requests
                $check_query = "SELECT COUNT(*) FROM vehicle_requests WHERE vehicle_id = :vehicle_id AND status IN ('pending', 'approved')";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':vehicle_id', $vehicle_id);
                $check_stmt->execute();
                
                if ($check_stmt->fetchColumn() > 0) {
                    $error = "Cannot delete vehicle with active requests. Consider marking it as unavailable instead.";
                } else {
                    $query = "DELETE FROM vehicles WHERE id = :vehicle_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':vehicle_id', $vehicle_id);
                    
                    if ($stmt->execute()) {
                        $success = "Vehicle deleted successfully!";
                    } else {
                        $error = "Failed to delete vehicle.";
                    }
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid vehicle ID.";
        }
    }
}

// Get all vehicles
$query = "SELECT * FROM vehicles ORDER BY name ASC";
$stmt = $conn->prepare($query);
$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Vehicles - Admin Panel</title>
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
        .vehicle-card {
            transition: transform 0.2s;
        }
        .vehicle-card:hover {
            transform: translateY(-2px);
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
                            <a class="nav-link" href="admin_requests.php">
                                <i class="fas fa-check-circle me-2"></i>Pending Requests
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="calendar.php">
                                <i class="fas fa-calendar me-2"></i>Calendar View
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="admin notif.php">
                                <i class="fas fa-bell me-2"></i>Notifications
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#" data-bs-toggle="collapse" data-bs-target="#manageSubmenu">
                                <i class="fas fa-cogs me-2"></i>Manage
                                <i class="fas fa-chevron-down ms-auto"></i>
                            </a>
                            <div class="collapse show" id="manageSubmenu">
                                <ul class="nav flex-column ms-3">
                                    <li class="nav-item">
                                        <a class="nav-link" href="manage_facilities.php"><i class="fas fa-building me-2"></i>Facilities</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="manage_items.php"><i class="fas fa-box me-2"></i>Items</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link active" href="manage_vehicles.php"><i class="fas fa-car me-2"></i>Vehicles</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="manage_users.php"><i class="fas fa-users me-2"></i>Users</a>
                                    </li>
                                </ul>
                            </div>
                        </li> 
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="fas fa-car me-2"></i>Manage Vehicles</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
                            <i class="fas fa-plus me-2"></i>Add New Vehicle
                        </button>
                        <div class="dropdown ms-2">
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
                                <h3 class="text-primary"><?php echo count($vehicles); ?></h3>
                                <p class="mb-0">Total Vehicles</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success"><?php echo count(array_filter($vehicles, function($vehicle) { return $vehicle['status'] === 'available'; })); ?></h3>
                                <p class="mb-0">Available</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info"><?php echo array_sum(array_column($vehicles, 'capacity')); ?></h3>
                                <p class="mb-0">Total Capacity</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vehicles Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">All Vehicles</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="vehiclesTable">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Capacity</th>
                                        <th>Plate Number</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="bg-info rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                                        <i class="fas fa-car text-white"></i>
                                                    </div>
                                                    <?php echo htmlspecialchars($vehicle['name']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($vehicle['description'] ?? 'No description'); ?></td>
                                            <td><?php echo $vehicle['capacity']; ?> passengers</td>
                                            <td><?php echo htmlspecialchars($vehicle['plate_number'] ?? 'Not specified'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $vehicle['status'] === 'available' ? 'success' : 'secondary'; ?>">
                                                    <?php echo ucfirst($vehicle['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-info me-1" onclick="editVehicle(<?php echo $vehicle['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteVehicle(<?php echo $vehicle['id']; ?>, '<?php echo htmlspecialchars($vehicle['name']); ?>')">
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

    <!-- Add Vehicle Modal -->
    <div class="modal fade" id="addVehicleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Vehicle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label for="name" class="form-label">Vehicle Name *</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="capacity" class="form-label">Capacity *</label>
                                <input type="number" class="form-control" id="capacity" name="capacity" min="1" value="1" required>
                            </div>
                            <div class="col-md-6">
                                <label for="status" class="form-label">Status *</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="available">Available</option>
                                    <option value="unavailable">Unavailable</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="plate_number" class="form-label">Plate Number</label>
                            <input type="text" class="form-control" id="plate_number" name="plate_number">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info">Add Vehicle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Vehicle Modal -->
    <div class="modal fade" id="editVehicleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Vehicle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
                        
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Vehicle Name *</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_capacity" class="form-label">Capacity *</label>
                                <input type="number" class="form-control" id="edit_capacity" name="capacity" min="1" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_status" class="form-label">Status *</label>
                                <select class="form-select" id="edit_status" name="status" required>
                                    <option value="available">Available</option>
                                    <option value="unavailable">Unavailable</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_plate_number" class="form-label">Plate Number</label>
                            <input type="text" class="form-control" id="edit_plate_number" name="plate_number">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Vehicle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Vehicle Modal -->
    <div class="modal fade" id="deleteVehicleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Delete Vehicle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="vehicle_id" id="delete_vehicle_id">
                        
                        <p>Are you sure you want to delete the vehicle <strong id="delete_vehicle_name"></strong>?</p>
                        <p class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Vehicle</button>
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
            $('#vehiclesTable').DataTable({
                order: [[0, 'asc']], // Sort by name
                pageLength: 10,
                responsive: true
            });
        });

        function editVehicle(vehicleId) {
            // Find vehicle data from the table
            const vehicles = <?php echo json_encode($vehicles); ?>;
            const vehicle = vehicles.find(v => v.id == vehicleId);
            
            if (vehicle) {
                document.getElementById('edit_vehicle_id').value = vehicle.id;
                document.getElementById('edit_name').value = vehicle.name;
                document.getElementById('edit_description').value = vehicle.description || '';
                document.getElementById('edit_capacity').value = vehicle.capacity;
                document.getElementById('edit_plate_number').value = vehicle.plate_number || '';
                document.getElementById('edit_status').value = vehicle.status;
                
                new bootstrap.Modal(document.getElementById('editVehicleModal')).show();
            }
        }

        function deleteVehicle(vehicleId, vehicleName) {
            document.getElementById('delete_vehicle_id').value = vehicleId;
            document.getElementById('delete_vehicle_name').textContent = vehicleName;
            
            new bootstrap.Modal(document.getElementById('deleteVehicleModal')).show();
        }
    </script>
</body>
</html>
