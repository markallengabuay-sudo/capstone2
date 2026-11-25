<?php
session_start();

if (!isset($_SESSION['logged_in'])) {
    header("Location: ../index.php");
    exit();
}

// Database connection
require_once '../includes/db.php';

// Get case_id if viewing specific case
$view_case_id = isset($_GET['case_id']) ? intval($_GET['case_id']) : 0;

// Get cases from Fiscal (cases with 'Forwarded to Clerk' or 'Filed in Court' status)
$cases = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.case_number, c.nps_docket_no as nps_docket,
               comp.full_name as complainant_name, comp.age as complainant_age, comp.sex as complainant_sex,
               resp.full_name as respondent_name, resp.age as respondent_age, resp.sex as respondent_sex, resp.status as respondent_status,
               c.offense, c.law_statute as law_violated,
               c.date_filed as date_forwarded, c.time_received as time_forwarded,
               u.full_name as assigned_prosecutor,
               c.incident_date, c.incident_time, c.incident_place,
               c.case_summary,
               c.status,
               c.criminal_case_no, c.date_docketed, c.court_branch as assigned_branch
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        LEFT JOIN users u ON c.assigned_prosecutor_id = u.id
        WHERE c.status IN ('Forwarded to Clerk', 'Filed in Court', 'Assigned to Branch', 'Archived')
        ORDER BY c.date_filed DESC
    ");
    $stmt->execute();
    $all_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Docket query returned " . count($all_cases) . " cases");

    // Re-index by id for compatibility
    foreach ($all_cases as $case) {
        $cases[$case['id']] = $case;
    }
} catch (PDOException $e) {
    error_log("Docket query error: " . $e->getMessage());
    $cases = [];
}

// Get specific case for viewing
$view_case = $view_case_id && isset($cases[$view_case_id]) ? $cases[$view_case_id] : null;

// Filter status
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
if ($filter_status) {
    if ($filter_status === 'Pending Docket') {
        $cases = array_filter($cases, fn($c) => !$c['criminal_case_no']);
    } elseif ($filter_status === 'Docketed') {
        $cases = array_filter($cases, fn($c) => $c['criminal_case_no'] && (!$c['assigned_branch'] || $c['assigned_branch'] == 0));
    } elseif ($filter_status === 'Raffled') {
        $cases = array_filter($cases, fn($c) => $c['assigned_branch'] && $c['assigned_branch'] != 0);
    }
}

