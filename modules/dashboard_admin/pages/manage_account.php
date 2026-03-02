    <?php
    $company_id = $_SESSION['company_id'];
    $current_admin_id = $_SESSION['user_id']; // Assuming you store the user ID in session
    $manageAccountDuplicateMessage = null;
    $manageAccountDuplicateContext = null;
    $manageAccountEditDefaults = null;
    $addUserFormDefaults = [
        'employee_id' => '',
        'username' => '',
        'role' => ''
    ];
    $unicodeStripChars = ["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"];
    $sanitizeEmployeeId = static function ($value) use ($unicodeStripChars): string {
        $value = (string)($value ?? '');
        if ($value === '') {
            return '';
        }
        return trim(str_replace($unicodeStripChars, '', $value));
    };
    $normalizeEmployeeId = static function ($value) use ($sanitizeEmployeeId): string {
        $value = $sanitizeEmployeeId($value);
        if ($value === '') {
            return '';
        }
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    };
    $employeeIdNormalizationSql = "UPPER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, CONVERT(0xC2A0 USING utf8mb4), ''), CONVERT(0xE2808B USING utf8mb4), ''), CONVERT(0xE2808C USING utf8mb4), ''), CONVERT(0xE2808D USING utf8mb4), ''), CONVERT(0xEFBBBF USING utf8mb4), ''))))";

    $checkDuplicateEmployeeId = static function (string $normalizedEmployeeId, ?int $excludeUserId = null) use ($pdo, $company_id, $normalizeEmployeeId): array {
        if ($normalizedEmployeeId === '') {
            return [];
        }

        $sources = [];

        $userSql = "SELECT id, employee_id FROM users WHERE company_id = ?";
        $userParams = [$company_id];
        if ($excludeUserId !== null) {
            $userSql .= " AND id != ?";
            $userParams[] = $excludeUserId;
        }
        $userStmt = $pdo->prepare($userSql);
        $userStmt->execute($userParams);
        while ($userRow = $userStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($normalizeEmployeeId($userRow['employee_id'] ?? '') === $normalizedEmployeeId) {
                $sources[] = 'the user module';
                break;
            }
        }

        $hrStmt = $pdo->prepare("SELECT employee_id FROM hr WHERE company_id = ?");
        $hrStmt->execute([$company_id]);
        while ($hrRow = $hrStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($normalizeEmployeeId($hrRow['employee_id'] ?? '') === $normalizedEmployeeId) {
                $sources[] = 'the HR module';
                break;
            }
        }

        return $sources;
    };
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_user'])) {
        $unlockUsername = strtolower(trim($_POST['unlock_username'] ?? ''));
        if ($unlockUsername !== '') {
            $attempts = loadLoginSecurityAttempts(LOGIN_ATTEMPT_FILE);
            if (isset($attempts[$unlockUsername])) {
                unset($attempts[$unlockUsername]);
                persistLoginSecurityAttempts(LOGIN_ATTEMPT_FILE, $attempts);
            }
            logActivity(
                $pdo,
                $company_id,
                $current_admin_id,
                $_SESSION['role'] ?? 'admin',
                'manage_account',
                'unlock_user',
                'Unlocked account: ' . $unlockUsername
            );
        }
        header("Location: dashboard_admin.php?page=manage_account");
        exit();
    }
    
        // Lock Account Feature - NEW
    if (isset($_GET['lock_user'])) {
        $lock_id = $_GET['lock_user'];
        // Ensure admin cannot lock themselves
        if ($lock_id == $current_admin_id) {
            header("Location: dashboard_admin.php?page=manage_account");
            exit();
        }
        // Get username for the lock attempt record
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? AND company_id = ?");
        $stmt->execute([$lock_id, $company_id]);
        $user = $stmt->fetch();
        
        if ($user) {
            $attempts = loadLoginSecurityAttempts(LOGIN_ATTEMPT_FILE);
            $lockedUntil = time() + (LOGIN_LOCKOUT_MINUTES * 60);
            // Store a specific flag to indicate manual lock
            $attempts[strtolower($user['username'])] = [
                'attempts' => 5, // Still set to max attempts to trigger lockout logic
                'last_attempt' => time(), // Record the time of the admin action as the 'last attempt' for the lock record
                'locked_until' => $lockedUntil,
                'locked_manually' => true // Add this flag
            ];
            persistLoginSecurityAttempts(LOGIN_ATTEMPT_FILE, $attempts);
            logActivity(
                $pdo,
                $company_id,
                $current_admin_id,
                $_SESSION['role'] ?? 'admin',
                'manage_account',
                'lock_user',
                'Locked account: ' . strtolower($user['username'])
            );
        }
        header("Location: dashboard_admin.php?page=manage_account");
        exit();
    }
    
    // Delete Head User
    if (isset($_GET['delete_user'])) {
        $delete_id = $_GET['delete_user'];
        // Ensure admin cannot delete themselves
        if ($delete_id == $current_admin_id) {
            header("Location: dashboard_admin.php?page=manage_account");
            exit();
        }
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? AND company_id = ?");
        $stmt->execute([$delete_id, $company_id]);
        $deletedUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($deletedUser) {
            logActivity(
                $pdo,
                $company_id,
                $current_admin_id,
                $_SESSION['role'] ?? 'admin',
                'manage_account',
                'delete_user',
                'Deleted user: ' . $deletedUser['username']
            );
        }
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND company_id = ?");
        $stmt->execute([$delete_id, $company_id]);
        header("Location: dashboard_admin.php?page=manage_account");
        exit();
    }
    
    // Edit User
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id === 0) {
            header("Location: dashboard_admin.php?page=manage_account");
            exit();
        }
        // Ensure admin cannot edit themselves
        if ($user_id == $current_admin_id) {
            header("Location: dashboard_admin.php?page=manage_account");
            exit();
        }

        $stmt = $pdo->prepare("SELECT username, employee_id, role FROM users WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$user_id, $company_id]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingUser) {
            header("Location: dashboard_admin.php?page=manage_account");
            exit();
        }

        $username = trim($_POST['username'] ?? '');
        $role = $_POST['role'] ?? '';
        $allowedRoles = ['admin', 'staff'];
        $rawPassword = $_POST['password'] ?? '';
        $employee_id = $sanitizeEmployeeId($_POST['employee_id'] ?? '');
        $currentEmployeeId = $sanitizeEmployeeId($existingUser['employee_id'] ?? '');
        $normalizedExistingEmployeeId = $normalizeEmployeeId($currentEmployeeId);
        $normalizedSubmittedEmployeeId = $normalizeEmployeeId($employee_id);

        $manageAccountEditDefaults = [
            'id' => $user_id,
            'employee_id' => $employee_id,
            'username' => $username,
            'role' => $role,
        ];

        $duplicateSources = [];
        $employeeIdChanged = $normalizedExistingEmployeeId !== $normalizedSubmittedEmployeeId;
        if (!in_array($role, $allowedRoles, true)) {
            $manageAccountDuplicateMessage = 'Invalid role selected. Please choose Admin or Staff (POS System).';
            $manageAccountDuplicateContext = 'edit';
        } elseif ($employeeIdChanged && $normalizedSubmittedEmployeeId !== '') {
            $duplicateSources = $checkDuplicateEmployeeId($normalizedSubmittedEmployeeId, $user_id);
        }

        if (!empty($duplicateSources)) {
            $duplicateLabel = count($duplicateSources) > 1
                ? implode(' and ', $duplicateSources)
                : $duplicateSources[0];
            $manageAccountDuplicateMessage = sprintf(
                'Employee ID %s already exists in %s. Please use another Employee ID.',
                $employee_id,
                $duplicateLabel
            );
            $manageAccountDuplicateContext = 'edit';
        } else {
            try {
                $passwordChanged = false;
                if ($rawPassword !== '') {
                    $password = password_hash($rawPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, employee_id = ?, role = ? WHERE id = ? AND company_id = ?");
                    $stmt->execute([$username, $password, $employee_id, $role, $user_id, $company_id]);
                    $passwordChanged = true;
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, employee_id = ?, role = ? WHERE id = ? AND company_id = ?");
                    $stmt->execute([$username, $employee_id, $role, $user_id, $company_id]);
                }

                $changeNotes = [];
                if ($existingUser) {
                    if ($existingUser['username'] !== $username) {
                        $changeNotes[] = 'username ' . $existingUser['username'] . ' â†’ ' . $username;
                    }
                    if ($employeeIdChanged) {
                        $changeNotes[] = 'employee ID ' . ($existingUser['employee_id'] ?? 'none') . ' â†’ ' . ($employee_id !== '' ? $employee_id : 'none');
                    }
                    if ($existingUser['role'] !== $role) {
                        $changeNotes[] = 'role ' . $existingUser['role'] . ' â†’ ' . $role;
                    }
                }
                if ($passwordChanged) {
                    $changeNotes[] = 'password updated';
                }
                $editDescription = 'Edited user: ' . $username;
                if (!empty($changeNotes)) {
                    $editDescription .= ' (' . implode('; ', $changeNotes) . ')';
                }
                logActivity(
                    $pdo,
                    $company_id,
                    $current_admin_id,
                    $_SESSION['role'] ?? 'admin',
                    'manage_account',
                    'edit_user',
                    $editDescription
                );
                header("Location: dashboard_admin.php?page=manage_account");
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $manageAccountDuplicateMessage = sprintf(
                        'Employee ID %s already exists. Please use another Employee ID.',
                        $employee_id !== '' ? $employee_id : 'you entered'
                    );
                    $manageAccountDuplicateContext = 'edit';
                } else {
                    throw $e;
                }
            }
        }
    }
    
    // Add User
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
        $employee_id = $sanitizeEmployeeId($_POST['employee_id'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $rawPassword = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        $allowedRoles = ['admin', 'staff'];
        $normalizedEmployeeId = $normalizeEmployeeId($employee_id);

        $addUserFormDefaults = [
            'employee_id' => $employee_id,
            'username' => $username,
            'role' => $role
        ];

        $duplicateSources = [];
        if (!in_array($role, $allowedRoles, true)) {
            $manageAccountDuplicateMessage = 'Invalid role selected. Please choose Admin or Staff (POS System).';
            $manageAccountDuplicateContext = 'add';
        } elseif ($normalizedEmployeeId !== '') {
            $duplicateSources = $checkDuplicateEmployeeId($normalizedEmployeeId, null);
        }

        if (!empty($duplicateSources)) {
            $duplicateLabel = count($duplicateSources) > 1
                ? implode(' and ', $duplicateSources)
                : $duplicateSources[0];
            $manageAccountDuplicateMessage = sprintf(
                'Employee ID %s already exists in %s. Please use another Employee ID.',
                $employee_id,
                $duplicateLabel
            );
            $manageAccountDuplicateContext = 'add';
        } else {
            try {
                $password = password_hash($rawPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, role, employee_id, company_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $password, $role, $employee_id, $company_id]);
                $addDescription = 'Added user: ' . $username . ' (employee ID: ' . ($employee_id !== '' ? $employee_id : 'none') . ', role: ' . $role . ')';
                logActivity(
                    $pdo,
                    $company_id,
                    $current_admin_id,
                    $_SESSION['role'] ?? 'admin',
                    'manage_account',
                    'add_user',
                    $addDescription
                );
                header("Location: dashboard_admin.php?page=manage_account");
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $manageAccountDuplicateMessage = sprintf(
                        'Employee ID %s already exists. Please use another Employee ID.',
                        $employee_id !== '' ? $employee_id : 'you entered'
                    );
                    $manageAccountDuplicateContext = 'add';
                } else {
                    throw $e;
                }
            }
        }
    }
    
    // --- MODIFIED SECTION START ---
    // Fetch all head users EXCEPT the current admin
    $stmt = $pdo->prepare("SELECT id, username, role, employee_id FROM users WHERE company_id = ? AND id != ? ORDER BY username ASC");
    $stmt->execute([$company_id, $current_admin_id]);
    $allUsers = $stmt->fetchAll(); // Fetch ALL users first

    // Get locked users based on login_attempts.json
    $lockedUsers = [];
    $lockedAttempts = loadLoginSecurityAttempts(LOGIN_ATTEMPT_FILE);

    if (!empty($lockedAttempts)) {
        $tz = new DateTimeZone('Asia/Manila');
        // Fetch all users for this company to map usernames from attempts file
        $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE company_id = ?");
        $stmt->execute([$company_id]);
        $companyUsers = $stmt->fetchAll();
        $userIndex = [];
        foreach ($companyUsers as $companyUser) {
            $userIndex[strtolower($companyUser['username'])] = $companyUser;
        }

        $nowTs = time();
        foreach ($lockedAttempts as $attemptUsername => $record) {
            if (!is_array($record)) { continue; }

            // Determine if the lock is active based on type
            $isManualLock = isset($record['locked_manually']) && $record['locked_manually'] === true;
            $isTimeBasedLockActive = isset($record['locked_until']) && (int)$record['locked_until'] >= $nowTs;

            // An entry is considered a "locked user" if:
            // 1. It's a manual lock (indefinite) OR
            // 2. It's an automatic lock (failed attempts) AND the time hasn't expired yet
            if ($isManualLock || $isTimeBasedLockActive) {
                $key = strtolower((string)$attemptUsername);
                if (!isset($userIndex[$key])) { continue; } // Ensure the user exists in the DB for this company

                // Calculate display properties based on lock type
                if ($isManualLock) {
                    // Manual locks are indefinite for display purposes
                    $lockedUntilLabel = 'Indefinitely';
                    $remainingMinutes = 'N/A';
                    $isPermanent = true;
                    // For manual locks, the 'last_attempt' time in the record reflects when the lock was applied by the admin
                    // or the time of the last failed attempt before the admin intervened.
                    $lastAttemptLabel = isset($record['last_attempt']) ? (new DateTime('@' . (int)$record['last_attempt']))->setTimezone($tz)->format('Y-m-d H:i') : 'â€”';
                    // Add context to the label for manual locks - this represents the time the lock state was created/applied
                  
                    // Lock type for manual lock
                    $lockType = 'Admin';
                } else {
                    // Automatic locks have a specific time and countdown
                    $lockedUntilTs = (int)$record['locked_until'];
                    $lockedUntilDt = (new DateTime('@' . $lockedUntilTs))->setTimezone($tz);
                    $lockedUntilLabel = $lockedUntilDt->format('Y-m-d H:i');
                    $remainingMinutes = max(0, (int)ceil(($lockedUntilTs - $nowTs) / 60));
                    $isPermanent = false;
                    // For automatic locks, show the time of the last failed attempt as is
                    $lastAttemptLabel = isset($record['last_attempt']) ? (new DateTime('@' . (int)$record['last_attempt']))->setTimezone($tz)->format('Y-m-d H:i') : 'â€”';
                    // Lock type for automatic lock
                    $lockType = 'Automatic';
                }

                $lockedUsers[] = [
                    'username' => $userIndex[$key]['username'],
                    'role' => $userIndex[$key]['role'],
                    'locked_until_ts' => (int)($record['locked_until'] ?? 0), // Store original timestamp for sorting
                    'locked_until_label' => $lockedUntilLabel,
                    'remaining_minutes' => $remainingMinutes,
                    'is_permanent' => $isPermanent,
                    'lock_type' => $lockType, // Add the new field
                    'last_attempt_label' => $lastAttemptLabel, // Use the modified label
                ];
            }
            // If an automatic lock's time has expired ($isTimeBasedLockActive is false AND $isManualLock is false),
            // it's not added to $lockedUsers and will appear in $users if it's a head user.
        }
        // Sort locked users: manual locks first, then by remaining time (descending for time-based)
        usort($lockedUsers, function (array $a, array $b): int {
            if ($a['is_permanent'] && !$b['is_permanent']) return -1;
            if (!$a['is_permanent'] && $b['is_permanent']) return 1;
            // Both are same type (or both time-based), sort by remaining time/lock expiry
            return $b['locked_until_ts'] <=> $a['locked_until_ts'];
        });
    }

    // Separate locked and unlocked users from the fetched list
    $lockedUsernames = array_column($lockedUsers, 'username'); // Extract usernames of locked users
    $users = array_filter($allUsers, function($user) use ($lockedUsernames) {
        return !in_array($user['username'], $lockedUsernames, true); // Keep users NOT in the locked list
    });

    $user_count = count($users);
    $locked_count = count($lockedUsers);
    // --- MODIFIED SECTION END --- ?>

    <style>
        .manage-account-tab-btn {
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            
        }
        .manage-account-tab-btn:hover {
            background: var(--border-light);
        }
        .manage-account-tab-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }
        .manage-account-tab-panel {
            margin-top: 1.5rem;
        }
        .manage-account-lock-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .manage-account-tabs {
            display: flex;
            gap: 1rem;
            margin: 1rem -1.5rem 0;
            padding: 0 1.5rem 0.5rem 2.5rem;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border-color);
        }
        .card-header-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .card-header-actions .card-badge {
            margin-left: 0;
        }
    </style>
    <div class="content-grid single-column">
        <div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-user-shield"></i> Users Management
                    </div>
                    <div class="card-header-actions">
                        <span class="card-badge"><span id="userCount"><?= $user_count ?></span> Users</span>
                        <span class="card-badge"><span id="lockedCount"><?= $locked_count ?></span> Locked</span>
                        <div style="display:flex; gap:0.5rem; align-items:center;">
                            <button type="button" class="edit-btn" style="padding: 0.6rem 0.9rem; font-size:0.9rem;" onclick="openAddUserModal()">
                                <i class="fas fa-user-plus"></i> Add User
                            </button>
                        </div>
                    </div>
                </div>
                <div class="manage-account-tabs">
                    <button type="button" class="manage-account-tab-btn active" data-tab="headUsersTab">
                        Users
                    </button>
                    <button type="button" class="manage-account-tab-btn" data-tab="lockedUsersTab">
                        Locked Accounts <span class="manage-account-lock-badge">(<span><?= $locked_count ?></span>)</span>
                    </button>
                </div>
                <div id="headUsersTab" class="manage-account-tab-panel">
                    <div class="table-container">
                        <table class="data-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u['username']) ?></td>
                                <td><span style="text-transform: capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $u['role'])) ?></span></td>
                                <td>
                                    <button type="button" class="edit-btn" onclick='openEditUserModal(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button type="button" class="action-btn" style="background: var(--danger); color: white;" onclick="openLockModal('dashboard_admin.php?page=manage_account&lock_user=<?= $u['id'] ?>')">
                                        <i class="fas fa-lock"></i> Lock
                                    </button>
                                    <button type="button" class="action-btn" onclick="openDeleteModal('dashboard_admin.php?page=manage_account&delete_user=<?= $u['id'] ?>')">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        </table>
                    </div>
                </div>
                <div id="lockedUsersTab" class="manage-account-tab-panel" style="display:none;">
                    <div class="table-container">
                        <!-- Updated message -->
                        <p style="margin-bottom: 1rem; color: var(--text-secondary); margin-left: 1rem;">
                            Accounts locked by an administrator require manual unlocking. Automatic locks from failed attempts expire after <?= LOGIN_LOCKOUT_MINUTES ?> minute(s).
                        </p>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Lock Type</th> <!-- New Column Header -->
                                    <th>Locked Until</th>
                                    <th>Locked Time</th> <!-- This column now shows the time the lock state was applied or the last failed attempt -->
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($lockedUsers)): ?>
                                    <?php foreach ($lockedUsers as $locked): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($locked['username']) ?></td>
                                        <td><span style="text-transform: capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $locked['role'])) ?></span></td>
                                        <td><?= htmlspecialchars($locked['lock_type']) ?></td> <!-- Display the lock type -->
                                        <!-- Updated display for "Locked Until" -->
                                        <td>
                                            <?php if ($locked['is_permanent']): ?>
                                                <span style="color: var(--danger); font-weight: bold;"><?= htmlspecialchars($locked['locked_until_label']) ?></span>
                                            <?php else: ?>
                                                <?= htmlspecialchars($locked['locked_until_label']) ?> (<?= $locked['remaining_minutes'] ?> min)
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($locked['last_attempt_label']) ?></td> <!-- Display the modified label -->
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="unlock_username" value="<?= htmlspecialchars($locked['username']) ?>">
                                                <button type="submit" name="unlock_user" class="action-btn">
                                                    <i class="fas fa-unlock"></i> Unlock
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center; color: var(--text-secondary);">
                                            No accounts are currently locked.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <!-- Add Head User form moved to a modal opened by header button -->
    </div>
    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal-overlay">
        <div class="modal-box" style="max-width: 500px;">
            <h3 class="modal-title" style="text-align: left;"><i class="fas fa-edit"></i> Edit User</h3>
            <form method="POST" data-feedback-disabled="true">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-group">
                    <label for="edit_user_employee_id">Employee ID</label>
                    <input type="text" id="edit_user_employee_id" name="employee_id" required>
                </div>
                <div class="form-group">
                    <label for="edit_user_username">Username</label>
                    <input type="text" id="edit_user_username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="edit_user_password">Password (leave blank to keep current)</label>
                    <input type="password" id="edit_user_password" name="password" placeholder="Enter new password">
                </div>
                <div class="form-group">
                    <label for="edit_user_role">Role</label>
