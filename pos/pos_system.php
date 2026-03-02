<?php
session_start();
require 'db.php';

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

if (!function_exists('posSlugify')) {
    function posSlugify(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value));
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'uncategorized';
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

// Check authorization - allow Admin and Staff (plus legacy POS/Sales head roles for compatibility)
if (!isset($_SESSION['user']) || !in_array($_SESSION['role'], ['admin', 'staff', 'head_pos', 'head_sales'])) {
    header("Location: login.php");
    exit();
}

if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$company_id = $_SESSION['company_id'];
$company_id = (int)$company_id;
posEnsureHiddenItemsTable($pdo);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $jsonPayload = file_get_contents('php://input');
    $decodedPayload = json_decode($jsonPayload, true);

    if (json_last_error() === JSON_ERROR_NONE && ($decodedPayload['action'] ?? '') === 'remove_item') {
        $itemId = isset($decodedPayload['item_id']) ? (int)$decodedPayload['item_id'] : 0;
        header('Content-Type: application/json');

        if ($itemId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid item selected.']);
            exit();
        }

        $removeStmt = $pdo->prepare("UPDATE inventory SET status = 'Inactive' WHERE company_id = ? AND id = ? AND (is_defective = 0 OR is_defective IS NULL)");
        $removeStmt->execute([$company_id, $itemId]);

        if ($removeStmt->rowCount() > 0) {
            logActivity(
                $pdo,
                $company_id,
                $_SESSION['user_id'] ?? null,
                $_SESSION['role'] ?? 'unknown',
                'pos',
                'remove_pos_item',
                sprintf('Removed item #%d from POS available products.', $itemId)
            );

            echo json_encode(['success' => true]);
            exit();
        }

        echo json_encode(['success' => false, 'message' => 'Item could not be removed or was already inactive.']);
        exit();
    }
}

