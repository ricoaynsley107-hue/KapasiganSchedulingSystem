<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$success = '';
$error = '';
$conflicts = [];
$suggestions = [];

// Handle item return
if (isset($_POST['action']) && $_POST['action'] === 'return_item') {
    $borrowing_id = $_POST['borrowing_id'] ?? '';
    $condition = $_POST['condition'] ?? 'good';
    $damage_notes = $_POST['damage_notes'] ?? '';
    
    if ($borrowing_id) {
        try {
            $query = "UPDATE item_borrowings 
                      SET status = 'returned', 
                          actual_return_date = CURDATE(),
                          condition_after = :condition,
                          admin_notes = :damage_notes
                      WHERE id = :id AND user_id = :user_id";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':condition', $condition);
            $stmt->bindParam(':damage_notes', $damage_notes);
            $stmt->bindParam(':id', $borrowing_id);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                $success = 'Item returned successfully!';
            } else {
                $error = 'Failed to mark item as returned.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle extension request
if (isset($_POST['action']) && $_POST['action'] === 'extend_schedule') {
    $borrowing_id = $_POST['borrowing_id'] ?? '';
    $new_return_date = $_POST['new_return_date'] ?? '';
    $extension_reason = $_POST['extension_reason'] ?? '';
    
    if ($borrowing_id && $new_return_date) {
        try {
            // Check if extension is reasonable (not too far in future)
            $query = "SELECT ib.*, i.name as item_name 
                      FROM item_borrowings ib
                      JOIN items i ON ib.item_id = i.id
                      WHERE ib.id = :id AND ib.user_id = :user_id";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $borrowing_id);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->execute();
            $borrowing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($borrowing) {
                // Create extension request (stored in admin_notes or create separate table)
                $extension_data = json_encode([
                    'requested_date' => $new_return_date,
                    'reason' => $extension_reason,
                    'requested_at' => date('Y-m-d H:i:s')
                ]);
                
                $query = "UPDATE item_borrowings 
                          SET admin_notes = :extension_data,
                              status = 'pending'
                          WHERE id = :id";
                
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':extension_data', $extension_data);
                $stmt->bindParam(':id', $borrowing_id);
                
                if ($stmt->execute()) {
                    $success = 'Extension request submitted! Waiting for admin approval.';
                } else {
                    $error = 'Failed to submit extension request.';
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle new borrowing request
if ($_POST && !isset($_POST['action'])) {
    $item_id = $_POST['item_id'] ?? '';
    $borrow_date = $_POST['borrow_date'] ?? '';
    $return_date = $_POST['return_date'] ?? '';
    $quantity = intval($_POST['quantity'] ?? 0);
    $purpose = $_POST['purpose'] ?? '';
    
    if ($item_id && $borrow_date && $return_date && $quantity > 0) {
        try {
            // Check availability
            $query = "SELECT SUM(quantity) as total_borrowed 
                      FROM item_borrowings 
                      WHERE item_id = :item_id 
                      AND status IN ('approved', 'pending')
                      AND (
                          (borrow_date <= :borrow_date AND return_date >= :borrow_date)
                          OR (borrow_date <= :return_date AND return_date >= :return_date)
                          OR (borrow_date >= :borrow_date AND return_date <= :return_date)
                      )";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
            $stmt->bindParam(':borrow_date', $borrow_date);
            $stmt->bindParam(':return_date', $return_date);
            $stmt->execute();
            
            $borrowed_quantity = $stmt->fetchColumn() ?: 0;
            
            // Get item info
            $query = "SELECT quantity, name FROM items WHERE id = :item_id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':item_id', $item_id);
            $stmt->execute();
            $item_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $available_quantity = $item_info['quantity'] - $borrowed_quantity;
            
            if ($available_quantity < $quantity) {
                $error = "Only {$available_quantity} units available. You requested {$quantity}.";
            } else {
                $query = "INSERT INTO item_borrowings 
                          (user_id, item_id, borrow_date, return_date, quantity, purpose) 
                          VALUES 
                          (:user_id, :item_id, :borrow_date, :return_date, :quantity, :purpose)";
                
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':user_id', $_SESSION['user_id']);
                $stmt->bindParam(':item_id', $item_id);
                $stmt->bindParam(':borrow_date', $borrow_date);
                $stmt->bindParam(':return_date', $return_date);
                $stmt->bindParam(':quantity', $quantity);
                $stmt->bindParam(':purpose', $purpose);
                
                if ($stmt->execute()) {
                    $success = 'Borrowing request submitted successfully!';
                    $_POST = array();
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get available items
$query = "SELECT * FROM items WHERE status = 'available' ORDER BY name";
$stmt = $conn->prepare($query);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's active borrowings
$query = "SELECT ib.*, i.name as item_name, i.quantity as total_quantity
          FROM item_borrowings ib
          JOIN items i ON ib.item_id = i.id
          WHERE ib.user_id = :user_id 
          AND ib.status IN ('approved', 'pending')
          AND ib.actual_return_date IS NULL
          ORDER BY ib.return_date ASC";

$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$active_borrowings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrow Items - Barangay Kapasigan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #f5f9ff 0%, #ffffff 50%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

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
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .borrowing-tabs {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }

        .tab-btn {
            background: white;
            border: 2px solid #e2e8f0;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            border-color: transparent;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .active-borrowings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .borrowing-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border-left: 4px solid #2563eb;
        }

        .borrowing-card.overdue {
            border-left-color: #ef4444;
            background: #fff5f5;
        }

        .borrowing-card.due-soon {
            border-left-color: #f59e0b;
            background: #fffbeb;
        }

        .borrowing-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .borrowing-title {
            font-weight: 700;
            color: #2d3748;
            font-size: 16px;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .borrowing-info {
            font-size: 13px;
            color: #718096;
            margin-bottom: 8px;
        }

        .borrowing-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }

        .btn-return {
            background: #10b981;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            flex: 1;
        }

        .btn-extend {
            background: #f59e0b;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            flex: 1;
        }

        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .modal-content {
            border-radius: 15px;
            border: none;
        }

        .modal-header {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            border-radius: 15px 15px 0 0;
        }
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
            <li class="nav-item">
                <a class="nav-link active" href="resident_dashboard.php">
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
                <a class="nav-link" href="resident calendar.php">
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
        <div class="page-header">
            <h1><i class="fas fa-box me-2"></i>Borrow Items</h1>
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

        <!-- Tabs -->
        <div class="borrowing-tabs">
            <button class="tab-btn active" onclick="switchTab('active')">
                <i class="fas fa-clock me-2"></i>Active Borrowings (<?php echo count($active_borrowings); ?>)
            </button>
            <button class="tab-btn" onclick="switchTab('new')">
                <i class="fas fa-plus me-2"></i>New Borrowing
            </button>
        </div>

        <!-- Active Borrowings Tab -->
        <div id="active-tab" class="tab-content active">
            <?php if (empty($active_borrowings)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No active borrowings</p>
                </div>
            <?php else: ?>
                <div class="active-borrowings-grid">
                    <?php foreach ($active_borrowings as $borrowing): 
                        $return_date = new DateTime($borrowing['return_date']);
                        $today = new DateTime();
                        $diff = $today->diff($return_date);
                        $days_diff = (int)$diff->format('%r%a');
                        
                        $card_class = '';
                        if ($days_diff < 0) {
                            $card_class = 'overdue';
                        } elseif ($days_diff <= 2) {
                            $card_class = 'due-soon';
                        }
                    ?>
                        <div class="borrowing-card <?php echo $card_class; ?>">
                            <div class="borrowing-header">
                                <div class="borrowing-title"><?php echo htmlspecialchars($borrowing['item_name']); ?></div>
                                <span class="status-badge status-<?php echo $borrowing['status']; ?>">
                                    <?php echo ucfirst($borrowing['status']); ?>
                                </span>
                            </div>
                            
                            <div class="borrowing-info">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Borrowed: <?php echo date('M d, Y', strtotime($borrowing['borrow_date'])); ?>
                            </div>
                            
                            <div class="borrowing-info">
                                <i class="fas fa-calendar-check me-2"></i>
                                Due: <?php echo date('M d, Y', strtotime($borrowing['return_date'])); ?>
                                <?php if ($days_diff < 0): ?>
                                    <span class="text-danger fw-bold ms-2">(<?php echo abs($days_diff); ?> days overdue)</span>
                                <?php elseif ($days_diff <= 2): ?>
                                    <span class="text-warning fw-bold ms-2">(<?php echo $days_diff; ?> days left)</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="borrowing-info">
                                <i class="fas fa-boxes me-2"></i>
                                Quantity: <?php echo $borrowing['quantity']; ?>
                            </div>

                            <?php if ($borrowing['status'] === 'approved'): ?>
                                <div class="borrowing-actions">
                                    <button class="btn-return" onclick="showReturnModal(<?php echo $borrowing['id']; ?>, '<?php echo htmlspecialchars($borrowing['item_name']); ?>')">
                                        <i class="fas fa-undo me-1"></i>Return Item
                                    </button>
                                    <button class="btn-extend" onclick="showExtendModal(<?php echo $borrowing['id']; ?>, '<?php echo htmlspecialchars($borrowing['item_name']); ?>', '<?php echo $borrowing['return_date']; ?>')">
                                        <i class="fas fa-clock me-1"></i>Extend
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- New Borrowing Tab -->
        <div id="new-tab" class="tab-content">
            <div class="form-card">
                <h4 class="mb-4"><i class="fas fa-file-alt me-2"></i>New Borrowing Request</h4>
                
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Item</label>
                        <select class="form-select" name="item_id" required>
                            <option value="">Choose an item...</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?php echo $item['id']; ?>">
                                    <?php echo htmlspecialchars($item['name']); ?> (Available: <?php echo $item['quantity']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Borrow Date</label>
                            <input type="date" class="form-control" name="borrow_date" 
                                   min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Return Date</label>
                            <input type="date" class="form-control" name="return_date" 
                                   min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Quantity</label>
                        <input type="number" class="form-control" name="quantity" min="1" value="1" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Purpose</label>
                        <textarea class="form-control" name="purpose" rows="3" 
                                  placeholder="Describe the purpose..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-paper-plane me-2"></i>Submit Request
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Return Modal -->
    <div class="modal fade" id="returnModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Return Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="return_item">
                        <input type="hidden" name="borrowing_id" id="return_borrowing_id">
                        
                        <p>Return: <strong id="return_item_name"></strong></p>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Item Condition</label>
                            <select class="form-select" name="condition" required>
                                <option value="good">Good - No damage</option>
                                <option value="minor_damage">Minor Damage</option>
                                <option value="major_damage">Major Damage</option>
                                <option value="lost">Lost/Missing</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Notes (if damaged)</label>
                            <textarea class="form-control" name="damage_notes" rows="3"
                                      placeholder="Describe any damage or issues..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Confirm Return</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Extend Modal -->
    <div class="modal fade" id="extendModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Extend Borrowing Period</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="extend_schedule">
                        <input type="hidden" name="borrowing_id" id="extend_borrowing_id">
                        
                        <p>Extend: <strong id="extend_item_name"></strong></p>
                        <p class="text-muted">Current due date: <span id="current_due_date"></span></p>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">New Return Date</label>
                            <input type="date" class="form-control" name="new_return_date" 
                                   id="new_return_date" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Reason for Extension</label>
                            <textarea class="form-control" name="extension_reason" rows="3"
                                      placeholder="Explain why you need an extension..." required></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Extension requests require admin approval.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Request Extension</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function switchTab(tab) {
            // Update buttons
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.closest('.tab-btn').classList.add('active');
            
            // Update content
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        function showReturnModal(borrowingId, itemName) {
            document.getElementById('return_borrowing_id').value = borrowingId;
            document.getElementById('return_item_name').textContent = itemName;
            new bootstrap.Modal(document.getElementById('returnModal')).show();
        }

        function showExtendModal(borrowingId, itemName, currentDueDate) {
            document.getElementById('extend_borrowing_id').value = borrowingId;
            document.getElementById('extend_item_name').textContent = itemName;
            document.getElementById('current_due_date').textContent = new Date(currentDueDate).toLocaleDateString();
            
            // Set minimum date to tomorrow
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('new_return_date').min = tomorrow.toISOString().split('T')[0];
            
            new bootstrap.Modal(document.getElementById('extendModal')).show();
        }
    </script>
</body>
</html>