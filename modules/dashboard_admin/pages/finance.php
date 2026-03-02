    <?php
        // Scope all finance operations to the signed-in company.
    $company_id = $_SESSION['company_id'];

        // Handle create transaction request from the "Add Transaction" modal.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_finance'])) {
            // Read submitted transaction payload.
        $amount = $_POST['amount'];
        $type = $_POST['type'];
        $description = $_POST['description'];
        $date = $_POST['date'];

            // Persist the finance row for this company.
        $stmt = $pdo->prepare("INSERT INTO finance (company_id, amount, type, description, date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$company_id, $amount, $type, $description, $date]);

            // Audit trail: record that a finance transaction was added.
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

    // Handle delete transaction action from table row actions.
    if (isset($_GET['delete_finance'])) {
        $delete_id = (int)$_GET['delete_finance'];

        // Fetch existing values first so the delete can be logged with context.
        $fetchStmt = $pdo->prepare("SELECT amount, type, date, description FROM finance WHERE id = ? AND company_id = ?");
        $fetchStmt->execute([$delete_id, $company_id]);
        $financeRecord = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        // Remove the selected record (company-scoped safety check in WHERE clause).
        $stmt = $pdo->prepare("DELETE FROM finance WHERE id = ? AND company_id = ?");
        $stmt->execute([$delete_id, $company_id]);

        // Audit trail: record delete details when a matching record existed.
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

    // Load all company finance records newest first.
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

    // Split expense rows into:
    // 1) Payroll salary costs, 2) Other operating expenses, 3) Deduction liabilities (hidden from expense tab).
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

    // Re-index arrays for predictable iteration in view templates.
    $payrollExpenseRecords = array_values($payrollExpenseRecords);
    $expense_records = array_values($otherExpenseRecords);

    // Pull top-level totals from database (income total and raw expense total).
    $stmt = $pdo->prepare("SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN type ='expense' THEN amount ELSE 0 END) AS total_expense
        FROM finance WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $totals = $stmt->fetch();

    // Utility to sum amount fields from any records array.
    $sumAmounts = static function (array $rows): float {
        return array_sum(array_map(static fn($row) => (float)($row['amount'] ?? 0), $rows));
    };

    // Visible expense excludes deduction liabilities by design.
    $payrollExpenseTotal = $sumAmounts($payrollExpenseRecords);
    $otherExpenseTotal = $sumAmounts($expense_records);
    $visibleExpenseTotal = $payrollExpenseTotal + $otherExpenseTotal;

    // Summary card values.
    $incomeTotal = (float)($totals['total_income'] ?? 0);
    $net = $incomeTotal - $visibleExpenseTotal;
    $expenseRecordCount = count($payrollExpenseRecords) + count($expense_records);
    $excludedDeductionExpenseTotal = $sumAmounts($excludedDeductionExpenseRecords);

    // Build growth indicators by comparing current month totals vs previous month totals.
    $currentMonthStart = date('Y-m-01');
    $nextMonthStart = date('Y-m-01', strtotime($currentMonthStart . ' +1 month'));
    $previousMonthStart = date('Y-m-01', strtotime($currentMonthStart . ' -1 month'));

    $trendStmt = $pdo->prepare("SELECT
        SUM(CASE WHEN type = 'income' AND date >= ? AND date < ? THEN amount ELSE 0 END) AS current_income,
        SUM(CASE WHEN type = 'expense' AND date >= ? AND date < ? THEN amount ELSE 0 END) AS current_expense,
        SUM(CASE WHEN type = 'income' AND date >= ? AND date < ? THEN amount ELSE 0 END) AS previous_income,
        SUM(CASE WHEN type = 'expense' AND date >= ? AND date < ? THEN amount ELSE 0 END) AS previous_expense
        FROM finance
        WHERE company_id = ?");
    $trendStmt->execute([
        $currentMonthStart, $nextMonthStart,
        $currentMonthStart, $nextMonthStart,
        $previousMonthStart, $currentMonthStart,
        $previousMonthStart, $currentMonthStart,
        $company_id
    ]);
    $trendTotals = $trendStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $currentIncome = (float)($trendTotals['current_income'] ?? 0);
    $currentExpense = (float)($trendTotals['current_expense'] ?? 0);
    $previousIncome = (float)($trendTotals['previous_income'] ?? 0);
    $previousExpense = (float)($trendTotals['previous_expense'] ?? 0);
    $currentNet = $currentIncome - $currentExpense;
    $previousNet = $previousIncome - $previousExpense;

    // Build trend metadata using the same rules/style pattern as default.php.
    $buildTrend = static function (float $currentValue, float $previousValue): array {
        $isUp = $currentValue >= $previousValue;
        if ($previousValue > 0) {
            $change = (($currentValue - $previousValue) / $previousValue) * 100;
        } elseif ($currentValue > 0) {
            $change = 100;
        } else {
            $change = 0;
        }

        return [
            'is_up' => $isUp,
            'change' => $change
        ];
    };

    $incomeTrend = $buildTrend($currentIncome, $previousIncome);
    $expenseTrend = $buildTrend($currentExpense, $previousExpense);
    $netTrend = $buildTrend($currentNet, $previousNet);
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
                        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                            <span class="stat-item-label">Total Sales</span>
                            <div class="stat-trend <?= $incomeTrend['is_up'] ? 'up' : 'down' ?>" style="margin:0;">
                                <i class="fas fa-arrow-<?= $incomeTrend['is_up'] ? 'up' : 'down' ?>"></i> <?= abs(round($incomeTrend['change'], 1)) ?>%
                            </div>
                        </div>
                        <span class="stat-item-value success">₱<?= number_format($incomeTotal, 2) ?></span>
                        <div class="stat-change">vs last month</div>
                    </div>

                    <div class="stat-item">
                        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                            <span class="stat-item-label">Total Expense</span>
                            <div class="stat-trend <?= $expenseTrend['is_up'] ? 'up' : 'down' ?>" style="margin:0;">
                                <i class="fas fa-arrow-<?= $expenseTrend['is_up'] ? 'up' : 'down' ?>"></i> <?= abs(round($expenseTrend['change'], 1)) ?>%
                            </div>
                        </div>
                        <span class="stat-item-value danger">₱<?= number_format($visibleExpenseTotal, 2) ?></span>
                        <div class="stat-change">vs last month</div>
                    </div>

                    <div class="stat-item">
                        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                            <span class="stat-item-label">Net Balance</span>
                            <div class="stat-trend <?= $netTrend['is_up'] ? 'up' : 'down' ?>" style="margin:0;">
                                <i class="fas fa-arrow-<?= $netTrend['is_up'] ? 'up' : 'down' ?>"></i> <?= abs(round($netTrend['change'], 1)) ?>%
                            </div>
                        </div>
                        <span class="stat-item-value">₱<?= number_format($net, 2) ?></span>
                        <div class="stat-change">vs last month</div>
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
    // Only finance tab links use in-page hash targets (#incomeTab / #expenseTab).
    const tabButtons = Array.from(document.querySelectorAll('a[href^="#"]')); // Select links that start with #
    const tabPanels = Array.from(document.querySelectorAll('.finance-tab-panel'));
    if (!tabButtons.length || !tabPanels.length) { return; }

    // Centralized tab activation so click events and initial hash use the same logic.
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

    // On first load, sync active tab with URL hash (defaults to income).
    let hashTarget = 'incomeTab';
    if (window.location.hash === '#expenseTab') {
        hashTarget = 'expenseTab';
    }
    activateTab(hashTarget);
})();

// Open add transaction modal and mark it visible for accessibility tools.
function openFinanceAddModal() {
    const modal = document.getElementById('financeAddModal');
    if (!modal) { return; }
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
}

// Close add transaction modal and restore hidden accessibility state.
function closeFinanceAddModal() {
    const modal = document.getElementById('financeAddModal');
    if (!modal) { return; }
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

// Allow closing modal by clicking the overlay (outside the modal box).
document.getElementById('financeAddModal')?.addEventListener('click', function(event) {
    if (event.target === this) {
        closeFinanceAddModal();
    }
});
</script>
<?php
