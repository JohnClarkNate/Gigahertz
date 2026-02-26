<?php
session_start();
ob_start();
require 'db.php';

$isPmReallocateRequest = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pm_reallocate_materials']));

if (!defined('LOGIN_ATTEMPT_FILE')) {
    define('LOGIN_ATTEMPT_FILE', __DIR__ . '/login_attempts.json');
}
if (!defined('LOGIN_ATTEMPT_LIMIT')) {
    define('LOGIN_ATTEMPT_LIMIT', 5);
}
if (!defined('LOGIN_LOCKOUT_MINUTES')) {
    define('LOGIN_LOCKOUT_MINUTES', 15);
}
// Fixed function definition
if (!function_exists('loadLoginSecurityAttempts')) {
    function loadLoginSecurityAttempts(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        $contents = file_get_contents($filePath);
        if ($contents === false || trim($contents) === '') {
            return [];
        }
        $decoded = json_decode($contents, true);
        // The original code snippet ended here abruptly without a return or closing brace.
        // It likely intended to return the decoded data if valid, or an empty array otherwise.
        return is_array($decoded) ? $decoded : [];
    } // End of loadLoginSecurityAttempts function
} // End of function_exists check
if (!function_exists('persistLoginSecurityAttempts')) {
    function persistLoginSecurityAttempts(string $filePath, array $attempts): void
    {
        $encoded = json_encode($attempts, JSON_PRETTY_PRINT);
        if ($encoded === false) {
            return;
        }
        file_put_contents($filePath, $encoded, LOCK_EX);
    }
}

// --- Add the logActivity function definition here ---
if (!function_exists('logActivity')) {
    function logActivity(PDO $pdo, int $companyId, ?int $userId, string $userRole, string $module, string $action, ?string $description = null, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (company_id, user_id, user_role, module, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $companyId,
            $userId,
            $userRole,
            $module,
            $action,
            $description,
            $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? null)
        ]);
    }
}
// --- End of function definition ---
if (!function_exists('inventoryNextId')) {
    function inventoryNextId(PDO $pdo): int
    {
        static $nextIdCache = [];
        $hash = spl_object_hash($pdo);

        if (!isset($nextIdCache[$hash])) {
            $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) FROM inventory');
            $nextIdCache[$hash] = ((int) $stmt->fetchColumn()) + 1;
        } else {
            $nextIdCache[$hash]++;
        }

        return $nextIdCache[$hash];
    }
}
if (!function_exists('inventoryBomNextId')) {
    function inventoryBomNextId(PDO $pdo): int
    {
        try {
            $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM inventory_bom');
            $nextId = (int)($stmt->fetchColumn() ?: 1);
            return max(1, $nextId);
        } catch (PDOException $e) {
            error_log('inventoryBomNextId failed: ' . $e->getMessage());
            return 1;
        }
    }
}

if (!function_exists('inventoryDecodeSignature')) {
    /**
     * Decodes a URL-safe base64 JSON signature into an associative array.
     * Returns null if decoding fails or the data is not an array.
     */
    function inventoryDecodeSignature(string $signature): ?array
    {
        $signature = trim($signature);
        if ($signature === '') {
            return null;
        }
        // Normalize URL-safe base64
        $b64 = str_replace(['-', '_'], ['+', '/'], $signature);
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('posEnsureHiddenItemsTable')) {
    function posEnsureHiddenItemsTable(PDO $pdo): void
    {
        static $posHiddenTableReady = false;
        if ($posHiddenTableReady) {
            return;
        }

        $hasHiddenByColumn = false;

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pos_hidden_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id INT NOT NULL,
                inventory_id INT NOT NULL,
                hidden_by INT DEFAULT NULL,
                hidden_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_company_inventory (company_id, inventory_id),
                KEY idx_company_hidden (company_id, hidden_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $error) {
            error_log('posEnsureHiddenItemsTable create failed: ' . $error->getMessage());
        }

        try {
            $columnStmt = $pdo->query("SHOW COLUMNS FROM pos_hidden_items LIKE 'hidden_by'");
            $hasHiddenByColumn = (bool)($columnStmt && $columnStmt->fetch(PDO::FETCH_ASSOC));
            if (!$hasHiddenByColumn) {
                $pdo->exec('ALTER TABLE pos_hidden_items ADD COLUMN hidden_by INT DEFAULT NULL AFTER inventory_id');
                $hasHiddenByColumn = true;
            }
        } catch (Throwable $error) {
            error_log('posEnsureHiddenItemsTable column sync failed: ' . $error->getMessage());
            $hasHiddenByColumn = false;
        }

        if ($hasHiddenByColumn) {
            try {
                $indexStmt = $pdo->query('SHOW INDEX FROM pos_hidden_items');
                $indexes = [];
                while ($indexStmt && ($row = $indexStmt->fetch(PDO::FETCH_ASSOC))) {
                    $keyName = $row['Key_name'];
                    if (!isset($indexes[$keyName])) {
                        $indexes[$keyName] = [
                            'non_unique' => (int)$row['Non_unique'],
                            'columns' => []
                        ];
                    }
                    $indexes[$keyName]['columns'][(int)$row['Seq_in_index']] = $row['Column_name'];
                }

                $hasUniquePair = false;
                foreach ($indexes as $meta) {
                    if ($meta['non_unique'] !== 0) {
                        continue;
                    }
                    ksort($meta['columns']);
                    $ordered = array_values($meta['columns']);
                    if ($ordered === ['company_id', 'inventory_id'] || $ordered === ['inventory_id', 'company_id']) {
                        $hasUniquePair = true;
                        break;
                    }
                }

                if (!$hasUniquePair) {
                    $pdo->exec('CREATE UNIQUE INDEX uniq_company_inventory ON pos_hidden_items (company_id, inventory_id)');
                }
            } catch (Throwable $error) {
                error_log('posEnsureHiddenItemsTable index sync failed: ' . $error->getMessage());
            }

            $posHiddenTableReady = true;
        }
    }
}

if (!function_exists('posSetItemVisibility')) {
    function posSetItemVisibility(PDO $pdo, int $companyId, int $inventoryId, bool $hidden, ?int $userId = null): bool
    {
        posEnsureHiddenItemsTable($pdo);
        try {
            if ($hidden) {
                $stmt = $pdo->prepare('
                    INSERT INTO pos_hidden_items (company_id, inventory_id, hidden_by, hidden_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE hidden_by = VALUES(hidden_by), hidden_at = NOW()
                ');
                return $stmt->execute([$companyId, $inventoryId, $userId]);
            }

            $stmt = $pdo->prepare('DELETE FROM pos_hidden_items WHERE company_id = ? AND inventory_id = ?');
            $stmt->execute([$companyId, $inventoryId]);
            return true;
        } catch (Throwable $error) {
            error_log('posSetItemVisibility failed: ' . $error->getMessage());
            return false;
        }
    }
}

if (!function_exists('pmEnsureProjectMetaRows')) {
    function pmEnsureProjectMetaRows(PDO $pdo, array $projects, int $company_id): void
    {
        if (empty($projects)) {
            return; 
        }
        // Normalize IDs to integers to ensure reliable comparisons with DB values
        $ids = array_values(array_filter(array_map(static fn($p) => isset($p['id']) ? (int)$p['id'] : null, $projects), fn($id) => $id !== null));
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $existingStmt = $pdo->prepare("SELECT project_id FROM pm_project_meta WHERE project_id IN ($placeholders)");
        // Execute with integer IDs
        $existingStmt->execute($ids);
        $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
        // Compute missing IDs using integer comparisons to avoid treating identical numeric strings as different
        $missing = array_values(array_filter($ids, fn($id) => !in_array((int)$id, $existingIds, true)));
        if (empty($missing)) {
            return;
        }
        $insertSql = "INSERT INTO pm_project_meta (project_id, company_id) VALUES " . implode(',', array_fill(0, count($missing), '(?, ?)'));
        $params = [];
        foreach ($missing as $pid) {
            $params[] = $pid;
            $params[] = $company_id;
        }
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute($params);
    }
}
if (!function_exists('pmFetchProjectMeta')) {
    function pmFetchProjectMeta(PDO $pdo, int $company_id): array
    {
        $stmt = $pdo->prepare('SELECT project_id, planned_budget, budget_threshold, deadline_buffer_days, auto_completion FROM pm_project_meta WHERE company_id = ?');
        $stmt->execute([$company_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['project_id']] = [
                'planned_budget' => (float)($row['planned_budget'] ?? 0),
                'budget_threshold' => (int)($row['budget_threshold'] ?? 80),
                'deadline_buffer_days' => (int)($row['deadline_buffer_days'] ?? 3),
                'auto_completion' => isset($row['auto_completion']) ? (float)$row['auto_completion'] : null,
            ];
        }
        return $map;
    }
}
if (!function_exists('pmUpdateAutoCompletion')) {
    function pmUpdateAutoCompletion(PDO $pdo, int $projectId, float $completion): void
    {
        $stmt = $pdo->prepare('UPDATE pm_project_meta SET auto_completion = ? WHERE project_id = ?');
        $stmt->execute([$completion, $projectId]);
    }
}

if (!function_exists('pmGetProjectName')) {
    function pmGetProjectName(PDO $pdo, int $companyId, int $projectId): ?string
    {
        if ($projectId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT name FROM projects WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$projectId, $companyId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? $name : null;
    }
}

if (!function_exists('pmGetTaskContext')) {
    function pmGetTaskContext(PDO $pdo, int $companyId, int $taskId): array
    {
        if ($taskId <= 0) {
            return [];
        }
        $stmt = $pdo->prepare('
            SELECT t.title, t.project_id, p.name AS project_name
            FROM pm_tasks t
            LEFT JOIN projects p ON p.id = t.project_id
            WHERE t.id = ? AND (p.company_id = ? OR t.project_id IN (SELECT id FROM projects WHERE company_id = ?))
            LIMIT 1
        ');
        $stmt->execute([$taskId, $companyId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (isset($row['project_id'])) {
            $row['project_id'] = (int)$row['project_id'];
        }
        return $row;
    }
}

if (!function_exists('pmGetResourceName')) {
    function pmGetResourceName(PDO $pdo, int $companyId, int $resourceId): ?string
    {
        if ($resourceId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT name FROM pm_resources WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$resourceId, $companyId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? $name : null;
    }
}

if (!function_exists('pmEnsureMaterialTables')) {
    function pmEnsureColumnExists(PDO $pdo, string $table, string $column, string $definition): void
    {
        $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $columnSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($tableSafe === '' || $columnSafe === '') {
            return;
        }

        try {
            $pdo->exec("ALTER TABLE `{$tableSafe}` ADD COLUMN `{$columnSafe}` {$definition}");
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'Duplicate column name') === false) {
                throw $e;
            }
        }
    }

    function pmEnsureMaterialTables(PDO $pdo): void
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS pm_project_materials (
                id INT(11) NOT NULL AUTO_INCREMENT,
                company_id INT(11) NOT NULL,
                project_id INT(11) NOT NULL,
                bom_id INT(11) NOT NULL,
                inventory_id INT(11) NOT NULL,
                required_qty DECIMAL(12,4) NOT NULL,
                allocated_qty DECIMAL(12,4) NOT NULL,
                shortage_qty DECIMAL(12,4) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pm_material_project (project_id),
                KEY idx_pm_material_inventory (inventory_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_purchase_requests (
                id INT(11) NOT NULL AUTO_INCREMENT,
                company_id INT(11) NOT NULL,
                project_id INT(11) DEFAULT NULL,
                inventory_id INT(11) NOT NULL,
                required_qty DECIMAL(12,4) NOT NULL,
                request_type VARCHAR(50) NOT NULL DEFAULT 'shortage',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                note VARCHAR(255) DEFAULT NULL,
                approval_token VARCHAR(64) DEFAULT NULL,
                approved_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_purchase_project (project_id),
                KEY idx_purchase_inventory (inventory_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            pmEnsureColumnExists($pdo, 'inventory_purchase_requests', 'request_type', "VARCHAR(50) NOT NULL DEFAULT 'shortage' AFTER required_qty");
            pmEnsureColumnExists($pdo, 'inventory_purchase_requests', 'approval_token', "VARCHAR(64) DEFAULT NULL AFTER note");
            pmEnsureColumnExists($pdo, 'inventory_purchase_requests', 'approved_at', 'TIMESTAMP NULL DEFAULT NULL AFTER approval_token');
        } catch (PDOException $e) {
            error_log('PM table bootstrap failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pmAllocateBomToProject')) {
    function pmAllocateBomToProject(PDO $pdo, int $companyId, int $projectId, int $bomId, float $buildQty): array
    {
        $ownsTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownsTransaction = true;
        }

        try {
            $buildQty = max(1, $buildQty);

            $componentStmt = $pdo->prepare("
                SELECT 
                    bi.inventory_id,
                    bi.quantity_required AS bom_quantity_required,
                    COALESCE(i.quantity, 0) AS stock_qty,
                    i.item_name,
                    i.reorder_level,
                    i.sku,
                    i.supplier_id,
                    b.output_qty
                FROM inventory_bom_items bi
                JOIN inventory_bom b ON bi.bom_id = b.id AND b.company_id = ?
                LEFT JOIN inventory i ON bi.inventory_id = i.id AND i.company_id = ?
                WHERE bi.bom_id = ?
            ");
            $componentStmt->execute([$companyId, $companyId, $bomId]);
            $components = $componentStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($components)) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'items_processed' => 0,
                    'allocated' => 0,
                    'purchase_requests' => 0,
                    'details' => [],
                    'message' => 'BOM has no components or does not exist.'
                ];
            }

            $bomOutputQty = (float)($components[0]['output_qty'] ?? 0);
            if ($bomOutputQty <= 0) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'items_processed' => 0,
                    'allocated' => 0,
                    'purchase_requests' => 0,
                    'details' => [],
                    'message' => 'BOM output quantity is invalid.'
                ];
            }

            $pdo->prepare('DELETE FROM pm_project_materials WHERE project_id = ? AND bom_id = ?')->execute([$projectId, $bomId]);

            $allocationInsert = $pdo->prepare('INSERT INTO pm_project_materials (company_id, project_id, bom_id, inventory_id, required_qty, allocated_qty, shortage_qty) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $inventoryUpdate = $pdo->prepare('UPDATE inventory SET quantity = quantity - ? WHERE id = ? AND company_id = ?');
            $purchaseInsert = $pdo->prepare('INSERT INTO inventory_purchase_requests (company_id, project_id, inventory_id, required_qty, request_type, status, note, approval_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $vendorLookupById = $pdo->prepare('SELECT email FROM vendors WHERE id = ? AND company_id = ? LIMIT 1');
            $vendorLookupBySupplier = $pdo->prepare('SELECT email FROM vendors WHERE supplier_id = ? AND company_id = ? LIMIT 1');

            $projectName = pmGetProjectName($pdo, $companyId, $projectId) ?? ('Project #' . $projectId);

            $details = [];
            $itemsProcessed = 0;
            $allocatedTotal = 0;
            $purchaseRequests = 0;
            $totalRequired = 0;

            foreach ($components as $component) {
                $itemsProcessed++;
                $inventoryId = (int)($component['inventory_id'] ?? 0);
                $requiredQty = round((float)$component['bom_quantity_required'] * ($buildQty / $bomOutputQty), 4);
                $availableQty = max(0, (float)$component['stock_qty']);
                $allocatedQty = min($availableQty, $requiredQty);
                $shortage = max(0, $requiredQty - $allocatedQty);

                $allocationInsert->execute([$companyId, $projectId, $bomId, $inventoryId, $requiredQty, $allocatedQty, $shortage]);

                if ($inventoryId > 0 && $allocatedQty > 0) {
                    $inventoryUpdate->execute([$allocatedQty, $inventoryId, $companyId]);
                }

                if ($inventoryId > 0 && $shortage > 0) {
                    $requestType = 'Material Shortage';
                    try {
                        $approvalToken = bin2hex(random_bytes(16));
                    } catch (Throwable $tokenError) {
                        $approvalToken = md5(uniqid((string)$inventoryId, true));
                    }

                    $purchaseInsert->execute([
                        $companyId,
                        $projectId,
                        $inventoryId,
                        $shortage,
                        $requestType,
                        'pending',
                        'Auto-generated from project allocation',
                        $approvalToken
                    ]);
                    $requestId = (int)$pdo->lastInsertId();
                    $purchaseRequests++;

                    $currentQty = max(0, $availableQty - $allocatedQty);
                    $reorderLevel = (int)($component['reorder_level'] ?? 0);
                    $sku = $component['sku'] ?? '';
                    $supplierRef = $component['supplier_id'] ?? null;
                    $vendorEmail = null;

                    if (!empty($supplierRef)) {
                        $vendorLookupById->execute([$supplierRef, $companyId]);
                        $vendorEmail = $vendorLookupById->fetchColumn() ?: null;
                        $vendorLookupById->closeCursor();

                        if ($vendorEmail === null) {
                            $vendorLookupBySupplier->execute([$supplierRef, $companyId]);
                            $vendorEmail = $vendorLookupBySupplier->fetchColumn() ?: null;
                            $vendorLookupBySupplier->closeCursor();
                        }
                    }

                    if ($vendorEmail) {
                        require_once 'send_purchase_request_alert.php';

                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $scriptDir = '';
                        if (!empty($_SERVER['PHP_SELF'])) {
                            $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
                            if ($scriptDir === '.' || $scriptDir === '/') {
                                $scriptDir = '';
                            }
                        }
                        $responsePath = ($scriptDir !== '') ? $scriptDir . '/purchase_request_response.php' : '/purchase_request_response.php';
                        if ($responsePath[0] !== '/') {
                            $responsePath = '/' . $responsePath;
                        }
                        $responseBase = $scheme . $host . $responsePath;
                        $approveUrl = $responseBase . '?token=' . urlencode($approvalToken) . '&decision=approve';
                        $declineUrl = $responseBase . '?token=' . urlencode($approvalToken) . '&decision=decline';

                        $emailPayload = [
                            'recipient'    => $vendorEmail,
                            'request_id'   => $requestId,
                            'request_type' => $requestType,
                            'project_name' => $projectName,
                            'item_name'    => $component['item_name'] ?? 'Unknown Item',
                            'sku'          => $sku,
                            'quantity'     => $shortage,
                            'current_qty'  => $currentQty,
                            'status'       => 'Pending Approval',
                            'approve_url'  => $approveUrl,
                            'decline_url'  => $declineUrl
                        ];

                        if (!sendPurchaseRequestAlert($emailPayload)) {
                            error_log(sprintf('Purchase request email failed for request #%d (%s).', $requestId, $vendorEmail));
                        }
                    } else {
                        error_log(sprintf(
                            'PM purchase request alert skipped for inventory ID %d (request #%d) - no vendor email found (supplier ref: %s).',
                            $inventoryId,
                            $requestId ?? 0,
                            $supplierRef ?? 'none'
                        ));
                    }
                }

                $allocatedTotal += $allocatedQty;
                $totalRequired += $requiredQty;

                $details[] = [
                    'inventory_id' => $inventoryId,
                    'item_name' => $component['item_name'] ?? 'Unknown Item',
                    'required' => $requiredQty,
                    'allocated' => $allocatedQty,
                    'shortage' => $shortage
                ];
            }

            $message = "Allocated {$allocatedTotal} of " . round($totalRequired, 4) . " BOM components.";

            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'items_processed' => $itemsProcessed,
                'allocated' => $allocatedTotal,
                'purchase_requests' => $purchaseRequests,
                'details' => $details,
                'message' => $message
            ];
        } catch (Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error in pmAllocateBomToProject: ' . $e->getMessage());
            return [
                'items_processed' => 0,
                'allocated' => 0,
                'purchase_requests' => 0,
                'details' => [],
                'message' => 'An error occurred during allocation.'
            ];
        }
    }
}

if (!function_exists('hrMinutesBetween')) {
    function hrMinutesBetween(string $date, ?string $timeIn, ?string $timeOut): int
    {
        if (!$timeIn || !$timeOut) {
            return 0;
        }

        $start = hrResolveDateTime($date, $timeIn);
        $end = hrResolveDateTime($date, $timeOut);

        if ($start === false || $end === false) {
            return 0;
        }

        if ($end <= $start) {
            // Handle overnight shifts by rolling the checkout to the next day
            $end += 86400;
        }

        return max(0, (int) round(($end - $start) / 60));
    }
}

if (!function_exists('hrResolveDateTime')) {
    function hrResolveDateTime(string $fallbackDate, string $value)
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $hasDate = preg_match('/\d{4}-\d{2}-\d{2}/', $value) === 1;
        if ($hasDate) {
            $ts = strtotime($value);
            return $ts === false ? false : $ts;
        }

        $ts = strtotime(sprintf('%s %s', $fallbackDate, $value));
        return $ts === false ? false : $ts;
    }
}

if (!function_exists('hrCalculateAttendanceTotals')) {
    function hrCalculateAttendanceTotals(PDO $pdo, int $companyId, string $employeeId, string $startDate, string $endDate): array
    {
        static $cache = [];
        $cacheKey = implode('|', [$companyId, $employeeId, $startDate, $endDate]);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $stmt = $pdo->prepare("SELECT date, time_in, time_out, ot_minutes, overtime_is_paid FROM attendance WHERE company_id = ? AND employee_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC");
        $stmt->execute([$companyId, $employeeId, $startDate, $endDate]);

        $regularMinutes = 0;
        $paidOvertimeMinutes = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $regularMinutes += hrMinutesBetween($row['date'], $row['time_in'] ?? null, $row['time_out'] ?? null);
            if (!empty($row['overtime_is_paid']) && isset($row['ot_minutes'])) {
                $paidOvertimeMinutes += (int) $row['ot_minutes'];
            }
        }

        return $cache[$cacheKey] = [
            'regular_minutes' => $regularMinutes,
            'paid_ot_minutes' => $paidOvertimeMinutes,
        ];
    }
}
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    if ($isPmReallocateRequest) {
        header('Content-Type: application/json', true, 401);
        echo json_encode([
            'success' => false,
            'message' => 'Your session expired. Please log in again to continue.'
        ]);
    } else {
        header("Location: login.php");
    }
    exit();
}
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}
$page = isset($_GET['page']) ? $_GET['page'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admin Dashboard</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
     <script>
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin_style.css">
    <script src="admin_js.js" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const triggerButtons = document.querySelectorAll('.pm-reallocate-trigger');
            const confirmModal = document.getElementById('pmReallocateConfirmModal');
            const confirmBtn = document.getElementById('pmReallocateConfirmBtn');
            const confirmCancel = document.getElementById('pmReallocateConfirmCancel');
            const formModal = document.getElementById('pmReallocateFormModal');
            const formCancel = document.getElementById('pmReallocateCancelBtn');
            const formSubmit = document.getElementById('pmReallocateSubmitBtn');
            const listContainer = document.getElementById('pmReallocateList');
            const errorBox = document.getElementById('pmReallocateError');
            const projectLabel = document.getElementById('pmReallocateProjectLabel');
            const projectTitleEl = document.querySelector('#pmMaterialsCard .card-title');

            let pendingPayload = null;

            const hideModal = (modal) => {
                if (modal) {
                    modal.style.display = 'none';
                }
            };

            const showModal = (modal) => {
                if (modal) {
                    modal.style.display = 'flex';
                }
            };

            const closeAll = () => {
                hideModal(confirmModal);
                hideModal(formModal);
                if (errorBox) {
                    errorBox.textContent = '';
                }
            };

            const allItemsOutOfStock = () => {
                if (!pendingPayload || !Array.isArray(pendingPayload.materials) || !pendingPayload.materials.length) {
                    return false;
                }
                return pendingPayload.materials.every((item) => Number(item.available_stock) <= 0);
            };

            const buildForm = () => {
                if (!pendingPayload || !listContainer) {
                    return;
                }
                listContainer.innerHTML = '';
                formSubmit.disabled = true;

                pendingPayload.materials.forEach((item) => {
                    const row = document.createElement('div');
                    row.className = 'pm-reallocate-row';

                    const title = document.createElement('h5');
                    title.textContent = item.item_name || 'Item';
                    row.appendChild(title);

                    const meta = document.createElement('div');
                    meta.className = 'pm-reallocate-row-meta';
                    meta.innerHTML = `
                        <span><strong>Required:</strong> ${Number(item.required_qty).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</span>
                        <span><strong>Allocated:</strong> ${Number(item.allocated_qty).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</span>
                        <span><strong>Shortage:</strong> ${Number(item.shortage_qty).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</span>
                        <span><strong>In Stock:</strong> ${Number(item.available_stock).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</span>`;
                    row.appendChild(meta);

                    const availableStock = Number(item.available_stock) || 0;
                    const isOutOfStock = availableStock <= 0;
                    const inputWrap = document.createElement('div');
                    inputWrap.className = 'pm-reallocate-input-wrapper';
                    const label = document.createElement('label');
                    label.setAttribute('for', `pmReallocateInput-${item.inventory_id}`);
                    label.textContent = 'Add from inventory';
                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = '0';
                    input.step = '0.01';
                    input.max = String(item.shortage_qty);
                    input.className = 'pm-reallocate-input';
                    input.id = `pmReallocateInput-${item.inventory_id}`;
                    input.dataset.inventoryId = item.inventory_id;
                    input.dataset.maxShortage = item.shortage_qty;
                    input.dataset.availableStock = availableStock;
                    input.dataset.itemName = item.item_name || 'Item';
                    if (isOutOfStock) {
                        input.disabled = true;
                        input.placeholder = 'Out of stock';
                        input.classList.add('pm-reallocate-input-disabled');
                    }

                    input.addEventListener('input', () => {
                        const value = parseFloat(input.value);
                        const shortage = parseFloat(input.dataset.maxShortage);
                        const stock = parseFloat(input.dataset.availableStock);
                        if (Number.isNaN(value) || value <= 0) {
                            input.classList.remove('input-valid');
                            input.classList.add('input-invalid');
                        } else if (value > shortage || value > stock) {
                            input.classList.add('input-invalid');
                            input.classList.remove('input-valid');
                        } else {
                            input.classList.remove('input-invalid');
                            input.classList.add('input-valid');
                        }
                        toggleSubmitState();
                    });

                    inputWrap.appendChild(label);
                    inputWrap.appendChild(input);
                    row.appendChild(inputWrap);
                    if (isOutOfStock) {
                        const notice = document.createElement('p');
                        notice.className = 'pm-reallocate-out';
                        notice.textContent = `${item.item_name || 'This item'} is out of stock. Please re-order.`;
                        row.appendChild(notice);
                    }
                        listContainer.appendChild(row);
                });

                if (projectLabel && pendingPayload.projectName) {
                    projectLabel.textContent = `Project: ${pendingPayload.projectName}`;
                }
            };

            const toggleSubmitState = () => {
                if (!formSubmit) {
                    return;
                }
                const inputs = Array.from(formModal.querySelectorAll('.pm-reallocate-input'));
                const validInputs = inputs.filter((input) => {
                    const value = parseFloat(input.value);
                    if (Number.isNaN(value) || value <= 0) {
                        return false;
                    }
                    const maxShortage = parseFloat(input.dataset.maxShortage);
                    const availableStock = parseFloat(input.dataset.availableStock);
                    return value <= maxShortage && value <= availableStock;
                });
                formSubmit.disabled = validInputs.length === 0;
            };

            const openForm = () => {
                hideModal(confirmModal);
                buildForm();
                showModal(formModal);
            };

            triggerButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const materialsData = btn.dataset.materials ? JSON.parse(btn.dataset.materials) : [];
                    if (!materialsData.length) {
                        return;
                    }
                    const projectName = projectTitleEl ? projectTitleEl.textContent.replace('Project Details', '').trim() : '';
                    pendingPayload = {
                        projectId: parseInt(btn.dataset.projectId, 10),
                        projectName,
                        materials: materialsData
                    };
                    showModal(confirmModal);
                });
            });

            confirmBtn?.addEventListener('click', openForm);
            confirmCancel?.addEventListener('click', () => hideModal(confirmModal));
            formCancel?.addEventListener('click', () => hideModal(formModal));

            formSubmit?.addEventListener('click', () => {
                if (!pendingPayload) {
                    return;
                }
                const inputs = Array.from(formModal.querySelectorAll('.pm-reallocate-input'));
                const materials = [];
                for (const input of inputs) {
                    const value = parseFloat(input.value);
                    if (Number.isNaN(value) || value <= 0) {
                        continue;
                    }
                    const shortage = parseFloat(input.dataset.maxShortage);
                    const stock = parseFloat(input.dataset.availableStock);
                    if (stock <= 0) {
                        errorBox.textContent = `${input.dataset.itemName || 'This item'} is out of stock. Please re-order.`;
                        return;
                    }
                    if (value > shortage || value > stock) {
                        errorBox.textContent = 'One or more entries exceed shortage or available stock.';
                        return;
                    }
                    materials.push({
                        inventory_id: parseInt(input.dataset.inventoryId, 10),
                        allocate_qty: value
                    });
                }

                if (!materials.length) {
                    errorBox.textContent = allItemsOutOfStock()
                        ? 'All selected materials are out of stock. Please re-order before reallocating.'
                        : 'Enter at least one valid adjustment.';
                    return;
                }

                formSubmit.disabled = true;
                errorBox.textContent = '';

                const formData = new FormData();
                formData.append('pm_reallocate_materials', '1');
                formData.append('project_id', pendingPayload.projectId);
                formData.append('materials', JSON.stringify(materials));

                fetch('dashboard_admin.php?page=pm&pm_view=projects', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(response => {
                    const contentType = response.headers.get('content-type') || '';
                    if (contentType.includes('application/json')) {
                        return response.json();
                    }
                    return response.text().then(text => {
                        if (response.redirected && response.url) {
                            window.location.href = response.url;
                            throw new Error('Session expired. Redirecting...');
                        }
                        const trimmed = (text || '').trim();
                        if (!trimmed) {
                            throw new Error('Unexpected empty response from server.');
                        }
                        if (trimmed.startsWith('<!DOCTYPE')) {
                            throw new Error('Unexpected HTML response. Please reload the page and try again.');
                        }
                        throw new Error(trimmed);
                    });
                })
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || 'Reallocation failed.');
                    }
                    closeAll();
                    const params = new URLSearchParams(window.location.search);
                    params.set('page', 'pm');
                    params.set('pm_view', 'projects');
                    params.set('focus_project', pendingPayload.projectId);
                    window.__actionFeedback?.queue('Materials reallocated successfully.', 'success', {
                        defer: true,
                        title: 'Materials Updated'
                    });
                    window.location.search = params.toString();
                })
                .catch(error => {
                    formSubmit.disabled = false;
                    errorBox.textContent = error.message || 'Unable to reallocate materials.';
                });
            });
        });
    </script>
    <script>
        window.__actionFeedback = (function () {
            const STORAGE_KEY = 'erpActionFeedback';
            const DEFAULT_DURATION = 4500;
            const ICONS = {
                success: '✓',
                danger: '✕',
                warning: '!',
                info: 'i'
            };
            const TITLES = {
                success: 'Success',
                danger: 'Action Completed',
                warning: 'Heads Up',
                info: 'Notice'
            };
            const state = { overlay: null, timeout: null };

            const normalizeConfig = (input) => {
                if (typeof input === 'number') {
                    return { duration: input };
                }
                return input && typeof input === 'object' ? input : {};
            };

            const ensureOverlay = () => {
                if (state.overlay && document.body.contains(state.overlay)) {
                    return state.overlay;
                }
                if (!document.body) {
                    return null;
                }
                const overlay = document.createElement('div');
                overlay.className = 'action-overlay';
                overlay.setAttribute('role', 'status');
                overlay.setAttribute('aria-live', 'polite');
                overlay.setAttribute('aria-hidden', 'true');
                overlay.addEventListener('click', (event) => {
                    if (event.target === overlay) {
                        hide();
                    }
                });
                document.body.appendChild(overlay);
                state.overlay = overlay;
                return overlay;
            };

            const hide = () => {
                const overlay = state.overlay;
                if (!overlay) {
                    return;
                }
                overlay.classList.remove('visible');
                overlay.setAttribute('aria-hidden', 'true');
                if (state.timeout) {
                    clearTimeout(state.timeout);
                    state.timeout = null;
                }
                setTimeout(() => {
                    if (overlay === state.overlay) {
                        overlay.innerHTML = '';
                    }
                }, 220);
            };

            const buildCard = (message, type, titleText, opts = {}) => {
                const card = document.createElement('div');
                card.className = `action-overlay-card action-overlay-${type}`;

                const iconWrap = document.createElement('span');
                iconWrap.className = 'action-overlay-icon';
                iconWrap.textContent = ICONS[type] || ICONS.info;

                const contentWrap = document.createElement('div');
                contentWrap.className = 'action-overlay-content';
                if (titleText) {
                    const titleEl = document.createElement('div');
                    titleEl.className = 'action-overlay-title';
                    titleEl.textContent = titleText;
                    contentWrap.appendChild(titleEl);
                }
                const bodyEl = document.createElement('div');
                bodyEl.className = 'action-overlay-message';
                bodyEl.textContent = message;
                contentWrap.appendChild(bodyEl);

                const actionsEl = document.createElement('div');
                actionsEl.className = 'action-overlay-actions';
                const okBtn = document.createElement('button');
                okBtn.type = 'button';
                okBtn.className = 'action-overlay-btn action-overlay-btn-primary';
                okBtn.textContent = opts.okLabel || 'Okay';
                okBtn.addEventListener('click', hide);
                actionsEl.appendChild(okBtn);
                contentWrap.appendChild(actionsEl);

                const dismissBtn = document.createElement('button');
                dismissBtn.type = 'button';
                dismissBtn.className = 'action-overlay-dismiss';
                dismissBtn.setAttribute('aria-label', 'Dismiss notification');
                dismissBtn.innerHTML = '&times;';
                dismissBtn.addEventListener('click', hide);

                card.appendChild(iconWrap);
                card.appendChild(contentWrap);
                card.appendChild(dismissBtn);
                return card;
            };

            const show = (message, type = 'success', config) => {
                if (!message) {
                    return;
                }
                const opts = normalizeConfig(config);
                const duration = Math.max(800, opts.duration || DEFAULT_DURATION);
                const titleText = opts.title || TITLES[type] || TITLES.info;
                const overlay = ensureOverlay();
                if (!overlay) {
                    document.addEventListener('DOMContentLoaded', () => show(message, type, opts), { once: true });
                    return;
                }
                overlay.innerHTML = '';
                overlay.appendChild(buildCard(message, type, titleText, opts));
                overlay.setAttribute('aria-hidden', 'false');
                requestAnimationFrame(() => overlay.classList.add('visible'));
                if (state.timeout) {
                    clearTimeout(state.timeout);
                }
                state.timeout = setTimeout(() => hide(), duration);
            };

            const queue = (message, type = 'success', options = {}) => {
                if (!message) {
                    return;
                }
                const payload = {
                    message,
                    type,
                    title: options.title || null,
                    duration: options.duration || DEFAULT_DURATION,
                    timestamp: Date.now()
                };
                if (options.defer === false) {
                    show(message, type, payload);
                    return;
                }
                try {
                    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
                } catch (error) {
                    console.warn('Action feedback storage failed.', error);
                }
            };

            const consume = () => {
                try {
                    const raw = sessionStorage.getItem(STORAGE_KEY);
                    if (!raw) {
                        return null;
                    }
                    sessionStorage.removeItem(STORAGE_KEY);
                    return JSON.parse(raw);
                } catch (error) {
                    console.warn('Action feedback retrieval failed.', error);
                    return null;
                }
            };

            const api = { queue, consume, show, hide, key: STORAGE_KEY };
            window.queueActionMessage = queue;
            window.flashActionMessage = (message, type = 'success', options) => show(message, type, options);
            return api;
        })();
    </script>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo" style="display: flex; justify-content: center; align-items: center;">
            <span><img src="/giga/white.png" alt="Logo" style="max-width: 180px; height: auto;"></span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <!-- Reports Link Added Here, In MAIN Section -->
        <a href="dashboard_admin.php?page=reports" class="nav-item <?= $page === 'reports' ? 'active' : '' ?>">
            <i class="fas fa-file-alt nav-icon"></i>
            <span>Reports</span>
        </a>
        <div class="nav-section">
            <div class="nav-section-title">Main</div>
            <a href="dashboard_admin.php" class="nav-item <?= empty($page) ? 'active' : '' ?>">
                <i class="fas fa-th-large nav-icon"></i>
                <span>Dashboard</span>
            </a>
            <!-- Add the Activity Logs link here -->
            <a href="dashboard_admin.php?page=activity_logs" class="nav-item <?= $page === 'activity_logs' ? 'active' : '' ?>">
                <i class="fas fa-history nav-icon"></i>
                <span>Activity Logs</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">Management</div>
            <a href="dashboard_admin.php?page=manage_account" class="nav-item <?= $page === 'manage_account' ? 'active' : '' ?>">
                <i class="fas fa-users nav-icon"></i>
                <span>Users</span>
            </a>
            <a href="dashboard_admin.php?page=finance" class="nav-item <?= $page === 'finance' ? 'active' : '' ?>">
                <i class="fas fa-wallet nav-icon"></i>
                <span>Finance</span>
            </a>
            <a href="dashboard_admin.php?page=inventory" class="nav-item <?= $page === 'inventory' ? 'active' : '' ?>">
                <i class="fas fa-boxes nav-icon"></i>
                <span>Inventory</span>
            </a>
            <a href="dashboard_admin.php?page=sales" class="nav-item <?= $page === 'sales' ? 'active' : '' ?>">
                <i class="fas fa-shopping-cart nav-icon"></i>
                <span>Sales</span>
            </a>
        </div>
    </nav>
    <div class="sidebar-footer">
        <form method="POST" data-feedback-disabled="true">
            <button type="submit" name="logout" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                Sign Out
            </button>
        </form>
    </div>