// Stats
$pending_count = count(array_filter($cases, fn($c) => !$c['criminal_case_no']));
$docketed_count = count(array_filter($cases, fn($c) => $c['criminal_case_no'] && (!$c['assigned_branch'] || $c['assigned_branch'] == 0)));
$raffled_count = count(array_filter($cases, fn($c) => $c['assigned_branch'] && $c['assigned_branch'] != 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Docket List - Clerk of Court</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-purple: #9c27b0;
            --dark-navy: #202124;
            --light-gray: #f8f9fa;
            --success-green: #0f9d58;
            --warning-yellow: #f4b400;
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
            background: var(--primary-purple);
            color: white;
            border-color: var(--primary-purple);
        }

        .docket-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .docket-table {
            width: 100%;
            border-collapse: collapse;
        }

        .docket-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 14px;
            border-bottom: 2px solid #eee;
        }

        .docket-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        .docket-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending { background: #fff3e0; color: #e65100; }
        .status-docketed { background: #e3f2fd; color: #1565c0; }
        .status-raffled { background: #e8f5e9; color: #2e7d32; }

        .action-btn {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }

        .btn-docket {
            background: var(--primary-purple);
            color: white;
        }

        .btn-docket:hover {
            background: #7b1fa2;
        }

        .btn-view {
            background: #e3f2fd;
            color: #1565c0;
        }

        .kpi-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .kpi-card.red { border-left-color: #d93025; }

        .kpi-card .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #202124;
        }

        .kpi-card .stat-label {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/navigation_clerk.php'; ?>

        <div class="main-content">
            <div class="top-header">
                <div>
                    <h2>Docket List</h2>
                    <p class="text-muted mb-0">Record and manage cases forwarded from Fiscal</p>
                </div>
            </div>

            <!-- KPI Stats -->
            <div class="kpi-cards">
                <div class="kpi-card orange">
                    <div class="stat-number" style="color: #e65100;"><?php echo $pending_count; ?></div>
                    <div class="stat-label">Pending Docket</div>
                </div>
                <div class="kpi-card blue">
                    <div class="stat-number" style="color: #1565c0;"><?php echo $docketed_count; ?></div>
                    <div class="stat-label">Docketed</div>
                </div>
                <div class="kpi-card green">
                    <div class="stat-number" style="color: #2e7d32;"><?php echo $raffled_count; ?></div>
                    <div class="stat-label">Raffled</div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <a href="docket.php" class="filter-btn <?php echo !$filter_status ? 'active' : ''; ?>">All</a>
                <a href="docket.php?status=Pending Docket" class="filter-btn <?php echo $filter_status === 'Pending Docket' ? 'active' : ''; ?>">Pending Docket</a>
                <a href="docket.php?status=Docketed" class="filter-btn <?php echo $filter_status === 'Docketed' ? 'active' : ''; ?>">Docketed</a>
                <a href="docket.php?status=Raffled" class="filter-btn <?php echo $filter_status === 'Raffled' ? 'active' : ''; ?>">Raffled</a>
            </div>

            <!-- Docket Table -->
            <div class="docket-card">
                <table class="docket-table">
                    <thead>
                        <tr>
                            <th>Case Details</th>
                            <th>Parties</th>
                            <th>Offense</th>
                            <th>Date Forwarded</th>
                            <th>Criminal Case No.</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $id => $case): ?>
                        <tr>
                            <td>
                                <strong><?php echo $case['case_number']; ?></strong><br>
                                <small class="text-muted"><?php echo $case['nps_docket']; ?></small><br>
                                <small class="text-muted">Prosecutor: <?php echo $case['assigned_prosecutor']; ?></small>
                            </td>
                            <td>
                                <div><i class="fas fa-user text-primary"></i> <?php echo $case['complainant_name']; ?></div>
                                <div><i class="fas fa-user-slash text-danger"></i> <?php echo $case['respondent_name']; ?></div>
                            </td>
                            <td>
                                <div><?php echo $case['offense']; ?></div>
                                <small class="text-muted"><?php echo $case['law_violated']; ?></small>
                            </td>
                            <td>
                                <?php echo date('M d, Y', strtotime($case['date_forwarded'])); ?><br>
                                <small class="text-muted"><?php echo $case['time_forwarded']; ?></small>
                            </td>
                            <td>
                                <?php if ($case['criminal_case_no']): ?>
                                    <strong><?php echo $case['criminal_case_no']; ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($case['assigned_branch'] && $case['assigned_branch'] != 0): ?>
                                    <span class="status-badge status-raffled">
                                        Assigned to Branch <?php echo $case['assigned_branch']; ?>
                                    </span>
                                <?php elseif ($case['criminal_case_no']): ?>
                                    <span class="status-badge status-docketed">
                                        Docketed
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-pending">
                                        Pending Docket
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="action-btn btn-view mb-1" data-bs-toggle="modal" data-bs-target="#docModal" onclick="viewDocument(<?php echo $id; ?>, 'criminal_info')">
                                    <i class="fas fa-eye"></i> View Docs
                                </button><br>
                                <?php if (!$case['assigned_branch'] || $case['assigned_branch'] == 0): ?>
                                <button class="action-btn btn-docket" data-bs-toggle="modal" data-bs-target="#docketModal" onclick="prepareDocket(<?php echo $id; ?>)">
                                    <i class="fas fa-plus"></i> Docket
                                </button>
                                <?php else: ?>
                                <button class="action-btn btn-view" disabled style="opacity: 0.5; cursor: not-allowed;">
                                    <i class="fas fa-check"></i> Assigned
                                </button>
                                <?php endif; ?>
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

            <!-- Docket Modal -->
            <div class="modal fade" id="docketModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Record in Docket</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="docketForm">
                                <input type="hidden" id="docketCaseId">
                                <div class="mb-3">
                                    <label class="form-label">Criminal Case Number <small class="text-muted">(Optional - will be auto-generated if blank)</small></label>
                                    <input type="text" class="form-control" id="criminalCaseNo" placeholder="Leave blank for auto-generation (e.g., Crim-2024-001)">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Date Docketed</label>
                                    <input type="date" class="form-control" id="dateDocketed" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Receiving Clerk</label>
                                    <input type="text" class="form-control" value="<?php echo $_SESSION['full_name'] ?? 'Clerk of Court'; ?>" readonly>
                                </div>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> After docketing, the case will be ready for raffle to Branch 27, 28, or 29.
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-docket" onclick="saveDocket()">
                                <i class="fas fa-save"></i> Record Docket
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Document Viewer Modal -->
            <div class="modal fade" id="docModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="docTitle">Document Viewer</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="docContent">
                            <!-- Content loaded dynamically -->
                        </div>
                        <div class="modal-footer">
                            <div class="btn-group me-auto">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="showDocument('criminal_info')">
                                    <i class="fas fa-file-alt"></i> Criminal Info
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="showDocument('resolution')">
                                    <i class="fas fa-file-signature"></i> Resolution
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="showDocument('affidavit')">
                                    <i class="fas fa-file-pdf"></i> Affidavit
                                </button>
                            </div>
                            <button type="button" class="btn btn-outline-primary" onclick="printDocument()">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function prepareDocket(caseId) {
            document.getElementById('docketCaseId').value = caseId;
        }

        function saveDocket() {
            const caseId = document.getElementById('docketCaseId').value;
            const criminalCaseNo = document.getElementById('criminalCaseNo').value;
            const dateDocketed = document.getElementById('dateDocketed').value;

            if (!dateDocketed) {
                alert('Please fill in the date');
                return;
            }

            // Send data to backend API (criminal case number will be auto-generated if empty)
            const formData = new FormData();
            formData.append('action', 'save_docket');
            formData.append('case_id', caseId);
            formData.append('criminal_case_no', criminalCaseNo);
            formData.append('date_docketed', dateDocketed);

            fetch('api_clerk_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const caseNo = data.criminal_case_no || criminalCaseNo;
                    alert('Case recorded in docket successfully!\n\nCriminal Case No.: ' + caseNo + '\nDate: ' + dateDocketed + '\n\nNext: Proceed to Raffle to assign to Branch 27, 28, or 29.');
                    // Reload page to show updated data
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error saving docket: ' + error);
            });
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('docketModal'));
            modal.hide();
        }

        // Case data from PHP
        const casesData = <?php echo json_encode($cases); ?>;
        let currentCaseId = null;

        function viewDocument(caseId, type) {
            currentCaseId = caseId;
            showDocument(type);
        }

        function showDocument(type) {
            if (!currentCaseId || !casesData[currentCaseId]) return;
            
            const caseData = casesData[currentCaseId];
            const title = document.getElementById('docTitle');
            const content = document.getElementById('docContent');
            
            if (type === 'criminal_info') {
                title.textContent = 'Criminal Information - ' + caseData.case_number;
                content.innerHTML = `
                    <div style="font-family: 'Times New Roman', serif; line-height: 1.6; padding: 20px;">
                        <div class="text-center mb-4">
                            <h6>REPUBLIC OF THE PHILIPPINES</h6>
                            <p class="small">REGIONAL TRIAL COURT</p>
                            <p class="small">Catbalogan City</p>
                            <h5 class="mt-3">CRIMINAL INFORMATION</h5>
                            <p class="small">Crim. Case No. ${caseData.criminal_case_no || '________'}</p>
                        </div>
                        <p>THE PEOPLE OF THE PHILIPPINES,</p>
                        <p style="text-align: center;">- versus -</p>
                        <p style="text-align: right;"><strong>${caseData.respondent_name}</strong></p>
                        <p style="text-align: right;">Accused.</p>
                        <hr>
                        <p><strong>FOR: ${caseData.offense.toUpperCase()}</strong> (Violation of ${caseData.law_violated})</p>
                        <p>The undersigned accuses ${caseData.respondent_name} of the crime of ${caseData.offense} committed as follows:</p>
                        <p>That on or about ${caseData.incident_date}, in Catbalogan City, Philippines, the said accused did then and there willfully, unlawfully and feloniously ${caseData.case_summary}, thereby committing the crime of ${caseData.offense}.</p>
                        <p>CONTRARY TO LAW.</p>
                        <div class="mt-4 pt-4 border-top">
                            <p class="mb-0"><strong>${caseData.assigned_prosecutor}</strong></p>
                            <p class="small text-muted">City Prosecutor/Representative</p>
                            <p class="small">Office of the City Prosecutor</p>
                            <p class="small">Catbalogan City</p>
                        </div>
                    </div>
                `;
            } else if (type === 'resolution') {
                title.textContent = 'Resolution - ' + caseData.case_number;
                content.innerHTML = `
                    <div style="font-family: 'Times New Roman', serif; line-height: 1.6; padding: 20px;">
                        <div class="text-center mb-4">
                            <h6>REPUBLIC OF THE PHILIPPINES</h6>
                            <p class="small">OFFICE OF THE CITY PROSECUTOR</p>
                            <p class="small">Catbalogan City</p>
                            <h5 class="mt-3">RESOLUTION</h5>
                            <p class="small">I.S. No. ${caseData.case_number}</p>
                        </div>
                        <p><strong>COMPLAINANT:</strong> ${caseData.complainant_name}</p>
                        <p><strong>ACCUSED:</strong> ${caseData.respondent_name}</p>
                        <p><strong>OFFENSE:</strong> ${caseData.offense} (${caseData.law_violated})</p>
                        <hr>
                        <p><strong>FINDINGS:</strong></p>
                        <p>After a careful and thorough examination of the evidence presented, this Office finds that there is <strong>SUFFICIENT GROUND</strong> to engender a well-founded belief that the crime of ${caseData.offense} has been committed and that the accused ${caseData.respondent_name} is probably guilty thereof.</p>
                        <p><strong>RESOLUTION:</strong></p>
                        <p>It is hereby recommended that an Information for ${caseData.offense} be filed against the accused ${caseData.respondent_name} before the proper court.</p>
                        <div class="mt-4 pt-4 border-top">
                            <p class="mb-0"><strong>${caseData.assigned_prosecutor}</strong></p>
                            <p class="small text-muted">Investigating Prosecutor</p>
                        </div>
                    </div>
                `;
            } else if (type === 'affidavit') {
                title.textContent = 'Complaint-Affidavit - ' + caseData.case_number;
                content.innerHTML = `
                    <div style="font-family: 'Times New Roman', serif; line-height: 1.6; padding: 20px;">
                        <div class="text-center mb-4">
                            <h5>COMPLAINT-AFFIDAVIT</h5>
                            <p class="small text-muted">Case No. ${caseData.case_number} | NPS Docket: ${caseData.nps_docket}</p>
                        </div>
                        <p><strong>I, ${caseData.complainant_name},</strong> of legal age, Filipino, after being sworn to in accordance with law, hereby depose and state:</p>
                        <p>1. That I am the complainant in the above-entitled case;</p>
                        <p>2. That on or about ${caseData.incident_date} at around ${caseData.incident_time}, at ${caseData.incident_place}, the herein respondent ${caseData.respondent_name} did then and there willfully, unlawfully, and feloniously commit the offense of ${caseData.offense} in violation of ${caseData.law_violated};</p>
                        <p>3. ${caseData.case_summary};</p>
                        <p>4. I am executing this affidavit to attest to the truth of the foregoing facts.</p>
                        <div class="mt-4">
                            <p class="mb-0"><strong>${caseData.complainant_name}</strong></p>
                            <p class="small text-muted">Complainant/Affiant</p>
                        </div>
                    </div>
                `;
            }
        }

        function printDocument() {
            const content = document.getElementById('docContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Print Document</title>');
            printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
            printWindow.document.write('</head><body>');
            printWindow.document.write(content);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.print();
        }
    </script>
</body>
</html>
