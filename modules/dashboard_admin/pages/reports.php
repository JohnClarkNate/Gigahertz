    <?php
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
            case 'product_profitability':
                $headers = ['Product', 'Category', 'Quantity Sold', 'Revenue', 'Estimated Cost', 'Estimated Profit'];
                $sql = "
                    SELECT
                        COALESCE(NULLIF(TRIM(s.product_name), ''), s.product) AS product_label,
                        COALESCE(ic.category, 'Uncategorized') AS category,
                        SUM(s.quantity) AS qty_sold,
                        SUM(s.quantity * s.price) AS total_revenue,
                        SUM(s.quantity * COALESCE(ic.avg_cost_price, 0)) AS estimated_cost,
                        (SUM(s.quantity * s.price) - SUM(s.quantity * COALESCE(ic.avg_cost_price, 0))) AS estimated_profit
                    FROM sales s
                    LEFT JOIN (
                        SELECT
                            company_id,
                            item_name,
                            MAX(category) AS category,
                            AVG(COALESCE(cost_price, 0)) AS avg_cost_price
                        FROM inventory
                        GROUP BY company_id, item_name
                    ) ic
                        ON ic.company_id = s.company_id
                        AND ic.item_name = COALESCE(NULLIF(TRIM(s.product_name), ''), s.product)
                    WHERE s.company_id = ?
                ";
                $params = [$company_id];
                if ($start_date && $end_date) {
                    $sql .= " AND s.date_sold BETWEEN ? AND ?";
                    $params[] = $start_date;
                    $params[] = $end_date;
                } elseif ($start_date) {
                    $sql .= " AND s.date_sold >= ?";
                    $params[] = $start_date;
                } elseif ($end_date) {
                    $sql .= " AND s.date_sold <= ?";
                    $params[] = $end_date;
                }
                if ($category_filter && $category_filter !== 'all') {
                    $sql .= " AND ic.category = ?";
                    $params[] = $category_filter;
                }
                $sql .= "
                    GROUP BY product_label, category
                    ORDER BY estimated_profit DESC, product_label ASC
                ";
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
    <!-- Product Profitability Report Section -->
    <div class="report-section">
        <div class="report-header">
            <div class="report-title">
                <i class="fas fa-users"></i> Product Profitability Report
            </div>
        </div>
        <div class="report-body">
            <form method="GET" action="dashboard_admin.php">
                <input type="hidden" name="page" value="reports">
                <input type="hidden" name="report" value="product_profitability">
                <div class="report-filters">
                    <div class="filter-group">
                        <label for="profitability_start_date">Start Date</label>
                        <input type="date" id="profitability_start_date" name="start_date">
                    </div>
                    <div class="filter-group">
                        <label for="profitability_end_date">End Date</label>
                        <input type="date" id="profitability_end_date" name="end_date">
                    </div>
                    <div class="filter-group">
                        <label for="profitability_category">Category (Optional)</label>
                        <select id="profitability_category" name="category">
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
                <small style="display:block; margin-top:0.5rem; color:var(--text-secondary);">
                    Profitability is estimated from sales revenue minus inventory cost price (average cost per product).
                </small>
            </form>
        </div>
    </div>
</div>
<?php
