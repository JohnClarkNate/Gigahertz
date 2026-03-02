    <?php
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
    $revenue_is_up = (float)$revenue_current >= (float)$revenue_previous;
    if ((float)$revenue_previous > 0) {
        $revenue_change = (((float)$revenue_current - (float)$revenue_previous) / (float)$revenue_previous) * 100;
    } elseif ((float)$revenue_current > 0) {
        $revenue_change = 100;
    } else {
        $revenue_change = 0;
    }

    // Current-month profit check (income - expense)
    $stmt = $pdo->prepare("\n        SELECT\n            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS month_income,\n            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS month_expense\n        FROM finance\n        WHERE company_id = ?\n          AND MONTH(date) = MONTH(CURDATE())\n          AND YEAR(date) = YEAR(CURDATE())\n    ");
    $stmt->execute([$company_id]);
    $monthFinance = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $month_income = (float)($monthFinance['month_income'] ?? 0);
    $month_expense = (float)($monthFinance['month_expense'] ?? 0);
    $is_profitable = ($month_income - $month_expense) > 0;
    $revenue_trend_up = $revenue_is_up;
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
    $orders_is_up = (int)$orders_current >= (int)$orders_previous;
    if ((int)$orders_previous > 0) {
        $orders_change = (((int)$orders_current - (int)$orders_previous) / (int)$orders_previous) * 100;
    } elseif ((int)$orders_current > 0) {
        $orders_change = 100;
    } else {
        $orders_change = 0;
    }
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

    // Low-stock check for inventory status color
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM inventory\n        WHERE company_id = ?\n          AND quantity <= reorder_level\n    ");
    $stmt->execute([$company_id]);
    $low_stock_count = (int)$stmt->fetchColumn();

    $revenueColor = $revenue_trend_up ? 'var(--success)' : 'var(--danger)';
    $ordersColor = $orders_change >= 0 ? 'var(--success)' : 'var(--danger)';
    $inventoryColor = $low_stock_count > 0 ? 'var(--danger)' : 'var(--success)';
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

        // Finance series for chart range filter
        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(date, '%Y-%m-%d') AS label,
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
            FROM finance
            WHERE company_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            GROUP BY DATE(date)
            ORDER BY DATE(date) ASC
        ");
        $stmt->execute([$company_id]);
        $finance_daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT
                CONCAT(DATE_FORMAT(MIN(date), '%Y-%m-%d'), ' to ', DATE_FORMAT(MAX(date), '%Y-%m-%d')) AS label,
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
            FROM finance
            WHERE company_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
            GROUP BY YEARWEEK(date, 1)
            ORDER BY MIN(date) ASC
        ");
        $stmt->execute([$company_id]);
        $finance_weekly = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(date, '%Y-%m') AS label,
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
            FROM finance
            WHERE company_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(date, '%Y-%m')
            ORDER BY DATE_FORMAT(date, '%Y-%m') ASC
        ");
        $stmt->execute([$company_id]);
        $finance_monthly_range = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(date, '%Y-%m') AS label,
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
            FROM finance
            WHERE company_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(date, '%Y-%m')
            ORDER BY DATE_FORMAT(date, '%Y-%m') ASC
        ");
        $stmt->execute([$company_id]);
        $finance_six_months = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(date, '%Y') AS label,
                SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense
            FROM finance
            WHERE company_id = ?
            GROUP BY DATE_FORMAT(date, '%Y')
            ORDER BY DATE_FORMAT(date, '%Y') ASC
        ");
        $stmt->execute([$company_id]);
        $finance_yearly = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            'finance_series' => [
                'daily' => $finance_daily ?? [],
                'weekly' => $finance_weekly ?? [],
                'monthly' => $finance_monthly_range ?? [],
                'six_months' => $finance_six_months ?? [],
                'yearly' => $finance_yearly ?? []
            ],
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
            <div class="stat-trend <?= $revenue_trend_up ? 'up' : 'down' ?>">
                <i class="fas fa-arrow-<?= $revenue_trend_up ? 'up' : 'down' ?>"></i> <?= abs(round($revenue_change, 1)) ?>%
            </div>
        </div>
        <div class="stat-value" style="color: <?= $revenueColor ?>;">&#8369;<span id="revenueCount"><?= number_format($revenue_current, 2) ?></span></div>
        <div class="stat-change">vs last month</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-title">Total Orders</div>
            <div class="stat-trend <?= $orders_is_up ? 'up' : 'down' ?>">
                <i class="fas fa-arrow-<?= $orders_is_up ? 'up' : 'down' ?>"></i> <?= abs(round($orders_change, 1)) ?>%
            </div>
        </div>
        <div class="stat-value" style="color: <?= $ordersColor ?>;"><span id="orderCount"><?= $orders_current ?></span></div>
        <div class="stat-change">vs last month</div>
    </div>
    <div class="stat-card">
        <div class="stat-header">
            <div class="stat-title">Inventory Items</div>
        </div>
        <div class="stat-value" style="color: <?= $inventoryColor ?>;"><span id="inventoryTotal"><?= $inventory_total ?></span></div>
        <div class="stat-change">Total items in stock</div>
    </div>
</div>
<!-- Charts Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-top: 24px;">
    <!-- Finance Chart -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                    <i class="fas fa-chart-line"></i> Income vs Expense
                </div>
                <div>
                    <select id="financeRangeFilter" style="padding:0.35rem 0.55rem; border-radius:var(--radius-sm); border:1px solid var(--border-color); background:var(--bg-secondary); color:var(--text-primary);">
                        <option value="daily" selected>Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                        <option value="six_months">6 Months</option>
                        <option value="yearly">Yearly</option>
                    </select>
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

<?php
