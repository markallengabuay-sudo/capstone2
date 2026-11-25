<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: ../index.php");
    exit();
}

// Database connection
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'fiscal_system';

$conn = new mysqli($host, $username, $password, $database);

// Get dashboard statistics (using defaults if DB not ready)
$total_cases = 0;
$new_submissions = 0;
$under_investigation = 0;
$for_resolution = 0;
$filed_in_court = 0;
$dismissed_cases = 0;

if (!$conn->connect_error) {
    $total_cases = $conn->query("SELECT COUNT(*) as count FROM complaints")->fetch_assoc()['count'] ?? 0;
    $new_submissions = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE status = 'New'") ->fetch_assoc()['count'] ?? 0;
    $under_investigation = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE status = 'Under Investigation'") ->fetch_assoc()['count'] ?? 0;
    $for_resolution = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE status = 'For Resolution'") ->fetch_assoc()['count'] ?? 0;
    $filed_in_court = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE status IN ('Filed in Court', 'Forwarded to Clerk')") ->fetch_assoc()['count'] ?? 0;
    $dismissed_cases = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE status = 'Dismissed'") ->fetch_assoc()['count'] ?? 0;
    
    // Get case status distribution for chart
    $status_distribution = $conn->query("
        SELECT status, COUNT(*) as count 
        FROM complaints 
        GROUP BY status
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Get monthly case trends for chart (all 12 months)
    $monthly_trends = $conn->query("
        SELECT DATE_FORMAT(date_received, '%M') as month_name, COUNT(*) as count 
        FROM complaints 
        WHERE date_received >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(date_received, '%M'), MONTH(date_received)
        ORDER BY MONTH(date_received) ASC
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Get offense type distribution
    $offense_distribution = $conn->query("
        SELECT offense, COUNT(*) as count 
        FROM complaints 
        GROUP BY offense 
        ORDER BY count DESC 
        LIMIT 10
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Get case resolution time analysis
    $resolution_time = $conn->query("
        SELECT 
            AVG(DATEDIFF(COALESCE(resolution_date, CURDATE()), date_received)) as avg_days,
            MIN(DATEDIFF(COALESCE(resolution_date, CURDATE()), date_received)) as min_days,
            MAX(DATEDIFF(COALESCE(resolution_date, CURDATE()), date_received)) as max_days
        FROM complaints 
        WHERE status IN ('Filed in Court', 'Dismissed', 'Archived') AND resolution_date IS NOT NULL
    ")->fetch_assoc();
    
    // Get recent cases
    $recent_cases_query = $conn->query("
        SELECT c.case_number, c.nps_docket_no, c.offense, c.status, c.date_received,
               comp.full_name as complainant, resp.full_name as respondent
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        ORDER BY c.date_received DESC
        LIMIT 5
    ");
    $recent_cases = [];
    while ($row = $recent_cases_query->fetch_assoc()) {
        $recent_cases[] = $row;
    }
    $conn->close();
} else {
    $recent_cases = [];
    $status_distribution = [];
    $monthly_trends = [];
    $offense_distribution = [];
    $resolution_time = ['avg_days' => 0, 'min_days' => 0, 'max_days' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiscal Dashboard - Office of the Prosecutor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-blue: #1a73e8;
            --danger-red: #d93025;
            --success-green: #0f9d58;
            --warning-yellow: #f4b400;
            --purple: #9c27b0;
            --dark-navy: #202124;
            --light-gray: #f8f9fa;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-gray);
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 15px;
        }

        .top-header {
            background: white;
            padding: 12px 20px;
            border-radius: 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .kpi-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 15px;
        }

        .kpi-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            text-align: center;
            border-top: 4px solid;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }

        .kpi-card i {
            font-size: 28px;
            margin-bottom: 8px;
            display: block;
        }

        .kpi-card .stat-label {
            font-size: 11px;
            color: #666;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .kpi-card .stat-number {
            font-size: 36px;
            font-weight: 700;
            margin: 5px 0;
            color: #333;
            line-height: 1;
        }

        .kpi-card .stat-trend {
            font-size: 11px;
            color: #888;
            margin-top: 8px;
            padding: 4px 10px;
            background: #f5f5f5;
            border-radius: 12px;
            display: inline-block;
        }

        /* Color variants */
        .kpi-card.blue { border-top-color: #3498db; }
        .kpi-card.green { border-top-color: #2ecc71; }
        .kpi-card.orange { border-top-color: #f39c12; }
        .kpi-card.purple { border-top-color: #9b59b6; }
        .kpi-card.teal { border-top-color: #1abc9c; }
        .kpi-card.red { border-top-color: #e74c3c; }

        .quick-actions {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 15px;
        }

        .quick-actions h5 {
            margin-bottom: 12px;
            color: #333;
            font-weight: 600;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--primary-blue);
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-right: 10px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }

        .action-btn:hover {
            background: #1557b0;
            color: white;
        }

        .recent-cases {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }

        .recent-cases h5 {
            margin-bottom: 12px;
            color: #333;
            font-weight: 600;
        }

        .case-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .case-item:last-child {
            border-bottom: none;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }

        .status-new { background: #e3f2fd; color: #1565c0; }
        .status-investigation { background: #fff3e0; color: #e65100; }
        .status-resolution { background: #fce4ec; color: #c2185b; }
        .status-filed { background: #e8f5e9; color: #2e7d32; }
        .status-dismissed { background: #f5f5f5; color: #616161; }

        .charts-section {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .charts-row {
            display: grid;
            gap: 15px;
        }

        .charts-row.full-width {
            grid-template-columns: 1fr;
        }

        .charts-row.three-columns {
            grid-template-columns: repeat(3, 1fr);
        }

        .chart-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }

        .chart-card h5 {
            margin-bottom: 12px;
            color: #333;
            font-weight: 600;
        }

        .chart-container {
            position: relative;
            height: 280px;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/navigation_fiscal.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-header">
                <div>
                    <h2>Fiscal Dashboard</h2>
                    <p class="text-muted mb-0">Welcome, <?php echo $_SESSION['full_name'] ?? 'Fiscal Officer'; ?></p>
                </div>
                <div class="user-info">
                    <i class="fas fa-user-circle" style="font-size: 30px; color: var(--primary-blue);"></i>
                    <div>
                        <div><?php echo $_SESSION['full_name'] ?? 'Fiscal Officer'; ?></div>
                        <small class="text-muted">City Prosecutor</small>
                    </div>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="kpi-cards">
                <div class="kpi-card blue">
                    <i class="fas fa-folder" style="color: #3498db;"></i>
                    <div class="stat-label">Total Cases</div>
                    <div class="stat-number" style="color: #3498db;"><?php echo $total_cases; ?></div>
                    <div class="stat-trend"><?php echo $total_cases; ?> cases</div>
                </div>
                <div class="kpi-card green">
                    <i class="fas fa-file-import" style="color: #2ecc71;"></i>
                    <div class="stat-label">New Submissions</div>
                    <div class="stat-number" style="color: #2ecc71;"><?php echo $new_submissions; ?></div>
                    <div class="stat-trend">+<?php echo $new_submissions; ?> new</div>
                </div>
                <div class="kpi-card orange">
                    <i class="fas fa-search" style="color: #f39c12;"></i>
                    <div class="stat-label">Under Investigation</div>
                    <div class="stat-number" style="color: #f39c12;"><?php echo $under_investigation; ?></div>
                    <div class="stat-trend"><?php echo $under_investigation; ?> active</div>
                </div>
                <div class="kpi-card purple">
                    <i class="fas fa-file-signature" style="color: #9b59b6;"></i>
                    <div class="stat-label">For Resolution</div>
                    <div class="stat-number" style="color: #9b59b6;"><?php echo $for_resolution; ?></div>
                    <div class="stat-trend"><?php echo $for_resolution; ?> pending</div>
                </div>
                <div class="kpi-card teal">
                    <i class="fas fa-gavel" style="color: #1abc9c;"></i>
                    <div class="stat-label">Filed in Court</div>
                    <div class="stat-number" style="color: #1abc9c;"><?php echo $filed_in_court; ?></div>
                    <div class="stat-trend">This Month</div>
                </div>
                <div class="kpi-card red">
                    <i class="fas fa-times-circle" style="color: #e74c3c;"></i>
                    <div class="stat-label">Dismissed Cases</div>
                    <div class="stat-number" style="color: #e74c3c;"><?php echo $dismissed_cases; ?></div>
                    <div class="stat-trend"><?php echo $dismissed_cases; ?> closed</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h5><i class="fas fa-bolt" style="color: var(--primary-blue); margin-right: 8px;"></i>Quick Actions</h5>
                <a href="case_intake.php" class="action-btn"><i class="fas fa-plus"></i> New Case Intake</a>
                <a href="investigation.php" class="action-btn"><i class="fas fa-search"></i> Pending Investigation</a>
                <a href="resolution.php" class="action-btn"><i class="fas fa-file-signature"></i> For Resolution</a>
                <a href="court_forwarding.php" class="action-btn"><i class="fas fa-paper-plane"></i> Forward to Court</a>
            </div>

            <!-- Charts Section -->
            <div class="charts-section">
                <div class="charts-row three-columns">
                    <div class="chart-card">
                        <h5><i class="fas fa-chart-pie" style="color: var(--primary-blue); margin-right: 8px;"></i>Case Status Distribution</h5>
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h5><i class="fas fa-chart-line" style="color: var(--primary-blue); margin-right: 8px;"></i>Monthly Case Trends</h5>
                        <div class="chart-container">
                            <canvas id="trendsChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h5><i class="fas fa-clock" style="color: var(--primary-blue); margin-right: 8px;"></i>Case Resolution Time</h5>
                        <div class="chart-container">
                            <canvas id="resolutionTimeChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="charts-row full-width">
                    <div class="chart-card">
                        <h5><i class="fas fa-chart-bar" style="color: var(--primary-blue); margin-right: 8px;"></i>Top Offense Types</h5>
                        <div class="chart-container">
                            <canvas id="offenseChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Cases -->
            <div class="recent-cases">
                <h5><i class="fas fa-list" style="color: var(--primary-blue); margin-right: 8px;"></i>Recent Cases</h5>
                <?php if (!empty($recent_cases)): ?>
                    <?php foreach ($recent_cases as $case): ?>
                        <div class="case-item">
                            <div>
                                <strong>Case #<?php echo $case['case_number']; ?></strong> | <small class="text-muted"><?php echo $case['nps_docket_no']; ?></small> - <?php echo $case['offense']; ?>
                                <div class="text-muted" style="font-size: 12px;"><?php echo $case['complainant']; ?> vs <?php echo $case['respondent']; ?> | Received: <?php echo date('M d, Y', strtotime($case['date_received'])); ?></div>
                            </div>
                            <?php
                            $status_class = 'status-new';
                            if ($case['status'] === 'Under Investigation') $status_class = 'status-investigation';
                            elseif ($case['status'] === 'For Resolution') $status_class = 'status-resolution';
                            elseif ($case['status'] === 'Filed in Court') $status_class = 'status-filed';
                            elseif ($case['status'] === 'Dismissed') $status_class = 'status-dismissed';
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>"><?php echo $case['status']; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-folder-open" style="font-size: 40px; margin-bottom: 10px;"></i>
                        <p>No recent cases found</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Case Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusData = <?php echo json_encode($status_distribution); ?>;
        const statusLabels = statusData.map(item => item.status);
        const statusCounts = statusData.map(item => item.count);
        
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusCounts,
                    backgroundColor: [
                        '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e74c3c', '#95a5a6'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Monthly Case Trends Chart
        const trendsCtx = document.getElementById('trendsChart').getContext('2d');
        const trendsData = <?php echo json_encode($monthly_trends); ?>;
        
        // Get all 12 months
        const allMonths = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        
        // Create a map of month names to counts
        const monthCounts = {};
        trendsData.forEach(item => {
            monthCounts[item.month_name] = item.count;
        });
        
        // Create labels and data arrays for all 12 months
        const trendLabels = allMonths;
        const trendCounts = allMonths.map(month => monthCounts[month] || 0);
        
        new Chart(trendsCtx, {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Cases',
                    data: trendCounts,
                    backgroundColor: 'rgba(156, 39, 176, 0.7)',
                    borderColor: '#9c27b0',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    },
                    x: {
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        // Offense Type Distribution Chart
        const offenseCtx = document.getElementById('offenseChart').getContext('2d');
        const offenseData = <?php echo json_encode($offense_distribution); ?>;
        const offenseLabels = offenseData.map(item => item.offense);
        const offenseCounts = offenseData.map(item => item.count);
        
        new Chart(offenseCtx, {
            type: 'bar',
            data: {
                labels: offenseLabels,
                datasets: [{
                    label: 'Cases',
                    data: offenseCounts,
                    backgroundColor: '#3498db',
                    borderColor: '#2980b9',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        // Case Resolution Time Chart
        const resolutionCtx = document.getElementById('resolutionTimeChart').getContext('2d');
        const resolutionData = <?php echo json_encode($resolution_time); ?>;
        
        new Chart(resolutionCtx, {
            type: 'bar',
            data: {
                labels: ['Average', 'Minimum', 'Maximum'],
                datasets: [{
                    label: 'Days',
                    data: [
                        Math.round(resolutionData.avg_days || 0),
                        Math.round(resolutionData.min_days || 0),
                        Math.round(resolutionData.max_days || 0)
                    ],
                    backgroundColor: ['#3498db', '#2ecc71', '#e74c3c'],
                    borderColor: ['#2980b9', '#27ae60', '#c0392b'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        title: {
                            display: true,
                            text: 'Days'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