</div>
<div class="main-content">
    <header>
        <div class="header-content">
            <div>
                <div class="header-greeting">
                    <?php
                    $hour = date('H');
                    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
                    echo $greeting . ', ' . htmlspecialchars($_SESSION['user']);
                    ?>
                </div>
                <div class="header-subtitle">
                    <?= date('l, F j, Y') ?>
                </div>
            </div>
            <div class="header-actions">
                <button class="theme-toggle" id="themeToggle" type="button">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="search-box">
                    <input type="text" placeholder="Search...">
                    <i class="fas fa-search search-icon"></i>
                </div>
            </div>
        </div>
    </header>
    <div class="content">
        <style>
            .activity-filter-actions {
                grid-column: 1 / -1;
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 0.35rem;
            }
            .activity-reset-btn {
                display: inline-flex;
                align-items: center;
                gap: 0.25rem;
                padding: 0.35rem 0.75rem;
                border: 1px solid var(--border-color);
                border-radius: var(--radius-sm);
                background: var(--bg-secondary);
                color: var(--text-primary);
                text-decoration: none;
                font-size: 0.85rem;
                font-weight: 600;
                transition: var(--transition);
            }
            .activity-reset-btn:hover {
                background: var(--border-light);
                color: var(--text-primary);
            }
        </style>
