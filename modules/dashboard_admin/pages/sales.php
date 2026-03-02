    <?php
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
                logActivity($pdo, $company_id, $_SESSION['user_id'], $_SESSION['role'], 'sales', 'add_sale', "Sold $quantity of " . $item['item_name'] . " for â‚±" . ($price * $quantity));
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

            logActivity($pdo, $company_id, $_SESSION['user_id'], $_SESSION['role'], 'sales', 'add_sale', "Sold $quantity of " . $item['item_name'] . " for Ã¢â€šÂ±" . ($price * $quantity));

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
                                    <td>â‚±<?= number_format($sale['price'], 2) ?></td>
                                    <td>â‚±<?= number_format($sale['quantity'] * $sale['price'], 2) ?></td>
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

    function openEditCustomerModal(customer = {}) {
        const modal = document.getElementById('editCustomerModal');
        if (!modal) { return; }

        const setValue = (id, value) => {
            const element = document.getElementById(id);
            if (!element) { return; }
            element.value = value ?? '';
        };

        setValue('edit_customer_id', customer.customer_id ?? customer.id ?? '');
        setValue('edit_customer_name', customer.name ?? '');
        setValue('edit_customer_email', customer.email ?? '');
        setValue('edit_customer_phone', customer.phone ?? '');
        setValue('edit_customer_address', customer.address ?? '');

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeEditCustomerModal() {
        const modal = document.getElementById('editCustomerModal');
        if (!modal) { return; }
        modal.style.display = 'none';
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
