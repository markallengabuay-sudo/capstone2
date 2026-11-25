<?php
session_start();

if (!isset($_SESSION['logged_in'])) {
    header("Location: ../index.php");
    exit();
}

// Include database connection
require_once '../includes/db.php';

// Get cases from database
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.case_number, c.nps_docket_no as nps_docket,
               GROUP_CONCAT(DISTINCT comp.full_name) as complainant,
               GROUP_CONCAT(DISTINCT resp.full_name) as respondent,
               resp.status as respondent_status, resp.arrest_date, resp.detention_location,
               c.offense, c.law_statute as law_violated,
               c.date_received, c.time_received, c.status, c.priority,
               u.full_name as assigned_prosecutor
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        LEFT JOIN users u ON c.assigned_prosecutor_id = u.id
        GROUP BY c.id
        ORDER BY c.date_filed DESC
    ");
    $stmt->execute();
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Remove any duplicates at PHP level as safety measure
    $case_ids = array_column($cases, 'id');
    if (count($case_ids) !== count(array_unique($case_ids))) {
        $cases = array_values(array_unique($cases, SORT_REGULAR));
    }
} catch (PDOException $e) {
    $cases = [];
}

// Filter by status if provided
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
if ($filter_status) {
    $cases = array_filter($cases, function($case) use ($filter_status) {
        return $case['status'] === $filter_status;
    });
}

