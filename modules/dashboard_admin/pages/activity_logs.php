<?php
    $company_id = $_SESSION['company_id'];
    $selectedModuleValue = '';
    $log_count = 0;
    $logs = [];
    $modules = [];
    $user_roles = [];
    $filter_user_role = '';
    $filter_date_start = '';
    $filter_date_end = '';
    $user_role = $_SESSION['role'] ?? 'unknown';
    $user_id = $_SESSION['user_id'] ?? null;
    // Determine active tab (only one tab now, 'Logs', which shows all filtered by the dropdown)
    $log_view = $_GET['log_view'] ?? 'hr'; // Default remains 'hr' for the tab link, but filter logic handles 'all'

    // Friendly module label mapping for the Activity Logs filter
    // This map translates user-friendly labels in the dropdown to internal module names used in the DB.
    $friendlyModuleMap = [
        'HR' => 'hr',
        'Users' => 'manage_account', // Internal module name for Manage Account
        'Finance' => 'finance',
        'Inventory' => 'inventory',
        'Login' => 'login',
        'Sales' => 'sales',
        'POS' => 'pos',
    ];

    // Reverse map to find the user-friendly label from the internal module name
    $internalToFriendly = array_flip($friendlyModuleMap);

    // --- Filter Parameter Logic ---
    // The 'filter_module' GET parameter now expects the *user-friendly* label (e.g., 'HR', 'Users').
    // If the parameter is empty or not recognized, it defaults to showing all logs (filter_module = '').

    $moduleParam = $_GET['filter_module'] ?? ''; // Get the raw filter value

    if ($moduleParam === '' || $moduleParam === null) {
        // If no filter is provided, show all modules.
        $filter_module = '';
        $selectedModuleValue = ''; // Represents "All Modules" in the dropdown
    } elseif (isset($friendlyModuleMap[$moduleParam])) {
        // If the parameter matches a user-friendly label, get the internal name.
        $filter_module = $friendlyModuleMap[$moduleParam];
        $selectedModuleValue = $moduleParam; // The value shown as selected in the dropdown
    } elseif (isset($internalToFriendly[$moduleParam])) {
        // If the parameter is an internal name (fallback, though unlikely from dropdown), map it back to friendly.
        $filter_module = $moduleParam; // Use the internal name directly
        $selectedModuleValue = $internalToFriendly[$moduleParam]; // Get the corresponding friendly label
    } else {
        // If the parameter is unrecognized, default to showing all modules.
        $filter_module = '';
        $selectedModuleValue = '';
    }

    // Get other filter parameters
    $filter_user_role = $_GET['filter_role'] ?? '';
    $filter_date_start = $_GET['filter_start_date'] ?? '';
    $filter_date_end = $_GET['filter_end_date'] ?? '';

    // --- Build SQL Query ---
    $whereConditions = ["l.company_id = ?"];
    $params = [$company_id];

    // Add module filter if a specific module is selected
    if ($filter_module !== '') {
        $whereConditions[] = "l.module = ?";
        $params[] = $filter_module;
    }
    // Add user role filter if specified
    if ($filter_user_role) {
        $whereConditions[] = "l.user_role = ?";
        $params[] = $filter_user_role;
    }
    // Add date range filters if specified
    if ($filter_date_start) {
        $whereConditions[] = "l.timestamp >= ?";
        $params[] = $filter_date_start . ' 00:00:00';
    }
    if ($filter_date_end) {
        $whereConditions[] = "l.timestamp <= ?";
        $params[] = $filter_date_end . ' 23:59:59';
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Fetch logs with user info
    $stmt = $pdo->prepare("
        SELECT l.*, u.username as user_name
        FROM activity_logs l
        LEFT JOIN users u ON l.user_id = u.id
        WHERE $whereClause
        ORDER BY l.timestamp DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch distinct modules for the filter dropdown (across all roles for this company)
    $stmt = $pdo->prepare("
        SELECT DISTINCT module
        FROM activity_logs
        WHERE company_id = ?
        ORDER BY module ASC
    ");
    $stmt->execute([$company_id]);
    $modules = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch distinct user roles for the filter dropdown (based on the currently selected module filter)
    if ($filter_module !== '') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT user_role
            FROM activity_logs
            WHERE company_id = ? AND module = ?
            ORDER BY user_role ASC
        ");
        $stmt->execute([$company_id, $filter_module]);
        $user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // If no module is selected (showing all), get roles for all modules
        $stmt = $pdo->prepare("
            SELECT DISTINCT user_role
            FROM activity_logs
            WHERE company_id = ?
            ORDER BY user_role ASC
        ");
        $stmt->execute([$company_id]);
        $user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $log_count = count($logs);
    ?>
    <div class="content">
        <!-- Tab Navigation (Only one tab now, 'Logs', which encompasses all modules) -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-history"></i> Activity Logs
                </div>
            </div>
            <div style="padding: 0.75rem;">
                <!-- The 'Logs' tab now represents the view filtered by the dropdown below -->
                <a href="dashboard_admin.php?page=activity_logs&log_view=hr" class="nav-item <?= $log_view === 'hr' ? 'active' : '' ?>" style="display: inline-block; margin-right: 1rem; padding: 0.5rem 1rem; border-radius: var(--radius-sm); text-decoration: none;">
                    <i class="fas fa-list nav-icon"></i> <span>Logs</span>
                </a>
                <!-- You can add other specific module tabs here if needed in the future -->
            </div>
        </div>

        <!-- Filter Form -->
        <div class="form-card" style="margin-bottom: 1.5rem;">
            <div class="form-title"><i class="fas fa-filter"></i> Filter Logs</div>
            <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <input type="hidden" name="page" value="activity_logs">
                <input type="hidden" name="log_view" value="<?= htmlspecialchars($log_view) ?>"> <!-- Keep the tab context if needed for other logic -->
                <div class="form-group">
                    <label for="filter_module">Module</label>
                    <select name="filter_module" id="filter_module">
                        <!-- Default option is now "All Modules" -->
                        <option value="">All Modules</option>
                        <!-- Add the predefined friendly module options -->
                        <?php foreach ($friendlyModuleMap as $friendlyLabel => $internalValue): ?>
                            <option value="<?= htmlspecialchars($friendlyLabel) ?>" <?= $selectedModuleValue === $friendlyLabel ? 'selected' : '' ?>>
                                <?= htmlspecialchars($friendlyLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="filter_role">User Role</label>
                    <select name="filter_role" id="filter_role">
                        <option value="">All Roles</option>
                        <?php foreach ($user_roles as $role): ?>
                            <option value="<?= htmlspecialchars($role) ?>" <?= $filter_user_role === $role ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="filter_start_date">Start Date</label>
                    <input type="date" name="filter_start_date" id="filter_start_date" value="<?= htmlspecialchars($filter_date_start) ?>">
                </div>
                <div class="form-group">
                    <label for="filter_end_date">End Date</label>
                    <input type="date" name="filter_end_date" id="filter_end_date" value="<?= htmlspecialchars($filter_date_end) ?>">
                </div>
                <div class="activity-filter-actions">
                    <button type="submit" class="btn-primary"><i class="fas fa-filter"></i>&nbsp;Apply Filters</button>
                    <a href="dashboard_admin.php?page=activity_logs&log_view=<?= urlencode($log_view) ?>" class="activity-reset-btn">
                        <i class="fas fa-rotate-left"></i> Reset Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="card">
            <div class="card-header">
                <!-- Update the card title to reflect the selected module -->
                <div class="card-title"><i class="fas fa-list"></i> Activity Logs (Module: <?= htmlspecialchars(($selectedModuleValue ?? '') !== '' ? $selectedModuleValue : 'All') ?>)</div>
                <span class="card-badge"><?= (int)($log_count ?? 0) ?> Entries</span>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Module</th> <!-- New Column -->
                            <th>User</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-secondary);"> <!-- Updated colspan -->
                                    No activity logs found matching the criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                // Determine the display label for the module
                                $moduleLabel = $internalToFriendly[$log['module']] ?? ucwords(str_replace('_', ' ', $log['module']));
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($log['timestamp']) ?></td>
                                    <td><span class="badge"><?= htmlspecialchars($moduleLabel) ?></span></td> <!-- New Column -->
                                    <td><?= htmlspecialchars($log['user_name'] ?? 'System/Unknown') ?></td>
                                    <td><span class="badge"><?= htmlspecialchars($log['user_role']) ?></span></td>
                                    <td><span class="badge"><?= htmlspecialchars($log['action']) ?></span></td>
                                    <td><?= htmlspecialchars($log['description']) ?></td>
                                    <td><?= htmlspecialchars($log['ip_address']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
    