<?php
switch ($page) {
    
case 'reports':
    // --- Handle Export Requests --- (Moved inside the case)
    $report_type = $_GET['report'] ?? '';
    $export_type = $_GET['export'] ?? '';
    if ($export_type && $report_type) {
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;
        $category_filter = $_GET['category'] ?? null; // For inventory report
        $company_id = $_SESSION['company_id'];
        // --- Define filename based on report and export type ---
        $date_suffix = '';
        if ($start_date && $end_date) {
            $date_suffix = '_' . $start_date . '_to_' . $end_date;
        } elseif ($start_date) {
            $date_suffix = '_' . $start_date;
        }
        $filename = $report_type . '_report' . $date_suffix . '.' . $export_type;
        // --- Fetch Data based on Report Type ---
        $data = [];
        $headers = [];
        $stmt = null;
        switch ($report_type) {
            case 'finance':
                $headers = ['Date', 'Type', 'Description', 'Amount'];
                $sql = "SELECT date, type, description, amount FROM finance WHERE company_id = ?";
                $params = [$company_id];
                $date_param_index = 1; // Index for start_date in WHERE clause
                $date_param_index2 = 2; // Index for end_date in WHERE clause
                if ($start_date && $end_date) {
                    $sql .= " AND date BETWEEN ? AND ?";
                    $params[] = $start_date;
                    $params[] = $end_date;
                } elseif ($start_date) {
                    $sql .= " AND date >= ?";
                    $params[] = $start_date;
                } elseif ($end_date) {
                    $sql .= " AND date <= ?";
                    $params[] = $end_date;
                }
                $sql .= " ORDER BY date DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_NUM); // Fetch as numeric array for CSV
                break;
            case 'inventory':
                $headers = ['Item Name', 'Quantity', 'Category', 'Date Added'];
                $sql = "SELECT item_name, quantity, category, date_added FROM inventory WHERE company_id = ?";
                $params = [$company_id];
                if ($category_filter && $category_filter !== 'all') {
                     $sql .= " AND category = ?";
                     $params[] = $category_filter;
                }
                $sql .= " ORDER BY item_name ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_NUM);
                break;
            case 'employees':
                $headers = ['Employee ID', 'Name', 'Date Hired'];
                $sql = "SELECT employee_id, name, date_hired FROM hr WHERE company_id = ?";
                $params = [$company_id];
                $date_param_index = 1;
                $date_param_index2 = 2;
                if ($start_date && $end_date) {
                    $sql .= " AND date_hired BETWEEN ? AND ?";
                    $params[] = $start_date;
                    $params[] = $end_date;
                } elseif ($start_date) {
                    $sql .= " AND date_hired >= ?";
                    $params[] = $start_date;
                } elseif ($end_date) {
                    $sql .= " AND date_hired <= ?";
                    $params[] = $end_date;
                }
                $sql .= " ORDER BY date_hired DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_NUM);
                break;
            default:
                // Invalid report type
                header("Location: dashboard_admin.php?page=reports");
                exit();
        }
        if ($export_type === 'csv') {
            // --- Output CSV ---
            // Clear any existing output buffers so only CSV data is sent
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            $output = fopen('php://output', 'w');
            if ($output) {
                // Optionally write UTF-8 BOM for Excel compatibility
                // fwrite($output, "\xEF\xBB\xBF");
                // Write headers
                fputcsv($output, $headers);
                // Write data rows
                foreach ($data as $row) {
                    fputcsv($output, $row);
                }
                fclose($output);
            }
            exit(); // Important: Stop script after outputting CSV
        }
        // Add PDF export logic here if needed later using a library like TCPDF or FPDF
        // For now, redirect back if not CSV
        header("Location: dashboard_admin.php?page=reports&error=export_not_supported");
        exit();
    }
    // --- Fetch available categories for inventory filter --- (Moved inside the case)
    $inventory_categories = [];
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM inventory WHERE company_id = ? ORDER BY category ASC");
    $stmt->execute([$_SESSION['company_id']]);
    $inventory_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="reports-container">
    <?php if (isset($_GET['error']) && $_GET['error'] === 'export_not_supported'): ?>
        <div class="error-message">
            Export type not supported yet. Please try CSV.
        </div>
    <?php endif; ?>
    <!-- Finance Report Section -->
    <div class="report-section">
        <div class="report-header">
            <div class="report-title">
                <i class="fas fa-wallet"></i> Finance Report
            </div>
        </div>
        <div class="report-body">
            <form method="GET" action="dashboard_admin.php">
                <input type="hidden" name="page" value="reports">
                <input type="hidden" name="report" value="finance">
                <div class="report-filters">
                    <div class="filter-group">
                        <label for="finance_start_date">Start Date</label>
                        <input type="date" id="finance_start_date" name="start_date">
                    </div>
                    <div class="filter-group">
                        <label for="finance_end_date">End Date</label>
                        <input type="date" id="finance_end_date" name="end_date">
                    </div>
                </div>
                <div class="report-actions">
                    <button type="submit" name="export" value="csv" class="export-btn csv">
                        <i class="fas fa-file-csv"></i> Export to CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- Inventory Report Section -->
    <div class="report-section">
        <div class="report-header">
            <div class="report-title">
                <i class="fas fa-boxes"></i> Inventory Report
            </div>
        </div>
        <div class="report-body">
            <form method="GET" action="dashboard_admin.php">
                <input type="hidden" name="page" value="reports">
                <input type="hidden" name="report" value="inventory">
                <div class="report-filters">
                    <div class="filter-group">
                        <label for="inventory_category">Category (Optional)</label>
                        <select id="inventory_category" name="category">
                            <option value="all">All Categories</option>
                            <?php foreach ($inventory_categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="report-actions">
                    <button type="submit" name="export" value="csv" class="export-btn csv">
                        <i class="fas fa-file-csv"></i> Export to CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- Employees Report Section -->
    <div class="report-section">
        <div class="report-header">
            <div class="report-title">
                <i class="fas fa-users"></i> Employee Report
            </div>
        </div>
        <div class="report-body">
            <form method="GET" action="dashboard_admin.php">
                <input type="hidden" name="page" value="reports">
                <input type="hidden" name="report" value="employees">
                <div class="report-filters">
                    <div class="filter-group">
                        <label for="employees_start_date">Start Date (Hired)</label>
                        <input type="date" id="employees_start_date" name="start_date">
                    </div>
                    <div class="filter-group">
                        <label for="employees_end_date">End Date (Hired)</label>
                        <input type="date" id="employees_end_date" name="end_date">
                    </div>
                </div>
                <div class="report-actions">
                    <button type="submit" name="export" value="csv" class="export-btn csv">
                        <i class="fas fa-file-csv"></i> Export to CSV
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
    break; // End case 'reports'

case 'manage_account':
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
    
    // Edit Head User
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
        if ($employeeIdChanged && $normalizedSubmittedEmployeeId !== '') {
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
                        $changeNotes[] = 'username ' . $existingUser['username'] . ' → ' . $username;
                    }
                    if ($employeeIdChanged) {
                        $changeNotes[] = 'employee ID ' . ($existingUser['employee_id'] ?? 'none') . ' → ' . ($employee_id !== '' ? $employee_id : 'none');
                    }
                    if ($existingUser['role'] !== $role) {
                        $changeNotes[] = 'role ' . $existingUser['role'] . ' → ' . $role;
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
    
    // Add Head User
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
        $employee_id = $sanitizeEmployeeId($_POST['employee_id'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $rawPassword = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';
        $normalizedEmployeeId = $normalizeEmployeeId($employee_id);

        $addUserFormDefaults = [
            'employee_id' => $employee_id,
            'username' => $username,
            'role' => $role
        ];

        $duplicateSources = [];
        if ($normalizedEmployeeId !== '') {
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
                    $lastAttemptLabel = isset($record['last_attempt']) ? (new DateTime('@' . (int)$record['last_attempt']))->setTimezone($tz)->format('Y-m-d H:i') : '—';
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
                    $lastAttemptLabel = isset($record['last_attempt']) ? (new DateTime('@' . (int)$record['last_attempt']))->setTimezone($tz)->format('Y-m-d H:i') : '—';
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
                        <i class="fas fa-user-shield"></i> Head Users Management
                    </div>
                    <div class="card-header-actions">
                        <span class="card-badge"><span id="userCount"><?= $user_count ?></span> Head Users</span>
                        <span class="card-badge"><span id="lockedCount"><?= $locked_count ?></span> Locked</span>
                        <div style="display:flex; gap:0.5rem; align-items:center;">
                            <button type="button" class="edit-btn" style="padding: 0.6rem 0.9rem; font-size:0.9rem;" onclick="openAddUserModal()">
                                <i class="fas fa-user-plus"></i> Add Head User
                            </button>
                        </div>
                    </div>
                </div>
                <div class="manage-account-tabs">
                    <button type="button" class="manage-account-tab-btn active" data-tab="headUsersTab">
                        Head Users
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
            <h3 class="modal-title" style="text-align: left;"><i class="fas fa-edit"></i> Edit Head User</h3>
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
    <option value="head_hr">Head HR</option>
    <option value="head_finance">Head Finance</option>
    <option value="head_sales">Head Sales</option>
    <option value="head_inventory">Head Inventory</option>
    <option value="head_pos">POS Head</option> <!-- Add this line -->
</select>
                </div>
                <div class="modal-actions" style="justify-content: flex-end; margin-top: 24px;">
                    <button type="button" class="btn-secondary" style="padding: 10px 20px; background: var(--border-color); color: var(--text-primary); border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer;" onclick="closeEditUserModal()">Cancel</button>
                    <button type="submit" name="edit_user" class="btn-primary" style="padding: 10px 20px;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Head User Modal -->
    <div id="addUserModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:500px;">
            <h3 class="modal-title" style="text-align:left;"><i class="fas fa-user-plus"></i> Add Head User</h3>
            <form method="POST" id="addUserForm">
                <div class="form-group">
                    <label for="add_user_employee_id">Employee ID</label>
                    <input type="text" id="add_user_employee_id" name="employee_id" value="<?= htmlspecialchars($addUserFormDefaults['employee_id'] ?? '') ?>" required>
                </div>
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
    <option value="head_hr" <?= ($addUserFormDefaults['role'] ?? '') === 'head_hr' ? 'selected' : '' ?>>Head HR</option>
    <option value="head_finance" <?= ($addUserFormDefaults['role'] ?? '') === 'head_finance' ? 'selected' : '' ?>>Head Finance</option>
    <option value="head_sales" <?= ($addUserFormDefaults['role'] ?? '') === 'head_sales' ? 'selected' : '' ?>>Head Sales</option>
    <option value="head_inventory" <?= ($addUserFormDefaults['role'] ?? '') === 'head_inventory' ? 'selected' : '' ?>>Head Inventory</option>
    <option value="head_pos" <?= ($addUserFormDefaults['role'] ?? '') === 'head_pos' ? 'selected' : '' ?>>POS Head</option> <!-- Add this line -->
</select>
                </div>
                <div class="modal-actions" style="justify-content:flex-end; margin-top:18px;">
                    <button type="button" class="btn-secondary" onclick="closeAddUserModal()">Cancel</button>
                    <button type="submit" name="add_user" class="btn-primary">Add Head User</button>
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

    // Add Head User Modal Functions (inline so button works without external JS)
    function openAddUserModal(options = {}) {
        const modal = document.getElementById('addUserModal');
        if (!modal) return;
        const preserveValues = options && options.preserveValues === true;
        if (!preserveValues) {
            const fields = ['add_user_employee_id','add_user_username','add_user_password','add_user_role'];
            fields.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
        }
        modal.style.display = 'flex';
        // autofocus first field after a short delay
        setTimeout(function(){ const f = document.getElementById('add_user_employee_id'); if (f) f.focus(); }, 80);
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
    break; // End case 'hr'

// --- NEW CASE: Activity Logs ---
case 'activity_logs':
    $company_id = $_SESSION['company_id'];
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
                <div class="card-title"><i class="fas fa-list"></i> Activity Logs (Module: <?= htmlspecialchars($selectedModuleValue !== '' ? $selectedModuleValue : 'All') ?>)</div>
                <span class="card-badge"><?= $log_count ?> Entries</span>
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
    break; // End case 'activity_logs'
// --- END OF NEW CASE ---



             case 'inventory':
    $company_id = $_SESSION['company_id'];
    posEnsureHiddenItemsTable($pdo);
    $inventory_visibility_flash = $_SESSION['inventory_visibility_flash'] ?? null;
    if (isset($_SESSION['inventory_visibility_flash'])) {
        unset($_SESSION['inventory_visibility_flash']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pos_visibility_action'], $_POST['inventory_id'])) {
        $visibilityAction = $_POST['pos_visibility_action'];
        $inventoryId = (int)($_POST['inventory_id'] ?? 0);
        $redirectTarget = 'dashboard_admin.php?page=inventory';

        if ($inventoryId > 0 && in_array($visibilityAction, ['hide', 'show'], true)) {
            $itemLookup = $pdo->prepare('SELECT item_name FROM inventory WHERE id = ? AND company_id = ? LIMIT 1');
            $itemLookup->execute([$inventoryId, $company_id]);
            $itemName = $itemLookup->fetchColumn();

            if ($itemName !== false) {
                $shouldHide = $visibilityAction === 'hide';
                $updated = posSetItemVisibility($pdo, $company_id, $inventoryId, $shouldHide, $_SESSION['user_id'] ?? null);

                if ($updated) {
                    $_SESSION['inventory_visibility_flash'] = [
                        'type' => 'success',
                        'message' => $shouldHide
                            ? sprintf('"%s" is now hidden from the POS.', $itemName)
                            : sprintf('"%s" is now visible in the POS.', $itemName)
                    ];

                    logActivity(
                        $pdo,
                        $company_id,
                        $_SESSION['user_id'] ?? null,
                        $_SESSION['role'] ?? 'admin',
                        'inventory',
                        $shouldHide ? 'pos_hide_item' : 'pos_show_item',
                        sprintf(
                            '%s inventory item "%s" (ID: %d) for POS display.',
                            $shouldHide ? 'Hidden' : 'Restored',
                            $itemName,
                            $inventoryId
                        )
                    );
                } else {
                    $_SESSION['inventory_visibility_flash'] = [
                        'type' => 'danger',
                        'message' => 'Unable to update POS visibility. Please try again.'
                    ];
                }
            } else {
                $_SESSION['inventory_visibility_flash'] = [
                    'type' => 'danger',
                    'message' => 'Inventory item not found.'
                ];
            }
        } else {
            $_SESSION['inventory_visibility_flash'] = [
                'type' => 'danger',
                'message' => 'Invalid POS visibility request.'
            ];
        }

        header('Location: ' . $redirectTarget);
        exit();
    }

    // --- Import Inventory CSV (Updated: Removed warehouse_location) ---
    $inventory_import_message = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_inventory'])) {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $inventory_import_message = "File upload failed. Please try again.";
        } else {
            $fileTmp = $_FILES['import_file']['tmp_name'];
            $fileName = $_FILES['import_file']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                $inventory_import_message = "Invalid file type. Please upload a CSV file.";
            } else {
                // Parse CSV and insert rows inside a transaction
                $handle = fopen($fileTmp, 'r');
                if ($handle === false) {
                    $inventory_import_message = "Unable to read uploaded file.";
                } else {
                    try {
                        $pdo->beginTransaction();
                        // Removed warehouse_location from the INSERT statement
                        $insertStmt = $pdo->prepare("INSERT INTO inventory (id, company_id, sku, item_name, quantity, unit, reorder_level, category, cost_price, selling_price, supplier_id, remarks, date_added) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $rowCount = 0;
                        $line = 0;
                        while (($row = fgetcsv($handle)) !== false) {
                            $line++;
                            // Skip empty rows
                            if (count($row) === 0) continue;
                            // Detect header row by checking first line for non-numeric quantity or header text
                            if ($line === 1) {
                                $first = strtolower(trim($row[0] ?? ''));
                                $second = strtolower(trim($row[1] ?? ''));
                                if (strpos($first, 'item') !== false || strpos($second, 'qty') !== false || !is_numeric($second)) {
                                    // assume header, skip
                                    continue;
                                }
                            }
                            // Map expected columns: sku, item_name, quantity, unit, reorder_level, category, cost_price, selling_price, supplier_id, remarks, date_added (date optional)
                            $sku = trim($row[0] ?? '');
                            $item_name = trim($row[1] ?? '');
                            $quantity_raw = trim($row[2] ?? '0');
                            $unit = trim($row[3] ?? '');
                            $reorder_raw = trim($row[4] ?? '');
                            $category = trim($row[5] ?? '');
                            $cost_raw = trim($row[6] ?? '');
                            $selling_raw = trim($row[7] ?? '');
                            $supplier_raw = trim($row[8] ?? '');
                            // $warehouse = trim($row[9] ?? ''); // Removed warehouse location
                            $remarks = trim($row[9] ?? ''); // Adjusted index for remarks
                            $date_added = trim($row[10] ?? ''); // Adjusted index for date_added

                            if ($item_name === '' || $quantity_raw === '') {
                                // skip invalid row
                                continue;
                            }
                            // normalize quantity
                            $quantity = (int) str_replace(',', '', $quantity_raw);
                            if ($quantity < 0) $quantity = 0;
                            // normalize reorder
                            $reorder_level = $reorder_raw !== '' ? (int) str_replace(',', '', $reorder_raw) : null;
                            // normalize prices
                            $cost_price = $cost_raw !== '' ? (float) $cost_raw : null;
                            $selling_price = $selling_raw !== '' ? (float) $selling_raw : null;
                            // normalize supplier
                            $supplier_id = $supplier_raw !== '' ? (int) $supplier_raw : null;
                            // normalize date
                            if ($date_added === '') {
                                $date_added = date('Y-m-d');
                            } else {
                                // Attempt to convert common date formats to Y-m-d
                                $ts = strtotime($date_added);
                                $date_added = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
                            }

                            // Check if an item with this SKU already exists for this company
                            $checkStmt = $pdo->prepare("SELECT id, item_name, quantity, reorder_level, supplier_id FROM inventory WHERE sku = ? AND company_id = ?");
                            $checkStmt->execute([$sku, $company_id]);
                            $existingItem = $checkStmt->fetch();

                            if ($existingItem) {
                                // Item exists, update its quantity
                                $newQuantity = $existingItem['quantity'] + $quantity; // Add imported quantity to existing quantity
                                $updateQtyStmt = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND company_id = ?");
                                $updateQtyStmt->execute([$newQuantity, $existingItem['id'], $company_id]);

                               // - ADD STOCK CHECK HERE (after quantity reduction) -
if ($new_qty <= $item['reorder_level']) {
    // Fetch vendor email for the *updated* item (from vendors table, using supplier_id_external)
    $vendorEmail = null;
    // We need to find the vendor whose supplier_id_external matches the inventory item's ID
   $vendorStmt = $pdo->prepare("SELECT email FROM vendors WHERE id = ? AND company_id = ?"); // <- Use 'id' in vendors table
$vendorStmt->execute([$item['supplier_id'], $company_id]);
    $vendor = $vendorStmt->fetch();
    if ($vendor && !empty($vendor['email'])) {
        $vendorEmail = $vendor['email'];
    }

    // Send alert to vendor if available
    if ($vendorEmail) {
        require_once 'send_stock_alert.php'; // Include the email function
        sendStockAlert($vendorEmail, $item['item_name'], $new_qty, $item['reorder_level'], $item['sku']);
    }

    if (!$vendorEmail) { // Changed condition: only log if no vendor email found
        error_log("Cannot send stock alert for item {$item['item_name']} (SKU: {$item['sku']}). No vendor email found or empty.");
    }
}
// - END STOCK CHECK -

                            } else {
                                // Item doesn't exist, insert new record
                                $inventoryId = inventoryNextId($pdo);
                                $insertStmt->execute([$inventoryId, $company_id, $sku, $item_name, $quantity, $unit, $reorder_level, $category, $cost_price, $selling_price, $supplier_id, $remarks, $date_added]);
                                $rowCount++;
                            }
                        }
                        fclose($handle);
                        $pdo->commit();
                        $inventory_import_message = "Import successful. {$rowCount} item(s) added.";
                        logActivity(
                            $pdo,
                            $company_id,
                            $_SESSION['user_id'] ?? null,
                            $_SESSION['role'] ?? 'unknown',
                            'inventory',
                            'import_inventory',
                            "Imported {$rowCount} inventory item(s) via {$fileName}."
                        );
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        if (is_resource($handle)) fclose($handle);
                        $inventory_import_message = "Import failed: " . htmlspecialchars($e->getMessage());
                    }
                }
            }
        }
    }

        // --- Add Item Logic (Updated: Removed warehouse_location) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_inventory'])) {
            $sku = trim($_POST['sku'] ?? '');
            $item_name = trim($_POST['item_name'] ?? '');
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit = trim($_POST['unit'] ?? '');
            $reorder_raw = $_POST['reorder_level'] ?? '';
            $category = trim($_POST['category'] ?? '');
            $cost_raw = $_POST['cost_price'] ?? '';
            $selling_raw = $_POST['selling_price'] ?? '';
            $supplier_raw = $_POST['supplier_id'] ?? '';
            $date_raw = trim($_POST['date_added'] ?? '');
            $inventoryAction = null;
            $inventoryDescription = null;

            // Validate required fields
            if (
                $sku === '' ||
                $item_name === '' ||
                $unit === '' ||
                $category === '' ||
                $date_raw === '' ||
                $quantity <= 0 ||
                $reorder_raw === '' ||
                $cost_raw === '' ||
                $selling_raw === '' ||
                $supplier_raw === ''
            ) {
                // $inventory_add_error = "All inventory fields are required.";
            } else {
                $reorder_level = (int)$reorder_raw;
                $cost_price = (float)$cost_raw;
                $selling_price = (float)$selling_raw;
                $supplier_id = (int)$supplier_raw;
                $dateTimestamp = strtotime($date_raw);
                $date_added = $dateTimestamp ? date('Y-m-d', $dateTimestamp) : date('Y-m-d');

                // Prevent duplicate SKUs per company (including defective items)
                $skuCheckStmt = $pdo->prepare("SELECT id, item_name FROM inventory WHERE sku = ? AND company_id = ? LIMIT 1");
                $skuCheckStmt->execute([$sku, $company_id]);
                $skuConflict = $skuCheckStmt->fetch(PDO::FETCH_ASSOC);
                if ($skuConflict) {
                    $_SESSION['inventory_add_error'] = sprintf(
                        'SKU %s is already used by %s. Please use a different SKU.',
                        $sku,
                        $skuConflict['item_name'] ?? 'another item'
                    );
                    header("Location: dashboard_admin.php?page=inventory");
                    exit();
                }

                $financeAmount = $quantity * $cost_price;
                $supplierName = 'Unknown supplier';
                if ($supplier_id > 0) {
                    // Try matching against vendor primary key first, then fall back to the supplier_id column
                    $supplierStmt = $pdo->prepare("
                        SELECT vendor_name FROM vendors
                        WHERE company_id = ? AND (id = ? OR supplier_id = ?)
                        LIMIT 1
                    ");
                    $supplierStmt->execute([$company_id, $supplier_id, $supplier_id]);
                    $supplierName = $supplierStmt->fetchColumn() ?: $supplierName;
                }

                try {
                    $pdo->beginTransaction();

                    // Check if an item with the same name and category already exists (non-defective)
                    $checkStmt = $pdo->prepare("
                        SELECT id, quantity FROM inventory 
                        WHERE sku = ? AND item_name = ? AND category = ? AND company_id = ? 
                        AND (is_defective = 0 OR is_defective IS NULL)
                    ");
                    $checkStmt->execute([$sku, $item_name, $category, $company_id]);
                    $existingItem = $checkStmt->fetch();

                    if ($existingItem) {
                        // Item exists, update quantity and other fields
                        $newQuantity = $existingItem['quantity'] + $quantity;
                        $updateStmt = $pdo->prepare("
                            UPDATE inventory 
                            SET quantity = ?, sku = ?, unit = ?, 
                                reorder_level = ?, cost_price = ?, 
                                selling_price = ?, supplier_id = ?, category = ?
                            WHERE id = ?
                        ");
                        $updateStmt->execute([
                            $newQuantity, $sku, $unit, $reorder_level, $cost_price, $selling_price, 
                            $supplier_id, $category, $existingItem['id']
                        ]);
                        $inventoryAction = 'update_item';
                        $inventoryDescription = "Increased {$item_name} stock by {$quantity}. New total: {$newQuantity}.";
                    } else {
                        // Item doesn't exist, insert new
                        $insertStmt = $pdo->prepare("
                            INSERT INTO inventory (id, company_id, sku, item_name, quantity, unit, reorder_level, category, cost_price, selling_price, supplier_id, date_added) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $newInventoryId = inventoryNextId($pdo);
                        $insertStmt->execute([
                            $newInventoryId,
                            $company_id, $sku, $item_name, $quantity, $unit, $reorder_level, $category, 
                            $cost_price, $selling_price, $supplier_id, $date_added
                        ]);
                        $inventoryAction = 'add_item';
                        $inventoryDescription = "Added inventory item {$item_name} ({$quantity} {$unit}) in {$category}.";
                    }

                    if ($financeAmount > 0) {
                        $financeDescription = sprintf('%s from %s - PHP %s', $item_name, $supplierName, number_format($financeAmount, 2));
                        $financeStmt = $pdo->prepare("INSERT INTO finance (company_id, amount, type, description, date) VALUES (?, ?, ?, ?, ?)");
                        $financeStmt->execute([
                            $company_id,
                            $financeAmount,
                            'expense',
                            $financeDescription,
                            $date_added
                        ]);
                    }

                    if ($inventoryAction !== null) {
                        logActivity(
                            $pdo,
                            $company_id,
                            $_SESSION['user_id'] ?? null,
                            $_SESSION['role'] ?? 'unknown',
                            'inventory',
                            $inventoryAction,
                            $inventoryDescription
                        );
                    }

                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $_SESSION['inventory_add_error'] = 'Failed to add inventory item: ' . $e->getMessage();
                }
            }
            // Redirect to prevent resubmission
            header("Location: dashboard_admin.php?page=inventory");
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_inventory_item'])) {
            $itemId = (int)($_POST['inventory_id'] ?? 0);
            $sku = trim($_POST['sku'] ?? '');
            $item_name = trim($_POST['item_name'] ?? '');
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit = trim($_POST['unit'] ?? '');
            $reorder_raw = $_POST['reorder_level'] ?? '';
            $cost_raw = $_POST['cost_price'] ?? '';
            $price_raw = $_POST['selling_price'] ?? '';
            $date_raw = trim($_POST['date_added'] ?? '');

            if (
                $itemId <= 0 ||
                $sku === '' ||
                $item_name === '' ||
                $unit === '' ||
                $reorder_raw === '' ||
                $cost_raw === '' ||
                $price_raw === '' ||
                $date_raw === '' ||
                $quantity < 0
            ) {
                $_SESSION['inventory_edit_error'] = 'All edit fields are required and quantity cannot be negative.';
                header("Location: dashboard_admin.php?page=inventory");
                exit();
            }

            $reorder_level = (int)$reorder_raw;
            $cost_price = (float)$cost_raw;
            $selling_price = (float)$price_raw;
            $dateTimestamp = strtotime($date_raw);
            $date_added = $dateTimestamp ? date('Y-m-d', $dateTimestamp) : date('Y-m-d');

            try {
                $pdo->beginTransaction();

                $fetchStmt = $pdo->prepare("SELECT id FROM inventory WHERE id = ? AND company_id = ? LIMIT 1");
                $fetchStmt->execute([$itemId, $company_id]);
                $existing = $fetchStmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing) {
                    throw new RuntimeException('Inventory item not found.');
                }

                $skuStmt = $pdo->prepare("SELECT id FROM inventory WHERE sku = ? AND company_id = ? AND id <> ? LIMIT 1");
                $skuStmt->execute([$sku, $company_id, $itemId]);
                if ($skuStmt->fetchColumn()) {
                    throw new RuntimeException('Another item already uses that SKU.');
                }

                $updateStmt = $pdo->prepare("
                    UPDATE inventory
                    SET sku = ?, item_name = ?, quantity = ?,  = ?, reorder_level = ?,
                        cost_price = ?, selling_price = ?, date_added = ?
                    WHERE id = ? AND company_id = ?
                ");
                $updateStmt->execute([
                    $sku,
                    $item_name,
                    $quantity,
                    $unit,
                    $reorder_level,
                    $cost_price,
                    $selling_price,
                    $date_added,
                    $itemId,
                    $company_id
                ]);

                logActivity(
                    $pdo,
                    $company_id,
                    $_SESSION['user_id'] ?? null,
                    $_SESSION['role'] ?? 'unknown',
                    'inventory',
                    'edit_item',
                    "Updated inventory item {$item_name} (SKU: {$sku})."
                );

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['inventory_edit_error'] = 'Failed to update inventory item: ' . $e->getMessage();
            }

            header("Location: dashboard_admin.php?page=inventory");
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['increase_inventory_qty'])) {
            $targetId = (int)($_POST['inventory_id'] ?? 0);
            $deltaQty = (int)($_POST['add_quantity'] ?? 0);

            if ($targetId > 0 && $deltaQty !== 0) {
                $fetchStmt = $pdo->prepare("SELECT item_name, quantity, cost_price FROM inventory WHERE id = ? AND company_id = ? AND (is_defective = 0 OR is_defective IS NULL) LIMIT 1");
                $fetchStmt->execute([$targetId, $company_id]);
                $targetItem = $fetchStmt->fetch(PDO::FETCH_ASSOC);

                if ($targetItem) {
                    $currentQuantity = (int)$targetItem['quantity'];
                    $newQuantity = $currentQuantity + $deltaQty;

                    if ($newQuantity < 0) {
                        $_SESSION['inventory_adjust_error'] = sprintf(
                            'Cannot deduct %d unit(s) from %s. Only %d unit(s) available.',
                            abs($deltaQty),
                            $targetItem['item_name'],
                            $currentQuantity
                        );
                    } else {
                        $updateStmt = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND company_id = ?");
                        $updateStmt->execute([$newQuantity, $targetId, $company_id]);

                        if ($deltaQty > 0) {
                            $unitCost = isset($targetItem['cost_price']) ? (float)$targetItem['cost_price'] : 0.0;
                            $expenseAmount = $unitCost * $deltaQty;
                            if ($expenseAmount > 0) {
                                $financeStmt = $pdo->prepare("INSERT INTO finance (company_id, amount, type, description, date) VALUES (?, ?, ?, ?, ?)");
                                $financeDescription = sprintf('Inventory replenishment: %s (+%d units)', $targetItem['item_name'], $deltaQty);
                                $financeStmt->execute([
                                    $company_id,
                                    $expenseAmount,
                                    'expense',
                                    $financeDescription,
                                    date('Y-m-d')
                                ]);
                            }
                        }

                        $changeQty = abs($deltaQty);
                        $actionText = $deltaQty > 0 ? 'Added' : 'Deducted';
                        logActivity(
                            $pdo,
                            $company_id,
                            $_SESSION['user_id'] ?? null,
                            $_SESSION['role'] ?? 'unknown',
                            'inventory',
                            'adjust_quantity',
                            sprintf('%s %d qty %s %s. New total: %d', $actionText, $changeQty, $deltaQty > 0 ? 'to' : 'from', $targetItem['item_name'], $newQuantity)
                        );
                    }
                } else {
                    $_SESSION['inventory_adjust_error'] = 'Inventory item not found or unavailable.';
                }
            } elseif ($targetId > 0 && $deltaQty === 0) {
                $_SESSION['inventory_adjust_error'] = 'Please enter a non-zero quantity change.';
            }

            header("Location: dashboard_admin.php?page=inventory");
            exit();
        }


        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_inventory'])) {
            $rawIds = $_POST['inventory_ids'] ?? [];
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)$rawIds), static fn($id) => $id > 0)));

            if (!empty($selectedIds)) {
                $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                $params = array_merge([$company_id], $selectedIds);

                $fetchStmt = $pdo->prepare("SELECT id, item_name, quantity FROM inventory WHERE company_id = ? AND id IN ($placeholders)");
                $fetchStmt->execute($params);
                $itemsToDelete = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($itemsToDelete)) {
                    $deleteStmt = $pdo->prepare("DELETE FROM inventory WHERE company_id = ? AND id IN ($placeholders)");
                    $deleteStmt->execute($params);

                    $names = array_slice(array_column($itemsToDelete, 'item_name'), 0, 5);
                    $summaryNames = implode(', ', array_map(static fn($name) => $name ?: 'Unnamed Item', $names));
                    if (count($itemsToDelete) > 5) {
                        $summaryNames .= sprintf(' +%d more', count($itemsToDelete) - 5);
                    }

                    logActivity(
                        $pdo,
                        $company_id,
                        $_SESSION['user_id'] ?? null,
                        $_SESSION['role'] ?? 'unknown',
                        'inventory',
                        'bulk_delete_items',
                        sprintf('Bulk deleted %d inventory item(s): %s', count($itemsToDelete), $summaryNames)
                    );

                    $_SESSION['inventory_bulk_flash'] = [
                        'type' => 'success',
                        'message' => sprintf('Deleted %d inventory item(s).', count($itemsToDelete))
                    ];
                } else {
                    $_SESSION['inventory_bulk_flash'] = [
                        'type' => 'warning',
                        'message' => 'Selected inventory items were not found.'
                    ];
                }
            } else {
                $_SESSION['inventory_bulk_flash'] = [
                    'type' => 'warning',
                    'message' => 'Select at least one inventory item to delete.'
                ];
            }

            header('Location: dashboard_admin.php?page=inventory');
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_defective'])) {
            $rawIds = $_POST['defective_ids'] ?? [];
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)$rawIds), static fn($id) => $id > 0)));

            if (!empty($selectedIds)) {
                $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                $params = array_merge([$company_id], $selectedIds);

                $fetchStmt = $pdo->prepare("SELECT id, item_name FROM inventory WHERE company_id = ? AND is_defective = 1 AND id IN ($placeholders)");
                $fetchStmt->execute($params);
                $defectiveItems = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($defectiveItems)) {
                    $deleteStmt = $pdo->prepare("DELETE FROM inventory WHERE company_id = ? AND is_defective = 1 AND id IN ($placeholders)");
                    $deleteStmt->execute($params);

                    $names = array_slice(array_column($defectiveItems, 'item_name'), 0, 5);
                    $summaryNames = implode(', ', array_map(static fn($name) => $name ?: 'Unnamed Item', $names));
                    if (count($defectiveItems) > 5) {
                        $summaryNames .= sprintf(' +%d more', count($defectiveItems) - 5);
                    }

                    logActivity(
                        $pdo,
                        $company_id,
                        $_SESSION['user_id'] ?? null,
                        $_SESSION['role'] ?? 'unknown',
                        'inventory',
                        'bulk_delete_defective_items',
                        sprintf('Bulk deleted %d defective item(s): %s', count($defectiveItems), $summaryNames)
                    );

                    $_SESSION['inventory_bulk_flash'] = [
                        'type' => 'success',
                        'message' => sprintf('Deleted %d defective item(s).', count($defectiveItems))
                    ];
                } else {
                    $_SESSION['inventory_bulk_flash'] = [
                        'type' => 'warning',
                        'message' => 'Selected defective items were not found.'
                    ];
                }
            } else {
                $_SESSION['inventory_bulk_flash'] = [
                    'type' => 'warning',
                    'message' => 'Select at least one defective item to delete.'
                ];
            }

            header('Location: dashboard_admin.php?page=inventory#defective');
            exit();
        }

        $inventory_add_error = $_SESSION['inventory_add_error'] ?? null;
        if (isset($_SESSION['inventory_add_error'])) {
            unset($_SESSION['inventory_add_error']);
        }

        $inventory_edit_error = $_SESSION['inventory_edit_error'] ?? null;
        if (isset($_SESSION['inventory_edit_error'])) {
            unset($_SESSION['inventory_edit_error']);
        }

        $inventory_adjust_error = $_SESSION['inventory_adjust_error'] ?? null;
        if (isset($_SESSION['inventory_adjust_error'])) {
            unset($_SESSION['inventory_adjust_error']);
        }

        $inventory_bulk_flash = $_SESSION['inventory_bulk_flash'] ?? null;
        if (isset($_SESSION['inventory_bulk_flash'])) {
            unset($_SESSION['inventory_bulk_flash']);
        }

        $deleteSignature = $_GET['delete_inventory_sig'] ?? null;
        $handledInventoryDelete = false;
        if ($deleteSignature !== null) {
            $signatureData = inventoryDecodeSignature($deleteSignature);
            if (is_array($signatureData) && isset($signatureData['id'])) {
                $deleteId = (int)($signatureData['id'] ?? 0);
                $deleteSku = $signatureData['sku'] ?? null;
                $deleteName = $signatureData['item_name'] ?? null;
                $deleteDate = $signatureData['date_added'] ?? null;
                $deleteQty = isset($signatureData['quantity']) ? (int)$signatureData['quantity'] : null;

                $targetParams = [$company_id, $deleteId, $deleteSku, $deleteName, $deleteDate, $deleteQty];
                $fetchStmt = $pdo->prepare("
                    SELECT item_name, quantity FROM inventory
                    WHERE company_id = ? AND id = ?
                      AND sku <=> ? AND item_name <=> ?
                      AND date_added <=> ? AND quantity <=> ?
                    LIMIT 1
                ");
                $fetchStmt->execute($targetParams);
                $deletedItem = $fetchStmt->fetch(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("
                    DELETE FROM inventory
                    WHERE company_id = ? AND id = ?
                      AND sku <=> ? AND item_name <=> ?
                      AND date_added <=> ? AND quantity <=> ?
                    LIMIT 1
                ");
                $stmt->execute($targetParams);
                $handledInventoryDelete = true;

                if ($deletedItem) {
                    $name = $deletedItem['item_name'] ?? 'Unknown Item';
                    $qty = (int)($deletedItem['quantity'] ?? 0);
                    logActivity(
                        $pdo,
                        $company_id,
                        $_SESSION['user_id'] ?? null,
                        $_SESSION['role'] ?? 'unknown',
                        'inventory',
                        'delete_item',
                        "Deleted inventory item {$name} (Qty: {$qty})."
                    );
                }
            }
        }

        if (!$handledInventoryDelete && isset($_GET['delete_inventory'])) {
            $delete_id = (int)$_GET['delete_inventory'];
            $fetchStmt = $pdo->prepare("SELECT item_name, quantity FROM inventory WHERE id = ? AND company_id = ? LIMIT 1");
            $fetchStmt->execute([$delete_id, $company_id]);
            $deletedItem = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ? AND company_id = ? LIMIT 1");
            $stmt->execute([$delete_id, $company_id]);

            if ($deletedItem) {
                $name = $deletedItem['item_name'] ?? 'Unknown Item';
                $qty = (int)($deletedItem['quantity'] ?? 0);
                logActivity(
                    $pdo,
                    $company_id,
                    $_SESSION['user_id'] ?? null,
                    $_SESSION['role'] ?? 'unknown',
                    'inventory',
                    'delete_item',
                    "Deleted inventory item {$name} (Qty: {$qty})."
                );
            }
        }


        // --- START OF INVENTORY DEFECTIVE HANDLERS ---

         // - START OF INVENTORY DEFECTIVE HANDLERS - // (Add this if missing)
// - Defective items handler: RESTORE -
$restoreSignature = $_GET['restore_defective_sig'] ?? null;
$restorePayload = $restoreSignature ? inventoryDecodeSignature($restoreSignature) : null;
$restoreId = null;
if ($restorePayload && isset($restorePayload['id'])) {
    $restoreId = (int)$restorePayload['id'];
} elseif (isset($_GET['restore_defective'])) {
    $restoreId = (int)$_GET['restore_defective'];
}

if ($restoreId !== null) {
    $extraRestoreSql = '';
    $extraRestoreParams = [];
    
    if ($restorePayload) {
        $extraRestoreSql = ' AND item_name <=> ? AND quantity <=> ? AND category <=> ? AND defective_at <=> ?';
        $extraRestoreParams = [
            $restorePayload['item_name'] ?? null,
            isset($restorePayload['quantity']) ? (int)$restorePayload['quantity'] : null,
            $restorePayload['category'] ?? null,
            $restorePayload['defective_at'] ?? null,
        ];
    }
    
    try {
        $pdo->beginTransaction(); // Start transaction for data integrity

        // 1. Fetch the specific defective item to get its details
        // We need reorder_level and supplier_id for the potential alert
        $fetchStmt = $pdo->prepare("
            SELECT id, item_name, quantity, category, date_added, company_id, reorder_level, sku, supplier_id
            FROM inventory
            WHERE id = ? AND company_id = ? AND is_defective = 1
            {$extraRestoreSql}
        ");
        $fetchParams = array_merge([$restoreId, $company_id], $extraRestoreParams);
        $fetchStmt->execute($fetchParams);
        $defectiveItem = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        if (!$defectiveItem) {
            throw new Exception("Defective item not found or does not belong to the company.");
        }

        $restoredQuantity = (int)$defectiveItem['quantity'];
        $itemName = $defectiveItem['item_name'];
        $category = $defectiveItem['category'];
        $originalSku = $defectiveItem['sku']; // Capture original SKU
        $originalReorderLevel = (int)$defectiveItem['reorder_level']; // Capture original reorder level
        $originalSupplierId = (int)$defectiveItem['supplier_id']; // Capture original supplier ID

        // 2. Check if a non-defective item with the same name and category exists for this company
        $checkStmt = $pdo->prepare("
            SELECT id, quantity, reorder_level, sku, supplier_id
            FROM inventory
            WHERE item_name = ? AND category = ? AND company_id = ?
            AND (is_defective = 0 OR is_defective IS NULL)
        ");
        $checkStmt->execute([$itemName, $category, $company_id]);
        $existingItem = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingItem) {
            // An existing non-defective item was found.
            $newQuantity = (int)$existingItem['quantity'] + $restoredQuantity;
            $updateExistingStmt = $pdo->prepare("
                UPDATE inventory
                SET quantity = ?
                WHERE id = ?
            ");
            $updateExistingStmt->execute([$newQuantity, $existingItem['id']]);

            // 3. Delete the defective record as its quantity is now merged
            $deleteDefectiveStmt = $pdo->prepare("
                DELETE FROM inventory
                WHERE id = ? AND company_id = ?
                {$extraRestoreSql}
                LIMIT 1
            ");
            $deleteDefectiveStmt->execute(array_merge([$restoreId, $company_id], $extraRestoreParams));

             logActivity(
                $pdo,
                $company_id,
                $_SESSION['user_id'] ?? null,
                $_SESSION['role'] ?? 'unknown',
                'inventory',
                'restore_defective',
                "Restored {$restoredQuantity} qty of {$itemName} (from defective item ID: {$restoreId}). Merged with existing item (ID: {$existingItem['id']}). New total: {$newQuantity}."
            );

// - ADD STOCK CHECK HERE (after quantity addition) -
if ($newQuantity <= $existingItem['reorder_level']) {
    // Fetch supplier email for the *updated* item (from suppliers table)
    $supplierEmail = null;
    if (!empty($existingItem['supplier_id'])) { // Check if inventory item has a supplier_id pointing to suppliers table
        $supplierStmt = $pdo->prepare("SELECT email FROM suppliers WHERE id = ? AND company_id = ?");
        $supplierStmt->execute([$existingItem['supplier_id'], $company_id]);
        $supplier = $supplierStmt->fetch();
        if ($supplier && !empty($supplier['email'])) {
            $supplierEmail = $supplier['email'];
        }
    }

    // Fetch vendor email for the *updated* item (from vendors table, using associated_inventory_item_id)
    $vendorEmail = null;
    // We need to find the vendor whose associated_inventory_item_id matches the inventory item's ID
    $vendorStmt = $pdo->prepare("SELECT email FROM vendors WHERE id = ? AND company_id = ?"); // Use 'id' in vendors table
$vendorStmt->execute([$existingItem['supplier_id'], $company_id]); // Use inventory item's supplier_id
    $vendor = $vendorStmt->fetch();
    if ($vendor && !empty($vendor['email'])) {
        $vendorEmail = $vendor['email'];
    }

    // Send alert to supplier if available
    if ($supplierEmail) {
        require_once 'send_stock_alert.php'; // Include the email function
        sendStockAlert($supplierEmail, $existingItem['item_name'], $newQuantity, $existingItem['reorder_level'], $existingItem['sku']);
    }

    // Send alert to vendor if available
    if ($vendorEmail) {
        require_once 'send_stock_alert.php'; // Include the email function
        // You might want to customize the message slightly or use a different function if needed
        sendStockAlert($vendorEmail, $existingItem['item_name'], $newQuantity, $existingItem['reorder_level'], $existingItem['sku']);
    }

    if (!$supplierEmail && !$vendorEmail) {
        error_log("Cannot send stock alert for item {$existingItem['item_name']} (SKU: {$existingItem['sku']}). No supplier or vendor email found or empty.");
    }
}
// - END STOCK CHECK -
        } else {
            // No existing non-defective item found.
            // Simply restore the defective item by removing the defective flag.
            $restoreStmt = $pdo->prepare("
                UPDATE inventory
                SET is_defective = NULL, defective_reason = NULL, defective_at = NULL, quantity = ?
                WHERE id = ? AND company_id = ?
                {$extraRestoreSql}
                LIMIT 1
            ");
            // Note: The quantity from the defective record becomes the quantity of the restored item
            $restoreStmt->execute([$restoredQuantity, $restoreId, $company_id]);

             logActivity(
                $pdo,
                $company_id,
                $_SESSION['user_id'] ?? null,
                $_SESSION['role'] ?? 'unknown',
                'inventory',
                'restore_defective',
                "Restored {$restoredQuantity} qty of {$itemName} (ID: {$restoreId}) from defective status."
            );

          // - ADD STOCK CHECK HERE (after quantity reduction) -
if ($new_qty <= $item['reorder_level']) {
    // Fetch vendor email for the *updated* item (from vendors table, using supplier_id_external)
    $vendorEmail = null;
    // We need to find the vendor whose supplier_id_external matches the inventory item's ID
   $vendorStmt = $pdo->prepare("SELECT email FROM vendors WHERE id = ? AND company_id = ?"); // <- Use 'id' in vendors table
$vendorStmt->execute([$item['supplier_id'], $company_id]); // Use inventory item's ID to find associated vendor
    $vendor = $vendorStmt->fetch();
    if ($vendor && !empty($vendor['email'])) {
        $vendorEmail = $vendor['email'];
    }

    // Send alert to vendor if available
    if ($vendorEmail) {
        require_once 'send_stock_alert.php'; // Include the email function
        sendStockAlert($vendorEmail, $item['item_name'], $new_qty, $item['reorder_level'], $item['sku']);
    }

    if (!$vendorEmail) { // Changed condition: only log if no vendor email found
        error_log("Cannot send stock alert for item {$item['item_name']} (SKU: {$item['sku']}). No vendor email found or empty.");
    }
}
// - END STOCK CHECK -
        }

        $pdo->commit(); // Commit the transaction

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        error_log("Restore defective error: " . $e->getMessage());
    }
    
    header("Location: dashboard_admin.php?page=inventory");
    exit();
}

// - Defective items handler: DELETE (from defective list) -
$deleteDefSignature = $_GET['delete_defective_sig'] ?? null;
$deleteDefPayload = $deleteDefSignature ? inventoryDecodeSignature($deleteDefSignature) : null;
$deleteDefId = null;

if ($deleteDefPayload && isset($deleteDefPayload['id'])) {
    $deleteDefId = (int)$deleteDefPayload['id'];
} elseif (isset($_GET['delete_defective'])) {
    $deleteDefId = (int)$_GET['delete_defective'];
}

if ($deleteDefId !== null) {
    $extraDeleteSql = '';
    $extraDeleteParams = [];
    
    if ($deleteDefPayload) {
        $extraDeleteSql = ' AND item_name <=> ? AND quantity <=> ? AND category <=> ? AND defective_at <=> ?';
        $extraDeleteParams = [
            $deleteDefPayload['item_name'] ?? null,
            isset($deleteDefPayload['quantity']) ? (int)$deleteDefPayload['quantity'] : null,
            $deleteDefPayload['category'] ?? null,
            $deleteDefPayload['defective_at'] ?? null,
        ];
    }
    
    $fetchStmt = $pdo->prepare("
        SELECT item_name, quantity 
        FROM inventory 
        WHERE id = ? AND company_id = ? AND is_defective = 1
        {$extraDeleteSql} 
        LIMIT 1
    ");
    $fetchStmt->execute(array_merge([$deleteDefId, $company_id], $extraDeleteParams));
    $defectiveItem = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        DELETE FROM inventory 
        WHERE id = ? AND company_id = ? AND is_defective = 1
        {$extraDeleteSql} 
        LIMIT 1
    ");
    $stmt->execute(array_merge([$deleteDefId, $company_id], $extraDeleteParams));

    if ($defectiveItem) {
        $name = $defectiveItem['item_name'] ?? 'Unknown Item';
        $qty = (int)($defectiveItem['quantity'] ?? 0);
        
        logActivity(
            $pdo,
            $company_id,
            $_SESSION['user_id'] ?? null,
            $_SESSION['role'] ?? 'unknown',
            'inventory',
            'delete_defective',
            "Deleted defective inventory item {$name} (Qty: {$qty})."
        );
    }
    
    header("Location: dashboard_admin.php?page=inventory");
    exit();
}

// - Defective items handler: MARK (as defective) -
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_defective'])) {
    $inv_id = (int)($_POST['inventory_id'] ?? 0);
    $def_qty = max(0, (int)($_POST['defective_quantity'] ?? 0));
    $reason = trim($_POST['defective_reason'] ?? '');

    if ($inv_id > 0 && $def_qty > 0) {
        // Fetch the current item details (including reorder level and supplier for potential alert)
        $stmt = $pdo->prepare("SELECT id, item_name, quantity, reorder_level, sku, supplier_id, category, date_added, company_id, unit, cost_price, selling_price, remarks FROM inventory WHERE id = ? AND company_id = ?");
        $stmt->execute([$inv_id, $company_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            $current = (int)$item['quantity'];
            if ($def_qty > $current) {
                // Defensive check: ensure we don't mark more defective than available
                $def_qty = $current;
            }

            $logAction = null;
            $logMessage = null;

            if ($def_qty >= $current) {
                // If defective quantity is equal to or greater than current, mark the entire item row as defective
                $upd = $pdo->prepare("UPDATE inventory SET is_defective = 1, defective_reason = ?, defective_at = NOW() WHERE id = ? AND company_id = ?");
                $upd->execute([$reason, $inv_id, $company_id]);

                $logAction = 'mark_defective_all';
                $logMessage = "Marked {$item['item_name']} (Qty: {$current}) as fully defective.";
            } else {
                // If only part is defective, reduce the quantity in the main inventory record
                $new_quantity = $current - $def_qty;
                $upd = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND company_id = ?");
                $upd->execute([$new_quantity, $inv_id, $company_id]);

                $logAction = 'mark_defective_part';
                $logMessage = "Marked {$def_qty} of {$item['item_name']} as defective. Remaining stock: {$new_quantity}.";

                // Create a separate inventory record for the defective quantity so it appears in the defective tab
                $defectiveId = inventoryNextId($pdo);
                $defectiveReason = $reason !== '' ? $reason : 'Marked as defective';
                $defectiveDate = $item['date_added'] ?: date('Y-m-d');
                $insertDefective = $pdo->prepare("INSERT INTO inventory (id, company_id, sku, item_name, quantity, unit, reorder_level, category, cost_price, selling_price, supplier_id, remarks, date_added, is_defective, defective_reason, defective_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())");
                $insertDefective->execute([
                    $defectiveId,
                    $company_id,
                    $item['sku'],
                    $item['item_name'],
                    $def_qty,
                    $item['unit'] ?? null,
                    $item['reorder_level'],
                    $item['category'],
                    $item['cost_price'] ?? null,
                    $item['selling_price'] ?? null,
                    $item['supplier_id'] ?? null,
                    $item['remarks'] ?? null,
                    $defectiveDate,
                    $defectiveReason
                ]);

             // - ADD STOCK CHECK HERE (after quantity reduction) -
if ($new_qty <= $item['reorder_level']) {
    // Fetch vendor email for the *updated* item (from vendors table, using supplier_id_external)
    $vendorEmail = null;
    // We need to find the vendor whose supplier_id_external matches the inventory item's ID
   $vendorStmt = $pdo->prepare("SELECT email FROM vendors WHERE id = ? AND company_id = ?"); // <- Use 'id' in vendors table
$vendorStmt->execute([$item['supplier_id'], $company_id]); // Use inventory item's ID to find associated vendor
    $vendor = $vendorStmt->fetch();
    if ($vendor && !empty($vendor['email'])) {
        $vendorEmail = $vendor['email'];
    }

    // Send alert to vendor if available
    if ($vendorEmail) {
        require_once 'send_stock_alert.php'; // Include the email function
        sendStockAlert($vendorEmail, $item['item_name'], $new_qty, $item['reorder_level'], $item['sku']);
    }

    if (!$vendorEmail) { // Changed condition: only log if no vendor email found
        error_log("Cannot send stock alert for item {$item['item_name']} (SKU: {$item['sku']}). No vendor email found or empty.");
    }
}
// - END STOCK CHECK -
            }

            if ($logAction && $logMessage) {
                logActivity($pdo, $company_id, $_SESSION['user_id'] ?? null, $_SESSION['role'] ?? 'unknown', 'inventory', $logAction, $logMessage);
            }

        } else {
            error_log("Attempted to mark non-existent item (ID: $inv_id) as defective.");
        }
    }

    header("Location: dashboard_admin.php?page=inventory&inventory_section=defective");
    exit();
}

// In the add_vendor block
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vendor'])) {
    $name = trim($_POST['vendor_name']);
    $email = trim($_POST['vendor_email']);
    $contact = trim($_POST['vendor_contact']);
    $address = trim($_POST['vendor_address']);
    // NEW: Get the selected supplier_id from the inventory table
    $linked_supplier_id = (int)($_POST['supplier_id'] ?? 0); // Use the name 'supplier_id' from the form

    if ($name) {
        try {
            // UPDATED: Insert into the new 'supplier_id' column
            $stmt = $pdo->prepare("INSERT INTO vendors (company_id, vendor_name, email, contact_number, address, supplier_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$company_id, $name, $email, $contact, $address, $linked_supplier_id]); // Use $linked_supplier_id

            $newVendorId = (int)$pdo->lastInsertId();
            $supplierLabel = $linked_supplier_id > 0 ? $linked_supplier_id : 'none';
            logActivity(
                $pdo,
                $company_id,
                $_SESSION['user_id'] ?? null,
                $_SESSION['role'] ?? 'unknown',
                'inventory',
                'add_vendor',
                "Added vendor {$name} (ID: {$newVendorId}, Supplier ID: {$supplierLabel})."
            );

            $message = "Vendor added successfully!";
            header("Location: dashboard_admin.php?page=inventory&vendor_msg=added");
            exit();
        } catch (Exception $e) {
            error_log("Vendor addition failed: " . $e->getMessage());
            $error = "Failed to add vendor. Check logs.";
        }
    } else {
        $error = "Vendor name is required.";
    }
}
// In the update_vendor block
// --- CORRECT Structure for Update Vendor Block ---

// This block only executes if the form with 'update_vendor' was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_vendor'])) {

    // Assign variables ONLY INSIDE this block, where $_POST data exists
    // Get the vendor ID to update
    $id = (int)($_POST['vendor_id'] ?? 0); // Use null coalescing operator for safety
    // Get the other fields from the form
    $name = trim($_POST['vendor_name'] ?? ''); // Use null coalescing operator for safety
    $email = trim($_POST['vendor_email'] ?? ''); // Use null coalescing operator for safety
    $contact = trim($_POST['vendor_contact'] ?? ''); // Use null coalescing operator for safety
    $address = trim($_POST['vendor_address'] ?? ''); // Use null coalescing operator for safety
    // NEW: Get the selected supplier_id from the inventory table (corresponding to the new column in vendors)
    $linked_supplier_id = (int)($_POST['supplier_id'] ?? 0); // Use the name 'supplier_id' from the form, null coalescing for safety

    // Validate that the vendor ID is valid and the name is not empty
    if ($id > 0 && $name !== '') { // Check if $id is positive and $name is not empty after trimming
        try {
            // UPDATED: Update the new 'supplier_id' column in the vendors table
            $stmt = $pdo->prepare("UPDATE vendors SET vendor_name = ?, email = ?, contact_number = ?, address = ?, supplier_id = ? WHERE id = ? AND company_id = ?");
            // Execute the statement with the correct variables
            $stmt->execute([$name, $email, $contact, $address, $linked_supplier_id, $id, $company_id]); // Use $linked_supplier_id, $name, etc.

            $supplierLabel = $linked_supplier_id > 0 ? $linked_supplier_id : 'none';
            logActivity(
                $pdo,
                $company_id,
                $_SESSION['user_id'] ?? null,
                $_SESSION['role'] ?? 'unknown',
                'inventory',
                'update_vendor',
                "Updated vendor {$name} (ID: {$id}, Supplier ID: {$supplierLabel})."
            );

            $message = "Vendor updated successfully!";
            header("Location: dashboard_admin.php?page=inventory&vendor_msg=updated");
            exit(); // Always exit after redirecting
        } catch (Exception $e) {
            error_log("Vendor update failed: " . $e->getMessage());
            $error = "Failed to update vendor.";
        }
    } else {
        $error = "Invalid vendor ID or name is required.";
    }
}

// --- End of Update Vendor Block ---
// Delete vendor
if (isset($_GET['delete_vendor'])) {
    $vendor_id = (int)$_GET['delete_vendor'];
    try {
        $vendorDetails = null;
        $fetchVendor = $pdo->prepare("SELECT vendor_name, supplier_id FROM vendors WHERE id = ? AND company_id = ? LIMIT 1");
        $fetchVendor->execute([$vendor_id, $company_id]);
        $vendorDetails = $fetchVendor->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("DELETE FROM vendors WHERE id = ? AND company_id = ?");
        $stmt->execute([$vendor_id, $company_id]);
        $message = "Vendor deleted successfully!";

        $vendorName = 'Unknown';
        $supplierLabel = 'none';
        if (is_array($vendorDetails)) {
            if (isset($vendorDetails['vendor_name']) && $vendorDetails['vendor_name'] !== '') {
                $vendorName = $vendorDetails['vendor_name'];
            }
            if (isset($vendorDetails['supplier_id']) && (int)$vendorDetails['supplier_id'] > 0) {
                $supplierLabel = (int)$vendorDetails['supplier_id'];
            }
        }
        logActivity(
            $pdo,
            $company_id,
            $_SESSION['user_id'] ?? null,
            $_SESSION['role'] ?? 'unknown',
            'inventory',
            'delete_vendor',
            sprintf('Deleted vendor %s (ID: %d, Supplier ID: %s).', $vendorName, $vendor_id, $supplierLabel)
        );
    } catch (Exception $e) {
        error_log("Vendor deletion failed: " . $e->getMessage());
        $error = "Failed to delete vendor.";
    }
}
// Fetch available inventory items for the vendor association selector
try {
    $stmt = $pdo->prepare("SELECT id, item_name, sku, quantity FROM inventory WHERE company_id = ? ORDER BY item_name ASC");
    $stmt->execute([$company_id]);
    $inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC); // Use $inventory_items as the variable name
} catch (Exception $e) {
    error_log("Failed to fetch inventory items for vendor selection: " . $e->getMessage());
    $inventory_items = []; // Ensure it's an array even if fetch fails
}

    // Fetch vendors for the current company
    try {
        // Example query to fetch vendors, make sure to select the new column
$stmt = $pdo->prepare("SELECT id, company_id, vendor_name, email, contact_number, address, created_at, supplier_id FROM vendors WHERE company_id = ? ORDER BY vendor_name ASC"); // Include 'supplier_id'
$stmt->execute([$company_id]);
$vendors = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Failed to fetch vendors: " . $e->getMessage());
        $vendors = [];
    }

    $vendor_supplier_options = [];
    if (!empty($vendors)) {
        foreach ($vendors as $vendorRow) {
            $supplierId = isset($vendorRow['supplier_id']) ? (int)$vendorRow['supplier_id'] : 0;
            if ($supplierId <= 0) {
                continue;
            }
            // Keep the first vendor name encountered for each supplier ID
            if (!isset($vendor_supplier_options[$supplierId])) {
                $label = trim($vendorRow['vendor_name'] ?? '');
                $vendor_supplier_options[$supplierId] = $label !== ''
                    ? sprintf('%s (ID %d)', $label, $supplierId)
                    : sprintf('Supplier ID %d', $supplierId);
            }
        }
    }

    if (isset($_GET['vendor_msg'])) {
    switch ($_GET['vendor_msg']) {
        case 'added':
            $message = "Vendor added successfully!";
            break;
        case 'updated':
            $message = "Vendor updated successfully!";
            break;
        case 'deleted':
            $message = "Vendor deleted successfully!";
            break;
        default:
            // Handle unexpected values if necessary
            break;
    }
    // Remove the message parameter from the URL to avoid showing it again on refresh
    $cleanUrl = $_SERVER['REQUEST_URI'];
    $cleanUrl = preg_replace('/[?&]vendor_msg=[^&]*/', '', $cleanUrl);
    $cleanUrl = rtrim($cleanUrl, '?&'); // Clean up trailing ? or &
    // Optional: You could do a silent redirect here to remove the parameter,
    // but usually just displaying the message once is sufficient.
    // header("Location: $cleanUrl");
    // exit();
}
// --- End of success message handling ---

        // Fetch main inventory (non-defective) - Updated query to exclude warehouse_location
        $stmt = $pdo->prepare("
            SELECT 
                i.id,
                i.sku,
                i.item_name,
                i.quantity,
                i.unit,
                i.reorder_level,
                i.category,
                i.cost_price,
                i.selling_price,
                i.supplier_id,
                i.remarks,
                i.date_added,
                CASE WHEN phi.inventory_id IS NULL THEN 0 ELSE 1 END AS pos_hidden,
                phi.hidden_at AS pos_hidden_at
            FROM inventory i
            LEFT JOIN pos_hidden_items phi ON phi.inventory_id = i.id AND phi.company_id = i.company_id
            WHERE i.company_id = ? AND (i.is_defective = 0 OR i.is_defective IS NULL)
            ORDER BY i.date_added DESC
        ");
        $stmt->execute([$company_id]);
        $inventory_data = $stmt->fetchAll();
        $inventory_count = count($inventory_data);

        // Fetch defective items - Updated query to exclude warehouse_location
        $stmt = $pdo->prepare("
            SELECT id, item_name, quantity, category, date_added, defective_reason, defective_at 
            FROM inventory 
            WHERE company_id = ? AND is_defective = 1 
            ORDER BY defective_at DESC
        ");
        $stmt->execute([$company_id]);
        $defective_items = $stmt->fetchAll();
        $defective_count = count($defective_items);

        // Fetch BOM list for this company - Modified for older MySQL without JSON_ARRAYAGG
        try {
            // Step 1: Fetch main BOM data without components aggregated
            $sql_bom = "
                SELECT 
                    b.id, 
                    b.name, 
                    b.output_qty, 
                    b.created_at
                FROM inventory_bom b
                WHERE b.company_id = ?
                ORDER BY b.created_at DESC
            ";
            $stmt_bom = $pdo->prepare($sql_bom);
            $stmt_bom->execute([$company_id]);
            $bom_results = $stmt_bom->fetchAll(PDO::FETCH_ASSOC);

            // Step 2: If BOMs were found, fetch their components
            $bom_list = []; // Initialize the final list
            if (!empty($bom_results)) {
                $bom_ids = array_column($bom_results, 'id');
                
                // Create placeholders for the IN clause
                $placeholders = str_repeat('?,', count($bom_ids) - 1) . '?'; 

                // Fetch components for all relevant BOM IDs in a single query
                $sql_components = "
                    SELECT 
                        bi.bom_id, 
                        bi.inventory_id,
                        i.item_name, 
                        bi.quantity_required
                    FROM inventory_bom_items bi
                    JOIN inventory i ON i.id = bi.inventory_id
                    WHERE bi.bom_id IN ($placeholders)
                    ORDER BY bi.bom_id, i.item_name -- Optional: Order components for consistency
                ";
                $stmt_components = $pdo->prepare($sql_components);
                $stmt_components->execute($bom_ids);
                $component_rows = $stmt_components->fetchAll(PDO::FETCH_ASSOC);
                
                // Step 3: Group components by BOM ID
                $components_by_bom = [];
                foreach ($component_rows as $comp) {
                    $bom_id = $comp['bom_id'];
                    if (!isset($components_by_bom[$bom_id])) {
                        $components_by_bom[$bom_id] = [];
                    }
                    $components_by_bom[$bom_id][] = [
                        'inventory_id' => isset($comp['inventory_id']) ? (int)$comp['inventory_id'] : null,
                        'item_name' => $comp['item_name'],
                        'qty' => $comp['quantity_required']
                    ];
                }
                
                // Step 4: Merge components back into the main BOM results
                foreach ($bom_results as $bom) {
                    // Decode the components array into a JSON string for display, 
                    // or just keep the array if your template expects it
                    // $bom['components'] = json_encode($components); 
                    $components = $components_by_bom[$bom['id']] ?? [];
                    $bom['components'] = json_encode($components);
                    
                    $bom_list[] = $bom; // Add the BOM with its components to the final list
                }
            } else {
                $bom_list = [];
            }
        } catch (PDOException $e) {
            // Handle potential database errors during the fetch
            error_log("Error fetching BOM list: " . $e->getMessage());
            $bom_list = []; // Ensure $bom_list is at least an empty array on error
        }
        $bom_inventory_options = array_map(
            fn($item) => [
                'id' => $item['id'],
                'name' => $item['item_name'],
                'qty' => $item['quantity']
            ],
            $inventory_data
        );

?>
    <style>
        .pos-visibility-control-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        .pos-filter-group {
            display: inline-flex;
            gap: 0.35rem;
            padding: 0.2rem;
            border-radius: 999px;
            background: var(--bg-secondary);
        }
        .pos-filter-btn {
            border: none;
            background: transparent;
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            cursor: pointer;
            transition: var(--transition);
        }
        .pos-filter-btn.active {
            background: var(--primary);
            color: #fff;
        }
        .pos-visibility-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.15rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: rgba(16,185,129,0.15);
            color: var(--success);
        }
        .pos-visibility-badge.hidden {
            background: rgba(239,68,68,0.15);
            color: var(--danger);
        }
        .pos-hidden-row td {
            background: rgba(239,68,68,0.05);
        }
        .pos-visibility-form button {
            border: none;
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 0.3rem 0.55rem;
            border-radius: var(--radius-sm);
        }
        .pos-visibility-form button:hover {
            background: var(--primary);
            color: #fff;
        }
    </style>
    <!-- Inventory: layout updated - main table on the left, right side removed -->
    <div class="content-grid inventory-layout">
        <div>
            <!-- Main Inventory Card with Tabs -->
            <div class="card inventory-main-card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                    <div style="display:flex; align-items:center; gap:1rem;">
                        <div class="card-title">
                            <i class="fas fa-boxes"></i> Inventory
                        </div>
                        <span class="card-badge"><span id="inventoryCount"><?= $inventory_count ?></span> Items</span>
                        <span class="card-badge"><span id="defectiveCount"><?= $defective_count ?></span> Defective</span>
                    </div>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <!-- Standardized button sizes using consistent inline styles -->
                        <button type="button" class="edit-btn" style="padding: 0.75rem 1rem; font-size: 0.9375rem;" onclick="openAddItemModal()">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                        <button type="button" class="edit-btn" style="padding: 0.75rem 1rem; font-size: 0.9375rem;" onclick="openImportModal()">
                            <i class="fas fa-file-import"></i> Import CSV
                        </button>
                    </div>
                </div>

                <form id="inventoryBulkDeleteForm" method="POST" style="display:none;">
                    <input type="hidden" name="bulk_delete_inventory" value="1">
                </form>

                <?php if (!empty($inventory_add_error)): ?>
                <div style="margin:0 1.25rem 1rem; padding:0.65rem 0.9rem; background:rgba(220,53,69,0.1); border:1px solid rgba(220,53,69,0.3); border-radius:var(--radius); color:var(--danger); font-size:0.9rem; display:flex; align-items:center; gap:0.4rem;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($inventory_add_error) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($inventory_edit_error)): ?>
                <div style="margin:0 1.25rem 1rem; padding:0.65rem 0.9rem; background:rgba(220,53,69,0.1); border:1px solid rgba(220,53,69,0.3); border-radius:var(--radius); color:var(--danger); font-size:0.9rem; display:flex; align-items:center; gap:0.4rem;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($inventory_edit_error) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($inventory_adjust_error)): ?>
                <div style="margin:0 1.25rem 1rem; padding:0.65rem 0.9rem; background:rgba(220,53,69,0.1); border:1px solid rgba(220,53,69,0.3); border-radius:var(--radius); color:var(--danger); font-size:0.9rem; display:flex; align-items:center; gap:0.4rem;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($inventory_adjust_error) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($inventory_bulk_flash)): ?>
                <?php
                    $bulkType = $inventory_bulk_flash['type'] ?? 'info';
                    $bulkMessage = $inventory_bulk_flash['message'] ?? '';
                    $bulkBg = $bulkType === 'success' ? 'rgba(16,185,129,0.12)' : 'rgba(245,158,11,0.12)';
                    $bulkBorder = $bulkType === 'success' ? 'rgba(16,185,129,0.35)' : 'rgba(245,158,11,0.35)';
                    $bulkColor = $bulkType === 'success' ? 'var(--success)' : 'var(--warning)';
                    $bulkIcon = $bulkType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle';
                ?>
                <div style="margin:0 1.25rem 1rem; padding:0.65rem 0.9rem; background:<?= $bulkBg ?>; border:1px solid <?= $bulkBorder ?>; border-radius:var(--radius); color:<?= $bulkColor ?>; font-size:0.9rem; display:flex; align-items:center; gap:0.4rem;">
                    <i class="fas <?= $bulkIcon ?>"></i>
                    <span><?= htmlspecialchars($bulkMessage) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($inventory_visibility_flash)): ?>
                <?php
                    $visibilityType = $inventory_visibility_flash['type'] ?? 'info';
                    $visibilityMessage = $inventory_visibility_flash['message'] ?? '';
                    $visibilityBg = $visibilityType === 'success' ? 'rgba(59,130,246,0.12)' : 'rgba(220,53,69,0.12)';
                    $visibilityBorder = $visibilityType === 'success' ? 'rgba(59,130,246,0.35)' : 'rgba(220,53,69,0.35)';
                    $visibilityColor = $visibilityType === 'success' ? 'var(--primary)' : 'var(--danger)';
                    $visibilityIcon = $visibilityType === 'success' ? 'fa-eye' : 'fa-times-circle';
                ?>
                <div style="margin:0 1.25rem 1rem; padding:0.65rem 0.9rem; background:<?= $visibilityBg ?>; border:1px solid <?= $visibilityBorder ?>; border-radius:var(--radius); color:<?= $visibilityColor ?>; font-size:0.9rem; display:flex; align-items:center; gap:0.4rem;">
                    <i class="fas <?= $visibilityIcon ?>"></i>
                    <span><?= htmlspecialchars($visibilityMessage) ?></span>
                </div>
                <?php endif; ?>

                <!-- Inventory Tabs -->
<div class="inventory-tabs">
    <button type="button" class="inventory-tab-btn active" data-tab="inventoryTab"><i class="fas fa-boxes"></i> Main Inventory</button>
    <button type="button" class="inventory-tab-btn" data-tab="defectiveTab"><i class="fas fa-exclamation-triangle"></i> Defective Items</button>
    <button type="button" class="inventory-tab-btn" data-tab="vendorsTab"><i class="fas fa-truck"></i> Vendors</button> <!-- Add this line -->
</div>

                <!-- Main Inventory Table Panel -->
                <div id="inventoryTab" class="inventory-tab-panel active">
                    <div class="card-body" style="padding: 1.25rem;"> <!-- Added padding here for even spacing -->
                        <div class="table-container">
                            <div class="pos-visibility-control-bar">
                                <div style="display:flex; flex-direction:column; gap:0.25rem;">
                                    <span style="font-size:0.85rem; font-weight:600; color:var(--text-secondary);">POS Visibility Filter</span>
                                    <div class="pos-filter-group">
                                        <button type="button" class="pos-filter-btn active" data-pos-filter="all">All</button>
                                    </div>
                                </div>
                                <button type="submit" form="inventoryBulkDeleteForm" id="inventoryBulkDeleteBtn" class="action-btn" style="padding: 0.65rem 0.95rem; background: var(--danger); color: #fff; opacity: 0.6; cursor: not-allowed;" disabled>
                                    <i class="fas fa-trash"></i> Delete Selected
                                </button>
                            </div>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 36px; text-align:center;">
                                            <input type="checkbox" id="inventorySelectAll" aria-label="Select all inventory items">
                                        </th>
                                        <th>SKU</th>
                                        <th>Date</th>
                                        <th>Item Name</th>
                                        <th>Qty</th>
                                        <th>Cost</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                        <th>Reorder Level</th>
                                        <th>Supplier ID</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventory_data as $item): ?>
                                    <?php
                                        // compute status from quantity and reorder_level
                                        $qty = isset($item['quantity']) ? (int)$item['quantity'] : 0;
                                        $reorder = isset($item['reorder_level']) && $item['reorder_level'] !== '' ? (int)$item['reorder_level'] : null;
                                        $isHiddenFromPos = isset($item['pos_hidden']) && (int)$item['pos_hidden'] === 1;

                                        if ($qty <= 0) {
                                            $status_text = 'Out of Stock';
                                            $status_class = 'out';
                                        } elseif ($reorder !== null) {
                                            if ($qty <= $reorder) {
                                                $status_text = 'Low Stock';
                                                $status_class = 'low';
                                            } elseif ($qty <= ($reorder * 2)) {
                                                // near threshold
                                                $status_text = 'Low / Reorder Soon';
                                                $status_class = 'low';
                                            } else {
                                                $status_text = 'In Stock';
                                                $status_class = 'plenty';
                                            }
                                        } else {
                                            // no reorder level provided
                                            $status_text = 'In Stock';
                                            $status_class = 'unknown';
                                        }
                                    ?>
                                    <tr class="pos-visibility-row <?= $isHiddenFromPos ? 'pos-hidden-row' : '' ?>" data-visibility="<?= $isHiddenFromPos ? 'hidden' : 'visible' ?>">
                                        <td style="text-align:center;">
                                            <input type="checkbox" class="inventory-row-checkbox" form="inventoryBulkDeleteForm" name="inventory_ids[]" value="<?= (int)$item['id'] ?>" aria-label="Select <?= htmlspecialchars($item['item_name'] ?? 'inventory item') ?>">
                                        </td>
                                        <td><?= htmlspecialchars($item['sku'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($item['date_added']) ?></td>
                                        <td><?= htmlspecialchars($item['item_name']) ?></td>
                                        <td>
                                            <div style="display:flex; flex-direction:column; gap:0.35rem; align-items:flex-start;">
                                                <span style="font-weight:600;"><?= htmlspecialchars($qty) ?></span>
                                                <form method="POST" style="display:flex; gap:0.25rem; align-items:center;">
                                                    <input type="hidden" name="inventory_id" value="<?= (int)$item['id'] ?>">
                                                    <input type="number" name="add_quantity" step="1" required placeholder="+/- Qty" title="Use positive value to add stock or a negative value to deduct" inputmode="numeric" oninput="updateInventoryQtyButton(this)" data-button-id="inventoryAdjustBtn<?= (int)$item['id'] ?>" class="inventory-qty-input" style="width:80px; padding:0.2rem 0.35rem; font-size:0.8rem; background:transparent; border:1px solid transparent; color:var(--text-primary, #fff);">
                                                    <button type="submit" id="inventoryAdjustBtn<?= (int)$item['id'] ?>" name="increase_inventory_qty" class="edit-btn" style="padding:0.2rem 0.5rem; font-size:0.75rem;">Add</button>
                                                </form>
                                            </div>
                                        </td>
                                        <td><?= $item['cost_price'] ?? '' ? '₱' . number_format($item['cost_price'], 2) : '' ?></td>
                                        <td><?= isset($item['selling_price']) && $item['selling_price'] !== null ? '₱' . number_format($item['selling_price'], 2) : '' ?></td>

                                        <!-- Status column: derived from qty/reorder -->
                                        <td>
                                            <span class="badge-status <?= htmlspecialchars($status_class) ?>">
                                                <?= htmlspecialchars($status_text) ?>
                                                <!-- Removed the quantity display next to the status text -->
                                            </span>
                                        </td>

                                        <td><?= htmlspecialchars($item['reorder_level'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($item['supplier_id'] ?? '') ?></td>
                                        <td style="text-align: center;"> <!-- Center align actions in the cell -->
                                            <!-- Container for action buttons to keep them together and aligned -->
                                            <div style="display: inline-flex; gap: 0.25rem; align-items: center; justify-content: center;">
                                                <form method="POST" class="pos-visibility-form" style="display:inline-flex;">
                                                    <input type="hidden" name="pos_visibility_action" value="<?= $isHiddenFromPos ? 'show' : 'hide' ?>">
                                                    <input type="hidden" name="inventory_id" value="<?= (int)$item['id'] ?>">
                                                    <button type="submit" title="<?= $isHiddenFromPos ? 'Show in POS' : 'Hide from POS' ?>">
                                                        <i class="fas <?= $isHiddenFromPos ? 'fa-eye' : 'fa-eye-slash' ?>"></i>
                                                    </button>
                                                </form>
                                                <button type="button" class="edit-btn" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;" onclick='openInventoryEditModal(<?= json_encode([
                                                    "id" => (int)$item["id"],
                                                    "sku" => $item["sku"] ?? "",
                                                    "item_name" => $item["item_name"] ?? "",
                                                    "quantity" => (int)($item["quantity"] ?? 0),
                                                    "reorder_level" => $item["reorder_level"] ?? "",
                                                    "cost_price" => $item["cost_price"] ?? "",
                                                    "selling_price" => $item["selling_price"] ?? "",
                                                    "date_added" => $item["date_added"] ?? "",
                                                    "supplier_id" => $item["supplier_id"] ?? ""
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="#" class="action-btn" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;" onclick="openDeleteModal('dashboard_admin.php?page=inventory&delete_inventory=<?= $item['id'] ?>')">
                                                    <i class="fas fa-trash-alt"></i> <!-- Consider using just the icon or a shorter text like 'Del' if space is tight -->
                                                </a>
                                                <button type="button" class="edit-btn" style="padding: 0.3rem 0.6rem; font-size: 0.75rem;" onclick='openMarkDefective(<?= json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="fas fa-wrench"></i> <!-- Consider using just the icon or a shorter text like 'Def' -->
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Defective Items Table Panel -->
                <div id="defectiveTab" class="inventory-tab-panel" hidden>
                    <div class="card-body" style="padding: 1.25rem;"> <!-- Added padding here for consistency -->
                        <div style="display:flex; justify-content:flex-end; margin-bottom:0.75rem;">
                            <button type="submit" form="defectiveBulkDeleteForm" id="defectiveBulkDeleteBtn" class="action-btn" style="padding:0.65rem 0.95rem; background:var(--danger); color:#fff; opacity:0.6; cursor:not-allowed;" disabled>
                                <i class="fas fa-trash"></i> Delete Selected
                            </button>
                        </div>

                        <form id="defectiveBulkDeleteForm" method="POST" style="display:none;">
                            <input type="hidden" name="bulk_delete_defective" value="1">
                        </form>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width:36px; text-align:center;">
                                            <input type="checkbox" id="defectiveSelectAll" aria-label="Select all defective items">
                                        </th>
                                        <th>Marked At</th>
                                        <th>Item Name</th>
                                        <th>Quantity</th>
                                        <th>Category</th>
                                        <th>Reason</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($defective_items)): ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; color: var(--text-muted);">No defective items found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($defective_items as $d): ?>
                                        <tr>
                                            <td style="text-align:center;">
                                                <input type="checkbox" class="defective-row-checkbox" form="defectiveBulkDeleteForm" name="defective_ids[]" value="<?= (int)$d['id'] ?>" aria-label="Select <?= htmlspecialchars($d['item_name'] ?? 'defective item') ?>">
                                            </td>
                                            <td><?= htmlspecialchars($d['defective_at'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($d['item_name']) ?></td>
                                            <td><?= htmlspecialchars($d['quantity']) ?></td>
                                            <td><?= htmlspecialchars($d['category']) ?></td>
                                            <td><?= htmlspecialchars($d['defective_reason'] ?? 'N/A') ?></td>
                                            <td>
                                                <a href="dashboard_admin.php?page=inventory&restore_defective=<?= $d['id'] ?>" class="edit-btn" title="Restore" style="display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.4rem 0.8rem; margin-right: 0.5rem;">
                                                    <i class="fas fa-undo"></i> Restore
                                                </a>
                                                <a href="#" class="action-btn" onclick="openDeleteModal('dashboard_admin.php?page=inventory&delete_defective=<?= $d['id'] ?>')" style="display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.4rem 0.8rem;">
                                                    <i class="fas fa-trash-alt"></i> Delete
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Vendors Table Panel -->
                <div id="vendorsTab" class="inventory-tab-panel" hidden>
                    <div class="card-body" style="padding: 1.25rem;">
                        <div class="header">
                            <div class="header-title">
                                <i class="fas fa-truck"></i>
                                <h2>Vendors</h2>
                            </div>
                            <div class="header-actions">
                                <button class="btn-primary" onclick="document.getElementById('addVendorModal').style.display='flex'">
                                    <i class="fas fa-plus"></i> Add Vendor
                                </button>
                            </div>
                        </div>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Vendor Name</th>
                                        <th>Email</th>
                                        <th>Contact Number</th>
                                        <th>Address</th>
                                        <th>Supplier ID</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($vendors as $vendor): ?>
                                <tr>
                                    <td><?= htmlspecialchars($vendor['vendor_name']) ?></td>
                                    <td><?= htmlspecialchars($vendor['email']) ?></td>
                                    <td><?= htmlspecialchars($vendor['contact_number']) ?></td>
                                    <td><?= htmlspecialchars($vendor['address']) ?></td>
                                    <td><?= htmlspecialchars($vendor['supplier_id'] ?? 'N/A') ?></td>
                                    <td>
                                        <button type="button" class="edit-btn" onclick='openEditVendorModal(<?= json_encode($vendor, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i> Edit</button>
                                        <a href="?page=inventory&delete_vendor=<?= (int)$vendor['id'] ?>" class="action-btn" onclick="return confirm('Are you sure you want to delete this vendor?')">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Removed the right-side Import/Add Item Form Card -->
    </div>
</div>
<!-- Add Vendor Modal -->
<div id="addVendorModal" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="addVendorModalTitle"
         style="max-width:480px; max-height:80vh; overflow-y:auto; padding:20px;">
        <h3 id="addVendorModalTitle" class="modal-title" style="text-align:left;"><i class="fas fa-plus"></i> Add Vendor</h3>
        <form method="POST" action="">
            <input type="hidden" name="add_vendor" value="1">
            <div class="form-group">
                <label for="vendor_name">Vendor Name *</label>
                <input type="text" id="vendor_name" name="vendor_name" required>
            </div>
            <div class="form-group">
                <label for="vendor_email">Email</label>
                <input type="email" id="vendor_email" name="vendor_email">
            </div>
            <div class="form-group">
                <label for="vendor_contact">Contact Number</label>
                <input type="text" id="vendor_contact" name="vendor_contact">
            </div>
            <div class="form-group">
                <label for="vendor_address">Address</label>
                <textarea id="vendor_address" name="vendor_address" rows="2"></textarea>
            </div>
           <!-- Inside the Add Vendor Modal form, replace the supplier selection part -->
<!-- Inside the Add Vendor Modal form -->
<div class="form-group">
    <label for="vendor_supplier_id">Supplier ID</label>
    <input type="number" id="vendor_supplier_id" name="supplier_id" min="0" placeholder="Enter supplier ID to assign">
</div>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary">Add Vendor</button>
                <button type="button" class="btn-secondary" onclick="document.getElementById('addVendorModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>
<!-- Edit Vendor Modal -->
<div id="editVendorModal" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="editVendorModalTitle"
         style="max-width:480px; max-height:80vh; overflow-y:auto; padding:20px;">
        <h3 id="editVendorModalTitle" class="modal-title" style="text-align:left;"><i class="fas fa-edit"></i> Edit Vendor</h3>
        <form method="POST" action="">
            <input type="hidden" name="update_vendor" value="1">
            <input type="hidden" id="edit_vendor_id" name="vendor_id" value="">
            <div class="form-group">
                <label for="edit_vendor_name">Vendor Name *</label>
                <input type="text" id="edit_vendor_name" name="vendor_name" required>
            </div>
            <div class="form-group">
                <label for="edit_vendor_email">Email</label>
                <input type="email" id="edit_vendor_email" name="vendor_email">
            </div>
            <div class="form-group">
                <label for="edit_vendor_contact">Contact Number</label>
                <input type="text" id="edit_vendor_contact" name="vendor_contact">
            </div>
            <div class="form-group">
                <label for="edit_vendor_address">Address</label>
                <textarea id="edit_vendor_address" name="vendor_address" rows="2"></textarea>
            </div>
           <!-- Inside the Edit Vendor Modal form, replace the supplier selection part -->
<!-- Inside the Edit Vendor Modal form -->
<div class="form-group">
    <label for="edit_vendor_supplier_id">Supplier ID</label>
    <input type="number" id="edit_vendor_supplier_id" name="supplier_id" min="0" placeholder="Enter supplier ID to assign">
</div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Update Vendor</button>
                <button type="button" class="btn-secondary" onclick="document.getElementById('editVendorModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>
    <!-- Import Modal -->
    <div id="importModal" class="modal-overlay" aria-hidden="true" style="display:none;">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="importModalTitle" style="max-width:480px;">
            <h3 id="importModalTitle" class="modal-title" style="text-align:left;"><i class="fas fa-file-import"></i> Import Inventory (CSV)</h3>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="modal_import_file">Choose CSV file</label>
                    <input type="file" id="modal_import_file" name="import_file" accept=".csv" required>
                    <small style="display:block; margin-top:6px; color:var(--text-secondary); font-size:0.85rem;">
                        Recommended file format: CSV<br>Recommended columns (example order): sku, item_name, quantity, unit, reorder_level, category, cost_price, selling_price, supplier_id, status, remarks, date_added (optional)
                    </small>
                </div>
                <div class="modal-actions" style="justify-content:flex-end; margin-top: 8px;">
                    <button type="button" class="btn-secondary" onclick="closeImportModal()" style="padding:10px 18px; background:var(--border-color); border:none; border-radius:var(--radius); color:var(--text-primary); font-weight:600;">Cancel</button>
                    <button type="submit" name="import_inventory" class="btn-primary" style="padding:10px 18px;">Import CSV</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Item Modal (Updated: Removed warehouse_location field) -->
   <div id="addItemModal" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="addItemModalTitle" 
         style="max-width:480px; max-height:80vh; overflow-y:auto; padding:20px;">
        <h3 id="addItemModalTitle" class="modal-title" style="text-align:left;">
            <i class="fas fa-plus"></i> Add Inventory Item
        </h3>
        <form method="POST">
            <div class="form-group">
                <label for="modal_sku">SKU *</label>
                <input type="text" id="modal_sku" name="sku" required placeholder="Enter SKU">
            </div>
            <div class="form-group">
                <label for="modal_item_name">Item Name *</label>
                <input type="text" id="modal_item_name" name="item_name" required placeholder="Enter item name">
            </div>
            <div class="form-group">
                <label for="modal_quantity">Quantity *</label>
                <input type="number" id="modal_quantity" name="quantity" min="1" required placeholder="Enter quantity">
            </div>
            <div class="form-group">
                <label for="modal_reorder">Reorder Level *</label>
                <input type="number" id="modal_reorder" name="reorder_level" min="0" required placeholder="Enter reorder level">
            </div>
            <div class="form-group">
                <label for="modal_category">Category *</label>
                <input type="text" id="modal_category" name="category" required placeholder="Enter category">
            </div>
            <div class="form-group">
                <label for="modal_cost_price">Cost Price *</label>
                <input type="number" step="0.01" min="0" id="modal_cost_price" name="cost_price" required placeholder="Enter cost price">
            </div>
            <div class="form-group">
                <label for="modal_selling_price">Selling Price *</label>
                <input type="number" step="0.01" min="0" id="modal_selling_price" name="selling_price" required placeholder="Enter selling price">
            </div>
            <div class="form-group">
                <label for="modal_supplier_id">Supplier ID *</label>
                <?php if (!empty($vendor_supplier_options)): ?>
                <select id="modal_supplier_id" name="supplier_id" required>
                    <option value="" disabled selected>Select supplier from vendors</option>
                    <?php foreach ($vendor_supplier_options as $supplierId => $label): ?>
                    <option value="<?= (int)$supplierId ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="number" id="modal_supplier_id" name="supplier_id" min="1" required placeholder="Enter supplier ID (add vendors first)">
                <small style="display:block; margin-top:0.25rem; color:var(--text-secondary);">Add at least one vendor to enable the supplier dropdown.</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="modal_date_added">Date Added *</label>
                <input type="date" id="modal_date_added" name="date_added" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="modal-actions" style="justify-content:flex-end; margin-top: 8px;">
                <button type="button" class="btn-secondary" onclick="closeAddItemModal()" 
                        style="padding:10px 18px; background:var(--border-color); border:none; border-radius:var(--radius); color:var(--text-primary); font-weight:600;">
                    Cancel
                </button>
                <button type="submit" name="add_inventory" class="btn-primary" style="padding:10px 18px;">
                    Add Item
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div id="editItemModal" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="editItemModalTitle"
         style="max-width:480px; max-height:80vh; overflow-y:auto; padding:20px;">
        <h3 id="editItemModalTitle" class="modal-title" style="text-align:left;">
            <i class="fas fa-edit"></i> Edit Inventory Item
        </h3>
        <form method="POST">
            <input type="hidden" name="update_inventory_item" value="1">
            <input type="hidden" id="edit_inventory_id" name="inventory_id" value="">
            <div class="form-group">
                <label for="edit_modal_sku">SKU *</label>
                <input type="text" id="edit_modal_sku" name="sku" required>
            </div>
            <div class="form-group">
                <label for="edit_modal_item_name">Item Name *</label>
                <input type="text" id="edit_modal_item_name" name="item_name" required>
            </div>
            <div class="form-group">
                <label for="edit_modal_quantity">Quantity *</label>
                <input type="number" id="edit_modal_quantity" name="quantity" min="0" required readonly style="background:var(--border-color); cursor:not-allowed;">
            </div>
            <div class="form-group">
                <label for="edit_modal_reorder">Reorder Level *</label>
                <input type="number" id="edit_modal_reorder" name="reorder_level" min="0" required>
            </div>
            <div class="form-group">
                <label for="edit_modal_cost_price">Cost Price *</label>
                <input type="number" step="0.01" min="0" id="edit_modal_cost_price" name="cost_price" required>
            </div>
            <div class="form-group">
                <label for="edit_modal_selling_price">Selling Price *</label>
                <input type="number" step="0.01" min="0" id="edit_modal_selling_price" name="selling_price" required>
            </div>
            <div class="form-group">
                <label for="edit_modal_date_added">Date *</label>
                <input type="date" id="edit_modal_date_added" name="date_added" required>
            </div>
            <div class="form-group">
                <label for="edit_modal_supplier_display">Supplier ID</label>
                <input type="text" id="edit_modal_supplier_display" readonly style="background:var(--border-color); cursor:not-allowed;">
                <small style="display:block; margin-top:0.25rem; color:var(--text-secondary);">Supplier cannot be changed.</small>
            </div>
            <div class="modal-actions" style="justify-content:flex-end; margin-top: 8px;">
                <button type="button" class="btn-secondary" onclick="closeInventoryEditModal()"
                        style="padding:10px 18px; background:var(--border-color); border:none; border-radius:var(--radius); color:var(--text-primary); font-weight:600;">
                    Cancel
                </button>
                <button type="submit" class="btn-primary" style="padding:10px 18px;">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>


    <script>
        if (componentData && componentData.inventory_id) {
            select.value = componentData.inventory_id;
        }

        const quantityInput = document.createElement('input');
        quantityInput.type = 'number';
        quantityInput.step = '0.01';
        quantityInput.min = '0.01';
        quantityInput.name = `${fieldPrefix}[${index}][qty]`;
        quantityInput.placeholder = 'Qty needed';
        quantityInput.required = true;
        if (componentData && componentData.qty !== undefined && componentData.qty !== null) {
            quantityInput.value = componentData.qty;
        }

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'action-btn';
        removeBtn.style.marginTop = '0.5rem';
        removeBtn.textContent = 'Remove';
        removeBtn.addEventListener('click', () => removeBomComponent(removeBtn));

        wrapper.appendChild(label);
        wrapper.appendChild(select);
        wrapper.appendChild(quantityInput);
        wrapper.appendChild(removeBtn);
        container.appendChild(wrapper);
    };
    // Modal handlers for Import and Add Item
    function openImportModal(){ const m=document.getElementById('importModal'); if(!m) return; m.style.display='flex'; m.setAttribute('aria-hidden','false'); }
    function closeImportModal(){ const m=document.getElementById('importModal'); if(!m) return; m.style.display='none'; m.setAttribute('aria-hidden','true'); }
    function openAddItemModal(){
        const m=document.getElementById('addItemModal');
        if(!m) return;
        const fieldIds=['modal_sku','modal_item_name','modal_quantity','modal_unit','modal_reorder','modal_category','modal_cost_price','modal_selling_price','modal_supplier_id'];
        fieldIds.forEach((id)=>{
            const el=document.getElementById(id);
            if(!el) return;
            if(el.tagName === 'SELECT'){
                el.selectedIndex = 0;
            } else {
                el.value='';
            }
        });
        const dateField=document.getElementById('modal_date_added');
        if(dateField){ dateField.value='<?= date('Y-m-d') ?>'; }
        m.style.display='flex';
        m.setAttribute('aria-hidden','false');
    }
    function closeAddItemModal(){ const m=document.getElementById('addItemModal'); if(!m) return; m.style.display='none'; m.setAttribute('aria-hidden','true'); }

    function openInventoryEditModal(item){
        const modal = document.getElementById('editItemModal');
        if (!modal || !item) { return; }
        const setVal = (id, value) => {
            const el = document.getElementById(id);
            if (!el) { return; }
            if (el.tagName === 'SELECT') {
                el.value = value ?? '';
            } else {
                el.value = value ?? '';
            }
        };

        setVal('edit_inventory_id', item.id ?? '');
        setVal('edit_modal_sku', item.sku ?? '');
        setVal('edit_modal_item_name', item.item_name ?? '');
        setVal('edit_modal_quantity', item.quantity ?? '');
        const unitSelect = document.getElementById('edit_modal_unit');
        if (unitSelect) {
            unitSelect.value = item.unit ?? '';
        }
        setVal('edit_modal_reorder', item.reorder_level ?? '');
        setVal('edit_modal_cost_price', item.cost_price ?? '');
        setVal('edit_modal_selling_price', item.selling_price ?? '');
        const dateField = document.getElementById('edit_modal_date_added');
        if (dateField) {
            const dateVal = item.date_added ? item.date_added.substring(0, 10) : '';
            dateField.value = dateVal;
        }
        const supplierField = document.getElementById('edit_modal_supplier_display');
        if (supplierField) {
            supplierField.value = item.supplier_id ?? 'N/A';
        }

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeInventoryEditModal(){
        const modal = document.getElementById('editItemModal');
        if (!modal) { return; }
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    function openEditBomModal(bom){
        if (!bom) { return; }
        const modal = document.getElementById('editBomModal');
        if (!modal) { return; }
        const idField = document.getElementById('edit_bom_id');
        if (idField) { idField.value = bom.id ?? ''; }
        const nameField = document.getElementById('edit_bom_name');
        if (nameField) { nameField.value = bom.name ?? ''; }
        const outputField = document.getElementById('edit_bom_output_qty');
        if (outputField) { outputField.value = bom.output_qty ?? 1; }
        const container = document.getElementById('editBomComponents');
        if (container) {
            container.innerHTML = '';
            const payload = Array.isArray(bom.components) && bom.components.length ? bom.components : [null];
            payload.forEach((component) => appendBomComponentRow('editBomComponents', component));
        }
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeEditBomModal(){
        const modal = document.getElementById('editBomModal');
        if (!modal) { return; }
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    function updateInventoryQtyButton(inputEl){
        if (!inputEl) { return; }
        const buttonId = inputEl.dataset.buttonId;
        const targetButton = buttonId ? document.getElementById(buttonId) : inputEl.closest('form')?.querySelector('button[name="increase_inventory_qty"]');
        if (!targetButton) { return; }
        const value = parseInt(inputEl.value, 10);
        const isDeduct = !Number.isNaN(value) && value < 0;
        targetButton.textContent = isDeduct ? 'Deduct' : 'Add';
    }

    // Close on overlay click
    document.getElementById('importModal')?.addEventListener('click', function(e){ if(e.target===this) closeImportModal(); });
    document.getElementById('addItemModal')?.addEventListener('click', function(e){ if(e.target===this) closeAddItemModal(); });
    document.getElementById('editItemModal')?.addEventListener('click', function(e){ if(e.target===this) closeInventoryEditModal(); });
    document.getElementById('editBomModal')?.addEventListener('click', function(e){ if(e.target===this) closeEditBomModal(); });

    // Inventory Tab Switching
    (function initInventoryTabs() {
    const tabButtons = Array.from(document.querySelectorAll('.inventory-tab-btn'));
    const tabPanels = Array.from(document.querySelectorAll('.inventory-tab-panel'));
    if (!tabButtons.length || !tabPanels.length) { return; }

    const activateTab = (targetId) => {
        tabButtons.forEach((btn) => {
            const isTarget = btn.getAttribute('data-tab') === targetId;
            btn.classList.toggle('active', isTarget);
        });
        tabPanels.forEach((panel) => {
            const isTarget = panel.id === targetId;
            panel.classList.toggle('active', isTarget);
            panel.hidden = !isTarget;
        });
    };

    const hashMap = {
        inventoryTab: '',
        defectiveTab: '#defective',
        bomTab: '#bom',
        vendorsTab: '#vendors'  // Added vendorsTab to the hashMap
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('data-tab');
            if (targetId) {
                activateTab(targetId);
                const hash = hashMap[targetId] ?? '';
                if (hash) {
                    window.location.hash = hash;
                } else {
                    history.replaceState(null, '', window.location.pathname + window.location.search);
                }
            }
        });
    });

    // Check URL hash on load and activate corresponding tab
    let hashTarget = 'inventoryTab';
    if (window.location.hash === '#defective') {
        hashTarget = 'defectiveTab';
    } else if (window.location.hash === '#bom') {
        hashTarget = 'bomTab';
    } else if (window.location.hash === '#vendors') {  // Added vendorsTab check
        hashTarget = 'vendorsTab';                     // Added vendorsTab assignment
    }
    activateTab(hashTarget);
})();

(function initInventoryBulkDelete() {
    const setupBulkDelete = ({ formId, buttonId, selectAllId, checkboxSelector, singularLabel, pluralLabel }) => {
        const form = document.getElementById(formId);
        const button = document.getElementById(buttonId);
        if (!form || !button) { return; }

        const selectAll = selectAllId ? document.getElementById(selectAllId) : null;
        const getCheckboxes = () => Array.from(document.querySelectorAll(checkboxSelector));

        const updateState = () => {
            const rowCheckboxes = getCheckboxes();
            const checkedCount = rowCheckboxes.filter(cb => cb.checked).length;
            button.disabled = checkedCount === 0;
            button.style.opacity = checkedCount === 0 ? '0.6' : '1';
            button.style.cursor = checkedCount === 0 ? 'not-allowed' : 'pointer';

            if (selectAll) {
                const total = rowCheckboxes.length;
                selectAll.checked = checkedCount > 0 && checkedCount === total && total > 0;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < total;
                if (checkedCount === 0) {
                    selectAll.indeterminate = false;
                }
            }
        };

        document.addEventListener('change', (event) => {
            if (event.target && event.target.matches(checkboxSelector)) {
                updateState();
            }
        });

        selectAll?.addEventListener('change', () => {
            const rowCheckboxes = getCheckboxes();
            rowCheckboxes.forEach(cb => { cb.checked = selectAll.checked; });
            updateState();
        });

        form.addEventListener('submit', (event) => {
            const rowCheckboxes = getCheckboxes();
            const checkedCount = rowCheckboxes.filter(cb => cb.checked).length;
            if (checkedCount === 0) {
                event.preventDefault();
                return;
            }
            const label = checkedCount === 1 ? singularLabel : pluralLabel;
            const confirmMessage = checkedCount === 1
                ? `Delete the selected ${label}? This action cannot be undone.`
                : `Delete ${checkedCount} selected ${label}? This action cannot be undone.`;
            if (!window.confirm(confirmMessage)) {
                event.preventDefault();
            }
        });

        updateState();
    };

    setupBulkDelete({
        formId: 'inventoryBulkDeleteForm',
        buttonId: 'inventoryBulkDeleteBtn',
        selectAllId: 'inventorySelectAll',
        checkboxSelector: '.inventory-row-checkbox',
        singularLabel: 'inventory item',
        pluralLabel: 'inventory items'
    });

    setupBulkDelete({
        formId: 'defectiveBulkDeleteForm',
        buttonId: 'defectiveBulkDeleteBtn',
        selectAllId: 'defectiveSelectAll',
        checkboxSelector: '.defective-row-checkbox',
        singularLabel: 'defective item',
        pluralLabel: 'defective items'
    });
})();


    const bomDeleteButtons = document.querySelectorAll('.inventory-bom-delete-btn');
    const bomDeleteModal = document.getElementById('inventoryDeleteBomModal');
    const bomDeleteMessage = document.getElementById('inventoryDeleteBomMessage');
    const bomDeleteConfirm = document.getElementById('inventoryDeleteBomConfirm');
    const bomDeleteCancel = document.getElementById('inventoryDeleteBomCancel');
    const bomDeleteForm = document.getElementById('inventoryDeleteBomForm');
    const bomDeleteInput = document.getElementById('inventoryDeleteBomId');
    let pendingBomId = null;

    const closeBomDeleteModal = () => {
        if (bomDeleteModal) {
            bomDeleteModal.style.display = 'none';
            bomDeleteModal.setAttribute('aria-hidden', 'true');
        }
        pendingBomId = null;
    };

    bomDeleteButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            pendingBomId = button.dataset.bomId || null;
            const bomName = button.dataset.bomName || 'this BOM';
            if (bomDeleteMessage) {
                bomDeleteMessage.textContent = `Do you want to delete ${bomName}?`;
            }
            if (bomDeleteModal) {
                bomDeleteModal.style.display = 'flex';
                bomDeleteModal.setAttribute('aria-hidden', 'false');
            }
        });
    });

    bomDeleteConfirm?.addEventListener('click', () => {
        if (!pendingBomId || !bomDeleteForm || !bomDeleteInput) { return; }
        bomDeleteInput.value = pendingBomId;
        window.__actionFeedback?.queue('Bill of Materials deleted successfully.', 'success', {
            defer: true,
            title: 'Inventory Update'
        });
        bomDeleteForm.submit();
    });

    bomDeleteCancel?.addEventListener('click', () => {
        closeBomDeleteModal();
    });

    bomDeleteModal?.addEventListener('click', (event) => {
        if (event.target === bomDeleteModal) {
            closeBomDeleteModal();
        }
    });

    const bomEditButtons = document.querySelectorAll('.inventory-bom-edit-btn');
    bomEditButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const payload = button.dataset.bom;
            if (!payload) { return; }
            try {
                const parsed = JSON.parse(payload);
                openEditBomModal(parsed);
            } catch (error) {
                console.error('Failed to parse BOM payload', error);
            }
        });
    });

    document.getElementById('editBomForm')?.addEventListener('submit', () => {
        closeEditBomModal();
    });

function openEditVendorModal(vendor) {
    document.getElementById('edit_vendor_id').value = vendor.id || '';
    document.getElementById('edit_vendor_name').value = vendor.vendor_name || '';
    document.getElementById('edit_vendor_email').value = vendor.email || '';
    document.getElementById('edit_vendor_contact').value = vendor.contact_number || '';
    document.getElementById('edit_vendor_address').value = vendor.address || '';
    // NEW: Populate the supplier_id field
    document.getElementById('edit_vendor_supplier_id').value = vendor.supplier_id || '';
    document.getElementById('editVendorModal').style.display = 'flex';
}

function addBomComponent() {
    appendBomComponentRow('bomComponents');
}

function addEditBomComponent() {
    appendBomComponentRow('editBomComponents');
}

function removeBomComponent(trigger) {
    const target = trigger.closest('.bom-component');
    if (!target) { return; }
    const container = target.parentElement;
    if (!container) { return; }
    const components = container.querySelectorAll('.bom-component');
    if (components.length <= 1) {
        const select = target.querySelector('select');
        const qty = target.querySelector('input[type="number"]');
        if (select) { select.value = ''; }
        if (qty) { qty.value = ''; }
        return;
    }
    target.remove();
}

    </script>
<?php

    break;
    case 'finance':
    $company_id = $_SESSION['company_id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_finance'])) {
        $amount = $_POST['amount'];
        $type = $_POST['type'];
        $description = $_POST['description'];
        $date = $_POST['date'];
        $stmt = $pdo->prepare("INSERT INTO finance (company_id, amount, type, description, date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$company_id, $amount, $type, $description, $date]);
        logActivity(
            $pdo,
            $company_id,
            $_SESSION['user_id'] ?? null,
            $_SESSION['role'] ?? 'unknown',
            'finance',
            'add_transaction',
            "Added {$type} transaction of ₱" . number_format((float)$amount, 2) . " dated {$date}."
        );
    }
    if (isset($_GET['delete_finance'])) {
        $delete_id = (int)$_GET['delete_finance'];
        $fetchStmt = $pdo->prepare("SELECT amount, type, date, description FROM finance WHERE id = ? AND company_id = ?");
        $fetchStmt->execute([$delete_id, $company_id]);
        $financeRecord = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("DELETE FROM finance WHERE id = ? AND company_id = ?");
        $stmt->execute([$delete_id, $company_id]);

        if ($financeRecord) {
            $amountFormatted = number_format((float)($financeRecord['amount'] ?? 0), 2);
            $typeLabel = $financeRecord['type'] ?? 'transaction';
            $dateLabel = $financeRecord['date'] ?? '';
            logActivity(
                $pdo,
                $company_id,
                $_SESSION['user_id'] ?? null,
                $_SESSION['role'] ?? 'unknown',
                'finance',
                'delete_transaction',
                "Deleted {$typeLabel} transaction of ₱{$amountFormatted} dated {$dateLabel}."
            );
        }
    }
    $stmt = $pdo->prepare("SELECT * FROM finance WHERE company_id = ? ORDER BY date DESC, id DESC");
    $stmt->execute([$company_id]);
    $records = $stmt->fetchAll();
    // Separate records into income and expense for tab rendering
    $income_records = array_values(array_filter($records, fn($r) => ($r['type'] ?? '') === 'income'));
    $expense_records = array_values(array_filter($records, fn($r) => ($r['type'] ?? '') === 'expense'));

    // Categorize expense rows so payroll salary costs are isolated from other expenses
    $payrollExpenseRecords = [];
    $otherExpenseRecords = [];
    $excludedDeductionExpenseRecords = [];
    $payrollIndicators = ['payroll:', 'salary', 'wage'];
    $deductionIndicators = ['sss', 'philhealth', 'pag-ibig', 'pagibig', 'pag ibig', 'withholding', 'tax'];

    foreach ($expense_records as $record) {
        $description = strtolower((string)($record['description'] ?? ''));
        $isDeduction = false;
        foreach ($deductionIndicators as $keyword) {
            if ($keyword !== '' && strpos($description, $keyword) !== false) {
                $isDeduction = true;
                break;
            }
        }

        if ($isDeduction) {
            $excludedDeductionExpenseRecords[] = $record;
            continue; // employee deductions should not hit the expense tab
        }

        $isPayrollSalaryCost = false;
        if (strpos($description, 'payroll:') === 0) {
            $isPayrollSalaryCost = true;
        } else {
            foreach ($payrollIndicators as $keyword) {
                if ($keyword === 'payroll:') {
                    continue;
                }
                if ($keyword !== '' && strpos($description, $keyword) !== false) {
                    $isPayrollSalaryCost = true;
                    break;
                }
            }
        }

        if ($isPayrollSalaryCost) {
            $payrollExpenseRecords[] = $record;
        } else {
            $otherExpenseRecords[] = $record;
        }
    }

    $payrollExpenseRecords = array_values($payrollExpenseRecords);
    $expense_records = array_values($otherExpenseRecords);

    $stmt = $pdo->prepare("SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN type ='expense' THEN amount ELSE 0 END) AS total_expense
        FROM finance WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $totals = $stmt->fetch();
    $sumAmounts = static function (array $rows): float {
        return array_sum(array_map(static fn($row) => (float)($row['amount'] ?? 0), $rows));
    };
    $payrollExpenseTotal = $sumAmounts($payrollExpenseRecords);
    $otherExpenseTotal = $sumAmounts($expense_records);
    $visibleExpenseTotal = $payrollExpenseTotal + $otherExpenseTotal;
    $incomeTotal = (float)($totals['total_income'] ?? 0);
    $net = $incomeTotal - $visibleExpenseTotal;
    $expenseRecordCount = count($payrollExpenseRecords) + count($expense_records);
    $excludedDeductionExpenseTotal = $sumAmounts($excludedDeductionExpenseRecords);
?>
<div class="content-grid">
    <div>
        <!-- Summary Card (moved to top) -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-chart-pie"></i> Summary
                </div>
            </div>
            <div class="card-body">
                <div class="stats-summary">
                    <div class="stat-item">
                        <span class="stat-item-label">Total Income</span>
                        <span class="stat-item-value success">₱<?= number_format($incomeTotal, 2) ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-item-label">Total Expense</span>
                        <span class="stat-item-value danger">₱<?= number_format($visibleExpenseTotal, 2) ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-item-label">Net Balance</span>
                        <span class="stat-item-value">₱<?= number_format($net, 2) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <!-- Tab Navigation -->
        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header" style="align-items: center; gap: 1rem;">
                <div class="card-title">
                    <i class="fas fa-receipt"></i> Financial Records
                </div>
                <button type="button" class="edit-btn" style="margin-left:auto; padding:0.75rem 1rem; font-size:0.9375rem; display:flex; align-items:center; gap:0.4rem;" onclick="openFinanceAddModal()">
                    <i class="fas fa-plus"></i> Add Transaction
                </button>
            </div>
            <div style="padding: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.5rem;">
                <a href="#incomeTab" class="nav-item active" style="padding: 0.5rem 1rem; border-radius: var(--radius-sm); text-decoration: none; white-space: nowrap;">
                    <i class="fas fa-plus-circle nav-icon" style="color: var(--success);"></i> <span>Income (<?= count($income_records) ?>)</span>
                </a>
                <a href="#expenseTab" class="nav-item" style="padding: 0.5rem 1rem; border-radius: var(--radius-sm); text-decoration: none; white-space: nowrap;">
                    <i class="fas fa-minus-circle nav-icon" style="color: var(--danger);"></i> <span>Operating Expense (<?= $expenseRecordCount ?>)</span>
                </a>
            </div>
        </div>
        <!-- Income Table Panel -->
        <div id="incomeTab" class="finance-tab-panel active">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-plus-circle" style="color: var(--success);"></i> Income Records
                    </div>
                    <span class="card-badge">₱<?= number_format($incomeTotal, 2) ?></span>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Description</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($income_records as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['date']) ?></td>
                                <td style="color: var(--success); font-weight: 600;">₱<?= number_format($r['amount'], 2) ?></td>
                                <td><?= htmlspecialchars($r['description']) ?></td>
                                <td>
                                    <a href="#" class="action-btn" onclick="openDeleteModal('dashboard_admin.php?page=finance&delete_finance=<?= $r['id'] ?>')">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($income_records)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-secondary);">No income records</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- Expense Table Panel -->

            <div class="card" style="margin-top: 1.5rem;">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fas fa-minus-circle" style="color: var(--danger);"></i> Other Expense Records
                    </div>
                    <span class="card-badge">₱<?= number_format($otherExpenseTotal, 2) ?></span>
                </div>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Description</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expense_records as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['date']) ?></td>
                                <td style="color: var(--danger); font-weight: 600;">₱<?= number_format($r['amount'], 2) ?></td>
                                <td><?= htmlspecialchars($r['description']) ?></td>
                                <td>
                                    <a href="#" class="action-btn" onclick="openDeleteModal('dashboard_admin.php?page=finance&delete_finance=<?= $r['id'] ?>')">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($expense_records)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-secondary);">No other expense records</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($excludedDeductionExpenseTotal > 0): ?>
            <div class="card" style="margin-top: 1rem; border-left: 4px solid var(--warning); background: rgba(245, 158, 11, 0.08);">
                <div class="card-body" style="color: var(--text-secondary);">
                    <strong>Heads up:</strong> ₱<?= number_format($excludedDeductionExpenseTotal, 2) ?> in employee deductions (SSS, PhilHealth, Pag-IBIG, tax) are tracked separately as liabilities and intentionally hidden from the expense tab.
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<div id="financeAddModal" class="modal-overlay" aria-hidden="true" style="display:none;">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="financeAddModalTitle" style="max-width: 480px; width: 100%;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3 id="financeAddModalTitle" style="margin:0; font-size:1.25rem;">Add Transaction</h3>
            <button type="button" class="action-btn" onclick="closeFinanceAddModal()" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST">
            <div class="form-group">
                <label for="modal_add_amount">Amount</label>
                <input type="number" id="modal_add_amount" step="0.01" name="amount" placeholder="Enter amount" required>
            </div>
            <div class="form-group">
                <label for="modal_add_type">Type</label>
                <select id="modal_add_type" name="type" required>
                    <option value="">Select type...</option>
                    <option value="income">Income</option>
                    <option value="expense">Expense</option>
                </select>
            </div>
            <div class="form-group">
                <label for="modal_add_desc">Description</label>
                <input type="text" id="modal_add_desc" name="description" placeholder="Enter description">
            </div>
            <div class="form-group">
                <label for="modal_add_finance_date">Date</label>
                <input type="date" id="modal_add_finance_date" name="date" required>
            </div>
            <div class="modal-actions" style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.5rem;">
                <button type="button" class="btn-secondary" onclick="closeFinanceAddModal()">Cancel</button>
                <button type="submit" name="add_finance" class="btn-primary">Save Transaction</button>
            </div>
        </form>
    </div>