// Fetch available items from inventory (exclude POS-hidden entries)
$stmt = $pdo->prepare('
        SELECT i.id, i.sku, i.item_name, i.category, i.selling_price, i.quantity
        FROM inventory i
        LEFT JOIN pos_hidden_items phi ON phi.inventory_id = i.id AND phi.company_id = i.company_id
        WHERE i.company_id = ?
            AND phi.inventory_id IS NULL
            AND i.status = "Active"
            AND i.quantity > 0
            AND (i.is_defective = 0 OR i.is_defective IS NULL)
        ORDER BY i.item_name ASC
');
$stmt->execute([$company_id]);
$available_items = $stmt->fetchAll();

$grouped_items = [];
foreach ($available_items as $item) {
    $categoryLabel = trim($item['category'] ?? '') !== '' ? $item['category'] : 'Uncategorized';
    $categorySlug = posSlugify($categoryLabel);
    $item['category_label'] = $categoryLabel;
    $item['category_slug'] = $categorySlug;
    if (!isset($grouped_items[$categoryLabel])) {
        $grouped_items[$categoryLabel] = [
            'slug' => $categorySlug,
            'items' => []
        ];
    }
    $grouped_items[$categoryLabel]['items'][] = $item;
}
ksort($grouped_items, SORT_NATURAL | SORT_FLAG_CASE);

logActivity(
    $pdo,
    $company_id,
    $_SESSION['user_id'] ?? null,
    $_SESSION['role'] ?? 'unknown',
    'pos',
    'view_pos_system',
    'Accessed POS System interface'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>POS System</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --success-hover: #059669;
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --warning: #f59e0b;
            --info: #0ea5e9;
            --dark: #1e293b;
            --light: #f8fafc;
            --border: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--light);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 20px;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            align-items: flex-start;
        }

        /* Header */
        .header {
            grid-column: 1 / -1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
        }

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .datetime-chip {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 12px;
            min-width: 230px;
            line-height: 1.2;
        }

        .datetime-daydate {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .datetime-time {
            font-size: 16px;
            color: var(--primary);
            font-weight: 700;
            margin-top: 3px;
        }

        .menu-container {
            position: relative;
        }

        .menu-toggle {
            width: 42px;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            display: inline-flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .menu-toggle:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow);
        }

        .menu-toggle-line {
            width: 18px;
            height: 2px;
            background: var(--text-primary);
            border-radius: 999px;
        }

        .menu-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 190px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
            padding: 8px;
            display: none;
            z-index: 20;
        }

        .menu-dropdown.show {
            display: block;
        }

        .menu-item {
            width: 100%;
            border: none;
            background: transparent;
            text-decoration: none;
            color: var(--text-primary);
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            text-align: left;
        }

        .menu-item:hover {
            background: var(--light);
        }

        .menu-item-danger {
            color: var(--danger);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: var(--success-hover);
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: var(--danger-hover);
        }

        .btn-secondary {
            background-color: #64748b;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #475569;
        }

        /* Products Section */
        .products-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow);
            grid-column: 1;
        }

        .category-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 15px;
        }

        .category-pill {
            border: 1px solid var(--border);
            background: white;
            color: var(--text-secondary);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .category-pill.active,
        .category-pill:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .category-empty-state {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: var(--text-secondary);
            border: 2px dashed var(--border);
            border-radius: 8px;
            margin-bottom: 15px;
            gap: 10px;
        }

        .category-empty-state i {
            font-size: 48px;
            opacity: 0.5;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--dark);
        }

        .search-box {
            margin-bottom: 15px;
        }

        .search-box input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }

        .products-grid {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .category-section {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 15px;
            background: #fff;
            box-shadow: var(--shadow);
        }

        .category-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .category-section-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .category-section-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 15px;
        }

        .product-card {
            background: var(--light);
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .product-card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .product-card.active {
            border-color: var(--success);
            background-color: rgba(16, 185, 129, 0.05);
        }

        .product-name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .product-price {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .product-stock {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }

        .product-category-label {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .product-card-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .add-to-cart-btn {
            width: 100%;
            padding: 8px;
            background-color: var(--success);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s ease;
            flex: 1;
        }

        .add-to-cart-btn:hover {
            background-color: var(--success-hover);
        }
        .remove-product-btn {
            border: none;
            background: var(--danger);
            color: #fff;
            border-radius: 4px;
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .remove-product-btn:hover {
            background: var(--danger-hover);
        }

        .add-to-cart-btn:disabled {
            background-color: #cbd5e1;
            cursor: not-allowed;
        }

        /* Cart Section */
        .cart-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow);
            grid-column: 2;
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 80px);
            overflow: hidden;
            align-self: flex-start;
        }

        .cart-items {
            flex: 1 1 auto;
            min-height: 120px;
            margin-bottom: 20px;
        }

        .cart-section.has-scroll .cart-items {
            overflow-y: auto;
            max-height: 360px;
            padding-right: 6px;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: var(--light);
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 4px solid var(--primary);
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
        }

        .cart-item-detail {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .cart-item-qty {
            display: flex;
            align-items: center;
            gap: 8px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 4px 8px;
        }

        .qty-input {
            width: 64px;
            text-align: center;
            border: none;
            border-left: 1px solid var(--border);
            border-right: 1px solid var(--border);
            padding: 2px 4px;
            font-size: 14px;
            color: var(--text-primary);
            background: transparent;
            appearance: textfield;
            -moz-appearance: textfield;
        }

        .qty-input::-webkit-outer-spin-button,
        .qty-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .qty-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            color: var(--primary);
            font-weight: 700;
            padding: 2px 6px;
        }

        .qty-btn:hover {
            color: var(--primary-hover);
        }

        .cart-item-total {
            font-weight: 600;
            color: var(--primary);
            text-align: right;
            min-width: 70px;
        }

        .cart-item-remove {
            background: var(--danger);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 10px;
            transition: all 0.2s ease;
        }

        .cart-item-remove:hover {
            background-color: var(--danger-hover);
        }

        /* Cart Summary */
        .cart-summary {
            border-top: 2px solid var(--border);
            padding-top: 15px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .discount-row {
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }

        .discount-input-group {
            display: flex;
            gap: 12px;
            width: 100%;
            flex-wrap: wrap;
        }

        .discount-field {
            flex: 1;
            min-width: 160px;
        }

        .discount-field label {
            display: block;
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }

        .discount-field select,
        .discount-field input {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }

        .summary-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .summary-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 12px;
            background: var(--primary);
            color: white;
            border-radius: 6px;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 15px;
        }

        .checkout-section {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .payment-input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }

        .payment-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .change-display {
            background: var(--light);
            padding: 10px;
            border-radius: 6px;
            text-align: center;
            font-size: 14px;
            display: none;
        }

        .change-display.show {
            display: block;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .payment-options {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .payment-option-btn {
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text-primary);
            border-radius: 8px;
            padding: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .payment-option-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .payment-option-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .payment-fields {
            margin-bottom: 16px;
        }

        .payment-fields label {
            display: block;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 6px;
            text-align: left;
        }

        .payment-fields input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }

        .cash-change-display {
            margin-top: 8px;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .cash-change-display.insufficient {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .payment-proceed-wrap {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .payment-proceed-wrap .btn {
            flex: 1;
            justify-content: center;
        }

        .payment-preview {
            text-align: left;
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 12px;
            background: var(--light);
            margin-bottom: 14px;
        }

        .payment-preview-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .payment-preview-item {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            padding: 6px 0;
            border-bottom: 1px solid var(--border);
        }

        .payment-preview-item:last-child {
            border-bottom: none;
        }

        .payment-preview-summary {
            margin-top: 10px;
            border-top: 1px solid var(--border);
            padding-top: 8px;
        }

        .payment-preview-summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .payment-preview-summary-row.total {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .action-buttons .btn {
            flex: 1;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
            max-width: 500px;
            width: 90%;
            text-align: center;
        }

        .modal-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .modal-success .modal-icon {
            color: var(--success);
        }

        .modal-error .modal-icon {
            color: var(--danger);
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .modal-message {
            color: var(--text-secondary);
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .receipt-preview {
            text-align: left;
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 15px;
            background: var(--light);
            margin-bottom: 20px;
        }

        .receipt-preview-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
            color: var(--text-primary);
        }

        .receipt-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        .receipt-item:last-child {
            border-bottom: none;
        }

        .receipt-item-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .receipt-item-price {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .receipt-item-subtotal {
            font-weight: 600;
            color: var(--primary);
        }

        .receipt-summary {
            margin-top: 15px;
            border-top: 1px solid var(--border);
            padding-top: 10px;
        }

        .receipt-summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            margin-bottom: 6px;
        }

        .receipt-summary-row.grand {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .empty-cart {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
        }

        .empty-cart-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        @media (max-width: 1024px) {
            .container {
                grid-template-columns: 1fr;
            }

            .products-section, .cart-section {
                grid-column: auto;
            }

            .cart-section {
                max-height: none;
                overflow: visible;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <img src="blue.png" alt="POS Logo" style="height: 40px; object-fit: contain;">
            <div class="header-actions">
                <div class="datetime-chip" aria-live="polite">
                    <div class="datetime-daydate" id="currentDayDate">--</div>
                    <div class="datetime-time" id="time">--:--:--</div>
                </div>
                <div class="menu-container" id="navMenuContainer">
                    <button type="button" class="menu-toggle" id="navMenuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="navMenuDropdown">
                        <span class="menu-toggle-line"></span>
                        <span class="menu-toggle-line"></span>
                        <span class="menu-toggle-line"></span>
                    </button>
                    <div class="menu-dropdown" id="navMenuDropdown">
                        <a href="pos/sales_history.php" class="menu-item">
                            <i class="fas fa-receipt"></i> Sales History
                        </a>
                        <form method="POST" style="margin: 0;">
                            <button type="submit" name="logout" class="menu-item menu-item-danger" id="logoutBtn">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Products Section -->
        <div class="products-section">
            <div class="section-title">
                <i class="fas fa-box"></i> Available Products
            </div>
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search products..." oninput="filterProducts()">
            </div>
            <div class="category-filter" id="categoryFilterButtons">
                <button type="button" class="category-pill active" data-category="all">All Items</button>
                <?php foreach ($grouped_items as $categoryLabel => $group): ?>
                    <button type="button" class="category-pill" data-category="<?= htmlspecialchars($group['slug']) ?>"><?= htmlspecialchars($categoryLabel) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="category-empty-state" id="emptyProductsState" style="display: <?= empty($available_items) ? 'flex' : 'none' ?>;">
                <i class="fas fa-inbox"></i>
                <p>No products available</p>
            </div>
            <div class="products-grid" id="productsGrid">
                <?php foreach ($grouped_items as $categoryLabel => $group): ?>
                    <section class="category-section" data-category-section="<?= htmlspecialchars($group['slug']) ?>">
                        <div class="category-section-header">
                            <h3><?= htmlspecialchars($categoryLabel) ?></h3>
                            <span><?= count($group['items']) ?> item(s)</span>
                        </div>
                        <div class="category-section-grid">
                            <?php foreach ($group['items'] as $item): ?>
                                <div class="product-card" data-item-id="<?= (int)$item['id'] ?>" data-sku="<?= htmlspecialchars($item['sku'] ?? '') ?>" data-item-name="<?= htmlspecialchars(strtolower($item['item_name'])) ?>" data-category="<?= htmlspecialchars($item['category_slug']) ?>" onclick="selectProduct(event, <?= $item['id'] ?>, '<?= addslashes($item['item_name']) ?>', <?= $item['selling_price'] ?>, <?= $item['quantity'] ?>)">
                                    <div class="product-name"><?= htmlspecialchars($item['item_name']) ?></div>
                                    <div class="product-price">₱<?= number_format($item['selling_price'], 2) ?></div>
                                    <div class="product-stock">Stock: <?= $item['quantity'] ?></div>
                                    <div class="product-category-label">Category: <?= htmlspecialchars($item['category_label']) ?></div>
                                    <div class="product-card-actions">
                                        <button type="button" class="add-to-cart-btn" onclick="addToCart(event, <?= $item['id'] ?>, '<?= addslashes($item['item_name']) ?>', <?= $item['selling_price'] ?>, <?= $item['quantity'] ?>)">Add to Cart</button>
                                    
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Cart Section -->
        <div class="cart-section">
            <div class="section-title">
                <i class="fas fa-shopping-cart"></i> Shopping Cart
            </div>
            
            <div class="cart-items" id="cartItems">
                <div class="empty-cart">
                    <div class="empty-cart-icon"><i class="fas fa-shopping-basket"></i></div>
                    <p>Cart is empty</p>
                </div>
            </div>

            <div id="manualAddArea" style="margin:12px 0 18px 0; display:flex; gap:8px; align-items:center;">
                <input type="text" id="manualSku" placeholder="Scan or enter SKU" style="flex:1;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:14px;">
                <input type="number" id="manualQty" value="1" min="1" style="width:90px;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:14px;">
                <button class="btn btn-success" id="manualAddBtn">Add</button>
            </div>

            <div class="cart-summary">
                <div class="summary-row">
                    <span class="summary-label">Enter Promo Code:</span>
                    <input type="text" id="promoCodeInput" class="payment-input" placeholder="Enter promo code" maxlength="20" autocomplete="off" spellcheck="false">
                </div>
                <div class="summary-row">
                    <span class="summary-label">Subtotal:</span>
                    <span class="summary-value" id="subtotal">₱0.00</span>
                </div>
                <div class="summary-row">
                    <span class="summary-label">Tax (12%):</span>
                    <span class="summary-value" id="tax">₱0.00</span>
                </div>
                <div class="total-row">
                    <span>TOTAL:</span>
                    <span id="total">₱0.00</span>
                </div>

                <div class="checkout-section">
                    <div class="action-buttons">
                        <button class="btn btn-primary" id="checkoutBtn" onclick="checkout()">
                            <i class="fas fa-credit-card"></i> Checkout
                        </button>
                        <button class="btn btn-danger" onclick="clearCart()">
                            <i class="fas fa-trash"></i> Clear
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Modal: appears before checkout to collect mode of payment details -->
    <div class="modal" id="paymentModal">
        <div class="modal-content">
            <div class="payment-preview" id="paymentPreviewBox">
                <div class="payment-preview-title">Receipt Preview</div>
                <div id="paymentPreviewItems"></div>
                <div class="payment-preview-summary">
                    <div class="payment-preview-summary-row">
                        <span>Subtotal</span>
                        <span id="paymentPreviewSubtotal">₱0.00</span>
                    </div>
                    <div class="payment-preview-summary-row">
                        <span>Promo Code</span>
                        <span id="paymentPreviewPromoCode">N/A</span>
                    </div>
                    <div class="payment-preview-summary-row">
                        <span>Tax</span>
                        <span id="paymentPreviewTax">₱0.00</span>
                    </div>
                    <div class="payment-preview-summary-row total">
                        <span>Total</span>
                        <span id="paymentPreviewTotal">₱0.00</span>
                    </div>
                </div>
            </div>

            <!-- Modal header required by UX: prompts user to pick payment mode first -->
            <div class="modal-title">Mode of Payment</div>

            <!-- 3 selectable payment modes -->
            <div class="payment-options">
                <button type="button" class="payment-option-btn" data-mode="cash">Cash</button>
                <button type="button" class="payment-option-btn" data-mode="card">Card</button>
                <button type="button" class="payment-option-btn" data-mode="ewallet">E-wallet</button>
            </div>

            <!-- Cash-only fields: user enters amount tendered and sees computed change -->
            <div class="payment-fields" id="cashFields" style="display:none;">
                <label for="cashPaymentAmount">Enter payment amount:</label>
                <input type="number" id="cashPaymentAmount" min="0" step="0.01" placeholder="0.00">
                <div id="cashChangeDisplay" class="cash-change-display">Change: ₱0.00</div>
            </div>

            <!-- Card/E-wallet fields: user must provide external reference number -->
            <div class="payment-fields" id="digitalFields" style="display:none;">
                <label for="digitalReferenceNo">Reference No.</label>
                <input type="text" id="digitalReferenceNo" placeholder="Enter reference number">
            </div>

            <!-- Final action button: validates mode-specific fields then processes checkout -->
            <div class="payment-proceed-wrap">
                <button type="button" class="btn btn-primary" id="proceedCheckoutBtn">Proceed to Checkout</button>
                <button type="button" class="btn btn-danger" id="cancelPaymentBtn">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal modal-success" id="successModal">
        <div class="modal-content">
            <div class="modal-icon"><i class="fas fa-check-circle"></i></div>
            <div class="modal-title">Sale Completed</div>
            <div class="receipt-preview" id="receiptPreview">
                <div class="receipt-preview-title">Receipt Preview</div>
                <div id="receiptItemsList"></div>
                <div class="receipt-summary">
                    <div class="receipt-summary-row">
                        <span>Running Total</span>
                        <span id="receiptRunningTotal">₱0.00</span>
                    </div>
                    <div class="receipt-summary-row">
                        <span>Promo Code</span>
                        <span id="receiptPromoCode">N/A</span>
                    </div>
                    <div class="receipt-summary-row">
                        <span>Tax</span>
                        <span id="receiptTax">₱0.00</span>
                    </div>
                    <div class="receipt-summary-row grand">
                        <span>Grand Total</span>
                        <span id="receiptGrandTotal">₱0.00</span>
                    </div>
                </div>
            </div>
            <div class="modal-message">
                Transaction has been successfully processed!<br>
                <strong>Receipt ID: <span id="receiptId"></span></strong>
            </div>
            <button class="btn btn-success" onclick="newTransaction()">New Transaction</button>
        </div>
    </div>

    <!-- Error Modal -->
    <div class="modal modal-error" id="errorModal">
        <div class="modal-content">
            <div class="modal-icon"><i class="fas fa-exclamation-circle"></i></div>
            <div class="modal-title">Error</div>
            <div class="modal-message" id="errorMessage"></div>
            <button class="btn btn-primary" onclick="closeErrorModal()">OK</button>
        </div>
    </div>

    <script>
        // Initialize cart from localStorage
        let cart = JSON.parse(localStorage.getItem('pos_cart')) || [];
        let isProcessingCheckout = false;
        let activeCategoryFilter = 'all';
        // Tracks currently selected payment mode in payment modal.
        let selectedPaymentMode = '';

        const checkoutButton = document.getElementById('checkoutBtn');
        const checkoutButtonDefaultLabel = checkoutButton ? checkoutButton.innerHTML : '';
        const promoCodeInput = document.getElementById('promoCodeInput');
        const emptyProductsState = document.getElementById('emptyProductsState');
        const paymentModal = document.getElementById('paymentModal');
        const cashFields = document.getElementById('cashFields');
        const digitalFields = document.getElementById('digitalFields');
        const cashPaymentAmountInput = document.getElementById('cashPaymentAmount');
        const digitalReferenceNoInput = document.getElementById('digitalReferenceNo');
        const cashChangeDisplay = document.getElementById('cashChangeDisplay');
        const cancelPaymentBtn = document.getElementById('cancelPaymentBtn');
        const proceedCheckoutBtn = document.getElementById('proceedCheckoutBtn');
        const proceedCheckoutBtnDefaultLabel = proceedCheckoutBtn ? proceedCheckoutBtn.innerHTML : '';
        const paymentPreviewItems = document.getElementById('paymentPreviewItems');
        const paymentPreviewSubtotal = document.getElementById('paymentPreviewSubtotal');
        const paymentPreviewPromoCode = document.getElementById('paymentPreviewPromoCode');
        const paymentPreviewTax = document.getElementById('paymentPreviewTax');
        const paymentPreviewTotal = document.getElementById('paymentPreviewTotal');
        const TAX_RATE = 0.12;

        // Promo rules that are allowed by the POS.
        // Each code has either a percent discount or a fixed amount discount.
        // minSubtotal prevents using a promo for very small purchases.
        const PROMO_RULES = {
            WELCOME10: { label: 'Welcome 10%', percent: 0.10, minSubtotal: 0 },
            SAVE50: { label: 'Save ₱50', fixed: 50, minSubtotal: 500 },
            VIP20: { label: 'VIP 20%', percent: 0.20, minSubtotal: 1000 }
        };

        <?php
            $sku_index = [];
            foreach ($available_items as $it) {
                $sku_val = trim((string)($it['sku'] ?? ''));
                if ($sku_val === '') continue;
                $sku_index[strtoupper($sku_val)] = [
                    'id' => (int)$it['id'],
                    'name' => $it['item_name'],
                    'price' => (float)$it['selling_price'],
                    'quantity' => (int)$it['quantity']
                ];
            }
        ?>

        const SKU_INDEX = <?= json_encode($sku_index, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?> || {};

        function addToCartBySkuObj(itemObj, qty) {
            if (!itemObj || !itemObj.id) {
                showError('Invalid item data.');
                return;
            }
            qty = Number(qty) || 1;
            const existing = cart.find(c => c.id === itemObj.id);
            const available = Number(itemObj.quantity) || 0;

            if (existing) {
                if (existing.quantity + qty <= available) {
                    existing.quantity += qty;
                } else {
                    existing.quantity = available;
                    showError('Insufficient stock available!');
                }
            } else {
                const addQty = Math.min(qty, available > 0 ? available : qty);
                if (addQty <= 0) {
                    showError('Item out of stock.');
                    return;
                }
                cart.push({
                    id: itemObj.id,
                    name: itemObj.name,
                    price: Number(itemObj.price) || 0,
                    quantity: addQty,
                    maxStock: available
                });
            }
            saveCart();
            updateCartDisplay();
        }

        function manualAdd() {
            const skuInput = document.getElementById('manualSku');
            const qtyInput = document.getElementById('manualQty');
            if (!skuInput) return;
            const raw = (skuInput.value || '').trim();
            if (!raw) {
                showError('Please enter or scan SKU.');
                return;
            }
            const key = raw.toUpperCase();
            const found = SKU_INDEX[key];
            if (!found) {
                showError('Item with SKU "' + raw + '" not found.');
                return;
            }
            const qty = Math.max(1, parseInt(qtyInput?.value || '1', 10));
            addToCartBySkuObj(found, qty);
            skuInput.value = '';
            qtyInput.value = '1';
            skuInput.focus();
        }

        // Updates header with current day/date and real-time clock.
        function updateHeaderDateTime() {
            const now = new Date();
            const dayDateEl = document.getElementById('currentDayDate');
            const timeEl = document.getElementById('time');
            if (!dayDateEl || !timeEl) {
                return;
            }

            dayDateEl.textContent = now.toLocaleDateString('en-US', {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });

            timeEl.textContent = now.toLocaleTimeString('en-US', {
                hour12: true,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        function formatCurrency(value) {
            return '₱' + (value || 0).toFixed(2);
        }

        function getCartSubtotal() {
            return cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        }

        // Converts any promo input to a safe standardized format.
        // 1) Uppercase so users can type in any letter case.
        // 2) Remove characters outside A-Z, 0-9, and dash.
        function normalizePromoCode(raw) {
            const text = String(raw || '').toUpperCase();
            return text.replace(/[^A-Z0-9-]/g, '').trim();
        }

        // Calculates promo discount based on subtotal and promo code.
        // Returns metadata used by totals, checkout payload, and receipt.
        function getPromoResult(subtotal) {
            // Read and normalize current promo text input.
            const rawCode = promoCodeInput ? promoCodeInput.value : '';
            const code = normalizePromoCode(rawCode);

            // Default result means no valid promo applied.
            const result = {
                code,
                valid: false,
                discount: 0,
                discountType: 'none',
                label: 'No Promo Applied'
            };

            // No code entered means keep default no-discount result.
            if (!code) {
                return result;
            }

            // Find promo rule by normalized code.
            const rule = PROMO_RULES[code];
            if (!rule) {
                return result;
            }

            // Check minimum subtotal requirement before applying promo.
            if ((Number(subtotal) || 0) < (Number(rule.minSubtotal) || 0)) {
                return result;
            }

            // Compute raw discount amount from percent or fixed value.
            let discountValue = 0;
            if (typeof rule.percent === 'number') {
                discountValue = subtotal * rule.percent;
            } else if (typeof rule.fixed === 'number') {
                discountValue = rule.fixed;
            }

            // Never allow discount to exceed subtotal.
            discountValue = Math.min(subtotal, Math.max(0, discountValue));

            // Mark result as valid and fill computed fields.
            result.valid = true;
            result.discount = Number(discountValue.toFixed(2));
            result.discountType = typeof rule.percent === 'number' ? 'promo_percent' : 'promo_fixed';
            result.label = rule.label;
            return result;
        }

        // Computes full totals in one place to avoid mismatched math in UI and checkout.
        function computeTotals() {
            const subtotal = getCartSubtotal();
            const promo = getPromoResult(subtotal);
            const discount = promo.discount;
            const tax = subtotal * TAX_RATE;
            const total = subtotal - discount + tax;

            return {
                subtotal,
                discount,
                tax,
                total,
                promoCode: promo.valid ? promo.code : '',
                discountType: promo.valid ? promo.discountType : 'none',
                promoLabel: promo.label,
                promoValid: promo.valid
            };
        }

        function resetDiscountFields() {
            if (promoCodeInput) {
                promoCodeInput.value = '';
            }
        }

        function initCategoryFilters() {
            document.querySelectorAll('.category-pill').forEach((button) => {
                button.addEventListener('click', () => {
                    setCategoryFilter(button.getAttribute('data-category'));
                });
            });
        }

        function setCategoryFilter(category) {
            activeCategoryFilter = category || 'all';
            document.querySelectorAll('.category-pill').forEach((button) => {
                button.classList.toggle('active', button.getAttribute('data-category') === activeCategoryFilter);
            });
            applyProductFilters();
        }

        function applyProductFilters() {
            const searchTerm = (document.getElementById('searchInput')?.value || '').trim().toLowerCase();
            const sections = document.querySelectorAll('.category-section');
            let anyVisible = false;

            sections.forEach((section) => {
                const cards = Array.from(section.querySelectorAll('.product-card'));
                let sectionVisible = false;
                cards.forEach((card) => {
                    const name = card.getAttribute('data-item-name') || '';
                    const cardCategory = card.getAttribute('data-category') || '';
                    const matchesCategory = activeCategoryFilter === 'all' || cardCategory === activeCategoryFilter;
                    const matchesSearch = !searchTerm || name.includes(searchTerm);
                    const shouldShow = matchesCategory && matchesSearch;
                    card.style.display = shouldShow ? '' : 'none';
                    if (shouldShow) {
                        sectionVisible = true;
                    }
                });
                section.style.display = sectionVisible ? '' : 'none';
                if (sectionVisible) {
                    anyVisible = true;
                }
            });

            if (emptyProductsState) {
                emptyProductsState.style.display = anyVisible ? 'none' : 'flex';
            }
        }

        function filterProducts() {
            applyProductFilters();
        }

        function requestProductRemoval(event, productId, productName) {
            event.preventDefault();
            event.stopPropagation();

            if (!confirm(`Remove ${productName} from available products?`)) {
                return;
            }

            fetch('pos.php?route=system', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ action: 'remove_item', item_id: productId })
            })
            .then(async (response) => {
                const payload = await response.json().catch(() => ({ success: false, message: 'Unexpected response.' }));
                if (!response.ok || !payload.success) {
                    throw new Error(payload.message || 'Unable to remove item.');
                }
                return payload;
            })
            .then(() => {
                const card = document.querySelector(`.product-card[data-item-id="${productId}"]`);
                const section = card?.closest('.category-section');
                if (card) {
                    card.remove();
                }
                if (section && section.querySelectorAll('.product-card').length === 0) {
                    section.remove();
                }
                applyProductFilters();
                alert(productName + ' removed from POS listings.');
            })
            .catch((error) => {
                showError(error.message || 'Unable to remove the item right now.');
            });
        }

        function addToCart(event, productId, productName, price, availableStock) {
            event.preventDefault();
            event.stopPropagation();

            const existingItem = cart.find(item => item.id === productId);
            
            if (existingItem) {
                if (existingItem.quantity < availableStock) {
                    existingItem.quantity++;
                } else {
                    showError('Insufficient stock available!');
                    return;
                }
            } else {
                cart.push({
                    id: productId,
                    name: productName,
                    price: parseFloat(price),
                    quantity: 1,
                    maxStock: availableStock
                });
            }

            saveCart();
            updateCartDisplay();
        }

        function selectProduct(event, productId, productName, price, availableStock) {
            // Prevent event from bubbling if button click
            if (event.target.classList.contains('add-to-cart-btn') || event.target.closest('.remove-product-btn')) {
                return;
            }
            
            event.stopPropagation();
            
            // Remove active class from all product cards
            const allCards = document.querySelectorAll('.product-card');
            allCards.forEach(card => {
                card.classList.remove('active');
            });
            
            // Add active class to clicked card
            event.currentTarget.classList.add('active');
            
            // Log for debugging
            console.log('Product selected:', productId, productName, 'Price:', price, 'Stock:', availableStock);
        }

        function updateQuantity(productId, change) {
            const item = cart.find(item => item.id === productId);
            if (item) {
                item.quantity += change;
                if (item.quantity <= 0) {
                    removeFromCart(productId);
                } else if (item.quantity > item.maxStock) {
                    item.quantity = item.maxStock;
                    showError('Cannot exceed available stock!');
                }
                saveCart();
                updateCartDisplay();
            }
        }

        function updateQuantityByIndex(index, change) {
            if (cart[index]) {
                cart[index].quantity += change;
                if (cart[index].quantity <= 0) {
                    removeFromCartByIndex(index);
                } else if (cart[index].quantity > cart[index].maxStock) {
                    cart[index].quantity = cart[index].maxStock;
                    showError('Cannot exceed available stock!');
                }
                saveCart();
                updateCartDisplay();
            }
        }

        function handleQtyInput(index, rawValue) {
            if (!cart[index]) {
                return;
            }

            const maxStock = Number(cart[index].maxStock) || 1;
            let parsed = parseInt(rawValue, 10);

            if (!Number.isFinite(parsed) || parsed <= 0) {
                parsed = 1;
            }

            if (parsed > maxStock) {
                parsed = maxStock;
                showError('Cannot exceed available stock!');
            }

            cart[index].quantity = parsed;
            saveCart();
            updateCartDisplay();
        }

        function removeFromCart(productId) {
            cart = cart.filter(item => item.id !== productId);
            saveCart();
            updateCartDisplay();
        }

        function removeFromCartByIndex(index) {
            cart.splice(index, 1);
            saveCart();
            updateCartDisplay();
        }

        function updateCartDisplay() {
            const cartItemsDiv = document.getElementById('cartItems');
            const cartSection = document.querySelector('.cart-section');
            
            if (cart.length === 0) {
                cartItemsDiv.innerHTML = `
                    <div class="empty-cart">
                        <div class="empty-cart-icon"><i class="fas fa-shopping-basket"></i></div>
                        <p>Cart is empty</p>
                    </div>
                `;
            } else {
                cartItemsDiv.innerHTML = cart.map((item, index) => `
                    <div class="cart-item">
                        <div class="cart-item-info">
                            <div class="cart-item-name">${item.name}</div>
                            <div class="cart-item-detail">₱${item.price.toFixed(2)} each</div>
                        </div>
                        <div class="cart-item-qty">
                            <button class="qty-btn" onclick="updateQuantityByIndex(${index}, -1)">−</button>
                            <input type="number" class="qty-input" value="${item.quantity}" min="1" max="${item.maxStock}" onchange="handleQtyInput(${index}, this.value)">
                            <button class="qty-btn" onclick="updateQuantityByIndex(${index}, 1)">+</button>
                        </div>
                        <div class="cart-item-total">₱${(item.price * item.quantity).toFixed(2)}</div>
                        <button class="cart-item-remove" onclick="removeFromCartByIndex(${index})">Remove</button>
                    </div>
                `).join('');
            }

            if (cartSection) {
                cartSection.classList.toggle('has-scroll', cart.length > 4);
            }

            updateTotals();
        }

        function updateTotals() {
            // Compute subtotal, promo discount, tax, and total in one consistent flow.
            const totals = computeTotals();

            // Update visible summary values.
            document.getElementById('subtotal').textContent = formatCurrency(totals.subtotal);
            document.getElementById('tax').textContent = formatCurrency(totals.tax);
            document.getElementById('total').textContent = formatCurrency(totals.total);

            calculateChange();
        }
        
        function renderReceiptPreview(details) {
            const receiptList = document.getElementById('receiptItemsList');
            const runningTotalEl = document.getElementById('receiptRunningTotal');
            const receiptPromoCodeEl = document.getElementById('receiptPromoCode');
            const receiptTaxEl = document.getElementById('receiptTax');
            const receiptGrandTotalEl = document.getElementById('receiptGrandTotal');

            if (!receiptList || !runningTotalEl || !receiptPromoCodeEl || !receiptTaxEl || !receiptGrandTotalEl) {
                return;
            }

            const items = details?.items || [];
            const subtotal = Number(details?.subtotal) || 0;
            const promoCode = String(details?.promoCode || '').trim();
            const tax = Number(details?.tax) || 0;
            const total = Number(details?.total) || 0;

            if (!items.length) {
                receiptList.innerHTML = '<div class="receipt-item"><div>No items in this transaction.</div></div>';
                runningTotalEl.textContent = formatCurrency(0);
                receiptPromoCodeEl.textContent = 'N/A';
                receiptTaxEl.textContent = formatCurrency(0);
                receiptGrandTotalEl.textContent = formatCurrency(0);
                return;
            }

            let runningTotal = 0;
            receiptList.innerHTML = items.map(item => {
                const quantity = Number(item.quantity) || 0;
                const price = Number(item.price) || 0;
                const itemSubtotal = price * quantity;
                runningTotal += itemSubtotal;
                const safeName = item.name ? item.name : 'Item';
                return `
                    <div class="receipt-item">
                        <div>
                            <div class="receipt-item-name">${safeName} x${quantity}</div>
                            <div class="receipt-item-price">₱${price.toFixed(2)} each</div>
                        </div>
                        <div class="receipt-item-subtotal">₱${itemSubtotal.toFixed(2)}</div>
                    </div>
                `;
            }).join('');
            runningTotalEl.textContent = formatCurrency(runningTotal);
            receiptPromoCodeEl.textContent = promoCode || 'N/A';
            receiptTaxEl.textContent = formatCurrency(tax);
            receiptGrandTotalEl.textContent = formatCurrency(total);
        }

        function calculateChange() {
            return;
        }

        function clearCart() {
            if (confirm('Are you sure you want to clear the cart?')) {
                cart = [];
                saveCart();
                updateCartDisplay();
                resetDiscountFields();
            }
        }

        function saveCart() {
            localStorage.setItem('pos_cart', JSON.stringify(cart));
        }

        // Clears client-side POS state that should not survive a logout/login cycle.
        // This keeps the next cashier session clean even when using the same browser.
        function clearPosStateOnLogout() {
            // Remove persisted cart so page reload after login starts with an empty cart.
            localStorage.removeItem('pos_cart');

            // Clear in-memory cart immediately (useful if logout is interrupted/cancelled).
            cart = [];

            // Reset promo field to avoid carrying over prior transaction discount context.
            if (promoCodeInput) {
                promoCodeInput.value = '';
            }
        }

        function setCheckoutProcessing(state) {
            isProcessingCheckout = state;
            if (!checkoutButton) {
                // Continue because payment modal buttons may still need state updates.
            } else {
                checkoutButton.disabled = state;
                if (state) {
                    checkoutButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                } else {
                    checkoutButton.innerHTML = checkoutButtonDefaultLabel || '<i class="fas fa-credit-card"></i> Checkout';
                }
            }

            if (proceedCheckoutBtn) {
                proceedCheckoutBtn.disabled = state;
                if (state) {
                    proceedCheckoutBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                } else {
                    proceedCheckoutBtn.innerHTML = proceedCheckoutBtnDefaultLabel || 'Proceed to Checkout';
                }
            }

            if (cancelPaymentBtn) {
                cancelPaymentBtn.disabled = state;
            }

            if (state) {
                return;
            }
        }

        function openPaymentModal() {
            // Render latest cart/totals snapshot on top preview before showing modal.
            renderPaymentModePreview();
            // Reset payment mode selection whenever modal opens.
            selectedPaymentMode = '';
            // Hide both mode-specific sections until user picks an option.
            if (cashFields) {
                cashFields.style.display = 'none';
            }
            if (digitalFields) {
                digitalFields.style.display = 'none';
            }
            // Clear previous input values from prior checkout attempt.
            if (cashPaymentAmountInput) {
                cashPaymentAmountInput.value = '';
            }
            if (digitalReferenceNoInput) {
                digitalReferenceNoInput.value = '';
            }
            // Remove visual active state from all payment option buttons.
            document.querySelectorAll('.payment-option-btn').forEach((btn) => btn.classList.remove('active'));
            // Initialize default change display content.
            updateCashChangeDisplay();
            // Finally show the modal.
            paymentModal?.classList.add('show');
        }

        function renderPaymentModePreview() {
            const totals = computeTotals();

            if (paymentPreviewItems) {
                if (!cart.length) {
                    paymentPreviewItems.innerHTML = '<div class="payment-preview-item"><span>No items in cart</span><span>₱0.00</span></div>';
                } else {
                    paymentPreviewItems.innerHTML = cart.map((item) => {
                        const quantity = Number(item.quantity) || 0;
                        const price = Number(item.price) || 0;
                        const lineTotal = price * quantity;
                        return `<div class="payment-preview-item"><span>${item.name} x${quantity}</span><span>${formatCurrency(lineTotal)}</span></div>`;
                    }).join('');
                }
            }

            if (paymentPreviewSubtotal) {
                paymentPreviewSubtotal.textContent = formatCurrency(totals.subtotal);
            }
            if (paymentPreviewPromoCode) {
                paymentPreviewPromoCode.textContent = totals.promoCode || 'N/A';
            }
            if (paymentPreviewTax) {
                paymentPreviewTax.textContent = formatCurrency(totals.tax);
            }
            if (paymentPreviewTotal) {
                paymentPreviewTotal.textContent = formatCurrency(totals.total);
            }
        }

        function closePaymentModal() {
            // Hide modal after successful checkout or when needed.
            paymentModal?.classList.remove('show');
        }

        function selectPaymentMode(mode) {
            // Persist chosen mode for validation and payload fields.
            selectedPaymentMode = mode;
            // Highlight active mode button for better UX feedback.
            document.querySelectorAll('.payment-option-btn').forEach((btn) => {
                btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
            });

            // Show cash fields only for cash mode.
            if (cashFields) {
                cashFields.style.display = mode === 'cash' ? 'block' : 'none';
            }
            // Show reference field for card/e-wallet modes.
            if (digitalFields) {
                digitalFields.style.display = (mode === 'card' || mode === 'ewallet') ? 'block' : 'none';
            }

            // Move focus into relevant input to speed up cashier workflow.
            if (mode === 'cash') {
                cashPaymentAmountInput?.focus();
                updateCashChangeDisplay();
            }
            if (mode === 'card' || mode === 'ewallet') {
                digitalReferenceNoInput?.focus();
            }
        }

        function updateCashChangeDisplay() {
            if (!cashChangeDisplay) {
                return;
            }
            // Use computed total (includes tax/promo discount) as baseline amount due.
            const total = computeTotals().total;
            // Read user-entered cash amount; treat empty as 0.
            const paymentAmount = Number(cashPaymentAmountInput?.value || 0);
            // Positive => change to return; negative => insufficient cash.
            const change = paymentAmount - total;

            if (change >= 0) {
                // Sufficient payment: display change amount in success style.
                cashChangeDisplay.classList.remove('insufficient');
                cashChangeDisplay.textContent = 'Change: ' + formatCurrency(change);
            } else {
                // Insufficient payment: display shortfall in warning style.
                cashChangeDisplay.classList.add('insufficient');
                cashChangeDisplay.textContent = 'Insufficient: ' + formatCurrency(Math.abs(change));
            }
        }

        function proceedCheckoutWithPayment() {
            // Guard against duplicate submits while request is in progress.
            if (isProcessingCheckout) {
                return;
            }

            // Enforce required mode selection.
            if (!selectedPaymentMode) {
                showError('Please select a mode of payment.');
                return;
            }

            // Recompute totals right before submit to avoid stale values.
            const totals = computeTotals();
            const tax = totals.tax;
            const discount = totals.discount;
            const selectedType = totals.discountType;
            const total = totals.total;
            // Default payment/change for non-cash methods.
            let payment = total;
            let change = 0;
            let referenceNo = '';

            if (selectedPaymentMode === 'cash') {
                // Cash mode: read tendered amount.
                payment = Number(cashPaymentAmountInput?.value || 0);
                if (!Number.isFinite(payment) || payment <= 0) {
                    showError('Please enter a valid cash payment amount.');
                    return;
                }
                // Cash must cover the total.
                if (payment < total) {
                    showError('Cash payment is not enough for the total amount.');
                    return;
                }
                // Compute change for receipt/server payload.
                change = Number((payment - total).toFixed(2));
            } else {
                // Card/E-wallet mode: require a non-empty reference number.
                referenceNo = (digitalReferenceNoInput?.value || '').trim();
                if (!referenceNo) {
                    showError('Please enter a reference number.');
                    return;
                }
            }

            // Lock checkout button while request runs.
            setCheckoutProcessing(true);

            // Build payload with totals + selected payment details.
            const checkoutData = {
                items: cart,
                subtotal: totals.subtotal,
                discount: discount,
                discount_type: selectedType,
                tax: tax,
                total: total,
                promo_code: totals.promoCode,
                payment_method: selectedPaymentMode,
                reference_no: referenceNo,
                payment: payment,
                change: change
            };

            // Submit checkout request to backend endpoint.
            fetch('pos.php?route=checkout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(checkoutData)
            })
            .then(async (response) => {
                // Read text first so we can handle invalid/non-JSON responses safely.
                const raw = await response.text();
                const trimmed = raw ? raw.trim() : '';
                if (!trimmed) {
                    throw new Error('Empty response from server.');
                }

                let data;
                try {
                    // Parse JSON response payload.
                    data = JSON.parse(trimmed);
                } catch (err) {
                    throw new Error('Invalid server response.');
                }

                // Handle backend-reported failures.
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Transaction failed. Please try again.');
                }

                return data;
            })
            .then(data => {
                // Close payment modal first, then show success/receipt modal.
                closePaymentModal();
                renderReceiptPreview({
                    items: cart,
                    subtotal: Number(data.subtotal) || totals.subtotal,
                    promoCode: String(data.promo_code || totals.promoCode || '').trim(),
                    tax: Number(data.tax) || tax,
                    total: Number(data.total) || total
                });
                closeErrorModal();
                document.getElementById('receiptId').textContent = data.receipt_id;
                document.getElementById('successModal').classList.add('show');
                // Re-enable checkout button state after success path.
                setCheckoutProcessing(false);
            })
            .catch(error => {
                // Normalize stock-related backend errors and surface to user.
                const friendlyMessage = handleCheckoutError(error.message);
                showError('Error processing transaction: ' + friendlyMessage);
                // Re-enable checkout button state after failure path.
                setCheckoutProcessing(false);
            });
        }

        function checkout() {
            // Validation
            if (cart.length === 0) {
                showError('Cart is empty! Please add items.');
                return;
            }

            // Instead of direct checkout, open payment mode selection first.
            openPaymentModal();
        }

        function newTransaction() {
            cart = [];
            saveCart();
                document.getElementById('successModal').classList.remove('show');
                updateCartDisplay();
                resetDiscountFields();
        }

        function handleCheckoutError(rawMessage) {
            if (!rawMessage) {
                return 'Unexpected error. Please try again.';
            }

            const stockMatch = rawMessage.match(/Insufficient stock for item\s+([^\.]+)\.\s*Available:\s*(\d+)/i);
            if (!stockMatch) {
                return rawMessage;
            }

            const itemName = stockMatch[1].trim();
            const availableQty = parseInt(stockMatch[2], 10);
            const normalizedName = itemName.toLowerCase();
            const itemIndex = cart.findIndex(item => item.name.toLowerCase() === normalizedName);

            if (itemIndex !== -1) {
                if (availableQty <= 0) {
                    cart.splice(itemIndex, 1);
                } else {
                    cart[itemIndex].maxStock = availableQty;
                    if (cart[itemIndex].quantity > availableQty) {
                        cart[itemIndex].quantity = availableQty;
                    }
                }
                saveCart();
                updateCartDisplay();
            }

            if (availableQty <= 0) {
                return itemName + ' is out of stock. Please remove it from the cart before checking out.';
            }

            return itemName + ' only has ' + availableQty + ' in stock. Please adjust the quantity.';
        }

        function showError(message) {
            document.getElementById('errorMessage').textContent = message;
            document.getElementById('successModal').classList.remove('show');
            document.getElementById('errorModal').classList.add('show');
        }

        function closeErrorModal() {
            document.getElementById('errorModal').classList.remove('show');
        }

        // Initialize on page load
        window.onload = function() {
            initCategoryFilters();
            applyProductFilters();
            updateHeaderDateTime();
            setInterval(updateHeaderDateTime, 1000);
            updateCartDisplay();

            // Wire manual SKU add controls
            const manualBtn = document.getElementById('manualAddBtn');
            const manualSku = document.getElementById('manualSku');
            const manualQty = document.getElementById('manualQty');
            const navMenuContainer = document.getElementById('navMenuContainer');
            const navMenuToggle = document.getElementById('navMenuToggle');
            const navMenuDropdown = document.getElementById('navMenuDropdown');
            const logoutBtn = document.getElementById('logoutBtn');

            if (logoutBtn) {
                logoutBtn.addEventListener('click', function() {
                    // Before submitting logout form, clear all POS client-side session state.
                    clearPosStateOnLogout();
                });
            }

            if (navMenuToggle && navMenuDropdown) {
                navMenuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const willOpen = !navMenuDropdown.classList.contains('show');
                    navMenuDropdown.classList.toggle('show', willOpen);
                    navMenuToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                });

                document.addEventListener('click', function(e) {
                    if (!navMenuContainer || !navMenuContainer.contains(e.target)) {
                        navMenuDropdown.classList.remove('show');
                        navMenuToggle.setAttribute('aria-expanded', 'false');
                    }
                });

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        navMenuDropdown.classList.remove('show');
                        navMenuToggle.setAttribute('aria-expanded', 'false');
                    }
                });
            }

            document.querySelectorAll('.payment-option-btn').forEach((btn) => {
                btn.addEventListener('click', function() {
                    // Route clicked option into payment mode selector.
                    selectPaymentMode(btn.getAttribute('data-mode') || '');
                });
            });

            // Keep cash change display synced while cashier types amount.
            cashPaymentAmountInput?.addEventListener('input', updateCashChangeDisplay);
            // Cancel button closes payment modal without continuing checkout.
            cancelPaymentBtn?.addEventListener('click', closePaymentModal);
            // Proceed button executes full mode-aware checkout flow.
            proceedCheckoutBtn?.addEventListener('click', proceedCheckoutWithPayment);

            // Promo input guard:
            // - Forces uppercase
            // - Removes unsupported characters
            // - Recalculates totals whenever promo text changes
            if (promoCodeInput) {
                promoCodeInput.addEventListener('input', function() {
                    const normalized = normalizePromoCode(promoCodeInput.value);
                    if (promoCodeInput.value !== normalized) {
                        promoCodeInput.value = normalized;
                    }
                    updateTotals();
                });

                promoCodeInput.addEventListener('blur', function() {
                    promoCodeInput.value = normalizePromoCode(promoCodeInput.value);
                    // Reject unknown promo codes so users cannot keep arbitrary values.
                    if (promoCodeInput.value && !PROMO_RULES[promoCodeInput.value]) {
                        showError('Invalid promo code. Valid codes: WELCOME10, SAVE50, VIP20.');
                        promoCodeInput.value = '';
                    }
                    updateTotals();
                });
            }
            if (manualBtn) {
                manualBtn.addEventListener('click', manualAdd);
            }
            if (manualSku) {
                manualSku.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        manualAdd();
                    }
                });
                // autofocus scanner input for quick scanning
                try { manualSku.focus(); } catch (e) { /* ignore */ }
            }
            if (manualQty) {
                manualQty.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        manualAdd();
                    }
                });
            }
            // Keep SKU input focused for barcode scanners unless user is typing in other inputs
            function isExcludedActive(el) {
                if (!el) return false;
                const id = (el.id || '').toString();
                if (['promoCodeInput', 'manualQty', 'searchInput', 'cashPaymentAmount', 'digitalReferenceNo'].includes(id)) return true;
                if (el.classList && el.classList.contains('qty-input')) return true;
                if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') return true;
                if (el.isContentEditable) return true;
                return false;
            }

            document.addEventListener('keydown', function(e) {
                try {
                    const active = document.activeElement;
                    if (!document.getElementById('manualSku')) return;
                    // Do not steal focus while payment modal is open.
                    if (paymentModal && paymentModal.classList.contains('show')) return;
                    // Don't steal focus if user is actively typing in an excluded field
                    if (isExcludedActive(active)) return;
                    // If manualSku already focused, do nothing
                    if (active && active.id === 'manualSku') return;
                    // Focus SKU input so scanner input lands there
                    document.getElementById('manualSku').focus();
                } catch (err) {
                    // ignore
                }
            });
        };
    </script>
</body>
</html>