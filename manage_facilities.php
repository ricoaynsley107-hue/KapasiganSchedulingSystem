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
        $location = trim($_POST['location'] ?? '');
        $status = $_POST['status'] ?? 'available';
        
        if ($name && $capacity > 0) {
            try {
                $query = "INSERT INTO facilities (name, description, capacity, location, status) 
                          VALUES (:name, :description, :capacity, :location, :status)";
                $stmt = $conn->prepare($query);
                
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':capacity', $capacity);
                $stmt->bindParam(':location', $location);
                $stmt->bindParam(':status', $status);
                
                if ($stmt->execute()) {
                    $success = "Facility added successfully!";
                } else {
                    $error = "Failed to add facility.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields and ensure capacity is greater than 0.";
        }
    } elseif ($action === 'edit') {
        $facility_id = $_POST['facility_id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $capacity = intval($_POST['capacity'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $status = $_POST['status'] ?? 'available';
        
        if ($facility_id && $name && $capacity > 0) {
            try {
                $query = "UPDATE facilities SET name = :name, description = :description, 
                          capacity = :capacity, location = :location, status = :status 
                          WHERE id = :facility_id";
                $stmt = $conn->prepare($query);
                
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':capacity', $capacity);
                $stmt->bindParam(':location', $location);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':facility_id', $facility_id);
                
                if ($stmt->execute()) {
                    $success = "Facility updated successfully!";
                } else {
                    $error = "Failed to update facility.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all required fields and ensure capacity is greater than 0.";
        }
    } elseif ($action === 'delete') {
        $facility_id = $_POST['facility_id'] ?? '';
        
        if ($facility_id) {
            try {
                // Check if facility has any active bookings
                $check_query = "SELECT COUNT(*) FROM facility_bookings WHERE facility_id = :facility_id AND status IN ('pending', 'approved')";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':facility_id', $facility_id);
                $check_stmt->execute();
                
                if ($check_stmt->fetchColumn() > 0) {
                    $error = "Cannot delete facility with active bookings. Consider marking it as unavailable instead.";
                } else {
                    $query = "DELETE FROM facilities WHERE id = :facility_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':facility_id', $facility_id);
                    
                    if ($stmt->execute()) {
                        $success = "Facility deleted successfully!";
                    } else {
                        $error = "Failed to delete facility.";
                    }
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid facility ID.";
        }
    }
}

// Get all facilities
$query = "SELECT * FROM facilities ORDER BY name ASC";
$stmt = $conn->prepare($query);
$stmt->execute();
$facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Facilities - Admin Panel</title>
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
        .facility-card {
            transition: transform 0.2s;
        }
        .facility-card:hover {
            transform: translateY(-2px);
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
                    <h1 class="h2"><i class="fas fa-building me-2"></i>Manage Facilities</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFacilityModal">
                            <i class="fas fa-plus me-2"></i>Add New Facility
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
                                <h3 class="text-primary"><?php echo count($facilities); ?></h3>
                                <p class="mb-0">Total Facilities</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-success"><?php echo count(array_filter($facilities, function($facility) { return $facility['status'] === 'available'; })); ?></h3>
                                <p class="mb-0">Available</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="text-info"><?php echo array_sum(array_column($facilities, 'capacity')); ?></h3>
                                <p class="mb-0">Total Capacity</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Facilities Table -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">All Facilities</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="facilitiesTable">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Capacity</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($facilities as $facility): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                                        <i class="fas fa-building text-white"></i>
                                                    </div>
                                                    <?php echo htmlspecialchars($facility['name']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($facility['description'] ?? 'No description'); ?></td>
                                            <td><?php echo $facility['capacity']; ?> people</td>
                                            <td><?php echo htmlspecialchars($facility['location'] ?? 'Not specified'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $facility['status'] === 'available' ? 'success' : 'secondary'; ?>">
                                                    <?php echo ucfirst($facility['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-info me-1" onclick="editFacility(<?php echo $facility['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteFacility(<?php echo $facility['id']; ?>, '<?php echo htmlspecialchars($facility['name']); ?>')">
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

    <!-- Add Facility Modal -->
    <div class="modal fade" id="addFacilityModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Facility</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label for="name" class="form-label">Facility Name *</label>
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
                            <label for="location" class="form-label">Location</label>
                            <input type="text" class="form-control" id="location" name="location">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Facility</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Facility Modal -->
    <div class="modal fade" id="editFacilityModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Facility</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="facility_id" id="edit_facility_id">
                        
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Facility Name *</label>
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
                            <label for="edit_location" class="form-label">Location</label>
                            <input type="text" class="form-control" id="edit_location" name="location">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Facility</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Facility Modal -->
    <div class="modal fade" id="deleteFacilityModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Delete Facility</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="facility_id" id="delete_facility_id">
                        
                        <p>Are you sure you want to delete the facility <strong id="delete_facility_name"></strong>?</p>
                        <p class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Facility</button>
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
            $('#facilitiesTable').DataTable({
                order: [[0, 'asc']], // Sort by name
                pageLength: 10,
                responsive: true
            });
        });

        function editFacility(facilityId) {
            // Find facility data from the table
            const facilities = <?php echo json_encode($facilities); ?>;
            const facility = facilities.find(f => f.id == facilityId);
            
            if (facility) {
                document.getElementById('edit_facility_id').value = facility.id;
                document.getElementById('edit_name').value = facility.name;
                document.getElementById('edit_description').value = facility.description || '';
                document.getElementById('edit_capacity').value = facility.capacity;
                document.getElementById('edit_location').value = facility.location || '';
                document.getElementById('edit_status').value = facility.status;
                
                new bootstrap.Modal(document.getElementById('editFacilityModal')).show();
            }
        }

        function deleteFacility(facilityId, facilityName) {
            document.getElementById('delete_facility_id').value = facilityId;
            document.getElementById('delete_facility_name').textContent = facilityName;
            
            new bootstrap.Modal(document.getElementById('deleteFacilityModal')).show();
        }
    </script>
</body>
</html>