</div>
<script>
// Finance Tab Switching
(function initFinanceTabs() {
    const tabButtons = Array.from(document.querySelectorAll('a[href^="#"]')); // Select links that start with #
    const tabPanels = Array.from(document.querySelectorAll('.finance-tab-panel'));
    if (!tabButtons.length || !tabPanels.length) { return; }

    const activateTab = (targetId) => {
        tabButtons.forEach((btn) => {
            const isTarget = btn.getAttribute('href') === '#' + targetId;
            btn.classList.toggle('active', isTarget);
        });
        tabPanels.forEach((panel) => {
            const isTarget = panel.id === targetId;
            panel.classList.toggle('active', isTarget);
            panel.hidden = !isTarget;
        });
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', (e) => {
            e.preventDefault(); // Prevent default anchor behavior
            const targetId = button.getAttribute('href').substring(1); // Get ID without #
            if (targetId) {
                activateTab(targetId);
            }
        });
    });

    // Check URL hash on load
    let hashTarget = 'incomeTab';
    if (window.location.hash === '#expenseTab') {
        hashTarget = 'expenseTab';
    }
    activateTab(hashTarget);
})();

function openFinanceAddModal() {
    const modal = document.getElementById('financeAddModal');
    if (!modal) { return; }
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

function closeFinanceAddModal() {
    const modal = document.getElementById('financeAddModal');
    if (!modal) { return; }
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

document.getElementById('financeAddModal')?.addEventListener('click', function(event) {
    if (event.target === this) {
        closeFinanceAddModal();
    }
});
</script>
<?php
    break;
// ...existing code...
case 'pm':
    $company_id = $_SESSION['company_id'];
    $user_id = $_SESSION['user_id'] ?? null;

    pmEnsureMaterialTables($pdo);

    // Determine main view and potential sub-view/action
    $pm_view = $_GET['pm_view'] ?? 'dashboard'; // Default to a new dashboard view
    $focus_project_id = isset($_GET['focus_project']) ? (int)$_GET['focus_project'] : null;
    $focus_task_id = isset($_GET['focus_task']) ? (int)$_GET['focus_task'] : null;

    // Handle POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['pm_reallocate_materials'])) {
            header('Content-Type: application/json');

            $projectId = (int)($_POST['project_id'] ?? 0);
            $materialsPayload = $_POST['materials'] ?? '[]';
            $requestedAllocations = json_decode($materialsPayload, true);

            if ($projectId <= 0 || !is_array($requestedAllocations)) {
                echo json_encode(['success' => false, 'message' => 'Invalid project or materials data.']);
                exit;
            }

            $requestedAllocations = array_filter($requestedAllocations, static function ($row) {
                return is_array($row) && !empty($row['inventory_id']) && isset($row['allocate_qty']);
            });

            if (empty($requestedAllocations)) {
                echo json_encode(['success' => false, 'message' => 'No materials submitted for reallocation.']);
                exit;
            }

            try {
                $pdo->beginTransaction();

                $materialFetchStmt = $pdo->prepare("SELECT required_qty, allocated_qty, shortage_qty FROM pm_project_materials WHERE company_id = ? AND project_id = ? AND inventory_id = ? LIMIT 1");
                $inventoryFetchStmt = $pdo->prepare("SELECT item_name, quantity FROM inventory WHERE company_id = ? AND id = ? LIMIT 1");
                $materialUpdateStmt = $pdo->prepare("UPDATE pm_project_materials SET allocated_qty = allocated_qty + ?, shortage_qty = GREATEST(shortage_qty - ?, 0) WHERE company_id = ? AND project_id = ? AND inventory_id = ?");
                $inventoryUpdateStmt = $pdo->prepare("UPDATE inventory SET quantity = quantity - ? WHERE company_id = ? AND id = ?");

                $applied = [];
                $outOfStockItems = [];

                foreach ($requestedAllocations as $entry) {
                    $inventoryId = (int)($entry['inventory_id'] ?? 0);
                    $requestedQty = (float)max(0, (float)($entry['allocate_qty'] ?? 0));

                    if ($inventoryId <= 0 || $requestedQty <= 0) {
                        continue;
                    }

                    $materialFetchStmt->execute([$company_id, $projectId, $inventoryId]);
                    $materialRow = $materialFetchStmt->fetch(PDO::FETCH_ASSOC);
                    $materialFetchStmt->closeCursor();

                    if (!$materialRow) {
                        continue;
                    }

                    $currentShortage = max(0, (float)($materialRow['shortage_qty'] ?? 0));
                    if ($currentShortage <= 0) {
                        continue;
                    }

                    $inventoryFetchStmt->execute([$company_id, $inventoryId]);
                    $inventoryRow = $inventoryFetchStmt->fetch(PDO::FETCH_ASSOC);
                    $inventoryFetchStmt->closeCursor();

                    if (!$inventoryRow) {
                        continue;
                    }

                    $availableStock = max(0, (float)($inventoryRow['quantity'] ?? 0));
                    $inventoryName = $inventoryRow['item_name'] ?? 'Item';
                    if ($availableStock <= 0) {
                        $outOfStockItems[] = $inventoryName;
                        continue;
                    }

                    $applyQty = min($requestedQty, $currentShortage, $availableStock);
                    if ($applyQty <= 0) {
                        continue;
                    }

                    $materialUpdateStmt->execute([$applyQty, $applyQty, $company_id, $projectId, $inventoryId]);
                    $inventoryUpdateStmt->execute([$applyQty, $company_id, $inventoryId]);

                    $applied[] = [
                        'inventory_id' => $inventoryId,
                        'quantity' => $applyQty
                    ];
                }

                if (empty($applied)) {
                    $pdo->rollBack();
                    $message = 'No allocations could be applied. Verify shortages and available stock.';
                    if (!empty($outOfStockItems)) {
                        $first = array_shift($outOfStockItems);
                        $additional = count($outOfStockItems);
                        if ($additional > 0) {
                            $message = $first . ' and ' . $additional . ' other item' . ($additional > 1 ? 's are' : ' is') . ' out of stock. Please re-order.';
                        } else {
                            $message = $first . ' is out of stock. Please re-order.';
                        }
                    }
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit;
                }

                $pdo->commit();

                try {
                    logActivity(
                        $pdo,
                        $company_id,
                        $user_id,
                        $_SESSION['role'] ?? 'unknown',
                        'pm',
                        'reallocate_materials',
                        'Adjusted allocations for project #' . $projectId . ' | Items: ' . count($applied)
                    );
                } catch (Throwable $activityError) {
                    error_log('PM reallocate log failed: ' . $activityError->getMessage());
                }

                echo json_encode(['success' => true, 'message' => 'Materials reallocated successfully.']);
            } catch (Throwable $reallocError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('PM reallocation failed: ' . $reallocError->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to reallocate materials. Please try again.']);
            }
            exit;
        }

        // Project Update
        if (isset($_POST['update_project'])) {
            $projectId = (int)($_POST['project_id'] ?? 0);
            $name = trim($_POST['project_name'] ?? '');
            $startDate = $_POST['start_date'] ?: null;
            $endDate = $_POST['end_date'] ?: null;
            $newBomRaw = isset($_POST['linked_bom_id']) ? trim((string)$_POST['linked_bom_id']) : '';
            $newBomId = $newBomRaw === '' ? null : (int)$newBomRaw;
            $currentBomIdRaw = isset($_POST['current_bom_id']) ? trim((string)$_POST['current_bom_id']) : '';
            $currentBomId = $currentBomIdRaw === '' ? null : (int)$currentBomIdRaw;
            $bomTargetQty = max(1, (float)($_POST['bom_target_qty'] ?? 1));

            if ($projectId > 0 && $name !== '') {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE projects SET name = ?, start_date = ?, end_date = ? WHERE id = ? AND company_id = ?");
                    $stmt->execute([$name, $startDate ?: null, $endDate ?: null, $projectId, $company_id]);

                    $allocationResult = null;
                    $shouldReallocate = ($currentBomId !== $newBomId);

                    if ($shouldReallocate) {
                        $pdo->prepare('DELETE FROM pm_project_materials WHERE project_id = ?')->execute([$projectId]);
                        if ($newBomId !== null) {
                            $allocationResult = pmAllocateBomToProject($pdo, $company_id, $projectId, $newBomId, $bomTargetQty);
                        }
                    }

                    $pdo->commit();

                    if (!empty($allocationResult)) {
                        $_SESSION['pm_allocation_notice'] = $allocationResult;
                    }

                    logActivity($pdo, $company_id, $user_id, $_SESSION['role'], 'pm', 'update_project', 'Updated project #' . $projectId);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Project update failed: ' . $e->getMessage());
                    $_SESSION['pm_allocation_notice'] = [
                        'message' => 'Project update failed. Please try again.',
                        'error' => $e->getMessage()
                    ];
                }
            }

            header('Location: dashboard_admin.php?page=pm&pm_view=projects');
            exit;
        }

        // Project Creation
        if (isset($_POST['create_project'])) {
            $name = trim($_POST['project_name'] ?? '');
            $desc = trim($_POST['project_description'] ?? '');
            $linkedBomRaw = isset($_POST['linked_bom_id']) ? trim((string)$_POST['linked_bom_id']) : '';
            $linkedBomId = $linkedBomRaw === '' ? null : (int)$linkedBomRaw;
            $bomTargetQty = max(1, (float)($_POST['bom_target_qty'] ?? 1));

            if ($name) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("INSERT INTO projects (company_id, name, description, start_date, end_date, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$company_id, $name, $desc, $_POST['start_date'] ?: null, $_POST['end_date'] ?: null, $user_id]);
                    $projectId = (int)$pdo->lastInsertId();

                    logActivity($pdo, $company_id, $user_id, $_SESSION['role'], 'pm', 'create_project', "Created project: $name");

                    $allocationResult = null;
                    if ($projectId > 0 && $linkedBomId !== null) {
                        $allocationResult = pmAllocateBomToProject($pdo, $company_id, $projectId, (int)$linkedBomId, $bomTargetQty);
                    }

                    $pdo->commit();

                    if (!empty($allocationResult)) {
                        $_SESSION['pm_allocation_notice'] = $allocationResult;
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Project creation failed: ' . $e->getMessage());
                    $_SESSION['pm_allocation_notice'] = [
                        'message' => 'Project creation failed. Check logs for details.',
                        'error' => $e->getMessage()
                    ];
                }
            }
            header('Location: dashboard_admin.php?page=pm&pm_view=projects');
            exit;
        }

        // Project Deletion
        if (isset($_POST['delete_project'])) {
            $projectId = (int)($_POST['delete_project_id'] ?? 0);
            if ($projectId > 0) {
                try {
                    $pdo->beginTransaction();

                    $checkStmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? AND company_id = ? LIMIT 1");
                    $checkStmt->execute([$projectId, $company_id]);
                    $projectRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if ($projectRow) {
                        $projectName = $projectRow['name'] ?? ('#' . $projectId);

                        $stmt = $pdo->prepare("DELETE FROM pm_assignments WHERE task_id IN (SELECT id FROM pm_tasks WHERE project_id = ?)");
                        $stmt->execute([$projectId]);

                        $stmt = $pdo->prepare("DELETE FROM pm_time_entries WHERE task_id IN (SELECT id FROM pm_tasks WHERE project_id = ?)");
                        $stmt->execute([$projectId]);

                        $stmt = $pdo->prepare("DELETE FROM pm_costs WHERE task_id IN (SELECT id FROM pm_tasks WHERE project_id = ?)");
                        $stmt->execute([$projectId]);

                        $stmt = $pdo->prepare("DELETE FROM pm_costs WHERE project_id = ?");
                        $stmt->execute([$projectId]);

                        $stmt = $pdo->prepare("DELETE FROM pm_project_materials WHERE project_id = ?");
                        $stmt->execute([$projectId]);

                        $stmt = $pdo->prepare("DELETE FROM pm_project_meta WHERE project_id = ? AND company_id = ?");
                        $stmt->execute([$projectId, $company_id]);

                        $stmt = $pdo->prepare("DELETE FROM pm_tasks WHERE project_id = ?");
                        $stmt->execute([$projectId]);

                        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ? AND company_id = ?");
                        $stmt->execute([$projectId, $company_id]);

                        $pdo->commit();

                        logActivity(
                            $pdo,
                            $company_id,
                            $user_id,
                            $_SESSION['role'],
                            'pm',
                            'delete_project',
                            'Deleted project "' . $projectName . '" and related records.'
                        );
                    } else {
                        $pdo->rollBack();
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Project deletion failed: ' . $e->getMessage());
                }
            }

            header('Location: dashboard_admin.php?page=pm&pm_view=projects');
            exit;
        }

        // Resource Creation
        if (isset($_POST['add_resource'])) {
            $stmt = $pdo->prepare("INSERT INTO pm_resources (company_id, name, role, hourly_rate) VALUES (?, ?, ?, ?)");
            $stmt->execute([$company_id, trim($_POST['res_name'] ?? ''), trim($_POST['res_role'] ?? ''), (float)($_POST['hourly_rate'] ?? 0)]);
            logActivity($pdo, $company_id, $user_id, $_SESSION['role'], 'pm', 'add_resource', "Added resource: " . trim($_POST['res_name'] ?? ''));
            header('Location: dashboard_admin.php?page=pm&pm_view=resources');
            exit;
        }

        // Task Creation
        if (isset($_POST['create_task'])) {
            $stmt = $pdo->prepare("INSERT INTO pm_tasks (project_id, title, description, status, priority, estimated_hours, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $projectId = (int)($_POST['project_id'] ?? 0);
            $taskTitle = trim($_POST['title'] ?? '') ?: 'Untitled Task';
            $stmt->execute([$projectId, $taskTitle, trim($_POST['description'] ?? ''), $_POST['status'] ?? 'todo', $_POST['priority'] ?? 'medium', (float)($_POST['estimated_hours'] ?? 0), $_POST['due_date'] ?: null, $user_id]);
            $projectName = pmGetProjectName($pdo, $company_id, $projectId);
            $projectLabel = $projectName ? 'project "' . $projectName . '"' : 'project #' . $projectId;
            logActivity($pdo, $company_id, $user_id, $_SESSION['role'], 'pm', 'create_task', "Created task \"{$taskTitle}\" for {$projectLabel}.");
            header('Location: dashboard_admin.php?page=pm&pm_view=tasks');
            exit;
        }

        // Task Update
        if (isset($_POST['update_task'])) {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $projectId = (int)($_POST['project_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $priority = $_POST['priority'] ?? 'medium';
            $status = $_POST['status'] ?? 'todo';
            $estimatedHours = (float)($_POST['estimated_hours'] ?? 0);
            $dueDate = $_POST['due_date'] ?: null;
            $allowedPriorities = ['low', 'medium', 'high'];
            $allowedStatuses = ['todo', 'in_progress', 'done'];

            if ($taskId > 0 && $projectId > 0 && $title !== '' && in_array($priority, $allowedPriorities, true) && in_array($status, $allowedStatuses, true)) {
                $projectCheckStmt = $pdo->prepare('SELECT id FROM projects WHERE id = ? AND company_id = ? LIMIT 1');
                $projectCheckStmt->execute([$projectId, $company_id]);
                $targetProjectValid = (bool)$projectCheckStmt->fetchColumn();

                $taskCheckStmt = $pdo->prepare('SELECT t.title, p.name AS project_name FROM pm_tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ? AND p.company_id = ? LIMIT 1');
                $taskCheckStmt->execute([$taskId, $company_id]);
                $taskRow = $taskCheckStmt->fetch(PDO::FETCH_ASSOC);

                if ($targetProjectValid && $taskRow) {
                    $updateStmt = $pdo->prepare('UPDATE pm_tasks SET project_id = ?, title = ?, description = ?, priority = ?, status = ?, estimated_hours = ?, due_date = ? WHERE id = ?');
                    $updateStmt->execute([$projectId, $title, $description, $priority, $status, $estimatedHours, $dueDate ?: null, $taskId]);

                    $projectName = pmGetProjectName($pdo, $company_id, $projectId);
                    $projectLabel = $projectName ? 'project "' . $projectName . '"' : 'project #' . $projectId;
                    logActivity(
                        $pdo,
                        $company_id,
                        $user_id,
                        $_SESSION['role'],
                        'pm',
                        'update_task',
                        'Updated task "' . $title . '" in ' . $projectLabel . '.'
                    );
                }
            }

            header('Location: dashboard_admin.php?page=pm&pm_view=tasks');
            exit;
        }

        // Task Deletion
        if (isset($_POST['delete_task'])) {
            $taskId = (int)($_POST['delete_task_id'] ?? 0);
            if ($taskId > 0) {
                try {
                    $pdo->beginTransaction();

                    $taskStmt = $pdo->prepare('SELECT t.title, p.name AS project_name FROM pm_tasks t JOIN projects p ON p.id = t.project_id WHERE t.id = ? AND p.company_id = ? LIMIT 1');
                    $taskStmt->execute([$taskId, $company_id]);
                    $taskRow = $taskStmt->fetch(PDO::FETCH_ASSOC);

                    if ($taskRow) {
                        $pdo->prepare('DELETE FROM pm_assignments WHERE task_id = ?')->execute([$taskId]);
                        $pdo->prepare('DELETE FROM pm_time_entries WHERE task_id = ?')->execute([$taskId]);
                        $pdo->prepare('DELETE FROM pm_costs WHERE task_id = ?')->execute([$taskId]);
                        $pdo->prepare('DELETE FROM pm_tasks WHERE id = ?')->execute([$taskId]);

                        $pdo->commit();

                        $taskLabel = $taskRow['title'] ?? ('#' . $taskId);
                        $projectName = $taskRow['project_name'] ?? '';
                        $projectLabel = $projectName !== '' ? 'project "' . $projectName . '"' : 'its project';
                        logActivity(
                            $pdo,
                            $company_id,
                            $user_id,
                            $_SESSION['role'],
                            'pm',
                            'delete_task',
                            'Deleted task "' . $taskLabel . '" from ' . $projectLabel . '.'
                        );
                    } else {
                        $pdo->rollBack();
                    }
                } catch (Exception $taskDeleteError) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Task deletion failed: ' . $taskDeleteError->getMessage());
                }
            }

            header('Location: dashboard_admin.php?page=pm&pm_view=tasks');
            exit;
        }

        // Bulk Assignment
        if (isset($_POST['bulk_assign_submit'])) {
            $resourceId = (int)($_POST['resource_id'] ?? 0);
            $allocationInput = trim((string)($_POST['allocation'] ?? ''));
            $allocation = $allocationInput === '' ? 100 : (int)$allocationInput;
            $allocation = max(1, min(100, $allocation));
            $roleNote = trim($_POST['role_note'] ?? '');
            $taskIdsRaw = array_filter(array_map('trim', explode(',', $_POST['task_ids'] ?? '')));
            $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIdsRaw), static fn($id) => $id > 0)));

            if ($resourceId > 0 && !empty($taskIds)) {
                $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
                $validationSql = "SELECT id, title FROM pm_tasks WHERE id IN ($placeholders) AND project_id IN (SELECT id FROM projects WHERE company_id = ?)";
                $stmt = $pdo->prepare($validationSql);
                $stmt->execute(array_merge($taskIds, [$company_id]));
                $validTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($validTasks)) {
                    $validTaskIds = array_map('intval', array_column($validTasks, 'id'));
                    $taskPlaceholders = implode(',', array_fill(0, count($validTaskIds), '?'));
                    $existingStmt = $pdo->prepare("SELECT task_id FROM pm_assignments WHERE resource_id = ? AND task_id IN ($taskPlaceholders)");
                    $existingStmt->execute(array_merge([$resourceId], $validTaskIds));
                    $alreadyAssigned = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
                    $tasksToAssign = array_values(array_diff($validTaskIds, $alreadyAssigned));

                    if (!empty($tasksToAssign)) {
                        $insertStmt = $pdo->prepare("INSERT INTO pm_assignments (task_id, resource_id, allocation_percent, role_note) VALUES (?, ?, ?, ?)");
                        foreach ($tasksToAssign as $assignTaskId) {
                            $insertStmt->execute([$assignTaskId, $resourceId, $allocation, $roleNote]);
                        }

                        $resourceName = pmGetResourceName($pdo, $company_id, $resourceId);
                        $resourceLabel = $resourceName ? 'resource "' . $resourceName . '"' : 'resource #' . $resourceId;
                        $taskNames = [];
                        foreach ($validTasks as $taskRow) {
                            if (in_array((int)$taskRow['id'], $tasksToAssign, true)) {
                                $taskNames[] = $taskRow['title'] ?? ('#' . $taskRow['id']);
                            }
                        }
                        $taskSummary = count($taskNames) <= 5
                            ? implode(', ', array_map(static fn($name) => '"' . $name . '"', $taskNames))
                            : count($taskNames) . ' tasks';

                        logActivity(
                            $pdo,
                            $company_id,
                            $user_id,
                            $_SESSION['role'],
                            'pm',
                            'bulk_assign_resource',
                            "Bulk assigned {$resourceLabel} to {$taskSummary}."
                        );
                    }
                }
            }

            header('Location: dashboard_admin.php?page=pm&pm_view=tasks');
            exit;
        }

        // Assignment
        if (isset($_POST['assign_resource'])) {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $resourceId = (int)($_POST['resource_id'] ?? 0);
            $allocation = (int)($_POST['allocation'] ?: 100);
            $roleNote = trim($_POST['role_note'] ?? '');
            $stmt = $pdo->prepare("INSERT INTO pm_assignments (task_id, resource_id, allocation_percent, role_note) VALUES (?, ?, ?, ?)");
            $stmt->execute([$taskId, $resourceId, $allocation, $roleNote]);

            $taskContext = pmGetTaskContext($pdo, $company_id, $taskId);
            $taskLabel = 'task "' . ($taskContext['title'] ?? ('#' . $taskId)) . '"';
            $projectLabel = !empty($taskContext['project_name']) ? ' in project "' . $taskContext['project_name'] . '"' : '';
            $resourceName = pmGetResourceName($pdo, $company_id, $resourceId);
            $resourceLabel = $resourceName ? 'resource "' . $resourceName . '"' : 'resource #' . $resourceId;
            $allocationNote = $allocation !== 100 ? " ({$allocation}% allocation)" : '';
            $roleNoteText = $roleNote !== '' ? " (role: {$roleNote})" : '';

            logActivity(
                $pdo,
                $company_id,
                $user_id,
                $_SESSION['role'],
                'pm',
                'assign_resource',
                "Assigned {$resourceLabel}{$allocationNote} to {$taskLabel}{$projectLabel}{$roleNoteText}."
            );
            header('Location: dashboard_admin.php?page=pm&pm_view=tasks');
            exit;
        }

        // Time Logging
        if (isset($_POST['log_time'])) {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $resourceId = (int)($_POST['resource_id'] ?? 0);
            $entryDate = $_POST['entry_date'] ?: date('Y-m-d');
            $hours = (float)($_POST['hours'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            $stmt = $pdo->prepare("INSERT INTO pm_time_entries (task_id, resource_id, entry_date, hours, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$taskId, $resourceId, $entryDate, $hours, $notes]);

            $taskContext = pmGetTaskContext($pdo, $company_id, $taskId);
            $taskLabel = 'task "' . ($taskContext['title'] ?? ('#' . $taskId)) . '"';
            $projectLabel = !empty($taskContext['project_name']) ? ' in project "' . $taskContext['project_name'] . '"' : '';
            $resourceName = pmGetResourceName($pdo, $company_id, $resourceId);
            $resourceLabel = $resourceName ? 'resource "' . $resourceName . '"' : 'resource #' . $resourceId;
            $notesText = $notes !== '' ? " (" . $notes . ")" : '';

            logActivity(
                $pdo,
                $company_id,
                $user_id,
                $_SESSION['role'],
                'pm',
                'log_time',
                "Logged " . number_format($hours, 2) . "h on {$taskLabel}{$projectLabel} for {$resourceLabel} dated {$entryDate}{$notesText}."
            );
            header('Location: dashboard_admin.php?page=pm&pm_view=tasks');
            exit;
        }

        // Update Task Status
        if (isset($_POST['update_task_status'])) {
            $taskId = (int)($_POST['task_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';
            $allowedStatuses = ['todo', 'in_progress', 'done'];
            if ($taskId > 0 && in_array($newStatus, $allowedStatuses, true)) {
                $stmt = $pdo->prepare("UPDATE pm_tasks SET status = ? WHERE id = ? AND project_id IN (SELECT id FROM projects WHERE company_id = ?)");
                $stmt->execute([$newStatus, $taskId, $company_id]);
                $taskContext = pmGetTaskContext($pdo, $company_id, $taskId);
                $taskLabel = 'task "' . ($taskContext['title'] ?? ('#' . $taskId)) . '"';
                $projectLabel = !empty($taskContext['project_name']) ? ' in project "' . $taskContext['project_name'] . '"' : '';
                $statusLabel = ucwords(str_replace('_', ' ', $newStatus));
                logActivity($pdo, $company_id, $user_id, $_SESSION['role'], 'pm', 'update_task_status', "Marked {$taskLabel}{$projectLabel} as {$statusLabel}.");
            }
            header('Location: dashboard_admin.php?page=pm&pm_view=tasks');
            exit;
        }

        // Add Cost
        if (isset($_POST['add_cost'])) {
            $taskId = (int)($_POST['task_id'] ?: 0);
            $projectId = (int)($_POST['project_id'] ?: 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $costType = $_POST['cost_type'] ?: 'other';
            $costDate = $_POST['cost_date'] ?: date('Y-m-d');
            $note = trim($_POST['note'] ?? '');
            $stmt = $pdo->prepare("INSERT INTO pm_costs (task_id, project_id, amount, cost_type, cost_date, note) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$taskId ?: null, $projectId ?: null, $amount, $costType, $costDate, $note]);

            $contextParts = [];
            if ($taskId > 0) {
                $taskContext = pmGetTaskContext($pdo, $company_id, $taskId);
                $taskLabel = 'task "' . ($taskContext['title'] ?? ('#' . $taskId)) . '"';
                $contextParts[] = $taskLabel;
                if (!empty($taskContext['project_name'])) {
                    $contextParts[] = 'project "' . $taskContext['project_name'] . '"';
                }
            } elseif ($projectId > 0) {
                $projectName = pmGetProjectName($pdo, $company_id, $projectId);
                $contextParts[] = $projectName ? 'project "' . $projectName . '"' : 'project #' . $projectId;
            }
            $contextText = !empty($contextParts) ? ' for ' . implode(' / ', $contextParts) : '';
            $noteText = $note !== '' ? " ({$note})" : '';

            $financeDescription = 'PM ' . ucwords(str_replace('_', ' ', $costType)) . ' cost';
            if (!empty($contextParts)) {
                $financeDescription .= ' - ' . implode(' / ', $contextParts);
            }
            if ($note !== '') {
                $financeDescription .= " ({$note})";
            }

            try {
                $financeStmt = $pdo->prepare("INSERT INTO finance (company_id, amount, type, description, date) VALUES (?, ?, ?, ?, ?)");
                $financeStmt->execute([
                    $company_id,
                    $amount,
                    'expense',
                    $financeDescription,
                    $costDate
                ]);
            } catch (Exception $financeError) {
                error_log('Failed to mirror PM cost to finance: ' . $financeError->getMessage());
            }

            logActivity(
                $pdo,
                $company_id,
                $user_id,
                $_SESSION['role'],
                'pm',
                'add_cost',
                "Added ₱" . number_format($amount, 2) . " {$costType} cost{$contextText} dated {$costDate}{$noteText}."
            );
            header('Location: dashboard_admin.php?page=pm&pm_view=costs');
            exit;
        }

        // Save Project Budget
        if (isset($_POST['save_project_budget'])) {
            $projectId = (int)($_POST['project_id'] ?? 0);
            if ($projectId > 0) {
                pmEnsureProjectMetaRows($pdo, [['id' => $projectId]], $company_id);
                $plannedBudget = max(0, (float)($_POST['planned_budget'] ?? 0));
                $threshold = max(5, min(100, (int)($_POST['budget_threshold'] ?? 80)));
                $stmt = $pdo->prepare("INSERT INTO pm_project_meta (project_id, company_id, planned_budget, budget_threshold) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE planned_budget = VALUES(planned_budget), budget_threshold = VALUES(budget_threshold)");
                $stmt->execute([$projectId, $company_id, $plannedBudget, $threshold]);
                $projectName = pmGetProjectName($pdo, $company_id, $projectId);
                $projectLabel = $projectName ? 'project "' . $projectName . '"' : 'project #' . $projectId;
                logActivity(
                    $pdo,
                    $company_id,
                    $user_id,
                    $_SESSION['role'],
                    'pm',
                    'save_project_budget',
                    "Set budget for {$projectLabel} to ₱" . number_format($plannedBudget, 2) . " with alerts at {$threshold}% utilization."
                );
            }
            header('Location: dashboard_admin.php?page=pm&pm_view=projects');
            exit;
        }

        // Save Project Reminder
        if (isset($_POST['save_project_reminder'])) {
            $projectId = (int)($_POST['project_id'] ?? 0);
            if ($projectId > 0) {
                pmEnsureProjectMetaRows($pdo, [['id' => $projectId]], $company_id);
                $days = max(1, min(30, (int)($_POST['deadline_buffer_days'] ?? 3)));
                $stmt = $pdo->prepare("INSERT INTO pm_project_meta (project_id, company_id, deadline_buffer_days) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE deadline_buffer_days = VALUES(deadline_buffer_days)");
                $stmt->execute([$projectId, $company_id, $days]);
                $projectName = pmGetProjectName($pdo, $company_id, $projectId);
                $projectLabel = $projectName ? 'project "' . $projectName . '"' : 'project #' . $projectId;
                logActivity(
                    $pdo,
                    $company_id,
                    $user_id,
                    $_SESSION['role'],
                    'pm',
                    'save_project_reminder',
                    "Set deadline reminder for {$projectLabel} to {$days} day(s) before due date."
                );
            }
            header('Location: dashboard_admin.php?page=pm&pm_view=projects');
            exit;
        }
    }

    // Fetch data for display
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE company_id = ? ORDER BY created_at DESC");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $projectsById = [];
    foreach ($projects as $projectRow) {
        $projectsById[(int)$projectRow['id']] = $projectRow;
    }

        $stmt = $pdo->prepare("
            SELECT t.*, p.name AS project_name,
                assign_summary.assigned_resources,
                (SELECT IFNULL(SUM(hours),0) FROM pm_time_entries te WHERE te.task_id = t.id) AS hours_logged,
                (SELECT IFNULL(SUM(amount),0) FROM pm_costs c WHERE c.task_id = t.id) AS costs_total
            FROM pm_tasks t
            LEFT JOIN projects p ON p.id = t.project_id
            LEFT JOIN (
                SELECT pa.task_id, GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS assigned_resources
                FROM pm_assignments pa
                INNER JOIN pm_resources r ON r.id = pa.resource_id
                GROUP BY pa.task_id
            ) AS assign_summary ON assign_summary.task_id = t.id
            WHERE (p.company_id = ? OR t.project_id IN (SELECT id FROM projects WHERE company_id = ?))
            ORDER BY t.due_date IS NULL, t.due_date ASC, FIELD(t.priority,'high','medium','low')
        ");
    $stmt->execute([$company_id, $company_id]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $taskAssignments = [];
    if (!empty($tasks)) {
        $taskIds = array_values(array_unique(array_map(static fn($row) => (int)($row['id'] ?? 0), $tasks)));
        $taskIds = array_values(array_filter($taskIds, static fn($id) => $id > 0));
        if (!empty($taskIds)) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $assignmentSql = "
                SELECT pa.task_id,
                       pa.resource_id,
                       COALESCE(r.name, 'Resource') AS resource_name,
                       COALESCE(r.role, '') AS resource_role,
                       COALESCE(pa.role_note, '') AS role_note
                FROM pm_assignments pa
                INNER JOIN pm_resources r ON r.id = pa.resource_id AND r.company_id = ?
                WHERE pa.task_id IN ($placeholders)
                ORDER BY r.name ASC
            ";
            $assignmentParams = array_merge([$company_id], $taskIds);
            $stmt = $pdo->prepare($assignmentSql);
            $stmt->execute($assignmentParams);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignRow) {
                $taskId = (int)($assignRow['task_id'] ?? 0);
                if ($taskId <= 0) {
                    continue;
                }
                if (!isset($taskAssignments[$taskId])) {
                    $taskAssignments[$taskId] = [];
                }
                $taskAssignments[$taskId][] = [
                    'resource_id' => (int)($assignRow['resource_id'] ?? 0),
                    'name' => $assignRow['resource_name'] ?? 'Resource',
                    'resource_role' => trim((string)($assignRow['resource_role'] ?? '')),
                    'role_note' => trim((string)($assignRow['role_note'] ?? '')),
                ];
            }
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM pm_resources WHERE company_id = ? ORDER BY name ASC");
    $stmt->execute([$company_id]);
    $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resourceWorkload = [];
    if (!empty($resources)) {
        $stmt = $pdo->prepare("
            SELECT pa.resource_id,
                   COUNT(DISTINCT pa.task_id) AS total_tasks,
                   SUM(CASE WHEN t.status IS NULL OR t.status <> 'done' THEN 1 ELSE 0 END) AS active_tasks
            FROM pm_assignments pa
            JOIN pm_resources r ON r.id = pa.resource_id AND r.company_id = ?
            LEFT JOIN pm_tasks t ON t.id = pa.task_id
            GROUP BY pa.resource_id
        ");
        $stmt->execute([$company_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $workloadRow) {
            $resourceWorkload[(int)$workloadRow['resource_id']] = [
                'total_tasks' => (int)($workloadRow['total_tasks'] ?? 0),
                'active_tasks' => (int)($workloadRow['active_tasks'] ?? 0),
            ];
        }
    }

    $stmt = $pdo->prepare("SELECT te.*, r.name AS resource_name, t.title AS task_title FROM pm_time_entries te JOIN pm_resources r ON te.resource_id = r.id JOIN pm_tasks t ON te.task_id = t.id WHERE r.company_id = ? ORDER BY te.entry_date DESC");
    $stmt->execute([$company_id]);
    $time_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT c.*, p.name AS project_name, t.title AS task_title FROM pm_costs c LEFT JOIN projects p ON c.project_id = p.id LEFT JOIN pm_tasks t ON c.task_id = t.id WHERE (p.company_id = ? OR t.project_id IN (SELECT id FROM projects WHERE company_id = ?)) ORDER BY c.cost_date DESC");
    $stmt->execute([$company_id, $company_id]);
    $costs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate counts
    $proj_count = count($projects);
    $res_count = count($resources);
    $task_count = count($tasks);
    $time_count = count($time_entries);
    $cost_count = count($costs);

    // Prepare data for alerts and health checks
    pmEnsureProjectMetaRows($pdo, $projects, $company_id);
    $pmProjectMeta = pmFetchProjectMeta($pdo, $company_id);

    $pmCostTotals = [];
    foreach ($costs as $costRow) {
        $pid = (int)($costRow['project_id'] ?? 0);
        if ($pid > 0) {
            $pmCostTotals[$pid] = ($pmCostTotals[$pid] ?? 0) + (float)$costRow['amount'];
        }
    }

    $pmTaskStats = [];
    $deadlineCandidates = [];
    $tz = new DateTimeZone('Asia/Manila');
    $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
    foreach ($tasks as $taskRow) {
        $pid = (int)$taskRow['project_id'];
        if (!isset($pmTaskStats[$pid])) {
            $pmTaskStats[$pid] = ['total' => 0, 'done' => 0];
        }
        $pmTaskStats[$pid]['total']++;
        if (($taskRow['status'] ?? '') === 'done') {
            $pmTaskStats[$pid]['done']++;
        }
        if (!empty($taskRow['due_date']) && ($taskRow['status'] ?? '') !== 'done') {
            try {
                $dueDate = (new DateTimeImmutable($taskRow['due_date'], $tz))->setTime(0, 0);
                $daysLeft = (int)$today->diff($dueDate)->format('%r%a');
                $deadlineCandidates[] = [
                    'project_id' => $pid,
                    'project_name' => $taskRow['project_name'] ?? '',
                    'task_id' => (int)$taskRow['id'],
                    'task_title' => $taskRow['title'] ?? 'Untitled Task',
                    'task_description' => $taskRow['description'] ?? '',
                    'due_date' => $taskRow['due_date'],
                    'days_left' => $daysLeft,
                    'priority' => $taskRow['priority'] ?? 'medium'
                ];
            } catch (Exception $e) { /* ignore invalid dates */ }
        }
    }

    $pmHealthRows = [];
    $pmBudgetAlerts = [];
    $pmDeadlineAlerts = ['overdue' => [], 'upcoming' => []];
    foreach ($projects as $projectRow) {
        $pid = (int)$projectRow['id'];
        $meta = $pmProjectMeta[$pid] ?? [];
        $plannedBudget = (float)($meta['planned_budget'] ?? 0);
        $thresholdPercent = (int)($meta['budget_threshold'] ?? 80);
        $actualSpend = $pmCostTotals[$pid] ?? 0;
        $totalTasks = $pmTaskStats[$pid]['total'] ?? 0;
        $doneTasks = $pmTaskStats[$pid]['done'] ?? 0;
        $completion = $totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100, 2) : 0.0;
        $storedCompletion = isset($meta['auto_completion']) ? (float)$meta['auto_completion'] : null;

        if ($storedCompletion === null || abs($completion - $storedCompletion) > 0.009) {
            pmUpdateAutoCompletion($pdo, $pid, $completion);
            $pmProjectMeta[$pid]['auto_completion'] = $completion;
        } else {
            $completion = $storedCompletion;
        }

        if ($plannedBudget > 0) {
            $thresholdAmount = $plannedBudget * max(0.05, min(1, $thresholdPercent / 100));
            if ($actualSpend >= $plannedBudget) {
                $pmBudgetAlerts[] = [
                    'project' => $projectRow['name'],
                    'status' => 'Exceeded',
                    'severity' => 'critical',
                    'planned' => $plannedBudget,
                    'actual' => $actualSpend
                ];
            } elseif ($actualSpend >= $thresholdAmount) {
                $pmBudgetAlerts[] = [
                    'project' => $projectRow['name'],
                    'status' => 'Approaching',
                    'severity' => 'warning',
                    'planned' => $plannedBudget,
                    'actual' => $actualSpend
                ];
            }
        }
        $pmHealthRows[] = [
            'id' => $pid,
            'name' => $projectRow['name'],
            'planned_budget' => $plannedBudget,
            'actual_spend' => $actualSpend,
            'completion' => $completion,
            'status' => $projectRow['status'] ?? 'planned',
            'total_tasks' => $totalTasks,
            'done_tasks' => $doneTasks
        ];
    }
    foreach ($deadlineCandidates as $candidate) {
        $buffer = (int)($pmProjectMeta[$candidate['project_id']]['deadline_buffer_days'] ?? 3);
        if ($candidate['days_left'] < 0) {
            $pmDeadlineAlerts['overdue'][] = $candidate;
        } elseif ($candidate['days_left'] <= $buffer) {
            $pmDeadlineAlerts['upcoming'][] = $candidate;
        }
    }

    $pmProjectsForJs = array_map(function (array $projRow) use ($pmProjectMeta) {
        $pid = (int)$projRow['id'];
        $meta = $pmProjectMeta[$pid] ?? [];
        return [
            'id' => $pid,
            'name' => $projRow['name'],
            'planned_budget' => (float)($meta['planned_budget'] ?? 0),
            'budget_threshold' => (int)($meta['budget_threshold'] ?? 80),
            'deadline_buffer_days' => (int)($meta['deadline_buffer_days'] ?? 3)
        ];
    }, $projects);

    try {
        $stmt = $pdo->prepare("SELECT id, name, output_qty FROM inventory_bom WHERE company_id = ? ORDER BY name ASC");
        $stmt->execute([$company_id]);
        $pmBomOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('PM BOM fetch failed: ' . $e->getMessage());
        $pmBomOptions = [];
    }

    $pmBomLookup = [];
    foreach ($pmBomOptions as $bomRow) {
        $bomId = (int)($bomRow['id'] ?? 0);
        if ($bomId <= 0) {
            continue;
        }
        $pmBomLookup[$bomId] = $bomRow['name'] ?? ('BOM #' . $bomId);
    }

    try {
        $stmt = $pdo->prepare("SELECT m.*, i.item_name, i.quantity AS available_stock FROM pm_project_materials m LEFT JOIN inventory i ON i.id = m.inventory_id WHERE m.company_id = ? ORDER BY m.project_id, i.item_name");
        $stmt->execute([$company_id]);
        $materialRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('PM material fetch failed: ' . $e->getMessage());
        $materialRows = [];
    }

    $pmMaterialsByProject = [];
    foreach ($materialRows as $row) {
        $pid = (int)($row['project_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if (!isset($pmMaterialsByProject[$pid])) {
            $pmMaterialsByProject[$pid] = [];
        }
        $pmMaterialsByProject[$pid][] = $row;
    }

    try {
        $stmt = $pdo->prepare("SELECT pr.*, i.item_name, p.name AS project_name FROM inventory_purchase_requests pr LEFT JOIN inventory i ON i.id = pr.inventory_id LEFT JOIN projects p ON p.id = pr.project_id WHERE pr.company_id = ? ORDER BY pr.created_at DESC");
        $stmt->execute([$company_id]);
        $pmPurchaseRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('PM purchase request fetch failed: ' . $e->getMessage());
        $pmPurchaseRequests = [];
    }
    $pendingPurchaseCount = 0;
    foreach ($pmPurchaseRequests as $requestRow) {
        if (($requestRow['status'] ?? 'pending') === 'pending') {
            $pendingPurchaseCount++;
        }
    }

    ?>
    
    <?php
    break;
// ...existing code...
case 'sales':
    $company_id = $_SESSION['company_id'];
    // Which sales sub-section: 'records' (default) or 'crm'
    $sales_section = $_GET['sales_section'] ?? 'records';
    // --- Handle Sale Submission: decrement inventory, insert sale, create finance record (transactional) ---
    $sales_message = null;
    $salesCrmRedirect = 'dashboard_admin.php?page=sales&sales_section=crm';
    $customerFlash = $_SESSION['sales_customer_flash'] ?? null;
    unset($_SESSION['sales_customer_flash']);
    $customerFormDefaults = $_SESSION['sales_customer_form_defaults'] ?? [
        'name' => '',
        'email' => '',
        'phone' => '',
        'address' => ''
    ];
    unset($_SESSION['sales_customer_form_defaults']);
    $customerEditDefaults = $_SESSION['sales_customer_edit_defaults'] ?? null;
    unset($_SESSION['sales_customer_edit_defaults']);
    $customerModalState = $_SESSION['sales_customer_modal'] ?? null;
    unset($_SESSION['sales_customer_modal']);

    $redirectSalesCrm = static function () use ($salesCrmRedirect) {
        header('Location: ' . $salesCrmRedirect);
        exit();
    };
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sale'])) {
        $inventory_id = $_POST['inventory_id'] ?? null;
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $price = (float) ($_POST['price'] ?? 0);
        $date_sold = $_POST['date_sold'] ?? null;
        if (!$inventory_id || $quantity <= 0 || $price <= 0 || !$date_sold) {
            $sales_message = "Please select a product, valid quantity, price and date.";
        } else {
            try {
                $pdo->beginTransaction();
                $selectInv = $pdo->prepare("SELECT item_name, quantity FROM inventory WHERE id = ? AND company_id = ? FOR UPDATE");
                $selectInv->execute([$inventory_id, $company_id]);
                $item = $selectInv->fetch(PDO::FETCH_ASSOC);
                if (!$item) throw new Exception("Selected inventory item not found.");
                if ((int)$item['quantity'] < $quantity) throw new Exception("Insufficient stock. Available: " . (int)$item['quantity']);
                $new_qty = (int)$item['quantity'] - $quantity;
                $updateInv = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND company_id = ?");
                $updateInv->execute([$new_qty, $inventory_id, $company_id]);
                $insertSale = $pdo->prepare("INSERT INTO sales (company_id, product, quantity, price, date_sold) VALUES (?, ?, ?, ?, ?)");
                $insertSale->execute([$company_id, $item['item_name'], $quantity, $price, $date_sold]);
                logActivity($pdo, $company_id, $_SESSION['user_id'], $_SESSION['role'], 'sales', 'add_sale', "Sold $quantity of " . $item['item_name'] . " for ₱" . ($price * $quantity));
                $sale_amount = $price * $quantity;
                $insertFinance = $pdo->prepare("INSERT INTO finance (company_id, amount, type, description, date) VALUES (?, ?, ?, ?, ?)");
                $insertFinance->execute([$company_id, $sale_amount, 'income', 'Sale: ' . $item['item_name'] . ' (Qty: ' . $quantity . ')', $date_sold]);
                $pdo->commit();
                header("Location: dashboard_admin.php?page=sales");
                exit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $sales_message = "Sale failed: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    // Handle delete sale (existing)
    if (isset($_GET['delete_sale'])) {
        $delete_id = $_GET['delete_sale'];
        $stmt = $pdo->prepare("DELETE FROM sales WHERE id = ? AND company_id = ?");
        $stmt->execute([$delete_id, $company_id]);
        logActivity($pdo, $company_id, $_SESSION['user_id'], $_SESSION['role'], 'sales', 'delete_sale', "Deleted sale ID: $delete_id");
    }
    // Fetch sales list and totals (always fetch so stats remain available even on crm tab)
    $stmt = $pdo->prepare("SELECT id, product, product_name, quantity, price, date_sold FROM sales WHERE company_id = ? ORDER BY date_sold DESC");
    $stmt->execute([$company_id]);
    $sales_data = $stmt->fetchAll();
    $sales_count = count($sales_data);
    $stmt = $pdo->prepare("SELECT SUM(quantity * price) AS total_revenue FROM sales WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $revenue = $stmt->fetchColumn();
    // Fetch available inventory items for the sales product selector
    $stmt = $pdo->prepare("SELECT id, item_name, quantity FROM inventory WHERE company_id = ? ORDER BY item_name ASC");
    $stmt->execute([$company_id]);
    $inventory_items = $stmt->fetchAll();
    // --- CRM: customers sync + add/delete ---
    // Add customer
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sale'])) {
    $inventory_id = $_POST['inventory_id'] ?? null;
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $price = (float) ($_POST['price'] ?? 0);
    $date_sold = $_POST['date_sold'] ?? null;

    if (!$inventory_id || $quantity <= 0 || $price <= 0 || !$date_sold) {
        $sales_message = "Please select a product, valid quantity, price and date.";
    } else {
        try {
            $pdo->beginTransaction();

            $selectInv = $pdo->prepare("SELECT id, item_name, quantity, reorder_level, sku, supplier_id FROM inventory WHERE id = ? AND company_id = ? FOR UPDATE"); // Added fields needed for check
            $selectInv->execute([$inventory_id, $company_id]);
            $item = $selectInv->fetch(PDO::FETCH_ASSOC);

            if (!$item) throw new Exception("Selected inventory item not found.");
            if ((int)$item['quantity'] < $quantity) throw new Exception("Insufficient stock. Available: " . (int)$item['quantity']);

            $new_qty = (int)$item['quantity'] - $quantity;

            $updateInv = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ? AND company_id = ?");
            $updateInv->execute([$new_qty, $inventory_id, $company_id]);

            $insertSale = $pdo->prepare("INSERT INTO sales (company_id, product, quantity, price, date_sold) VALUES (?, ?, ?, ?, ?)");
            $insertSale->execute([$company_id, $item['item_name'], $quantity, $price, $date_sold]);

            logActivity($pdo, $company_id, $_SESSION['user_id'], $_SESSION['role'], 'sales', 'add_sale', "Sold $quantity of " . $item['item_name'] . " for â‚±" . ($price * $quantity));

            $sale_amount = $price * $quantity;

         // - ADD STOCK CHECK HERE (after quantity reduction) -
// It's safer to re-fetch the item details after the update to ensure we have the latest quantity and other fields like reorder_level, sku, supplier_id
// --- CORRECTED STOCK CHECK LOGIC (within the add_sale block, after inventory update and sale record insertion, but before commit) ---
// Re-fetch item details to get the *new* quantity and other fields needed for the alert, just to be absolutely sure
$checkStmt = $pdo->prepare("SELECT id, item_name, quantity, reorder_level, sku FROM inventory WHERE id = ? AND company_id = ?"); // Removed supplier_id as we're not using suppliers
$checkStmt->execute([$inventory_id, $company_id]);
$updatedItem = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($updatedItem && $updatedItem['quantity'] <= $updatedItem['reorder_level']) { // Compare the quantity fetched AFTER the update

    // NO SUPPLIER CHECK: Removed because 'suppliers' table doesn't exist

    // Fetch vendor email (from vendors table, using supplier_id_external - WHICH IS CORRECT COLUMN NAME)
    $vendorEmail = null;
    // We need to find the vendor whose supplier_id_external matches the inventory item's ID
   $vendorStmt = $pdo->prepare("SELECT email FROM vendors WHERE id = ? AND company_id = ?"); // <- Use 'id' in vendors table
$vendorStmt->execute([$item['supplier_id'], $company_id]);
    $vendor = $vendorStmt->fetch();
    if ($vendor && !empty($vendor['email'])) {
        $vendorEmail = $vendor['email'];
    }

    // NO SUPPLIER ALERT: Removed because 'suppliers' table doesn't exist

    // Send alert to vendor if available (SHOULD WORK NOW)
    if ($vendorEmail) {
        require_once 'send_stock_alert.php'; // Include the email function
        sendStockAlert($vendorEmail, $updatedItem['item_name'], $updatedItem['quantity'], $updatedItem['reorder_level'], $updatedItem['sku']); // <--- Uses correct values from $updatedItem fetched *after* update
    }

    if (!$vendorEmail) { // Changed condition
        error_log("Cannot send stock alert for item {$updatedItem['item_name']} (SKU: {$updatedItem['sku']}). No vendor email found or empty.");
    }
}
// --- END CORRECTED STOCK CHECK ---

            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollback();
            $sales_message = "Sale failed: " . $e->getMessage();
        }
    }

    header("Location: dashboard_admin.php?page=sales");
    exit();
}

    // CRM customer handlers
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {
        $formDefaults = [
            'name' => trim($_POST['customer_name'] ?? ''),
            'email' => trim($_POST['customer_email'] ?? ''),
            'phone' => trim($_POST['customer_phone'] ?? ''),
            'address' => trim($_POST['customer_address'] ?? '')
        ];

        if ($formDefaults['name'] === '') {
            $_SESSION['sales_customer_flash'] = ['type' => 'error', 'message' => 'Customer name is required.'];
            $_SESSION['sales_customer_form_defaults'] = $formDefaults;
            $_SESSION['sales_customer_modal'] = 'add';
            $redirectSalesCrm();
        }

        if ($formDefaults['email'] !== '' && !filter_var($formDefaults['email'], FILTER_VALIDATE_EMAIL)) {
            $_SESSION['sales_customer_flash'] = ['type' => 'error', 'message' => 'Please provide a valid email address.'];
            $_SESSION['sales_customer_form_defaults'] = $formDefaults;
            $_SESSION['sales_customer_modal'] = 'add';
            $redirectSalesCrm();
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO customers (company_id, name, email, phone, address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$company_id, $formDefaults['name'], $formDefaults['email'], $formDefaults['phone'], $formDefaults['address']]);
            logActivity($pdo, $company_id, $_SESSION['user_id'] ?? null, $_SESSION['role'] ?? 'unknown', 'sales', 'add_customer', 'Added customer ' . $formDefaults['name']);
            $_SESSION['sales_customer_flash'] = ['type' => 'success', 'message' => 'Customer added successfully.'];
        } catch (PDOException $e) {
            error_log('Add customer failed: ' . $e->getMessage());
            $_SESSION['sales_customer_flash'] = ['type' => 'error', 'message' => 'Unable to add customer. Please try again.'];
            $_SESSION['sales_customer_form_defaults'] = $formDefaults;
            $_SESSION['sales_customer_modal'] = 'add';
        }

        $redirectSalesCrm();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_customer'])) {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $editDefaults = [
            'customer_id' => $customerId,
            'name' => trim($_POST['customer_name'] ?? ''),
            'email' => trim($_POST['customer_email'] ?? ''),
            'phone' => trim($_POST['customer_phone'] ?? ''),
            'address' => trim($_POST['customer_address'] ?? '')
        ];

        if ($customerId <= 0) {
            $_SESSION['sales_customer_flash'] = ['type' => 'error', 'message' => 'Invalid customer selection.'];
            $_SESSION['sales_customer_modal'] = 'edit';
            $_SESSION['sales_customer_edit_defaults'] = $editDefaults;
            $redirectSalesCrm();
        }

        if ($editDefaults['name'] === '') {
            $_SESSION['sales_customer_flash'] = ['type' => 'error', 'message' => 'Customer name is required.'];
            $_SESSION['sales_customer_modal'] = 'edit';
            $_SESSION['sales_customer_edit_defaults'] = $editDefaults;
            $redirectSalesCrm();
        }

        if ($editDefaults['email'] !== '' && !filter_var($editDefaults['email'], FILTER_VALIDATE_EMAIL)) {
            $_SESSION['sales_customer_flash'] = ['type' => 'error', 'message' => 'Please provide a valid email address.'];
            $_SESSION['sales_customer_modal'] = 'edit';
            $_SESSION['sales_customer_edit_defaults'] = $editDefaults;
            $redirectSalesCrm();
        }

        try {
            $stmt = $pdo->prepare("UPDATE customers SET name = ?, email = ?, phone = ?, address = ? WHERE customer_id = ? AND company_id = ?");
            $stmt->execute([$editDefaults['name'], $editDefaults['email'], $editDefaults['phone'], $editDefaults['address'], $customerId, $company_id]);
            logActivity($pdo, $company_id, $_SESSION['user_id'] ?? null, $_SESSION['role'] ?? 'unknown', 'sales', 'edit_customer', 'Updated customer ' . $editDefaults['name'] . " (ID: $customerId)");
            $_SESSION['sales_customer_flash'] = ['type' => 'success', 'message' => 'Customer updated successfully.'];
        } catch (PDOException $e) {
            error_log('Edit customer failed: ' . $e->getMessage());
            $_SESSION['sales_customer_flash'] = ['type' => 'error', 'message' => 'Unable to update customer. Please try again.'];
            $_SESSION['sales_customer_modal'] = 'edit';
            $_SESSION['sales_customer_edit_defaults'] = $editDefaults;
        }

        $redirectSalesCrm();
    }

    if (isset($_GET['delete_customer'])) {
        $customerId = (int)$_GET['delete_customer'];
        if ($customerId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM customers WHERE customer_id = ? AND company_id = ?");
                $stmt->execute([$customerId, $company_id]);
                if ($stmt->rowCount() > 0) {
                    logActivity($pdo, $company_id, $_SESSION['user_id'] ?? null, $_SESSION['role'] ?? 'unknown', 'sales', 'delete_customer', 'Deleted customer ID: ' . $customerId);
                    $_SESSION['sales_customer_flash'] = ['type' => 'success', 'message' => 'Customer deleted successfully.'];
                } else {
                    $_SESSION['sales_customer_flash'] = ['type' => 'error', 'message' => 'Customer not found or already removed.'];
                }
            } catch (PDOException $e) {
                error_log('Delete customer failed: ' . $e->getMessage());
                $_SESSION['sales_customer_flash'] = ['type' => 'error', 'message' => 'Unable to delete customer. Please try again.'];
            }
        }
        $redirectSalesCrm();
    }

    // Fetch customers for CRM list
    $stmt = $pdo->prepare("SELECT customer_id, name, email, phone, address, created_at FROM customers WHERE company_id = ? ORDER BY created_at DESC");
    $stmt->execute([$company_id]);
    $customers = $stmt->fetchAll();
    // --- Render navigation for Sales (Records | CRM) similar to HR navigation ---
    ?>
    <div class="card" style="margin-bottom: 1.5rem;">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-shopping-cart"></i> Sales
            </div>
        </div>
        <div class="inventory-tabs">
            <button type="button"
                    class="inventory-tab-btn <?= $sales_section === 'records' ? 'active' : '' ?>"
                    onclick="window.location.href='dashboard_admin.php?page=sales&sales_section=records';">
                <i class="fas fa-receipt nav-icon"></i>
                <span>Sales Records</span>
            </button>
        </div>
    </div>
    <?php
    // --- Show the chosen subsection ---
    if ($sales_section === 'records'):
    ?>
        <!-- existing Sales Records UI (unchanged) -->
        <div class="content-grid">
            <div>
                <div class="card">
                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                        <div class="card-title">
                            <i class="fas fa-shopping-cart"></i> Sales Records
                        </div>
                        <button type="button" class="edit-btn" style="padding: 0.75rem 1rem; font-size: 0.9375rem;" onclick="openSalesModal()" <?= count($inventory_items) === 0 ? 'disabled' : '' ?>>
                            <i class="fas fa-plus"></i> Record Sale
                        </button>
                    </div>
                    <div class="table-container">
                        <?php if ($customerFlash): ?>
                            <?php $crmFlashIsError = ($customerFlash['type'] ?? 'info') === 'error'; ?>
                            <div class="crm-flash-message" style="margin-bottom:1rem; padding:0.75rem 1rem; border-radius:var(--radius); border:1px solid <?= $crmFlashIsError ? 'rgba(239, 68, 68, 0.35)' : 'rgba(34, 197, 94, 0.35)' ?>; background: <?= $crmFlashIsError ? 'rgba(239, 68, 68, 0.1)' : 'rgba(34, 197, 94, 0.12)' ?>; color: var(--text-primary);">
                                <?= htmlspecialchars($customerFlash['message']) ?>
                            </div>
                        <?php endif; ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date Sold</th>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Price</th>
                                    <th>Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sales_data as $sale): ?>
                                <tr>
                                    <td><?= htmlspecialchars($sale['date_sold']) ?></td>
                                    <?php $productLabel = $sale['product_name'] ?? $sale['product']; ?>
                                    <td><?= htmlspecialchars($productLabel) ?></td>
                                    <td><?= htmlspecialchars($sale['quantity']) ?></td>
                                    <td>₱<?= number_format($sale['price'], 2) ?></td>
                                    <td>₱<?= number_format($sale['quantity'] * $sale['price'], 2) ?></td>
                                    <td>
                                        <a href="#" class="action-btn" onclick="openDeleteModal('dashboard_admin.php?page=sales&delete_sale=<?= $sale['id'] ?>')">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div id="recordSaleModal" class="modal-overlay" style="display:none;">
            <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="recordSaleModalTitle" style="max-width: 480px; width: 100%;">
                <div class="modal-header" style="justify-content: flex-start; align-items: center; margin-bottom: 0.75rem;">
                    <h3 id="recordSaleModalTitle" class="modal-title" style="margin: 0;">Record Sale</h3>
                </div>
                <div class="modal-body">
                    <?php if (!empty($sales_message)): ?>
                        <div class="error-message" style="margin-bottom: 1rem;"><?= $sales_message ?></div>
                    <?php endif; ?>
                    <form method="POST" id="salesForm">
                        <div class="form-group">
                            <label for="add_inventory_select">Product</label>
                            <?php if (count($inventory_items) === 0): ?>
                                <div style="padding: .75rem; background: var(--border-light); border-radius: var(--radius); color: var(--text-secondary);">
                                    No inventory items available. Please add items in Inventory first.
                                </div>
                            <?php else: ?>
                                <select id="add_inventory_select" name="inventory_id" required>
                                    <option value="">Select product...</option>
                                    <?php foreach ($inventory_items as $it): ?>
                                        <option value="<?= (int)$it['id'] ?>" data-qty="<?= (int)$it['quantity'] ?>">
                                            <?= htmlspecialchars($it['item_name']) ?> (Available: <?= (int)$it['quantity'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="add_qty">Quantity</label>
                            <input type="number" id="add_qty" name="quantity" min="1" placeholder="Enter quantity" required>
                            <small id="availableHint" style="display:block; margin-top:6px; color:var(--text-secondary); font-size:0.85rem;"></small>
                        </div>
                        <div class="form-group">
                            <label for="add_price">Price per Unit</label>
                            <input type="number" id="add_price" step="0.01" name="price" placeholder="Enter price" required>
                        </div>
                        <div class="form-group">
                            <label for="add_date_sold">Date Sold</label>
                            <input type="date" id="add_date_sold" name="date_sold" required>
                        </div>
                        <div class="modal-actions" style="justify-content: flex-end; margin-top: 1rem;">
                            <button type="button" class="btn-secondary" onclick="closeSalesModal()">Cancel</button>
                            <button type="submit" name="add_sale" class="btn-primary" style="margin-left: .75rem;" <?= count($inventory_items) === 0 ? 'disabled' : '' ?>>Record Sale</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php
    elseif ($sales_section === 'crm'):
    ?>
        <!-- CRM: Customers list with modal-trigger button -->
        <div class="content-grid">
            <div>
                <div class="card">
                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
                        <div class="card-title"><i class="fas fa-address-book"></i> Customers</div>
                        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                            <span class="card-badge"><?= count($customers) ?> Customers</span>
                            <button type="button" class="edit-btn" style="padding: 0.75rem 1rem; font-size: 0.9375rem;" onclick="openCustomerModal()">
                                <i class="fas fa-user-plus"></i> Add Customer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php
    endif;
    ?>
    <script>
    function openSalesModal() {
        const modal = document.getElementById('recordSaleModal');
        if (!modal) { return; }
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeSalesModal() {
        const modal = document.getElementById('recordSaleModal');
        if (!modal) { return; }
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    document.getElementById('recordSaleModal')?.addEventListener('click', function(event) {
        if (event.target === this) {
            closeSalesModal();
        }
    });

    function openCustomerModal() {
        const modal = document.getElementById('addCustomerModal');
        if (!modal) { return; }
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeCustomerModal() {
        const modal = document.getElementById('addCustomerModal');
        if (!modal) { return; }
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    document.getElementById('addCustomerModal')?.addEventListener('click', function(event) {
        if (event.target === this) {
            closeCustomerModal();
        }
    });

    modal.setAttribute('aria-hidden', 'true');
    }

    document.getElementById('editCustomerModal')?.addEventListener('click', function(event) {
        if (event.target === this) {
            closeEditCustomerModal();
        }
    });

    <?php if (!empty($sales_message) && $sales_section === 'records'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        openSalesModal();
    });
    <?php endif; ?>

    const editCustomerDefaultsPayload = <?= !empty($customerEditDefaults) ? json_encode($customerEditDefaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : 'null' ?>;
    const customerModalState = <?= $customerModalState ? json_encode($customerModalState) : 'null' ?>;
    if (customerModalState === 'add') {
        document.addEventListener('DOMContentLoaded', function() {
            openCustomerModal();
        });
    } else if (customerModalState === 'edit' && editCustomerDefaultsPayload) {
        document.addEventListener('DOMContentLoaded', function() {
            openEditCustomerModal(editCustomerDefaultsPayload);
        });
    }
    </script>
    <?php
    break;
default:
    $company_id = $_SESSION['company_id'];
    // Revenue data (current vs previous month)
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE()) THEN amount ELSE 0 END) AS current_month,
            SUM(CASE WHEN MONTH(date) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date) = YEAR(CURDATE() - INTERVAL 1 MONTH) THEN amount ELSE 0 END) AS previous_month
        FROM finance WHERE company_id = ? AND type = 'income'
    ");
    $stmt->execute([$company_id]);
    $rev = $stmt->fetch();
    $revenue_current = $rev['current_month'] ?? 0;
    $revenue_previous = $rev['previous_month'] ?? 0;
    $revenue_change = $revenue_previous > 0 ? (($revenue_current - $revenue_previous) / $revenue_previous) * 100 : 0;
    // Sales data
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN MONTH(date_sold) = MONTH(CURDATE()) AND YEAR(date_sold) = YEAR(CURDATE()) THEN 1 END) AS current_month,
            COUNT(CASE WHEN MONTH(date_sold) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date_sold) = YEAR(CURDATE() - INTERVAL 1 MONTH) THEN 1 END) AS previous_month
        FROM sales WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    $orders = $stmt->fetch();
    $orders_current = $orders['current_month'] ?? 0;
    $orders_previous = $orders['previous_month'] ?? 0;
    $orders_change = $orders_previous > 0 ? (($orders_current - $orders_previous) / $orders_previous) * 100 : 0;
    // Employee data (from hr table)
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN MONTH(date_hired) = MONTH(CURDATE()) AND YEAR(date_hired) = YEAR(CURDATE()) THEN 1 END) AS current_month,
            COUNT(CASE WHEN MONTH(date_hired) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(date_hired) = YEAR(CURDATE() - INTERVAL 1 MONTH) THEN 1 END) AS previous_month
        FROM hr WHERE company_id = ?
    ");
    $stmt->execute([$company_id]);
    $customers = $stmt->fetch();
    $customers_current = $customers['current_month'] ?? 0;
    $customers_previous = $customers['previous_month'] ?? 0;
    $customer_change = $customers_previous > 0 ? (($customers_current - $customers_previous) / $customers_previous) * 100 : 0;
    // Inventory total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $inventory_total = $stmt->fetchColumn();
    // Get monthly finance data for last 6 months
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
        FROM finance 
        WHERE company_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute([$company_id]);
    $finance_monthly = $stmt->fetchAll();
    // Get sales by product (top 5)
    $stmt = $pdo->prepare("
        SELECT product, SUM(quantity) as total_quantity, SUM(quantity * price) as total_revenue
        FROM sales 
        WHERE company_id = ?
        GROUP BY product
        ORDER BY total_revenue DESC
        LIMIT 5
    ");
    $stmt->execute([$company_id]);
    $top_products = $stmt->fetchAll();
    // Get inventory by category
    $stmt = $pdo->prepare("
        SELECT category, SUM(quantity) as total_quantity
        FROM inventory 
        WHERE company_id = ?
        GROUP BY category
        ORDER BY total_quantity DESC
    ");
    $stmt->execute([$company_id]);
    $inventory_by_category = $stmt->fetchAll();
    // Get employee hiring trend (last 6 months)
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date_hired, '%Y-%m') as month,
            COUNT(*) as count
        FROM hr 
        WHERE company_id = ? AND date_hired >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(date_hired, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute([$company_id]);
    $hr_monthly = $stmt->fetchAll();

    $dashboardData = [
        'revenue_current' => (float)($revenue_current ?? 0),
        'orders_current' => (int)($orders_current ?? 0),
        'customers_current' => (int)($customers_current ?? 0),
        'finance_monthly' => $finance_monthly ?? [],
        'top_products' => $top_products ?? [],
        'inventory_by_category' => $inventory_by_category ?? [],
        'hr_monthly' => $hr_monthly ?? []
    ];
?>
<script>
window.DASHBOARD_DATA = <?= json_encode($dashboardData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<div class="dashboard-grid">
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-title">Total Revenue</div>
            <div class="stat-trend <?= $revenue_change >= 0 ? 'up' : 'down' ?>">
                <i class="fas fa-arrow-<?= $revenue_change >= 0 ? 'up' : 'down' ?>"></i> <?= abs(round($revenue_change, 1)) ?>%
            </div>
        </div>
        <div class="stat-value">₱<span id="revenueCount"><?= number_format($revenue_current, 2) ?></span></div>
        <div class="stat-change">vs last month</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-title">Total Orders</div>
            <div class="stat-trend <?= $orders_change >= 0 ? 'up' : 'down' ?>">
                <i class="fas fa-arrow-<?= $orders_change >= 0 ? 'up' : 'down' ?>"></i> <?= abs(round($orders_change, 1)) ?>%
            </div>
        </div>
        <div class="stat-value"><span id="orderCount"><?= $orders_current ?></span></div>
        <div class="stat-change">vs last month</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-title">Inventory Items</div>
        </div>
        <div class="stat-value"><span id="inventoryTotal"><?= $inventory_total ?></span></div>
        <div class="stat-change">Total items in stock</div>
    </div>
</div>
<!-- Charts Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-top: 24px;">
    <!-- Finance Chart -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-chart-line"></i> Income vs Expense (6 Months)
            </div>
        </div>
        <div class="card-body">
            <canvas id="financeChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
    <!-- Sales by Product Chart -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-chart-bar"></i> Top 5 Products by Revenue
            </div>
        </div>
        <div class="card-body">
            <canvas id="salesChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
    <!-- Inventory Chart -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-chart-pie"></i> Inventory by Category
            </div>
        </div>
        <div class="card-body">
            <canvas id="inventoryChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Chart.js default colors
const chartColors = {
    primary: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim(),
    success: getComputedStyle(document.documentElement).getPropertyValue('--success').trim(),
    danger: getComputedStyle(document.documentElement).getPropertyValue('--danger').trim(),
    warning: getComputedStyle(document.documentElement).getPropertyValue('--warning').trim(),
    info: getComputedStyle(document.documentElement).getPropertyValue('--info').trim(),
};
// Ensure DOM is loaded before initializing charts
document.addEventListener('DOMContentLoaded', function() {
    // Finance Chart (Income vs Expense)
    const financeData = <?= json_encode($finance_monthly) ?>;
    const financeLabels = financeData.map(item => {
        const date = new Date(item.month + '-01');
        return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    });
    const incomeData = financeData.map(item => parseFloat(item.income) || 0); // Ensure numeric value, default to 0
    const expenseData = financeData.map(item => parseFloat(item.expense) || 0); // Ensure numeric value, default to 0
    new Chart(document.getElementById('financeChart'), {
        type: 'line',
        data: {
            labels: financeLabels,
            datasets: [{
                label: 'Income',
                data: incomeData,
                borderColor: chartColors.success,
                backgroundColor: chartColors.success + '20',
                tension: 0.4,
                fill: true
            }, {
                label: 'Expense',
                data: expenseData,
                borderColor: chartColors.danger,
                backgroundColor: chartColors.danger + '20',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₱' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
    // Sales Chart (Top Products)
    const salesData = <?= json_encode($top_products) ?>;
    const productLabels = salesData.map(item => item.product || 'Unknown Product'); // Handle potential null/undefined product names
    const revenueData = salesData.map(item => parseFloat(item.total_revenue) || 0); // Ensure numeric value, default to 0
    new Chart(document.getElementById('salesChart'), {
        type: 'bar',
        data: {
            labels: productLabels,
            datasets: [{
                label: 'Revenue',
                data: revenueData,
                backgroundColor: [
                    chartColors.primary + 'CC',
                    chartColors.success + 'CC',
                    chartColors.info + 'CC',
                    chartColors.warning + 'CC',
                    chartColors.danger + 'CC'
                ],
                borderColor: [
                    chartColors.primary,
                    chartColors.success,
                    chartColors.info,
                    chartColors.warning,
                    chartColors.danger
                ],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₱' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
    // Inventory Chart (By Category)
    const inventoryData = <?= json_encode($inventory_by_category) ?>;
    const categoryLabels = inventoryData.map(item => item.category || 'Uncategorized'); // Handle potential null/undefined categories
    const quantityData = inventoryData.map(item => parseInt(item.total_quantity) || 0); // Ensure integer, default to 0
    new Chart(document.getElementById('inventoryChart'), {
        type: 'doughnut',
        data: {
            labels: categoryLabels,
            datasets: [{
                data: quantityData,
                backgroundColor: [
                    chartColors.primary + 'CC',
                    chartColors.success + 'CC',
                    chartColors.info + 'CC',
                    chartColors.warning + 'CC',
                    chartColors.danger + 'CC',
                    '#9333ea' + 'CC',
                    '#ec4899' + 'CC'
                ],
                borderColor: '#ffffff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'right'
                }
            }
        }
    });
    // HR Chart (Hiring Trend)
    const hrData = <?= json_encode($hr_monthly) ?>;
    const hrLabels = hrData.map(item => {
        const date = new Date(item.month + '-01');
        return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    });
    const hiringData = hrData.map(item => parseInt(item.count) || 0); // Ensure integer, default to 0
    new Chart(document.getElementById('hrChart'), {
        type: 'line',
        data: {
            labels: hrLabels,
            datasets: [{
                label: 'New Hires',
                data: hiringData,
                borderColor: chartColors.primary,
                backgroundColor: chartColors.primary + '20',
                tension: 0.4,
                fill: true,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
});
</script>
<?php
    break;
} // end switch
?>
    </div>
</div>
<div id="deleteModal" class="modal-overlay">
    <div class="modal-box">
        <h3><i class="fas fa-exclamation-triangle" style="color: var(--warning);"></i> Confirm Deletion</h3>
        <p>Are you sure you want to delete this item? This action cannot be undone.</p>
        <div class="modal-actions">
            <button id="confirmDeleteBtn">Yes, Delete</button>
            <button onclick="closeDeleteModal(true)">Cancel</button>
        </div>
    </div>
</div>
<script>
function toggleTheme() {
    const html = document.documentElement;
    const themeToggle = document.getElementById('themeToggle');
    const icon = themeToggle.querySelector('i');
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    // Update icon with smooth transition
    icon.style.transform = 'rotate(360deg)';
    setTimeout(() => {
        icon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        icon.style.transform = 'rotate(0deg)';
    }, 150);
}
// Inventory table search (guarded so other modules without the search box do not break scripts)
const inventorySearchInput = document.querySelector('.search-box input');
if (inventorySearchInput) {
    inventorySearchInput.addEventListener('input', function() {
        const search = this.value.toLowerCase();
        const rows = document.querySelectorAll('.data-table tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(search) ? '' : 'none';
        });
    });
}
// Load saved theme on page load
document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('theme') || 'light';
    const html = document.documentElement;
    const themeToggle = document.getElementById('themeToggle');
    const icon = themeToggle.querySelector('i');
    html.setAttribute('data-theme', savedTheme);
    icon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
});
function animate(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    let start = 0;
    const duration = 1000;
    const step = (timestamp) => {
        if (!start) start = timestamp;
        const progress = Math.min((timestamp - start) / duration, 1);
        const val = Math.floor(progress * value);
        if (el.textContent.includes('₱')) {
            el.textContent = '₱' + val.toLocaleString();
        } else {
            el.textContent = val.toLocaleString();
        }
        if (progress < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
}
document.addEventListener("DOMContentLoaded", () => {
    // Animate dashboard stats on load
    animate("userCount", <?= $user_count ?? 0 ?>);
    animate("totalIncome", <?= $incomeTotal ?>);
    animate("totalExpense", <?= $visibleExpenseTotal ?>);
    animate("netBalance", <?= $net ?>);
    animate("inventoryCount", <?= $inventory_count ?? 0 ?>);
    animate("salesCount", <?= $sales_count ?? 0 ?>);
    animate("totalRevenue", <?= $revenue ?? 0 ?>);
    animate("hrCount", <?= $hr_count ?? 0 ?>);
    animate("revenueCount", <?= $revenue_current ?? 0 ?>);
    animate("orderCount", <?= $orders_current ?? 0 ?>);
    animate("customerCount", <?= $customers_current ?? 0 ?>);
});
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const feedback = window.__actionFeedback;
    if (!feedback) {
        return;
    }

    const TYPE_TITLES = {
        success: 'Success',
        danger: 'Action Completed',
        warning: 'Heads Up',
        info: 'Notice'
    };

    const pending = feedback.consume();
    if (pending && pending.message) {
        feedback.show(pending.message, pending.type || 'success', {
            duration: pending.duration || 4500,
            title: pending.title || TYPE_TITLES[pending.type || 'success']
        });
    }

    const deriveMessage = (form) => {
        if (form.dataset.actionLabel) {
            return `${form.dataset.actionLabel} completed successfully.`;
        }
        const submitButton = form.querySelector('[type="submit"]');
        if (submitButton) {
            const label = (submitButton.dataset.feedbackLabel || submitButton.value || submitButton.textContent || 'Action').trim();
            if (label) {
                return `${label} successful.`;
            }
        }
        return 'Action completed successfully.';
    };

    const deriveTitle = (target, type) => {
        const customTitle = target?.dataset?.successTitle || target?.dataset?.queueTitle;
        if (customTitle) {
            return customTitle;
        }
        return TYPE_TITLES[type] || TYPE_TITLES.success;
    };

    const trackedForms = document.querySelectorAll('form[method="post" i]:not([data-feedback-disabled="true"]), form[data-success-message]');
    trackedForms.forEach((form) => {
        form.addEventListener('submit', () => {
            const message = form.dataset.successMessage || deriveMessage(form);
            const type = form.dataset.successType || 'success';
            const title = deriveTitle(form, type);
            feedback.queue(message, type, { defer: true, title });
        }, { capture: true });
    });

    const isTrue = (value) => typeof value === 'string' && ['true', '1', 'yes'].includes(value.toLowerCase());

    document.querySelectorAll('[data-queue-message]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const message = trigger.dataset.queueMessage;
            if (!message) {
                return;
            }
            const type = trigger.dataset.queueType || 'success';
            const title = deriveTitle(trigger, type);
            const defer = isTrue(trigger.dataset.queueDefer ?? 'true');
            feedback.queue(message, type, { defer, title });
        });
    });
});
</script>
<!-- place this before </body> in your dashboards or save as assets/search.js and include -->
<script>
(function(){
  function debounce(fn, wait){
    let t;
    return function(...args){ clearTimeout(t); t = setTimeout(()=>fn.apply(this,args), wait); };
  }
  function escapeRegExp(s){ return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  function makeNoResultsRow(table){
    const tbody = table.tBodies[0]; if(!tbody) return null;
    let nr = tbody.querySelector('.no-results-row');
    if (!nr) {
      nr = document.createElement('tr'); nr.className = 'no-results-row';
      const colspan = (table.tHead && table.tHead.rows[0]) ? table.tHead.rows[0].cells.length : 1;
      nr.innerHTML = `<td colspan="${colspan}" style="text-align:center;padding:1rem;color:var(--text-secondary)">No matching results</td>`;
      tbody.appendChild(nr);
    }
    return nr;
  }
  // Strict date parser: accepts YYYY-MM-DD, YYYY/MM/DD, DD/MM/YYYY, DD-MM-YYYY, MM/DD/YYYY
  function toISODateStrict(s){
    if(!s) return '';
    s = String(s).trim();
    // strip time portion if present (e.g. "2025-10-20 12:34:56")
    const dateOnly = s.split('T')[0].split(' ')[0];
    // YYYY-MM-DD or YYYY/MM/DD
    let m = dateOnly.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
    if (m) {
      const yyyy = m[1], mm = m[2].padStart(2,'0'), dd = m[3].padStart(2,'0');
      const d = new Date(`${yyyy}-${mm}-${dd}`);
      if (!isNaN(d)) return d.toISOString().slice(0,10);
    }
    // DD/MM/YYYY or DD-MM-YYYY or MM/DD/YYYY
    m = dateOnly.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    if (m) {
      // try DD/MM/YYYY
      let d = new Date(`${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`);
      if (!isNaN(d)) return d.toISOString().slice(0,10);
      // try MM/DD/YYYY
      d = new Date(`${m[3]}-${m[1].padStart(2,'0')}-${m[2].padStart(2,'0')}`);
      if (!isNaN(d)) return d.toISOString().slice(0,10);
    }
    // Fallback: try native Date parse on original string (handles ISO+time)
    const dFallback = new Date(s);
    if (!isNaN(dFallback)) return dFallback.toISOString().slice(0,10);
    return '';
  }
  function parseNumberFromString(s){
    if(!s) return null;
    const cleaned = String(s).replace(/[^0-9\.\-]/g, '');
    if(cleaned === '' || cleaned === '.' || cleaned === '-') return null;
    const n = Number(cleaned);
    return isNaN(n) ? null : n;
  }
  function filterTables(input){
    const raw = (input.value || '').trim();
    if(raw === ''){
      const scope = input.closest('.main-content, .content') || document;
      Array.from(scope.querySelectorAll('.data-table tbody tr')).forEach(r => r.style.display = '');
      Array.from(document.querySelectorAll('.no-results-row')).forEach(n => n.style.display = 'none');
      return;
    }
    // tokens
    const tokens = raw.split(/\s+/).filter(Boolean);
    const numericTokens = tokens.filter(t => /^\d+$/.test(t)).map(Number);
    const dateTokens = tokens.map(t => toISODateStrict(t)).filter(Boolean); // only strict parsed dates
    const textTokens = tokens.filter(t => !/^\d+$/.test(t) && !toISODateStrict(t));
    const scope = input.closest('.main-content, .content') || document;
    let tables = Array.from(scope.querySelectorAll('.data-table'));
    if(tables.length === 0) tables = Array.from(document.querySelectorAll('.data-table'));
    tables.forEach(table => {
      const tbody = table.tBodies[0]; if(!tbody) return;
      const rows = Array.from(tbody.rows).filter(r => !r.classList.contains('no-results-row') && !r.classList.contains('template'));
      // detect relevant column indexes
      let qtyIdx = -1, dateIdx = -1, itemIdx = -1, catIdx = -1;
      if(table.tHead && table.tHead.rows[0]){
        Array.from(table.tHead.rows[0].cells).forEach((th,i) => {
          const h = th.textContent.trim().toLowerCase();
          if(qtyIdx === -1 && h.includes('quantity')) qtyIdx = i;
          if(dateIdx === -1 && (h.includes('date added') || h.includes('date sold') || h === 'date')) dateIdx = i;
          if(itemIdx === -1 && (h.includes('item') || h.includes('product') || h.includes('name'))) itemIdx = i;
          if(catIdx === -1 && h.includes('category')) catIdx = i;
        });
      }
      let visible = 0;
      rows.forEach(row => {
        // text check: require all text tokens to appear in item or category (if available), fallback to whole row
        let textOk = true;
        if(textTokens.length){
          textOk = textTokens.every(tok => {
            const tokL = tok.toLowerCase();
            let found = false;
            if(itemIdx >= 0){
              const c = row.cells[itemIdx] ? row.cells[itemIdx].textContent.toLowerCase() : '';
              if(c.indexOf(tokL) !== -1) found = true;
            }
            if(!found && catIdx >= 0){
              const c = row.cells[catIdx] ? row.cells[catIdx].textContent.toLowerCase() : '';
              if(c.indexOf(tokL) !== -1) found = true;
            }
            if(!found){
              // fallback to row-wide search to preserve current behavior
              const full = row.textContent.toLowerCase();
              if(full.indexOf(tokL) !== -1) found = true;
            }
            return found;
          });
        }
        // numeric check: if numeric tokens present, require any numeric token to equal quantity cell
        let numericOk = true;
        if(numericTokens.length){
          if(qtyIdx >= 0){
            const cell = row.cells[qtyIdx];
            const cellNum = parseNumberFromString(cell ? cell.textContent : '');
            if(cellNum === null){
              // fallback: string contains token
              numericOk = numericTokens.some(nt => (cell && cell.textContent || '').indexOf(String(nt)) !== -1);
            } else {
              numericOk = numericTokens.some(nt => cellNum === nt);
            }
          } else {
            // no qty column -> fallback to row-wide substring match for any numeric token
            const full = row.textContent.toLowerCase();
            numericOk = numericTokens.some(nt => full.indexOf(String(nt)) !== -1);
          }
        }
        // date check: if date tokens present, require any date token to equal date cell
        let dateOk = true;
        if(dateTokens.length){
          if(dateIdx >= 0){
            const cell = row.cells[dateIdx];
            const cellISO = toISODateStrict(cell ? cell.textContent : '');
            dateOk = dateTokens.some(dt => dt === cellISO);
          } else {
            // no date column -> fallback false (user searched date but no date column)
            dateOk = false;
          }
        }
        const ok = textOk && numericOk && dateOk;
        row.style.display = ok ? '' : 'none';
        if(ok) visible++;
      });
      const nr = makeNoResultsRow(table);
      if(nr) nr.style.display = visible === 0 ? '' : 'none';
    });
  }
    document.addEventListener('DOMContentLoaded', function(){
        const inputs = Array.from(document.querySelectorAll('.search-box input'));
        if (!inputs.length) {
            return;
        }
        inputs.forEach(inp => {
            const handler = debounce(()=>filterTables(inp), 120);
            inp.removeEventListener && inp.removeEventListener('input', handler); // safe remove if duplicate
            inp.addEventListener('input', handler);
            inp.addEventListener('keydown', function(e){
                if(e.key === 'Enter'){
                    const scope = inp.closest('.main-content, .content') || document;
                    const first = scope.querySelector('.data-table tbody tr:not([style*="display: none"])');
                    if(first) first.scrollIntoView({behavior:'smooth', block:'center'});
                }
            });
        });
    });
})();
</script>
<script>
// Replace existing openMarkDefective block with this safe, global function
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
`;
            document.body.appendChild(modal);
            // close handlers
            modal.addEventListener('click', function(e){
                if (e.target === modal) modal.style.display = 'none';
            });
            modal.querySelector('#defCancelBtn')?.addEventListener('click', function(){
                modal.style.display = 'none';
            });
        }
        // find fields (support legacy id names too)
        const fid = document.getElementById('def_inventory_id') || document.querySelector('input[name="inventory_id"]');
        const nameField = document.getElementById('def_item_name') || document.getElementById('def_item');
        const curQtyField = document.getElementById('def_current_quantity') || document.getElementById('def_quantity');
        const defQtyField = document.getElementById('defective_quantity') || document.getElementById('defective_qty') || null;
        const reasonField = document.getElementById('def_reason') || document.getElementById('defective_reason');
        const id = item.id ?? item.ID ?? '';
        const name = item.item_name ?? item.itemName ?? item.name ?? '';
        const qty = Number(item.quantity ?? item.qty ?? 0) || 0;
        if (fid) fid.value = id;
        if (nameField) nameField.value = name;
        if (curQtyField) curQtyField.value = qty;
        if (defQtyField) {
            defQtyField.max = Math.max(1, qty);
            defQtyField.value = Math.min(Math.max(1, parseInt(defQtyField.value || 1, 10)), defQtyField.max || 1);
        }
        if (reasonField) reasonField.value = '';
        modal.style.display = 'flex';
        setTimeout(()=> { (reasonField || defQtyField)?.focus(); }, 120);
    } catch (err) {
        console.error('openMarkDefective error:', err);
    }
};
</script>

</body>
</html>