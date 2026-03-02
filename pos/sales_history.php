<!DOCTYPE html>
<html lang="en">
<head>
    <title>POS Transactions History</title>
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
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #1e293b;
            --light: #f8fafc;
            --border: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--light);
            color: var(--text-primary);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: var(--shadow);
        }

        .header-logo {
            height: 40px;
            object-fit: contain;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
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
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
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
            text-decoration: none;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
        }

        .btn-secondary {
            background-color: #64748b;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #475569;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
        }

        .stat-card.success {
            border-left-color: var(--success);
        }

        .stat-card.warning {
            border-left-color: var(--warning);
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-card.success .stat-value {
            color: var(--success);
        }

        .stat-card.warning .stat-value {
            color: var(--warning);
        }

        .transactions-section {
            background: white;
            border-radius: 8px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .section-header {
            padding: 20px;
            border-bottom: 2px solid var(--border);
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background-color: var(--light);
            border-bottom: 2px solid var(--border);
        }

        th {
            padding: 15px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
        }

        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background-color 0.2s ease;
        }

        tbody tr:hover {
            background-color: var(--light);
        }

        td {
            padding: 15px;
            font-size: 14px;
        }

        .product-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .quantity-badge {
            background-color: var(--primary);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .price-amount {
            font-weight: 600;
            color: var(--success);
        }

        .date-badge {
            background-color: #dbeafe;
            color: var(--primary);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .empty-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .filters {
            display: flex;
            gap: 10px;
            padding: 20px;
            background: var(--light);
            border-bottom: 1px solid var(--border);
        }

        .filters input, .filters select {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
        }

        .filters input {
            min-width: 220px;
        }

        @media print {
            body {
                background: white;
            }

            .header, .action-buttons, .filters {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <img src="../blue.png" alt="Gigahertz Logo" class="header-logo">
            <div class="header-actions">
                <div class="datetime-chip" aria-live="polite">
                    <div class="datetime-daydate" id="currentDayDate">--</div>
                    <div class="datetime-time" id="currentTime">--:--:--</div>
                </div>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <div class="menu-container" id="navMenuContainer">
                    <button type="button" class="menu-toggle" id="navMenuToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="navMenuDropdown">
                        <span class="menu-toggle-line"></span>
                        <span class="menu-toggle-line"></span>
                        <span class="menu-toggle-line"></span>
                    </button>
                    <div class="menu-dropdown" id="navMenuDropdown">
                        <a href="../pos.php?route=system" class="menu-item">
                            <i class="fas fa-arrow-left"></i> Back to POS
                        </a>
                        <a href="../login.php" class="menu-item menu-item-danger">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <!-- Summary cards built from aggregate query above. -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Transactions</div>
                <div class="stat-value" id="totalTransactions">0</div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Total Sales (30 Days)</div>
                <div class="stat-value" id="totalSales">₱0.00</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">Average per Transaction</div>
                <div class="stat-value" id="averageSales">₱0.00</div>
            </div>
        </div>

        <!-- Transactions Table -->
        <!-- Data table built from $transactions result set. -->
        <div class="transactions-section">
            <div class="section-header">
                <i class="fas fa-list"></i> Recent Transactions (Last 30 Days)
            </div>
            <div class="filters">
                <input type="text" id="searchInput" placeholder="Search product...">
                <input type="date" id="dateInput">
            </div>
            
            <div id="emptyState" class="empty-state" style="display:none;">
                <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                <p>No transactions found</p>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Amount</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Frontend-only seed data (temporary until backend integration is re-enabled).
        const sampleTransactions = [
            { date: '2026-02-27', product: 'Wireless Mouse', quantity: 2, price: 450.00 },
            { date: '2026-02-26', product: 'Mechanical Keyboard', quantity: 1, price: 3200.00 },
            { date: '2026-02-25', product: 'USB-C Cable', quantity: 3, price: 180.00 },
            { date: '2026-02-24', product: 'Laptop Stand', quantity: 1, price: 950.00 }
        ].map((item) => ({ ...item, total: item.quantity * item.price }));

        function formatCurrency(amount) {
            return '₱' + Number(amount || 0).toFixed(2);
        }

        function formatDate(dateString) {
            const d = new Date(dateString + 'T00:00:00');
            return d.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
        }

        // Renders the visible table rows based on active filters.
        function renderTable(rows) {
            const tbody = document.getElementById('transactionsBody');
            const emptyState = document.getElementById('emptyState');
            if (!tbody || !emptyState) {
                return;
            }

            if (!rows.length) {
                tbody.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';
            tbody.innerHTML = rows.map((row) => `
                <tr>
                    <td><span class="date-badge">${formatDate(row.date)}</span></td>
                    <td><span class="product-name">${row.product}</span></td>
                    <td><span class="quantity-badge">${row.quantity} units</span></td>
                    <td>${formatCurrency(row.price)}</td>
                    <td><span class="price-amount">${formatCurrency(row.total)}</span></td>
                </tr>
            `).join('');
        }

        // Updates top summary cards from the currently displayed dataset.
        function renderStats(rows) {
            const totalTransactions = rows.length;
            const totalSales = rows.reduce((sum, row) => sum + row.total, 0);
            const average = totalTransactions > 0 ? totalSales / totalTransactions : 0;

            document.getElementById('totalTransactions').textContent = totalTransactions;
            document.getElementById('totalSales').textContent = formatCurrency(totalSales);
            document.getElementById('averageSales').textContent = formatCurrency(average);
        }

        // Applies product/date filters, then refreshes table and stats.
        function applyFilters() {
            const search = (document.getElementById('searchInput')?.value || '').trim().toLowerCase();
            const dateFilter = (document.getElementById('dateInput')?.value || '').trim();

            const filtered = sampleTransactions.filter((row) => {
                const matchesSearch = !search || row.product.toLowerCase().includes(search);
                const matchesDate = !dateFilter || row.date === dateFilter;
                return matchesSearch && matchesDate;
            });

            renderTable(filtered);
            renderStats(filtered);
        }

        // Updates header with current day/date and real-time clock.
        function updateCurrentDateTime() {
            const now = new Date();
            const dayDateEl = document.getElementById('currentDayDate');
            const timeEl = document.getElementById('currentTime');
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

        // Initial render and event hooks for filter controls.
        window.addEventListener('DOMContentLoaded', () => {
            const navMenuContainer = document.getElementById('navMenuContainer');
            const navMenuToggle = document.getElementById('navMenuToggle');
            const navMenuDropdown = document.getElementById('navMenuDropdown');

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

            document.getElementById('searchInput')?.addEventListener('input', applyFilters);
            document.getElementById('dateInput')?.addEventListener('change', applyFilters);
            updateCurrentDateTime();
            setInterval(updateCurrentDateTime, 1000);
            applyFilters();
        });
    </script>
</body>
</html>
