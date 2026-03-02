    <?php
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
    const bomInventoryOptions = <?= json_encode($bom_inventory_options ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    const appendBomComponentRow = (containerId, componentData = null) => {
        const container = document.getElementById(containerId);
        if (!container) { return; }

        const index = container.querySelectorAll('.bom-component').length;
        const fieldPrefix = containerId === 'editBomComponents' ? 'edit_components' : 'components';

        const wrapper = document.createElement('div');
        wrapper.className = 'bom-component';

        const label = document.createElement('label');
        label.textContent = 'Component';

        const select = document.createElement('select');
        select.name = `${fieldPrefix}[${index}][inventory_id]`;
        select.required = true;

        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = 'Select item...';
        select.appendChild(defaultOption);

        (Array.isArray(bomInventoryOptions) ? bomInventoryOptions : []).forEach((optionItem) => {
            const option = document.createElement('option');
            option.value = optionItem.id ?? '';
            option.textContent = optionItem.label ?? `Item #${optionItem.id ?? ''}`;
            select.appendChild(option);
        });

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

    