<select id="edit_user_role" name="role" required>
    <option value="">Select role...</option>
    <option value="admin">Admin (Admin Dashboard)</option>
    <option value="staff">Staff (POS System)</option>
</select>
                </div>
                <div class="modal-actions" style="justify-content: flex-end; margin-top: 24px;">
                    <button type="button" class="btn-secondary" style="padding: 10px 20px; background: var(--border-color); color: var(--text-primary); border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer;" onclick="closeEditUserModal()">Cancel</button>
                    <button type="submit" name="edit_user" class="btn-primary" style="padding: 10px 20px;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:500px;">
            <h3 class="modal-title" style="text-align:left;"><i class="fas fa-user-plus"></i> Add User</h3>
            <form method="POST" id="addUserForm">
                <div class="form-group">
                    <label for="add_user_username">Username</label>
                    <input type="text" id="add_user_username" name="username" value="<?= htmlspecialchars($addUserFormDefaults['username'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="add_user_password">Password</label>
                    <input type="password" id="add_user_password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="add_user_role">Role</label>
<select id="add_user_role" name="role" required>
    <option value="">Select role...</option>
    <option value="admin" <?= ($addUserFormDefaults['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin (Admin Dashboard)</option>
    <option value="staff" <?= ($addUserFormDefaults['role'] ?? '') === 'staff' ? 'selected' : '' ?>>Staff (POS System)</option>
</select>
                </div>
                <div class="modal-actions" style="justify-content:flex-end; margin-top:18px;">
                    <button type="button" class="btn-secondary" onclick="closeAddUserModal()">Cancel</button>
                    <button type="submit" name="add_user" class="btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lock User Confirmation Modal -->
    <div id="lockUserModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="lockUserModalTitle" style="max-width:420px;">
            <h3 class="modal-title" id="lockUserModalTitle" style="text-align:left;">
                <i class="fas fa-lock"></i> Confirm Lock
            </h3>
            <p style="margin: 12px 0 24px; color: var(--text-secondary);">
                Locking this account will prevent the user from signing in until an administrator unlocks it. Continue?
            </p>
            <div class="modal-actions" style="justify-content:flex-end; gap:0.75rem;">
                <button type="button" class="btn-secondary" onclick="closeLockUserModal()">Cancel</button>
                <a id="confirmLockBtn" class="btn-primary" href="#" style="display:inline-flex; align-items:center; gap:0.35rem; text-decoration:none;">
                    <i class="fas fa-check"></i> Yes, Lock
                </a>
            </div>
        </div>
    </div>
    <script>
    document.getElementById('editUserModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeEditUserModal();
    });

    // Lock User Modal Functions
    function openLockModal(url) {
        document.getElementById('confirmLockBtn').href = url;
        document.getElementById('lockUserModal').style.display = 'flex';
    }
    function closeLockUserModal() {
        document.getElementById('lockUserModal').style.display = 'none';
    }
    // Close modal on outside click
    document.getElementById('lockUserModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeLockUserModal();
    });

    // Add User Modal Functions (inline so button works without external JS)
    function openAddUserModal(options = {}) {
        const modal = document.getElementById('addUserModal');
        if (!modal) return;
        const preserveValues = options && options.preserveValues === true;
        if (!preserveValues) {
            const fields = ['add_user_username','add_user_password','add_user_role'];
            fields.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
        }
        modal.style.display = 'flex';
        // autofocus first field after a short delay
        setTimeout(function(){ const f = document.getElementById('add_user_username'); if (f) f.focus(); }, 80);
    }
    function closeAddUserModal() {
        const modal = document.getElementById('addUserModal'); if (!modal) return; modal.style.display = 'none';
    }
    document.getElementById('addUserModal')?.addEventListener('click', function(e){ if (e.target === this) closeAddUserModal(); });

    function openManageAccountDuplicateModal(message) {
        const modal = document.getElementById('manageAccountDuplicateModal');
        if (!modal) { return; }
        const messageNode = modal.querySelector('.manage-account-duplicate-message');
        if (messageNode) {
            messageNode.textContent = message;
        }
        const resumeBtn = document.getElementById('manageAccountDuplicateResumeBtn');
        if (resumeBtn) {
            const shouldShowResume = manageAccountDuplicateContextValue === 'edit' && !!manageAccountEditDefaultsPayload;
            resumeBtn.style.display = shouldShowResume ? 'inline-flex' : 'none';
        }
        modal.style.display = 'flex';
    }

    function closeManageAccountDuplicateModal() {
        const modal = document.getElementById('manageAccountDuplicateModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    function resumeManageAccountEditFromDuplicate() {
        closeManageAccountDuplicateModal();
        if (manageAccountEditDefaultsPayload) {
            openEditUserModal(manageAccountEditDefaultsPayload);
        }
    }

    document.getElementById('manageAccountDuplicateModal')?.addEventListener('click', function(e){
        if (e.target === this) {
            closeManageAccountDuplicateModal();
        }
    });

    document.getElementById('manageAccountDuplicateResumeBtn')?.addEventListener('click', function() {
        resumeManageAccountEditFromDuplicate();
    });

    const editUserForm = document.querySelector('#editUserModal form');
    editUserForm?.addEventListener('submit', function() {
        closeEditUserModal();
    });

    const manageAccountTabs = document.querySelectorAll('.manage-account-tab-btn');
    const manageAccountPanels = document.querySelectorAll('.manage-account-tab-panel');
    manageAccountTabs.forEach(btn => {
        btn.addEventListener('click', () => {
            manageAccountTabs.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const targetId = btn.getAttribute('data-tab');
            manageAccountPanels.forEach(panel => {
                if (panel.id === targetId) {
                    panel.style.display = 'block';
                } else {
                    panel.style.display = 'none';
                }
            });
        });
    });

    <?php if (!empty($manageAccountDuplicateMessage)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($manageAccountDuplicateContext === 'add'): ?>
        openAddUserModal({ preserveValues: true });
        <?php else: ?>
        closeEditUserModal();
        <?php endif; ?>
        openManageAccountDuplicateModal(<?= json_encode($manageAccountDuplicateMessage) ?>);
    });
    <?php endif; ?>
    </script>

    <!-- Mark Defective Modal (replace existing modal) -->
    <div id="markDefectiveModal" class="modal-overlay" style="display:none;">
      <div class="modal-box" style="max-width:440px;">
        <h3 class="modal-title"><i class="fas fa-wrench"></i> Mark Item Defective</h3>
        <form method="POST" id="markDefectiveForm">
          <input type="hidden" name="inventory_id" id="def_inventory_id">
          <div class="form-group">
            <label>Item</label>
            <input type="text" id="def_item_name" readonly style="background:var(--bg-secondary); color:var(--text-primary); border:1px solid var(--border-color); cursor:not-allowed;">
          </div>
          <div class="form-group">
            <label>Current Quantity</label>
            <input type="number" id="def_current_quantity" readonly style="background:var(--bg-secondary); color:var(--text-primary); border:1px solid var(--border-color); cursor:not-allowed;">
          </div>
          <div class="form-group">
            <label>Defective Quantity</label>
            <input type="number" name="defective_quantity" id="defective_quantity" min="1" value="1" required style="background:var(--bg-secondary); color:var(--text-primary); border:1px solid var(--border-color);">
          </div>
          <div class="form-group">
            <label>Reason</label>
            <textarea name="defective_reason" id="def_reason" rows="3" placeholder="Describe defect (optional)" style="background:var(--bg-secondary); color:var(--text-primary); border:1px solid var(--border-color);"></textarea>
          </div>
          <div class="modal-actions" style="justify-content:flex-end;">
            <button type="button" class="btn-secondary" id="defCancelBtn">Cancel</button>
            <button type="submit" name="mark_defective" class="btn-primary">Mark Defective</button>
          </div>
        </form>
      </div>
    </div>

    <script>
    // global helper used by inventory row buttons
    window.openMarkDefective = window.openMarkDefective || function(item) {
        try {
            if (typeof item === 'string') {
                try { item = JSON.parse(item); } catch (e) { /* ignore parse error */ }
            }
            item = item || {};

            // ensure modal exists
            let modal = document.getElementById('markDefectiveModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'markDefectiveModal';
                modal.className = 'modal-overlay';
                modal.style.display = 'none';
                modal.innerHTML = `
        <div class="modal-box" style="max-width:440px;">
            <h3 class="modal-title"><i class="fas fa-wrench"></i> Mark Item Defective</h3>
            <form method="POST" id="markDefectiveForm">
                <input type="hidden" name="inventory_id" id="def_inventory_id">
                <div class="form-group">
                    <label>Item</label>
                    <input type="text" id="def_item_name" readonly style="background:var(--bg-secondary); color:var(--text-primary); border:1px solid var(--border-color); cursor:not-allowed;">
                </div>
                <div class="form-group">
                    <label>Current Quantity</label>
                    <input type="number" id="def_current_quantity" readonly style="background:var(--bg-secondary); color:var(--text-primary); border:1px solid var(--border-color); cursor:not-allowed;">
                </div>
                <div class="form-group">
                    <label>Defective Quantity</label>
                    <input type="number" name="defective_quantity" id="defective_quantity" min="1" value="1" required style="background:var(--bg-secondary); color:var(--text-primary); border:1px solid var(--border-color);">
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <textarea name="defective_reason" id="def_reason" rows="3" placeholder="Describe defect (optional)" style="background:var(--bg-secondary); color:var(--text-primary); border:1px solid var(--border-color);"></textarea>
                </div>
                <div class="modal-actions" style="justify-content:flex-end;">
                    <button type="button" class="btn-secondary" id="defCancelBtn">Cancel</button>
                    <button type="submit" name="mark_defective" class="btn-primary">Mark Defective</button>
                </div>
            </form>
        </div>
    `
<?php