// Search functionality
$search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
if ($search) {
    $cases = array_filter($cases, function($case) use ($search) {
        $found = false;
        foreach (['case_number', 'nps_docket', 'complainant', 'respondent', 'offense'] as $field) {
            if (strpos(strtolower($case[$field] ?? ''), $search) !== false) {
                $found = true;
                break;
            }
        }
        return $found;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Case List - Office of the Prosecutor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            padding: 20px;
        }

        .top-header {
            background: white;
            padding: 15px 20px;
            border-radius: 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .filter-bar {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-btn {
            padding: 8px 20px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: #333;
            font-size: 14px;
        }

        .filter-btn.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .filter-btn:hover {
            background: #f0f0f0;
            text-decoration: none;
        }

        .filter-btn.active:hover {
            background: #1557b0;
        }

        .case-table {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .case-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .case-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 14px;
            border-bottom: 2px solid #eee;
        }

        .case-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        .case-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-new { background: #e3f2fd; color: #1565c0; }
        .status-investigation { background: #fff3e0; color: #e65100; }
        .status-resolution { background: #fce4ec; color: #c2185b; }
        .status-filed { background: #e8f5e9; color: #2e7d32; }
        .status-dismissed { background: #f5f5f5; color: #616161; }

        .priority-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }

        .priority-normal { background: #e8f5e9; color: #2e7d32; }
        .priority-high { background: #fff3e0; color: #e65100; }
        .priority-urgent { background: #ffebee; color: #c62828; }

        .action-btn {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }

        .btn-investigate {
            background: var(--warning-yellow);
            color: white;
        }

        .btn-resolve {
            background: var(--purple);
            color: white;
        }

        .btn-forward {
            background: var(--success-green);
            color: white;
        }

        .btn-view {
            background: var(--primary-blue);
            color: white;
        }

        .btn-delete {
            background: var(--danger-red);
            color: white;
        }

        .action-btn:hover {
            opacity: 0.9;
        }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 300px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border: 1px solid #ddd;
            border-radius: 20px;
            font-size: 14px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/navigation_fiscal.php'; ?>

        <div class="main-content">
            <div class="top-header">
                <div>
                    <h2>Case List</h2>
                    <p class="text-muted mb-0">View and manage all cases</p>
                </div>
                <a href="case_intake.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Case Intake
                </a>
            </div>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($cases); ?></div>
                    <div class="stat-label">Total Cases</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #1565c0;"><?php echo count(array_filter($cases, fn($c) => $c['status'] === 'New')); ?></div>
                    <div class="stat-label">New</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #e65100;"><?php echo count(array_filter($cases, fn($c) => $c['status'] === 'Under Investigation')); ?></div>
                    <div class="stat-label">Under Investigation</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #c2185b;"><?php echo count(array_filter($cases, fn($c) => $c['status'] === 'For Resolution')); ?></div>
                    <div class="stat-label">For Resolution</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #2e7d32;"><?php echo count(array_filter($cases, fn($c) => $c['status'] === 'Filed in Court')); ?></div>
                    <div class="stat-label">Filed in Court</div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <form method="GET" action="">
                        <input type="text" name="search" placeholder="Search case #, docket, names..." value="<?php echo htmlspecialchars($search); ?>">
                    </form>
                </div>
                
                <a href="cases.php" class="filter-btn <?php echo !$filter_status ? 'active' : ''; ?>">All</a>
                <a href="cases.php?status=New" class="filter-btn <?php echo $filter_status === 'New' ? 'active' : ''; ?>">New</a>
                <a href="cases.php?status=Under Investigation" class="filter-btn <?php echo $filter_status === 'Under Investigation' ? 'active' : ''; ?>">Investigation</a>
                <a href="cases.php?status=For Resolution" class="filter-btn <?php echo $filter_status === 'For Resolution' ? 'active' : ''; ?>">Resolution</a>
                <a href="cases.php?status=Forwarded to Clerk" class="filter-btn <?php echo $filter_status === 'Forwarded to Clerk' ? 'active' : ''; ?>">Forwarded to Clerk</a>
                <a href="cases.php?status=Filed in Court" class="filter-btn <?php echo $filter_status === 'Filed in Court' ? 'active' : ''; ?>">Filed in Court</a>
                <a href="cases.php?status=Dismissed" class="filter-btn <?php echo $filter_status === 'Dismissed' ? 'active' : ''; ?>">Dismissed</a>
            </div>

            <!-- Case Table -->
            <div class="case-table">
                <table>
                    <thead>
                        <tr>
                            <th>Case Details</th>
                            <th>Parties</th>
                            <th>Offense / Law</th>
                            <th>Date Received</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $case): ?>
                        <tr>
                            <td>
                                <strong><?php echo $case['case_number']; ?></strong><br>
                                <small class="text-muted"><?php echo $case['nps_docket']; ?></small>
                            </td>
                            <td>
                                <div><i class="fas fa-user text-primary"></i> <?php echo $case['complainant']; ?></div>
                                <div><i class="fas fa-user-slash text-danger"></i> <?php echo $case['respondent']; ?></div>
                                <?php if ($case['respondent_status']): ?>
                                <small class="badge bg-<?php 
                                    echo match($case['respondent_status']) {
                                        'arrested' => 'danger',
                                        'detained' => 'warning',
                                        'on_bail' => 'info',
                                        'at_large' => 'secondary',
                                        'deceased' => 'dark',
                                        default => 'secondary'
                                    };
                                ?> text-capitalize" style="font-size: 10px;">
                                    <?php echo str_replace('_', ' ', $case['respondent_status']); ?>
                                </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo $case['offense']; ?></div>
                                <small class="text-muted"><?php echo strtoupper($case['law_violated']); ?></small>
                            </td>
                            <td>
                                <?php echo date('M d, Y', strtotime($case['date_received'])); ?><br>
                                <small class="text-muted"><?php echo $case['time_received']; ?></small>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $case['status'])); ?>">
                                    <?php echo $case['status']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="priority-badge priority-<?php echo $case['priority']; ?>">
                                    <?php echo ucfirst($case['priority']); ?>
                                </span>
                            </td>
                            <td><?php echo $case['assigned_prosecutor']; ?></td>
                            <td>
                                <?php if ($case['status'] === 'Pending' || $case['status'] === 'New' || $case['status'] === 'Under Investigation'): ?>
                                    <a href="investigation.php?case_id=<?php echo $case['id']; ?>" class="action-btn btn-investigate">
                                        <i class="fas fa-search"></i> Investigate
                                    </a>
                                <?php elseif ($case['status'] === 'For Resolution'): ?>
                                    <a href="resolution.php?case_id=<?php echo $case['id']; ?>" class="action-btn btn-resolve">
                                        <i class="fas fa-file-signature"></i> Resolve
                                    </a>
                                <?php elseif ($case['status'] === 'Forwarded to Clerk' || $case['status'] === 'Filed in Court'): ?>
                                    <a href="court_forwarding.php?case_id=<?php echo $case['id']; ?>" class="action-btn btn-forward">
                                        <i class="fas fa-gavel"></i> View Filing
                                    </a>
                                <?php else: ?>
                                    <a href="investigation.php?case_id=<?php echo $case['id']; ?>" class="action-btn btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                <?php endif; ?>
                                <button onclick="deleteCase(<?php echo $case['id']; ?>)" class="action-btn btn-delete ms-1">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (empty($cases)): ?>
            <div class="text-center py-5">
                <i class="fas fa-folder-open" style="font-size: 48px; color: #ddd;"></i>
                <p class="text-muted mt-3">No cases found matching your criteria.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function deleteCase(caseId) {
            if (confirm('Are you sure you want to delete this case? This will also delete all associated documents and cannot be undone.')) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=delete_case&case_id=' + caseId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Case deleted successfully');
                        location.reload();
                    } else {
                        alert('Error deleting case: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error: ' + error);
                });
            }
        }
    </script>
</body>
</html>
