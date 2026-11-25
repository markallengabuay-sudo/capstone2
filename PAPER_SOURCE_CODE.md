# Selected Source Code Listings

## Integrated Court Case Aging & Monitoring System

The following listings are selected excerpts from the system source code. They illustrate representative functions of the application; they are not complete source files. Ellipses indicate omitted surrounding code. The source filenames refer to the project version reviewed for this document.

> **Before submission:** Confirm the required format with your adviser. Do not include production credentials, real user information, or confidential case records. The database connection setup is intentionally omitted.

## Listing 1. User Login and Role-Based Redirection

**Sources:** `index.php` (login form) and `auth/login.php` (authentication and routing)

The login page submits the username, password, and session CSRF token to the authentication handler. After a successful credential check, the handler stores the user's role in the session and redirects the user to the appropriate dashboard.

```php
<!-- index.php: login form excerpt -->
<form action="auth/login.php" method="POST">
    <input type="hidden" name="csrf_token"
           value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

    <label for="username">Username</label>
    <input type="text" id="username" name="username" required>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>

    <button type="submit">Login</button>
</form>
```

```php
// auth/login.php: selected authentication excerpt
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: ../index.php?error=csrf_invalid");
        exit();
    }

    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    $stmt = $conn->prepare(
        "SELECT id, username, password, role, full_name
         FROM users WHERE username = ?"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $password_valid = password_verify(
            $password,
            (string) $user['password']
        );

        if ($password_valid) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['logged_in'] = true;

            if ($user['role'] === 'clerk') {
                header("Location: ../clerk/dashboard.php");
            } elseif ($user['role'] === 'branch') {
                header("Location: ../branch/dashboard.php");
            } else {
                header("Location: ../fiscal/dashboard.php");
            }
            exit();
        }
    }
}
```

## Listing 2. Case Intake and Database Transaction

**Source:** `fiscal/case_intake.php`

The intake workflow starts a database transaction, inserts complainant and respondent records, and uses their generated IDs when creating the complaint. The transaction is committed when processing succeeds and rolled back if a database error occurs.

```php
// Selected case-intake excerpt
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO complainants
            (full_name, age, sex, civil_status, contact_number, address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_POST['comp_name'] ?? '',
        $_POST['comp_age'] ?? null,
        $_POST['comp_sex'] ?? '',
        $_POST['comp_civil'] ?? '',
        $_POST['comp_contact'] ?? '',
        $_POST['comp_address'] ?? ''
    ]);
    $complainant_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("
        INSERT INTO respondents
            (full_name, age, sex, status, address, arrest_date,
             detention_location, custody_details)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_POST['resp_name'] ?? 'Unknown',
        $_POST['resp_age'] ?? null,
        $_POST['resp_sex'] ?? null,
        $_POST['resp_status'] ?? 'At Large',
        $_POST['resp_address'] ?? '',
        !empty($_POST['arrest_date']) ? $_POST['arrest_date'] : null,
        $_POST['detention_location'] ?? null,
        $_POST['custody_details'] ?? null
    ]);
    $respondent_id = $pdo->lastInsertId();

    // The complaint record is inserted using the generated party IDs.
    // Additional complaint fields and document handling are omitted.
    $stmt = $pdo->prepare("
        INSERT INTO complaints (
            case_number, nps_docket_no, date_received, time_received,
            date_filed, receiving_staff, offense, case_type, offense_type,
            law_statute, law_section, case_summary, description, priority,
            incident_date, incident_time, incident_place, complainant_id,
            respondent_id, assigned_prosecutor_id, status, similar_complaint,
            respondent_aware, related_case, related_case_details
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                  ?, ?, ?, ?, ?, ?)
    ");

    // The application builds the complaint values from submitted form data.
    // Remaining intake fields and evidence uploads are omitted here.

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = "Error saving case: " . $e->getMessage();
}
```

## Listing 3. Random Raffle and Branch Assignment

**Sources:** `clerk/raffle.php` (selection and confirmation) and `clerk/api_clerk_actions.php` (database update)

The raffle interface selects a branch for the selected case. When the Clerk confirms the result, the page sends the case ID and branch to the server. The server updates the case's assigned branch and status.

```javascript
// clerk/raffle.php: selected startRaffle() excerpt
function startRaffle() {
    if (!selectedCaseId) {
        alert('Please select a case first');
        return;
    }

    const random = Math.random();
    if (random < 0.33) assignedBranch = '27';
    else if (random < 0.66) assignedBranch = '28';
    else assignedBranch = '29';

    // The remaining code animates the wheel and displays the result.
}
```

```javascript
// clerk/raffle.php: confirmAssignment() excerpt
function confirmAssignment() {
    if (!assignedBranch || !selectedCaseId) return;

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
            window.location.href = 'docket.php';
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error assigning branch: ' + error);
    });
}
```

