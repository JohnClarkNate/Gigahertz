// ...existing code...
  // Lightweight DOM helpers available to the whole bundle
  const root = typeof window !== 'undefined' ? window : globalThis;

  const ensureHelper = (key, impl) => {
    if (!root || typeof root[key] === 'function') {
      return;
    }
    root[key] = impl;
  };

  ensureHelper('qs', (sel, ctx = document) => {
    const context = ctx || document;
    return context ? context.querySelector(sel) : null;
  });

  ensureHelper('qsa', (sel, ctx = document) => {
    const context = ctx || document;
    return context ? Array.from(context.querySelectorAll(sel)) : [];
  });

  ensureHelper('debounce', (fn, wait = 0) => {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), wait);
    };
  });

  const qs = root && typeof root.qs === 'function'
    ? root.qs
    : (sel, ctx = document) => (ctx || document).querySelector(sel);

  const qsa = root && typeof root.qsa === 'function'
    ? root.qsa
    : (sel, ctx = document) => Array.from((ctx || document).querySelectorAll(sel));

  const debounce = root && typeof root.debounce === 'function'
    ? root.debounce
    : (fn, wait = 0) => {
        let t;
        return function (...args) {
          clearTimeout(t);
          t = setTimeout(() => fn.apply(this, args), wait);
        };
      };

  // Theme toggle
  function setThemeIcon(next){
    const icon = qs('#themeToggle i');
    const btn = qs('#themeToggle');
    if(icon) icon.className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    if(btn){
      btn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
      btn.setAttribute('aria-label', next === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
    }
  }

  function toggleTheme(){
    const html = document.documentElement;
    const current = html.getAttribute('data-theme') || 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    const icon = qs('#themeToggle i');
    if(icon){
      icon.style.transform = 'rotate(360deg)';
      setTimeout(()=>{
        setThemeIcon(next);
        icon.style.transform='';
      }, 150);
    } else {
      setThemeIcon(next);
    }
  }
  
// ...existing code...
  document.addEventListener('DOMContentLoaded', function(){
    // Theme init icon (ensure aria + icon reflect stored or document value)
    const savedTheme = localStorage.getItem('theme') || document.documentElement.getAttribute('data-theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    const themeToggle = qs('#themeToggle');
    if(themeToggle){
      setThemeIcon(savedTheme);
      themeToggle.addEventListener('click', toggleTheme);
    }
    
// ...existing code...
  });

(function(){
  function toISODateStrict(s){
    if(!s) return '';
    s = String(s).trim();
    const dateOnly = s.split('T')[0].split(' ')[0];
    let m = dateOnly.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
    if (m) { const yyyy=m[1], mm=m[2].padStart(2,'0'), dd=m[3].padStart(2,'0'); const d=new Date(`${yyyy}-${mm}-${dd}`); if(!isNaN(d)) return d.toISOString().slice(0,10); }
    m = dateOnly.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    if (m) {
      let d = new Date(`${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`);
      if(!isNaN(d)) return d.toISOString().slice(0,10);
      d = new Date(`${m[3]}-${m[1].padStart(2,'0')}-${m[2].padStart(2,'0')}`);
      if(!isNaN(d)) return d.toISOString().slice(0,10);
    }
    const dFallback = new Date(s);
    return isNaN(dFallback) ? '' : dFallback.toISOString().slice(0,10);
  }
  function parseNumberFromString(s){
    if(!s) return null;
    const cleaned = String(s).replace(/[^0-9\.\-]/g, '');
    if(cleaned === '' || cleaned === '.' || cleaned === '-') return null;
    const n = Number(cleaned);
    return isNaN(n) ? null : n;
  }

  // Theme toggle handled globally above to avoid double bindings

  // Modal helpers
  function openModal(id){ const m=qs('#'+id); if(m){ m.style.display='flex'; m.setAttribute('aria-hidden','false'); } }
  function closeModal(id){ const m=qs('#'+id); if(m){ m.style.display='none'; m.setAttribute('aria-hidden','true'); } }

  // Edit user modal
  window.openEditUserModal = function(user){
    try{
      if(typeof user === 'string') user = JSON.parse(user);
      qs('#edit_user_id').value = user.id || '';
      qs('#edit_user_employee_id').value = user.employee_id || '';
      qs('#edit_user_username').value = user.username || '';
      qs('#edit_user_password').value = '';
      qs('#edit_user_role').value = user.role || '';
      openModal('editUserModal');
    }catch(e){ console.error(e); }
  };
  window.closeEditUserModal = function(){ closeModal('editUserModal'); };

  // Edit employee modal
  window.openEditEmployeeModal = function(employee){
    try{
      if(typeof employee === 'string') employee = JSON.parse(employee);
      qs('#edit_employee_db_id').value = employee.id || '';
      qs('#edit_employee_id').value = employee.employee_id || '';
      qs('#edit_employee_name').value = employee.name || '';
      qs('#edit_employee_date_hired').value = employee.date_hired || '';
      openModal('editEmployeeModal');
    }catch(e){ console.error(e); }
  };
  window.closeEditEmployeeModal = function(){ closeModal('editEmployeeModal'); };

  // Import/Add Item modals
  window.openImportModal = function(){ openModal('importModal'); };
  window.closeImportModal = function(){ closeModal('importModal'); };
  window.openAddItemModal = function(){
    const m = qs('#addItemModal'); if(!m) return;
    ['modal_item_name','modal_quantity','modal_category','modal_date_added'].forEach(id=>{
      const el = qs('#'+id); if(el) el.value = '';
    });
    qs('#modal_date_added') && (qs('#modal_date_added').value = (new Date()).toISOString().slice(0,10));
    openModal('addItemModal');
  };
  window.closeAddItemModal = function(){ closeModal('addItemModal'); };

  // Add Head User modal handlers
  window.openAddUserModal = function(){
    const m = qs('#addUserModal'); if(!m) return;
    ['add_user_employee_id','add_user_username','add_user_password','add_user_role'].forEach(id=>{
      const el = qs('#'+id); if(el) el.value = '';
    });
    openModal('addUserModal');
  };
  window.closeAddUserModal = function(){ closeModal('addUserModal'); };

  // Project management modals
  window.openAssignModal = function(taskId){ qs('#assign_task_id').value = taskId; openModal('pmAssignModal'); };
  window.closeAssignModal = function(){ closeModal('pmAssignModal'); };
  window.openLogTimeModal = function(taskId){ qs('#log_task_id').value = taskId; openModal('pmLogModal'); };
  window.closeLogModal = function(){ closeModal('pmLogModal'); };

  // Delete modal
  let deleteAction = null;
  const setDeleteAction = (action) => {
    deleteAction = typeof action === 'undefined' ? null : action;
  };

  window.openDeleteModal = function(action){
    setDeleteAction(action || null);
    openModal('deleteModal');
  };

  window.closeDeleteModal = function(clearPending){
    setDeleteAction(null);
    closeModal('deleteModal');
    if (!clearPending) {
      return;
    }
    try {
      const feedback = window.__actionFeedback;
      if (feedback?.key) {
        sessionStorage.removeItem(feedback.key);
      }
    } catch (error) {
      console.warn('Failed to clear pending delete feedback:', error);
    }
  };

  const handleDeleteConfirmation = (action) => {
    if (typeof action === 'function') {
      window.closeDeleteModal();
      action();
      return;
    }
    if (typeof action === 'string' && action.trim() !== '') {
      window.__actionFeedback?.queue('Record deleted successfully.', 'success', {
        defer: true,
        title: 'Record Removed'
      });
      window.location.href = action;
    } else {
      window.closeDeleteModal();
    }
  };

  document.addEventListener('click', function(e){
    const el = e.target;
    if (el && el.id === 'confirmDeleteBtn') {
      e.preventDefault();
      const action = deleteAction;
      setDeleteAction(null);
      if (!action) {
        window.closeDeleteModal();
        return;
      }
      handleDeleteConfirmation(action);
    }
  });

  document.addEventListener('click', function(e){
    const modal = document.getElementById('deleteModal');
    if (modal && e.target === modal) {
      window.closeDeleteModal(true);
    }
  });

  // openMarkDefective (creates modal if missing)
  window.openMarkDefective = window.openMarkDefective || function(item){
    try{
      if(typeof item === 'string') { try{ item = JSON.parse(item); } catch(e){} }
      item = item || {};
      let modal = qs('#markDefectiveModal');
      if(!modal){
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
          </div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(e){ if(e.target===modal) modal.style.display='none'; });
        modal.querySelector('#defCancelBtn')?.addEventListener('click', function(){ modal.style.display='none'; });
      }

      const fid = qs('#def_inventory_id') || qs('input[name="inventory_id"]');
      const nameField = qs('#def_item_name');
      const curQtyField = qs('#def_current_quantity');
      const defQtyField = qs('#defective_quantity');
      const reasonField = qs('#def_reason');

      const id = item.id ?? item.ID ?? '';
      const name = item.item_name ?? item.itemName ?? item.name ?? '';
      const qty = Number(item.quantity ?? item.qty ?? 0) || 0;

      if(fid) fid.value = id;
      if(nameField) nameField.value = name;
      if(curQtyField) curQtyField.value = qty;
      if(defQtyField){
        defQtyField.max = Math.max(1, qty);
        defQtyField.value = Math.min(Math.max(1, parseInt(defQtyField.value || 1,10)), defQtyField.max || 1);
      }
      if(reasonField) reasonField.value = '';

      modal.style.display = 'flex';
      setTimeout(()=>{ (reasonField || defQtyField) && (reasonField || defQtyField).focus(); }, 120);
    }catch(err){
      console.error('openMarkDefective error:', err);
    }
  };

  // Table search & advanced filter (scoped)
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

  function filterTables(input){
    const raw = (input.value || '').trim();
    if(raw === ''){
      const scope = input.closest('.main-content, .content') || document;
      Array.from(scope.querySelectorAll('.data-table tbody tr')).forEach(r => r.style.display = '');
      Array.from(document.querySelectorAll('.no-results-row')).forEach(n => n.style.display = 'none');
      return;
    }
    const tokens = raw.split(/\s+/).filter(Boolean);
    const numericTokens = tokens.filter(t => /^\d+$/.test(t)).map(Number);
    const dateTokens = tokens.map(t => toISODateStrict(t)).filter(Boolean);
    const textTokens = tokens.filter(t => !/^\d+$/.test(t) && !toISODateStrict(t));

    const scope = input.closest('.main-content, .content') || document;
    let tables = Array.from(scope.querySelectorAll('.data-table'));
    if(tables.length === 0) tables = Array.from(document.querySelectorAll('.data-table'));

    tables.forEach(table => {
      const tbody = table.tBodies[0]; if(!tbody) return;
      const rows = Array.from(tbody.rows).filter(r => !r.classList.contains('no-results-row') && !r.classList.contains('template'));
      let qtyIdx=-1, dateIdx=-1, itemIdx=-1, catIdx=-1;
      if(table.tHead && table.tHead.rows[0]){
        Array.from(table.tHead.rows[0].cells).forEach((th,i)=>{
          const h = th.textContent.trim().toLowerCase();
          if(qtyIdx===-1 && h.includes('quantity')) qtyIdx=i;
          if(dateIdx===-1 && (h.includes('date added') || h.includes('date sold') || h==='date')) dateIdx=i;
          if(itemIdx===-1 && (h.includes('item')||h.includes('product')||h.includes('name'))) itemIdx=i;
          if(catIdx===-1 && h.includes('category')) catIdx=i;
        });
      }
      let visible=0;
      rows.forEach(row=>{
        let textOk=true;
        if(textTokens.length){
          textOk = textTokens.every(tok=>{
            const tokL = tok.toLowerCase();
            let found=false;
            if(itemIdx>=0){ const c = row.cells[itemIdx] ? row.cells[itemIdx].textContent.toLowerCase() : ''; if(c.indexOf(tokL)!==-1) found=true; }
            if(!found && catIdx>=0){ const c = row.cells[catIdx] ? row.cells[catIdx].textContent.toLowerCase() : ''; if(c.indexOf(tokL)!==-1) found=true; }
            if(!found){ const full = row.textContent.toLowerCase(); if(full.indexOf(tokL)!==-1) found=true; }
            return found;
          });
        }
        let numericOk=true;
        if(numericTokens.length){
          if(qtyIdx>=0){
            const cell=row.cells[qtyIdx];
            const cellNum=parseNumberFromString(cell?cell.textContent:'');
            if(cellNum===null){ numericOk = numericTokens.some(nt => (cell && cell.textContent || '').indexOf(String(nt))!==-1); }
            else numericOk = numericTokens.some(nt => cellNum === nt);
          } else {
            const full = row.textContent.toLowerCase();
            numericOk = numericTokens.some(nt => full.indexOf(String(nt)) !== -1);
          }
        }
        let dateOk=true;
        if(dateTokens.length){
          if(dateIdx>=0){
            const cell=row.cells[dateIdx];
            const cellISO = toISODateStrict(cell?cell.textContent:'');
            dateOk = dateTokens.some(dt => dt === cellISO);
          } else dateOk = false;
        }
        const ok = textOk && numericOk && dateOk;
        row.style.display = ok ? '' : 'none';
        if(ok) visible++;
      });
      const nr = makeNoResultsRow(table);
      if(nr) nr.style.display = visible === 0 ? '' : 'none';
    });
  }

  // Animations for numeric counters
  function animate(id, value){
    const el = qs('#'+id); if(!el) return;
    const duration = 1000; let start=null;
    const format = (v)=> (String(el.textContent || '').includes('₱') ? ('₱'+Number(v).toLocaleString()) : Number(v).toLocaleString());
    function step(ts){
      if(!start) start=ts;
      const progress = Math.min((ts-start)/duration,1);
      const val = Math.floor(progress * value);
      el.textContent = format(val);
      if(progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  // Maintain a registry of Chart.js instances so we can safely re-render
  const chartRegistry = window.__dashboardCharts = window.__dashboardCharts || {};
  const getCanvasKey = (canvas) => {
    if (!canvas) return null;
    if (canvas.id) return canvas.id;
    const existing = canvas.getAttribute('data-chart-key');
    if (existing) return existing;
    const key = `chart-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    canvas.setAttribute('data-chart-key', key);
    return key;
  };
  function mountChart(canvas, config){
    if(!canvas || typeof Chart === 'undefined') return null;
    const key = getCanvasKey(canvas);
    if(key && chartRegistry[key]){
      try{ chartRegistry[key].destroy(); }catch(err){ console.warn('Chart destroy failed', err); }
    }
    const instance = new Chart(canvas, config);
    if(key) chartRegistry[key] = instance;
    return instance;
  }

  // Charts initialization: uses window.DASHBOARD_DATA provided by server
  function initCharts(){
    if(typeof Chart === 'undefined') return;
    const D = window.DASHBOARD_DATA || {};
    // finance chart
    try{
      const ctxF = qs('#financeChart');
      const filterEl = qs('#financeRangeFilter');
      const financeSeries = D.finance_series || {};
      const validRanges = ['daily', 'weekly', 'monthly', 'six_months', 'yearly'];
      const getSeries = (range) => {
        if (financeSeries && Array.isArray(financeSeries[range])) {
          return financeSeries[range];
        }
        if (range === 'six_months' && Array.isArray(D.finance_monthly)) {
          return D.finance_monthly;
        }
        return [];
      };
      const formatLabel = (range, raw) => {
        if (!raw && raw !== 0) return '';
        const value = String(raw);
        if (range === 'weekly') {
          return value;
        }
        if (range === 'yearly') {
          return value;
        }
        if (range === 'daily') {
          const d = new Date(value);
          return isNaN(d) ? value : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }
        const monthToken = value.includes('-') ? `${value}-01` : value;
        const date = new Date(monthToken);
        return isNaN(date) ? value : date.toLocaleDateString('en-US',{month:'short',year:'numeric'});
      };
      const renderFinanceChart = (range) => {
        if(!ctxF) return;
        const financeData = getSeries(range);
        const financeLabels = financeData.map(item => formatLabel(range, item.label ?? item.month ?? ''));
        const incomeData = financeData.map(item => parseFloat(item.income)||0);
        const expenseData = financeData.map(item => parseFloat(item.expense)||0);
        mountChart(ctxF, { type:'line', data:{ labels:financeLabels, datasets:[{ label:'Income', data:incomeData, borderColor:getComputedStyle(document.documentElement).getPropertyValue('--success').trim(), backgroundColor:getComputedStyle(document.documentElement).getPropertyValue('--success').trim()+'20', tension:0.4, fill:true },{ label:'Expense', data:expenseData, borderColor:getComputedStyle(document.documentElement).getPropertyValue('--danger').trim(), backgroundColor:getComputedStyle(document.documentElement).getPropertyValue('--danger').trim()+'20', tension:0.4, fill:true }] }, options:{ responsive:true, maintainAspectRatio:true, plugins:{ legend:{ display:true, position:'top' } }, scales:{ y:{ beginAtZero:true, ticks:{ callback:function(v){ return '₱'+v.toLocaleString(); } } } } } });
      };

      let selectedRange = (filterEl && validRanges.includes(filterEl.value)) ? filterEl.value : 'daily';
      renderFinanceChart(selectedRange);
      if(filterEl && !filterEl.dataset.bound){
        filterEl.dataset.bound = '1';
        filterEl.addEventListener('change', () => {
          const nextRange = validRanges.includes(filterEl.value) ? filterEl.value : 'daily';
          renderFinanceChart(nextRange);
        });
      }
    }catch(e){ console.error(e); }
    // sales chart
    try{
      const salesData = D.top_products || [];
      const productLabels = salesData.map(i=> i.product || 'Unknown');
      const revenueData = salesData.map(i=> parseFloat(i.total_revenue)||0);
      const ctxS = qs('#salesChart');
      if(ctxS) mountChart(ctxS, { type:'bar', data:{ labels:productLabels, datasets:[{ label:'Revenue', data:revenueData, backgroundColor:[ '#3b82f6cc','#34d399cc','#7dd3fcc','#fbbf24cc','#ef4444cc' ], borderColor:[ '#3b82f6','#34d399','#7dd3fc','#f59e0b','#ef4444' ], borderWidth:2 }] }, options:{ responsive:true, maintainAspectRatio:true, plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true, ticks:{ callback:function(v){ return '₱'+v.toLocaleString(); } } } } } });
    }catch(e){ console.error(e); }
    // inventory chart
    try{
      const invData = D.inventory_by_category || [];
      const categoryLabels = invData.map(i=> i.category || 'Uncategorized');
      const quantityData = invData.map(i=> parseInt(i.total_quantity)||0);
      const ctxI = qs('#inventoryChart');
      if(ctxI) mountChart(ctxI, { type:'doughnut', data:{ labels:categoryLabels, datasets:[{ data:quantityData, backgroundColor:['#3b82f6cc','#34d399cc','#7dd3fcc','#fbbf24cc','#ef4444cc','#9333eacc','#ec4899cc'], borderColor:'#ffffff', borderWidth:2 }] }, options:{ responsive:true, maintainAspectRatio:true, plugins:{ legend:{ display:true, position:'right' } } } });
    }catch(e){ console.error(e); }
    // HR chart
    try{
      const hrData = D.hr_monthly || [];
      const hrLabels = hrData.map(item => { const d=new Date((item.month||'')+'-01'); return isNaN(d) ? (item.month||'') : d.toLocaleDateString('en-US',{month:'short',year:'numeric'}); });
      const hiringData = hrData.map(item => parseInt(item.count)||0);
      const ctxH = qs('#hrChart');
      if(ctxH) mountChart(ctxH, { type:'line', data:{ labels:hrLabels, datasets:[{ label:'New Hires', data:hiringData, borderColor:getComputedStyle(document.documentElement).getPropertyValue('--primary').trim(), backgroundColor:getComputedStyle(document.documentElement).getPropertyValue('--primary').trim()+'20', tension:0.4, fill:true, pointRadius:5, pointHoverRadius:7 }] }, options:{ responsive:true, maintainAspectRatio:true, plugins:{ legend:{ display:true, position:'top' } }, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1 } } } } });
    }catch(e){ console.error(e); }
  }

  // Attach UI listeners on DOM ready
  document.addEventListener('DOMContentLoaded', function(){
    // Search inputs
    qsa('.search-box input').forEach(inp=>{
      const handler = debounce(()=>filterTables(inp), 120);
      inp.addEventListener('input', handler);
      inp.addEventListener('keydown', function(e){
        if(e.key === 'Enter'){
          const scope = inp.closest('.main-content, .content') || document;
          const first = scope.querySelector('.data-table tbody tr:not([style*="display: none"])');
          if(first) first.scrollIntoView({behavior:'smooth', block:'center'});
        }
      });
    });

    // Close modals on overlay click where needed (generic)
    qsa('.modal-overlay').forEach(m=>{
      m.addEventListener('click', function(e){ if(e.target === m) m.style.display='none'; });
    });

    // Delete modal confirm button already handled via document click

    // animate counters from server-provided data
    const D = window.DASHBOARD_DATA || null;
    if(D && typeof D === 'object'){
      const metrics = [
        ["userCount", "user_count"],
        ["totalIncome", "total_income"],
        ["totalExpense", "total_expense"],
        ["netBalance", "net"],
        ["inventoryCount", "inventory_count"],
        ["salesCount", "sales_count"],
        ["totalRevenue", "revenue"],
        ["hrCount", "hr_count"],
        ["revenueCount", "revenue_current"],
        ["orderCount", "orders_current"],
        ["customerCount", "customers_current"]
      ];
      const hasDashboardMetrics = metrics.some(([, key]) => Object.prototype.hasOwnProperty.call(D, key));
      if(hasDashboardMetrics){
        metrics.forEach(([id, key]) => {
          if(!Object.prototype.hasOwnProperty.call(D, key)) return;
          try{
            animate(id, Number(D[key]) || 0);
          }catch(err){ /* ignore individual metric errors */ }
        });
      }
    }

    // initialize charts (Chart.js must be loaded)
    if(typeof Chart !== 'undefined'){
      initCharts();
    } else {
      // try again shortly if Chart.js loads after this file
      let retries = 0;
      const t = setInterval(()=>{ if(typeof Chart !== 'undefined'){ clearInterval(t); initCharts(); } if(++retries > 10) clearInterval(t); }, 300);
    }
  });

  document.addEventListener('DOMContentLoaded', function(){
    const filterButtons = qsa('.pos-filter-btn');
    if(!filterButtons.length) {
      return;
    }
    const highlightActive = (activeBtn) => {
      filterButtons.forEach(btn => btn.classList.toggle('active', btn === activeBtn));
    };
    const applyFilter = (filterValue) => {
      qsa('#inventoryTab .pos-visibility-row').forEach(row => {
        const state = row.getAttribute('data-visibility') || 'visible';
        row.style.display = (filterValue === 'all' || state === filterValue) ? '' : 'none';
      });
    };
    filterButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        const value = btn.getAttribute('data-pos-filter') || 'all';
        highlightActive(btn);
        applyFilter(value);
      });
    });
    applyFilter('all');
  });

  // expose some utilities for inline usage if needed
  window.DashboardUtils = {
    openImportModal, closeImportModal, openAddItemModal, closeAddItemModal,
    openAssignModal, closeAssignModal, openLogTimeModal, closeLogModal,
    openEditUserModal, closeEditUserModal, openEditEmployeeModal, closeEditEmployeeModal,
    openDeleteModal, closeDeleteModal, openMarkDefective, initCharts
  };
})();