# QUICK TEST GUIDE - Bug Fixes

## Test 1: POS Cart - Multiple Items Separated ✓

### Steps:
1. Login as head_sales
2. Open POS System (pos_system.php)
3. Add "Gummy" to cart - Qty: 2
4. Add "Gummy" again to cart - Qty: 3
5. Add "Hotdog" to cart - Qty: 1

### Expected Result:
- Cart shows 3 separate line items:
  - Line 1: Gummy, Qty: 2, Total: ₱4.00 (assuming ₱2.00 each)
  - Line 2: Gummy, Qty: 3, Total: ₱6.00
  - Line 3: Hotdog, Qty: 1, Total: ₱5.00 (assuming ₱5.00 each)

### Test Actions:
- Click +/- on Line 1 → Only affects Line 1
- Click +/- on Line 2 → Only affects Line 2
- Click Remove on Line 2 → Only removes Gummy (3 units)
- Verify Line 1 (Gummy 2 units) still there
- Verify Line 3 (Hotdog) still there

---

## Test 2: Inventory - Proper Deletion

### Setup:
1. Create 2 items with same name: "Gummy"
   - Item ID: 1 - Name: "Gummy" - Qty: 100
   - Item ID: 2 - Name: "Gummy" - Qty: 200

2. Login as head_inventory
3. Go to Inventory Dashboard

### Steps:
1. Find first "Gummy" item (ID: 1)
2. Click Delete button
3. Verify confirmation shows: "Are you sure you want to delete 'Gummy'? This action cannot be undone."
4. Click Cancel
5. Verify both items still exist

### Continue Test:
1. Click Delete on first "Gummy" again
2. Click OK/Confirm
3. Verify first item deleted
4. Verify second "Gummy" (ID: 2) still exists with 200 qty
5. Item count decreased by 1

### Expected Behavior:
- ✓ Confirmation shows specific product name
- ✓ Only 1 item deleted (by ID)
- ✓ Other item with same name still exists
- ✓ Success message: "Item deleted."

---

## Test 3: Inventory - Mark as Defective

### Setup:
1. Login as head_inventory
2. Find any inventory item
3. Example: "Gummy" item

### Steps:
1. Click "Defective" button (orange/yellow button)
2. Browser prompt appears asking: "Mark 'Gummy' as defective. Enter reason (optional):"
3. Enter reason: "Cracked packaging"
4. Click OK

### Expected Result:
- ✓ Page refreshes
- ✓ Success message: "Item marked as defective." (in orange)
- ✓ Item status changes to "Defective"
- ✓ Item no longer shows in POS (status != 'Active')
- ✓ Item still visible in inventory list

### Verify in Database:
```sql
SELECT id, item_name, is_defective, defective_reason, defective_at, status 
FROM inventory 
WHERE item_name = 'Gummy' LIMIT 1;
```

Expected columns:
- is_defective: 1
- defective_reason: "Cracked packaging"
- defective_at: Current timestamp
- status: "Defective"

### Test in POS:
1. Go back to POS System
2. Try to find "Gummy"
3. Verify it's NOT showing (filtered out by status != 'Active')

---

## Test 4: Cart with Multiple Different Items

### Steps:
1. Add 3 x "Gummy" @ ₱2.00 each
2. Add 2 x "Hotdog" @ ₱5.00 each
3. Add 1 x "Popsicle" @ ₱10.00 each

### Verify Display:
```
Cart:
Line 1: Gummy Qty: 3 @ ₱2.00 = ₱6.00
Line 2: Hotdog Qty: 2 @ ₱5.00 = ₱10.00
Line 3: Popsicle Qty: 1 @ ₱10.00 = ₱10.00

Subtotal: ₱26.00
Total: ₱26.00
```

### Test Quantity Adjustments:
- Decrease Line 1 by 1 → Shows Qty: 2, Total: ₱4.00
- Increase Line 2 by 1 → Shows Qty: 3, Total: ₱15.00
- Remove Line 1 → Line 1 disappears, others remain
- Verify Total updates correctly each time

---

## Test 5: Checkout Still Works

### After Tests 1-4:
1. Have multiple items in cart (from Test 4)
2. Enter payment: ₱100.00
3. Click "Checkout"

### Expected Result:
- ✓ All items processed
- ✓ Inventory reduced for each item
- ✓ Finance record created with total amount
- ✓ Sales records created for each item
- ✓ Success modal shows Receipt ID
- ✓ Receipt ID format: RC{YYYYMMDDHHmmss}{random}

---

## Troubleshooting

### Issue: Defective button not working
- Check browser console (F12) for errors
- Verify item has valid ID
- Check database permissions for UPDATE

### Issue: Items still combining in cart
- Clear localStorage: 
  - Open DevTools (F12)
  - Go to Application → LocalStorage
  - Find "pos_cart" and delete it
  - Refresh page

### Issue: Delete showing wrong item
- Make sure item ID in URL matches
- Check that itemName parameter is passed correctly
- Verify no special characters in product name

---

## Quick Checklist

- [ ] POS Cart shows items separately
- [ ] Can adjust each cart item independently
- [ ] Delete shows specific product name
- [ ] Only deletes 1 item by ID
- [ ] Defective button appears (orange)
- [ ] Defective prompt accepts reason input
- [ ] Item marked as defective successfully
- [ ] Defective items hidden from POS
- [ ] Checkout still processes correctly

---

**All tests passed = Ready for Production ✓**