```php
// clerk/api_clerk_actions.php: assign_branch handler
} elseif ($action === 'assign_branch') {
    $case_id = isset($_POST['case_id'])
        ? intval($_POST['case_id'])
        : 0;
    $branch = isset($_POST['branch'])
        ? trim($_POST['branch'])
        : '';

    if (!$case_id || !$branch) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields'
        ]);
        exit();
    }

    $stmt = $pdo->prepare(
        "UPDATE complaints
         SET court_branch = ?, status = 'Assigned to Branch'
         WHERE id = ?"
    );
    $stmt->execute([$branch, $case_id]);

    echo json_encode([
        'success' => true,
        'message' => 'Case assigned to branch successfully'
    ]);
}
```

## Listing 4. Case Aging Calculation

**Source:** `branch/case_aging.php`

The system calculates the elapsed time since filing and classifies cases into the aging groups displayed in the branch case-aging report.

```php
function getCaseAge($date_filed) {
    $filed = new DateTime($date_filed);
    $now = new DateTime();
    return $filed->diff($now)->days;
}

function getCaseAgeYears($date_filed) {
    $filed = new DateTime($date_filed);
    $now = new DateTime();
    return $filed->diff($now)->y;
}

$aging_categories = [
    'under_5' => 0,
    'five_to_under_10' => 0,
    'ten_and_above' => 0
];

foreach ($branch_cases as &$case) {
    $case['age_days'] = getCaseAge($case['date_filed']);
    $case['age_years'] = getCaseAgeYears($case['date_filed']);

    if ($case['age_years'] < 5) {
        $case['age_category'] = 'under_5';
        $case['age_category_label'] = 'Under 5 Years';
        $aging_categories['under_5']++;
    } elseif ($case['age_years'] < 10) {
        $case['age_category'] = 'five_to_under_10';
        $case['age_category_label'] = '5 to under 10 Years';
        $aging_categories['five_to_under_10']++;
    } else {
        $case['age_category'] = 'ten_and_above';
        $case['age_category_label'] = '10 Years and Above';
        $aging_categories['ten_and_above']++;
    }
}
unset($case);
```

## Listing 5. Hearing Scheduling

**Sources:** `branch/hearings.php` (form submission) and `branch/api_branch_actions.php` (validation and database insert)

The hearing page sends the selected case and schedule details to the branch API. The server checks that required fields are present and that the case belongs to the branch before saving the hearing.

```javascript
// branch/hearings.php: selected saveHearing() excerpt
function saveHearing(event) {
    event.preventDefault();

    const caseId = document.getElementById('caseSelect').value;
    const date = document.getElementById('hearingDate').value;
    const time = document.getElementById('hearingTime').value;
    const type = document.getElementById('hearingType').value;
    const room = document.getElementById('courtroom').value;
    const notes = document.getElementById('hearingNotes').value;

    if (!caseId) {
        alert('Please select a case');
        return false;
    }

    const formData = new FormData();
    formData.append('action', 'save_hearing');
    formData.append('case_id', caseId);
    formData.append('date', date);
    formData.append('time', time);
    formData.append('type', type);
    formData.append('room', room);
    formData.append('notes', notes);
    formData.append(
        'csrf_token',
        document.getElementById('hearingCsrfToken').value
    );

    fetch('api_branch_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
}
```

```php
// branch/api_branch_actions.php: save_hearing handler
} elseif ($action === 'save_hearing') {
    $case_id = isset($_POST['case_id'])
        ? intval($_POST['case_id'])
        : 0;
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';
    $time = isset($_POST['time']) ? trim($_POST['time']) : '';
    $type = isset($_POST['type']) ? trim($_POST['type']) : '';
    $room = isset($_POST['room']) ? trim($_POST['room']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    if (!$case_id || !$date || !$time || !$type || !$room) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields'
        ]);
        exit();
    }

    $case_stmt = $pdo->prepare(
        "SELECT id FROM complaints
         WHERE id = ? AND court_branch = ?"
    );
    $case_stmt->execute([$case_id, $branch_number]);
    if (!$case_stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode([
            'success' => false,
            'message' => 'Case not found in this branch'
        ]);
        exit();
    }

    $stmt = $pdo->prepare("
        INSERT INTO hearings
            (complaint_id, date, time, type, room, status, branch_id, notes)
        VALUES (?, ?, ?, ?, ?, 'upcoming', ?, ?)
    ");
    $result = $stmt->execute([
        $case_id, $date, $time, $type, $room, $branch_number, $notes
    ]);

    echo json_encode([
        'success' => (bool) $result,
        'message' => $result
            ? 'Hearing scheduled successfully'
            : 'Failed to schedule hearing'
    ]);
}
```

## Source Files Referenced

- `index.php`
- `auth/login.php`
- `fiscal/case_intake.php`
- `clerk/raffle.php`
- `clerk/api_clerk_actions.php`
- `branch/case_aging.php`
- `branch/hearings.php`
- `branch/api_branch_actions.php`
