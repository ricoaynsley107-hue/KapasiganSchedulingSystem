<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireAdmin();

$database = new Database();
$conn = $database->getConnection();

// Get model accuracy over time (last 30 days)
$accuracy_query = "
    SELECT 
        DATE(fb.created_at) as date,
        COUNT(*) as total_bookings,
        SUM(CASE WHEN fb.status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN fb.status = 'approved' AND fb.admin_notes LIKE '%Auto-approved%' THEN 1 ELSE 0 END) as auto_approved
    FROM facility_bookings fb
    WHERE fb.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(fb.created_at)
    ORDER BY date ASC
";

$stmt = $conn->prepare($accuracy_query);
$stmt->execute();
$daily_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall metrics
$total_bookings = array_sum(array_column($daily_stats, 'total_bookings'));
$total_approved = array_sum(array_column($daily_stats, 'approved'));
$total_auto_approved = array_sum(array_column($daily_stats, 'auto_approved'));

$auto_approval_rate = $total_approved > 0 ? ($total_auto_approved / $total_approved) * 100 : 0;
$time_saved_hours = round(($total_auto_approved * 5) / 60, 1);

// Get ML prediction stats
$ml_stats_query = "
    SELECT 
        COUNT(*) as total_predictions,
        AVG(confidence_score) as avg_confidence,
        MIN(confidence_score) as min_confidence,
        MAX(confidence_score) as max_confidence
    FROM ml_predictions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
";

