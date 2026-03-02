<?php
session_start();
ob_start();
require 'db.php';

require_once __DIR__ . '/modules/dashboard_admin/helpers.php';
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <script src="admin_js.js" defer></script>
    <script>
        (() => {
            const STORAGE_KEY = 'admin_action_feedback';
            const DEFAULT_DURATION = 4000;

            const show = (message, type = 'success', options = {}) => {
                const content = String(message ?? '').trim();
                if (!content) {
                    return;
                }

                const existing = document.getElementById('admin-action-feedback-toast');
                if (existing) {
                    existing.remove();
                }

                const toast = document.createElement('div');
                toast.id = 'admin-action-feedback-toast';
                toast.style.position = 'fixed';
                toast.style.right = '16px';
                toast.style.bottom = '16px';
                toast.style.zIndex = '9999';
                toast.style.padding = '10px 14px';
                toast.style.borderRadius = '8px';
                toast.style.color = '#fff';
                toast.style.fontWeight = '600';
                toast.style.maxWidth = '340px';
                toast.style.boxShadow = '0 10px 30px rgba(0,0,0,0.25)';
                toast.style.background = type === 'error' ? '#dc2626' : '#16a34a';
                toast.textContent = content;
                document.body.appendChild(toast);

                const duration = Number(options.duration) > 0 ? Number(options.duration) : DEFAULT_DURATION;
                window.setTimeout(() => {
                    toast.remove();
                }, duration);
            };

            const hide = () => {
                const existing = document.getElementById('admin-action-feedback-toast');
                if (existing) {
                    existing.remove();
                }
            };

            const queue = (message, type = 'success', options = {}) => {
                if (!message) {
                    return;
                }

                const payload = {
                    message,
                    type,
                    duration: Number(options.duration) > 0 ? Number(options.duration) : DEFAULT_DURATION,
                    timestamp: Date.now()
                };

                if (options.defer === false) {
                    show(payload.message, payload.type, { duration: payload.duration });
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
            window.__actionFeedback = api;
            window.queueActionMessage = queue;
            window.flashActionMessage = show;

            document.addEventListener('DOMContentLoaded', () => {
                const pending = consume();
                if (!pending || !pending.message) {
                    return;
                }
                show(pending.message, pending.type || 'success', { duration: pending.duration || DEFAULT_DURATION });
            });
        })();
    </script>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo" style="display: flex; justify-content: center; align-items: center;">
            <span><img src="white.png" alt="Logo" style="max-width: 180px; height: auto;"></span>
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
        include __DIR__ . '/modules/dashboard_admin/pages/reports.php';
        break;
    case 'manage_account':
        include __DIR__ . '/modules/dashboard_admin/pages/manage_account.php';
        break;
    case 'activity_logs':
        include __DIR__ . '/modules/dashboard_admin/pages/activity_logs.php';
        break;
    case 'inventory':
        include __DIR__ . '/modules/dashboard_admin/pages/inventory.php';
        break;
    case 'finance':
        include __DIR__ . '/modules/dashboard_admin/pages/finance.php';
        break;
    case 'sales':
        include __DIR__ . '/modules/dashboard_admin/pages/sales.php';
        break;
    default:
        include __DIR__ . '/modules/dashboard_admin/pages/default.php';
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
    const pesoSign = '\u20B1';
    let start = 0;
    const duration = 1000;
    const step = (timestamp) => {
        if (!start) start = timestamp;
        const progress = Math.min((timestamp - start) / duration, 1);
        const val = Math.floor(progress * value);
        if (el.textContent.includes(pesoSign) || el.textContent.includes('â‚±')) {
            el.textContent = pesoSign + val.toLocaleString();
        } else {
            el.textContent = val.toLocaleString();
        }
        if (progress < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
}
document.addEventListener("DOMContentLoaded", () => {
    // Animate dashboard stats on load
    animate("userCount", <?= (int)($user_count ?? 0) ?>);
    animate("totalIncome", <?= (float)($incomeTotal ?? 0) ?>);
    animate("totalExpense", <?= (float)($visibleExpenseTotal ?? 0) ?>);
    animate("netBalance", <?= (float)($net ?? 0) ?>);
    animate("inventoryCount", <?= (int)($inventory_count ?? 0) ?>);
    animate("salesCount", <?= (int)($sales_count ?? 0) ?>);
    animate("totalRevenue", <?= (float)($revenue ?? 0) ?>);
    animate("hrCount", <?= (int)($hr_count ?? 0) ?>);
    animate("revenueCount", <?= (float)($revenue_current ?? 0) ?>);
    animate("orderCount", <?= (int)($orders_current ?? 0) ?>);
    animate("customerCount", <?= (int)($customers_current ?? 0) ?>);
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
