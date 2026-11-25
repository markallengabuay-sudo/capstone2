<?php
session_start();

if (!isset($_SESSION['logged_in'])) {
    header("Location: ../index.php");
    exit();
}

// Database connection
require_once '../includes/db.php';

// Get cases ready for raffle (forwarded cases without criminal_case_no or with criminal_case_no but no court_branch)
$raffle_cases = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.case_number, c.nps_docket_no as nps_docket,
               c.criminal_case_no,
               comp.full_name as complainant, resp.full_name as respondent,
               c.offense, c.date_docketed, c.court_branch as assigned_branch,
               u.full_name as prosecutor
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        LEFT JOIN users u ON c.assigned_prosecutor_id = u.id
        WHERE c.status IN ('Filed in Court', 'Forwarded to Clerk', 'Assigned to Branch')
        AND (c.criminal_case_no IS NULL OR c.criminal_case_no = '' OR (c.court_branch IS NULL OR c.court_branch = 0))
        ORDER BY c.date_filed DESC
    ");
    $stmt->execute();
    $raffle_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $raffle_cases = [];
}

// Get branch statistics from database
$branches = [
    '27' => ['name' => 'RTC Branch 27', 'cases' => 0, 'judge' => 'Hon. Judge A. Santos'],
    '28' => ['name' => 'RTC Branch 28', 'cases' => 0, 'judge' => 'Hon. Judge B. Reyes'],
    '29' => ['name' => 'RTC Branch 29', 'cases' => 0, 'judge' => 'Hon. Judge C. Cruz']
];

try {
    foreach (array_keys($branches) as $branch_num) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM complaints WHERE court_branch = ?");
        $stmt->execute([$branch_num]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $branches[$branch_num]['cases'] = $result['count'];
        error_log("Branch $branch_num count: " . $result['count']);
    }
    // Debug: Log all branches data
    error_log("Branches data: " . json_encode($branches));
} catch (PDOException $e) {
    error_log("Branch statistics error: " . $e->getMessage());
    // Keep default 0 values if query fails
}

// Get selected case for raffle
$selected_case_id = isset($_GET['case_id']) ? intval($_GET['case_id']) : 0;
$selected_case = null;
foreach ($raffle_cases as $case) {
    if ($case['id'] === $selected_case_id) {
        $selected_case = $case;
        break;
    }
}

// Stats for KPI cards
$total_ready = count($raffle_cases);
$total_branches = count($branches);
$total_assigned = array_sum(array_column($branches, 'cases'));
$avg_cases = $total_branches > 0 ? round($total_assigned / $total_branches) : 0;