$stmt = $conn->prepare($ml_stats_query);
$stmt->execute();
$ml_stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ML Performance Analytics - Admin Panel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body {
    background-color: #f8f9fa;
}
.sidebar {
    min-height: 100vh;
    background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
}
.sidebar .nav-link {
    color: rgba(255, 255, 255, 0.9);
    padding: 10px 15px;
    margin: 2px 0;
    border-radius: 8px;
    transition: all 0.3s;
}
.sidebar .nav-link:hover, .sidebar .nav-link.active {
    color: white;
    background-color: rgba(255, 255, 255, 0.1);
}
.metric-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
    height: 100%;
}
.chart-container {
    background: white;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
    margin-bottom: 30px;
}
.stat-number {
    font-size: 2.5rem;
    font-weight: bold;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
.progress-ring {
    width: 120px;
    height: 120px;
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
        <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
            <div class="position-sticky pt-3">
                <div class="text-center mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white" style="width: 60px; height: 60px;">
                        <img src="kapasigan.png" alt="Logo" style="width: 50px; height: 50px;">
                    </div>
                    <h5 class="text-white mt-2">Admin Panel</h5>
                    <span class="admin-badge">Administrator</span>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link" href="admin_dashboard.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_approval.php"><i class="fas fa-check-circle me-2"></i>Approve Requests</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_ml_dashboard.php"><i class="fas fa-robot me-2"></i>ML Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link active" href="admin_ml_performance.php"><i class="fas fa-chart-line me-2"></i>ML Performance</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin_reports.php"><i class="fas fa-chart-bar me-2"></i>Reports</a></li>
                </ul>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <!-- Header -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-chart-line me-2"></i>ML Performance Analytics</h1>
                <div class="btn-toolbar">
                    <button class="btn btn-sm btn-outline-primary me-2" onclick="exportReport()">
                        <i class="fas fa-download me-1"></i>Export Report
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-outline-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-shield me-2"></i><?php echo $_SESSION['full_name']; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Key Metrics -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="metric-card text-center">
                        <i class="fas fa-robot fa-2x mb-3" style="color: #667eea;"></i>
                        <div class="stat-number"><?php echo number_format($ml_stats['total_predictions']); ?></div>
                        <p class="text-muted mb-0">Total Predictions</p>
                        <small class="text-muted">Last 30 days</small>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="metric-card text-center">
                        <i class="fas fa-check-circle fa-2x mb-3 text-success"></i>
                        <div class="stat-number"><?php echo round($auto_approval_rate, 1); ?>%</div>
                        <p class="text-muted mb-0">Auto-Approval Rate</p>
                        <small class="text-muted"><?php echo $total_auto_approved; ?> of <?php echo $total_approved; ?> approvals</small>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="metric-card text-center">
                        <i class="fas fa-gauge-high fa-2x mb-3" style="color: #667eea;"></i>
                        <div class="stat-number"><?php echo round($ml_stats['avg_confidence'] * 100, 1); ?>%</div>
                        <p class="text-muted mb-0">Avg Confidence</p>
                        <small class="text-muted">Model accuracy score</small>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <div class="metric-card text-center">
                        <i class="fas fa-clock fa-2x mb-3 text-warning"></i>
                        <div class="stat-number"><?php echo $time_saved_hours; ?>h</div>
                        <p class="text-muted mb-0">Time Saved</p>
                        <small class="text-muted">Admin hours saved</small>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="row">
                <div class="col-md-8 mb-4">
                    <div class="chart-container">
                        <h5 class="mb-3"><i class="fas fa-chart-area me-2"></i>Auto-Approval Trend (Last 30 Days)</h5>
                        <canvas id="approvalTrendChart"></canvas>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="chart-container">
                        <h5 class="mb-3"><i class="fas fa-pie-chart me-2"></i>Booking Status Distribution</h5>
                        <canvas id="statusPieChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-4">
                    <div class="chart-container">
                        <h5 class="mb-3"><i class="fas fa-chart-bar me-2"></i>Daily Booking Volume</h5>
                        <canvas id="volumeChart"></canvas>
                    </div>
                </div>
                <div class="col-md-6 mb-4">
                    <div class="chart-container">
                        <h5 class="mb-3"><i class="fas fa-brain me-2"></i>ML Confidence Distribution</h5>
                        <div class="text-center my-4">
                            <div class="progress" style="height: 30px;">
                                <div class="progress-bar" style="width: <?php echo round($ml_stats['avg_confidence'] * 100); ?>%; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);">
                                    <?php echo round($ml_stats['avg_confidence'] * 100, 1); ?>% Average
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-6">
                                    <p class="mb-0"><strong>Min:</strong></p>
                                    <p class="h4"><?php echo round($ml_stats['min_confidence'] * 100, 1); ?>%</p>
                                </div>
                                <div class="col-6">
                                    <p class="mb-0"><strong>Max:</strong></p>
                                    <p class="h4"><?php echo round($ml_stats['max_confidence'] * 100, 1); ?>%</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Insights -->
            <div class="row">
                <div class="col-12">
                    <div class="chart-container">
                        <h5 class="mb-3"><i class="fas fa-lightbulb me-2"></i>AI Insights & Recommendations</h5>
                        
                        <?php if ($auto_approval_rate < 50): ?>
                            <div class="alert alert-warning">
                                <strong>⚠️ Low Auto-Approval Rate</strong><br>
                                Current rate is <?php echo round($auto_approval_rate, 1); ?>%. Consider reviewing ML model parameters or retraining with more data.
                            </div>
                        <?php elseif ($auto_approval_rate > 90): ?>
                            <div class="alert alert-info">
                                <strong>ℹ️ Very High Auto-Approval Rate</strong><br>
                                <?php echo round($auto_approval_rate, 1); ?>% of bookings are auto-approved. System is working efficiently!
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <strong>✅ Optimal Performance</strong><br>
                                Auto-approval rate of <?php echo round($auto_approval_rate, 1); ?>% indicates healthy ML performance.
                            </div>
                        <?php endif; ?>
                        
                        <div class="row mt-3">
                            <div class="col-md-4">
                                <div class="card border-primary mb-3">
                                    <div class="card-body text-center">
                                        <i class="fas fa-trophy fa-3x text-primary mb-2"></i>
                                        <h6>Best Performance Day</h6>
                                        <p class="h5"><?php echo !empty($daily_stats) ? date('M d', strtotime($daily_stats[0]['date'])) : 'N/A'; ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card border-success mb-3">
                                    <div class="card-body text-center">
                                        <i class="fas fa-users fa-3x text-success mb-2"></i>
                                        <h6>Total Users Served</h6>
                                        <p class="h5"><?php echo $total_bookings; ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card border-info mb-3">
                                    <div class="card-body text-center">
                                        <i class="fas fa-magic fa-3x text-info mb-2"></i>
                                        <h6>AI Efficiency</h6>
                                        <p class="h5"><?php echo round($ml_stats['avg_confidence'] * 100, 1); ?>%</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Prepare data for charts
const dailyData = <?php echo json_encode($daily_stats); ?>;

// Approval Trend Chart
const approvalCtx = document.getElementById('approvalTrendChart').getContext('2d');
new Chart(approvalCtx, {
    type: 'line',
    data: {
        labels: dailyData.map(d => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
        datasets: [{
            label: 'Total Bookings',
            data: dailyData.map(d => d.total_bookings),
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.4,
            fill: true
        }, {
            label: 'Auto-Approved',
            data: dailyData.map(d => d.auto_approved),
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Status Pie Chart
const statusCtx = document.getElementById('statusPieChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Auto-Approved', 'Manual Approved', 'Pending'],
        datasets: [{
            data: [
                <?php echo $total_auto_approved; ?>,
                <?php echo $total_approved - $total_auto_approved; ?>,
                <?php echo $total_bookings - $total_approved; ?>
            ],
            backgroundColor: [
                '#667eea',
                '#3b82f6',
                '#fbbf24'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Volume Chart
const volumeCtx = document.getElementById('volumeChart').getContext('2d');
new Chart(volumeCtx, {
    type: 'bar',
    data: {
        labels: dailyData.map(d => new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
        datasets: [{
            label: 'Daily Bookings',
            data: dailyData.map(d => d.total_bookings),
            backgroundColor: 'rgba(102, 126, 234, 0.8)',
            borderColor: '#667eea',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

function exportReport() {
    alert('📊 Exporting ML Performance Report...\n\nReport will include:\n• Daily statistics\n• Model accuracy\n• Auto-approval trends\n• Time savings analysis');
    // Implement actual export functionality
    window.location.href = 'api/export_ml_report.php';
}
</script>
</body>
</html>