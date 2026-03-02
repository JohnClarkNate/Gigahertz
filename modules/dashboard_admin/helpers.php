<?php

if (!defined('LOGIN_ATTEMPT_FILE')) {
    define('LOGIN_ATTEMPT_FILE', __DIR__ . '/../../login_attempts.json');
}
if (!defined('LOGIN_ATTEMPT_LIMIT')) {
    define('LOGIN_ATTEMPT_LIMIT', 5);
}
if (!defined('LOGIN_LOCKOUT_MINUTES')) {
    define('LOGIN_LOCKOUT_MINUTES', 15);
}

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
        return is_array($decoded) ? $decoded : [];
    }
}

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
    function inventoryDecodeSignature(string $signature): ?array
    {
        $signature = trim($signature);
        if ($signature === '') {
            return null;
        }

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