// Get recently raffled cases (cases with court_branch assigned)
$recent_raffles = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.criminal_case_no,
            CONCAT('People vs. ', COALESCE(resp.full_name, 'Unknown Respondent')) as title,
            c.court_branch as branch,
            COALESCE(c.date_docketed, CURDATE()) as date
        FROM complaints c
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        WHERE c.status IN ('Filed in Court', 'Forwarded to Clerk', 'Assigned to Branch', 'Archived')
        AND c.court_branch IS NOT NULL AND c.court_branch != 0
        ORDER BY c.date_docketed DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_raffles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_raffles = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raffle Cases - Clerk of Court</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-purple: #9c27b0;
            --dark-navy: #202124;
            --light-gray: #f8f9fa;
            --success-green: #0f9d58;
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

        .raffle-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            text-align: center;
        }

        .branch-wheel {
            width: 300px;
            height: 300px;
            margin: 30px auto;
            border-radius: 50%;
            background: conic-gradient(
                #9c27b0 0deg 120deg,
                #1a73e8 120deg 240deg,
                #0f9d58 240deg 360deg
            );
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .branch-wheel::before {
            content: '';
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            position: absolute;
            z-index: 10;
        }

        .branch-label {
            position: absolute;
            font-weight: bold;
            color: white;
            font-size: 18px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .branch-27 { top: 20%; right: 20%; background: #9c27b0; }
        .branch-28 { bottom: 20px; left: 50%; transform: translateX(-50%); background: #1a73e8; }
        .branch-29 { top: 20%; left: 20%; background: #0f9d58; }

        .raffle-btn {
            padding: 15px 40px;
            font-size: 18px;
            background: var(--primary-purple);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .raffle-btn:hover {
            background: #7b1fa2;
            transform: translateY(-2px);
        }

        .raffle-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .case-list {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .case-item {
            padding: 15px;
            border: 2px solid #eee;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .case-item:hover {
            border-color: var(--primary-purple);
            background: #f8f9fa;
        }

        .case-item.selected {
            border-color: var(--primary-purple);
            background: #f3e5f5;
        }

        .branch-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            text-align: center;
            border-top: 4px solid;
        }

        .branch-card i {
            font-size: 24px;
            margin-bottom: 6px;
            display: block;
        }

        .branch-card.branch-27 { border-top-color: #9c27b0; }
        .branch-card.branch-28 { border-top-color: #1a73e8; }
        .branch-card.branch-29 { border-top-color: #0f9d58; }

        .branch-tabs {
            display: flex !important;
            gap: 10px !important;
            margin-bottom: 20px !important;
        }

        .branch-tab {
            flex: 1 !important;
            padding: 20px !important;
            background: white !important;
            border: 2px solid #eee !important;
            border-radius: 8px !important;
            text-align: center !important;
            cursor: pointer !important;
            transition: all 0.3s !important;
        }

        .branch-tab:hover {
            border-color: var(--primary-purple);
            background: #f8f9fa;
        }

        .branch-tab.branch-27:hover { border-color: #9c27b0; background: #f3e5f5; }
        .branch-tab.branch-28:hover { border-color: #1a73e8; background: #e8f0fe; }
        .branch-tab.branch-29:hover { border-color: #0f9d58; background: #e8f5e9; }

        .branch-number {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .branch-tab.branch-27 .branch-number { color: #9c27b0; }
        .branch-tab.branch-28 .branch-number { color: #1a73e8; }
        .branch-tab.branch-29 .branch-number { color: #0f9d58; }

        .result-display {
            font-size: 48px;
            font-weight: bold;
            color: var(--primary-purple);
            margin: 20px 0;
        }

        .manual-select {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid #eee;
        }

        /* KPI Cards - Matching Dashboard Style */
        .kpi-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .kpi-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            border-left: 4px solid var(--primary-purple);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }

        .kpi-card.purple { border-left-color: #9c27b0; }
        .kpi-card.blue { border-left-color: #1a73e8; }
        .kpi-card.green { border-left-color: #0f9d58; }
        .kpi-card.orange { border-left-color: #f4b400; }

        .kpi-card .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #202124;
        }

        .kpi-card .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        /* Enhanced Case Item */
        .case-item {
            padding: 15px;
            border: 2px solid #eee;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
        }

        .case-item:hover {
            border-color: var(--primary-purple);
            background: #f8f9fa;
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .case-item.selected {
            border-color: var(--primary-purple);
            background: #f3e5f5;
            box-shadow: 0 4px 12px rgba(156,39,176,0.2);
        }

        .case-item .offense-tag {
            display: inline-block;
            padding: 2px 8px;
            background: #e3f2fd;
            color: #1565c0;
            border-radius: 12px;
            font-size: 11px;
            margin-top: 5px;
        }

        /* Wheel Pointer */
        .wheel-container {
            position: relative;
            display: inline-block;
        }

        .wheel-pointer {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 15px solid transparent;
            border-right: 15px solid transparent;
            border-top: 25px solid #333;
            z-index: 20;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));
        }

        /* Result Display Enhancement */
        .result-display {
            font-size: 56px;
            font-weight: bold;
            margin: 20px 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .result-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin: 15px 0;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        /* Recently Raffled Section */
        .recent-raffles {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-top: 20px;
        }

        .raffle-history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #eee;
        }

        .raffle-history-item:last-child {
            border-bottom: none;
        }

        .branch-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }

        .branch-badge.branch-27 { background: #9c27b0; }
        .branch-badge.branch-28 { background: #1a73e8; }
        .branch-badge.branch-29 { background: #0f9d58; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/navigation_clerk.php'; ?>

        <div class="main-content">
            <div class="top-header">
                <div>
                    <h2>Raffle Cases to Branches</h2>
                    <p class="text-muted mb-0">Assign docketed cases to RTC Branch 27, 28, or 29</p>
                </div>
                <a href="docket.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Docket
                </a>
            </div>

            <!-- KPI Cards -->
            <div class="kpi-cards">
                <div class="kpi-card orange">
                    <div class="stat-number" style="color: #e65100;"><?php echo $total_ready; ?></div>
                    <div class="stat-label">Cases Ready for Raffle</div>
                </div>
                <div class="kpi-card purple">
                    <div class="stat-number" style="color: #9c27b0;"><?php echo $total_assigned; ?></div>
                    <div class="stat-label">Cases Assigned Today</div>
                </div>
                <div class="kpi-card blue">
                    <div class="stat-number" style="color: #1a73e8;"><?php echo $total_branches; ?></div>
                    <div class="stat-label">Active Branches</div>
                </div>
                <div class="kpi-card green">
                    <div class="stat-number" style="color: #0f9d58;"><?php echo $avg_cases; ?></div>
                    <div class="stat-label">Avg Cases per Branch</div>
                </div>
            </div>

            <!-- Branch Summary -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px;">
                <?php foreach ($branches as $num => $branch): ?>
                <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); text-align: center; border-top: 4px solid <?php echo $num == '27' ? '#9c27b0' : ($num == '28' ? '#1a73e8' : '#0f9d58'); ?>;">
                    <i class="fas fa-building" style="font-size: 24px; margin-bottom: 6px; display: block;"></i>
                    <div class="stat-label"><?php echo $branch['name']; ?></div>
                    <div class="stat-number" style="color: <?php echo $num == '27' ? '#9c27b0' : ($num == '28' ? '#1a73e8' : '#0f9d58'); ?>;"><?php echo $branch['cases']; ?></div>
                    <div class="stat-trend"><?php echo $branch['judge']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="row mt-4">
                <!-- Left Column: Case Selection and Recently Raffled -->
                <div class="col-md-5">
                    <!-- Case Selection -->
                    <div class="case-list mb-3">
                        <h5><i class="fas fa-list" style="color: var(--primary-purple); margin-right: 8px;"></i>Select Case to Raffle</h5>
                        <p class="text-muted small">Click a case to select it for raffle</p>

                        <?php if (!empty($raffle_cases)): ?>
                        <?php foreach ($raffle_cases as $case): ?>
                        <div class="case-item <?php echo isset($case['id']) && $selected_case_id == $case['id'] ? 'selected' : ''; ?>" onclick="selectCase(<?php echo $case['id'] ?? 0; ?>)">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong><?php echo $case['criminal_case_no'] ?? 'Pending Docket'; ?></strong>
                                    <div class="offense-tag"><?php echo $case['offense'] ?? 'N/A'; ?></div>
                                </div>
                                <span class="badge bg-primary"><?php echo $case['case_number'] ?? 'N/A'; ?></span>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted"><?php echo $case['complainant'] ?? 'N/A'; ?> vs <?php echo $case['respondent'] ?? 'N/A'; ?></small><br>
                                <small class="text-muted"><i class="fas fa-gavel" style="font-size: 10px;"></i> <?php echo $case['prosecutor'] ?? 'N/A'; ?> | <?php echo $case['criminal_case_no'] ? 'Docketed: ' . date('M d, Y', strtotime($case['date_docketed'])) : 'Pending Docket'; ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-folder-open" style="font-size: 40px; margin-bottom: 10px;"></i>
                            <p>No cases found for raffle</p>
                            <p class="small">Cases must first be forwarded from Fiscal before they can be raffled to branches.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recently Raffled -->
                    <div class="case-list">
                        <h5><i class="fas fa-history" style="color: var(--primary-purple); margin-right: 8px;"></i>Recently Raffled</h5>
                        <p class="text-muted small">Cases recently assigned to branches</p>

                        <?php if (!empty($recent_raffles)): ?>
                        <?php foreach ($recent_raffles as $raffle): ?>
                        <div class="case-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong><?php echo $raffle['criminal_case_no'] ?: 'No Case Number'; ?></strong>
                                    <div class="offense-tag">Branch <?php echo $raffle['branch']; ?></div>
                                </div>
                                <span class="badge bg-success">Raffled</span>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted"><?php echo $raffle['title']; ?></small><br>
                                <small class="text-muted"><i class="fas fa-calendar" style="font-size: 10px;"></i> <?php echo date('M d, Y', strtotime($raffle['date'])); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-history" style="font-size: 40px; margin-bottom: 10px;"></i>
                            <p>No recently raffled cases</p>
                            <p class="small">Cases will appear here after being raffled to branches.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Column: Raffle Area -->
                <div class="col-md-7">
                    <div class="raffle-container">
                        <h4><i class="fas fa-random" style="color: var(--primary-purple); margin-right: 10px;"></i>Random Raffle</h4>
                        
                        <?php if ($selected_case): ?>
                        <div class="alert alert-info mb-3">
                            <strong>Selected:</strong> <?php echo $selected_case['criminal_case_no'] ?? 'Pending Docket'; ?><br>
                            <small><?php echo $selected_case['complainant'] ?? 'N/A'; ?> vs <?php echo $selected_case['respondent'] ?? 'N/A'; ?></small>
                        </div>
                        <?php endif; ?>

                        <div class="wheel-container">
                            <div class="wheel-pointer"></div>
                            <div class="branch-wheel" id="raffleWheel">
                                <div class="branch-label branch-27">27</div>
                                <div class="branch-label branch-28">28</div>
                                <div class="branch-label branch-29">29</div>
                            </div>
                        </div>

                        <div id="raffleResult" style="display: none;">
                            <div class="result-card">
                                <div class="result-display" id="resultNumber">?</div>
                                <p class="mb-2">Case assigned to <strong id="resultBranch">RTC Branch ?</strong></p>
                                <p class="mb-0"><small class="text-muted"><i class="fas fa-user-tie"></i> Hon. <span id="resultJudge">Judge</span></small></p>
                            </div>
                        </div>

                        <button class="raffle-btn" id="raffleBtn" onclick="startRaffle()" <?php echo !$selected_case ? 'disabled' : ''; ?>>
                            <i class="fas fa-play"></i> START RAFFLE
                        </button>

                        <div id="confirmSection" style="display: none;" class="mt-4">
                            <button class="btn btn-success btn-lg" onclick="confirmAssignment()">
                                <i class="fas fa-check"></i> CONFIRM ASSIGNMENT
                            </button>
                            <button class="btn btn-outline-secondary" onclick="resetRaffle()">
                                <i class="fas fa-redo"></i> Retry
                            </button>
                        </div>

                        <!-- Manual Assignment -->
                        <div class="manual-select">
                            <p class="text-muted">Or manually assign to:</p>
                            <div class="btn-group" role="group">
                                <?php foreach ($branches as $num => $branch): ?>
                                <button type="button" class="btn btn-outline-primary" onclick="manualAssign('<?php echo $num; ?>')">
                                    Branch <?php echo $num; ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedCaseId = <?php echo $selected_case_id ? $selected_case_id : 'null'; ?>;
        let assignedBranch = null;

        const branches = {
            '27': { name: 'RTC Branch 27', color: '#9c27b0', judge: 'Hon. Judge A. Santos' },
            '28': { name: 'RTC Branch 28', color: '#1a73e8', judge: 'Hon. Judge B. Reyes' },
            '29': { name: 'RTC Branch 29', color: '#0f9d58', judge: 'Hon. Judge C. Cruz' }
        };

        function selectCase(caseId) {
            window.location.href = 'raffle.php?case_id=' + caseId;
        }

        function startRaffle() {
            if (!selectedCaseId) {
                alert('Please select a case first');
                return;
            }

            const btn = document.getElementById('raffleBtn');
            const wheel = document.getElementById('raffleWheel');
            const result = document.getElementById('raffleResult');
            const confirm = document.getElementById('confirmSection');

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> RAFFLING...';
            result.style.display = 'none';
            confirm.style.display = 'none';

            // Random selection weighted by case load (fewer cases = higher chance)
            const random = Math.random();
            if (random < 0.33) assignedBranch = '27';
            else if (random < 0.66) assignedBranch = '28';
            else assignedBranch = '29';

            const branch = branches[assignedBranch];

            // Calculate target rotation to point to the selected branch
            // Branch positions: 27 = 0-120deg (purple, middle at 60deg), 28 = 120-240deg (blue, middle at 180deg), 29 = 240-360deg (green, middle at 300deg)
            // Pointer is at top pointing down (0deg), so we need to rotate the wheel to bring the MIDDLE of each sector to top
            const targetRotations = {
                '27': 300,    // Rotate 300° clockwise to bring purple middle (60deg) to top
                '28': 180,    // Rotate 180° clockwise to bring blue middle (180deg) to top
                '29': 60      // Rotate 60° clockwise to bring green middle (300deg) to top
            };

            // Add multiple full rotations (5-10) for the spin effect
            const targetRotation = targetRotations[assignedBranch] + (360 * 5);

            // Spin animation
            let rotation = 0;
            const duration = 3000; // 3 seconds
            const startTime = Date.now();
            const startRotation = 0;

            function animate() {
                const elapsed = Date.now() - startTime;
                const progress = Math.min(elapsed / duration, 1);
                
                // Easing function for smooth deceleration
                const easeOut = 1 - Math.pow(1 - progress, 3);
                rotation = startRotation + (targetRotation * easeOut);
                
                wheel.style.transform = 'rotate(' + rotation + 'deg)';

                if (progress < 1) {
                    requestAnimationFrame(animate);
                } else {
                    // Show result
                    document.getElementById('resultNumber').textContent = assignedBranch;
                    document.getElementById('resultNumber').style.color = branch.color;
                    document.getElementById('resultBranch').textContent = branch.name;
                    document.getElementById('resultJudge').textContent = branch.judge;

                    result.style.display = 'block';
                    confirm.style.display = 'block';
                    
                    btn.innerHTML = '<i class="fas fa-check"></i> COMPLETED';
                }
            }

            animate();
        }

        function manualAssign(branchNum) {
            if (!selectedCaseId) {
                alert('Please select a case first');
                return;
            }

            assignedBranch = branchNum;
            const branch = branches[branchNum];

            document.getElementById('resultNumber').textContent = branchNum;
            document.getElementById('resultNumber').style.color = branch.color;
            document.getElementById('resultBranch').textContent = branch.name;
            document.getElementById('resultJudge').textContent = branch.judge;

            document.getElementById('raffleResult').style.display = 'block';
            document.getElementById('confirmSection').style.display = 'block';
            
            document.getElementById('raffleBtn').disabled = true;
            document.getElementById('raffleBtn').innerHTML = '<i class="fas fa-check"></i> MANUAL ASSIGNMENT';
            
            // Auto-save manual assignment
            const formData = new FormData();
            formData.append('action', 'assign_branch');
            formData.append('case_id', selectedCaseId);
            formData.append('branch', branchNum);

            fetch('api_clerk_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('SUCCESS!\n\nCase assigned to:\n' + branch.name + '\n' + branch.judge + '\n\nThe case will now appear in the branch docket.');
                    window.location.href = 'docket.php';
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error assigning branch: ' + error);
            });
        }

        function confirmAssignment() {
            if (!assignedBranch || !selectedCaseId) return;

            const branch = branches[assignedBranch];
            
            // Send data to backend API
            const formData = new FormData();
            formData.append('action', 'assign_branch');
            formData.append('case_id', selectedCaseId);
            formData.append('branch', assignedBranch);

            fetch('api_clerk_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('SUCCESS!\n\nCase assigned to:\n' + branch.name + '\n' + branch.judge + '\n\nThe case will now appear in the branch docket.');
                    window.location.href = 'docket.php';
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error assigning branch: ' + error);
            });
        }

        function resetRaffle() {
            assignedBranch = null;
            document.getElementById('raffleResult').style.display = 'none';
            document.getElementById('confirmSection').style.display = 'none';
            document.getElementById('raffleBtn').disabled = false;
            document.getElementById('raffleBtn').innerHTML = '<i class="fas fa-play"></i> START RAFFLE';
            document.getElementById('raffleWheel').style.transform = 'rotate(0deg)';
        }
    </script>
</body>
</html>
