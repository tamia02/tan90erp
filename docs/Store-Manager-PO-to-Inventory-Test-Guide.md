# Manual Test: New SKU → PO → GRN → Inventory (Store Manager flow)

Run this on the **local site** (http://127.0.0.1:8000). On the live site, a new PO is sent to the client's **real Zoho**.
To switch role: go to `/login` and click that role's card.

**Get these ready first:**
- One photo or PDF to use as the "POD" (proof of delivery). It's required at unloading.
- A new, unique bill number, for example `BILL-PORM-001`.
- A new, unique PO number, for example `PO RM 2627 0666`.

---

## 1. Store Manager: add the SKU
Menu: **SKU Master**
1. **Product Name:** `PORM666`. It must be the exact text you'll put on the PO.
2. **Category:** `PCM — Raw Material` · **Unit:** `KG`
3. Leave **"Mapped (Tan90-only)…" ticked**. If you untick it, the gate entry gets blocked later.
4. Click **Add to master**.

✅ Check: **Inventory → Stock Summary** lists PORM666 as Raw Material (RM), with stock **0**.

## 2. Store Manager: create and release the PO
Menu: **PO Master** → **Add PO**
1. **Nature of PO:** Raw Material. Category and payment terms fill in by themselves.
2. **PO Number:** type `PO RM 2627 0666`.
3. **Vendor Name:** search for any vendor and click it.
4. **Billing address:** fill all 5 boxes: building, street, city, state and PIN.
5. **Line item:**
   - Product / SKU: `PORM666`
   - Quantity: `250`
   - UOM: `KG`
   - List Price: `40`
   - Use **one line only**, with a **whole number** quantity.
6. Click **Save PO draft**.
7. In the PO list, on that PO's row, click **Release to vendor**, then **OK**.

✅ A green message says "… released to [vendor]".

*The vendor doesn't need to do anything for this test.*

## 3. Guard: gate entry
Menu: **New Gate Pass** (keep the **Inward** tab selected)
1. **Invoice / PO Number:** type `PO RM 2627 0666`, then click **Fetch**.
2. A green box says "Source: PO Master". Vendor, amount and material fill in by themselves.
3. In the invoice box, type the new bill number `BILL-PORM-001`. **Don't click Fetch again.**
4. Fill **Driver Name**, **Driver Phone Number** and **Vehicle Number** (e.g. `MH 04 GT 5521`).
5. Click **Save Inward Entry**.

## 4. Store Manager: approve the entry
Menu: **Entry Approvals** → section "Inward — awaiting approval"
1. Click **Approve entry**. A dock is assigned automatically.
2. **If it's blocked:** go to **Validation Issues**, open the issue, and click **Approve** or **Resolve**. Red issues need a short note (at least 10 characters). Then come back and approve.

## 5. Store Executive: unloading
Menu: **Loading & Unloading**
1. Click **Allot & auto-locate bay**.
2. Click **Open start step**. Set **Manpower assigned** to `2`, then click **Start unloading**.
3. In the completion form:
   - **Physical count:** `250`
   - **Packing unit:** choose any.
   - **POD evidence (required):** upload your photo or PDF.
4. Click **Complete unloading & send to QC Check**.

## 6. QC: quality check
Menu: **QC Queue**
1. Click **QC Check** on the entry.
2. **Accepted** is already `250`. Leave Hold, Defective and Rejected at `0`. Don't delete the pre-filled parameter rows.
3. Click **Submit QC Check & send to GRN Check**.

*If you enter Defective or Rejected above 0, you must add 2 tagged photos.*

## 7. Store Manager: GRN
Menu: **GRN Check**
1. Click **Post GRN**.
2. **Suggested bin:** `BIN-A1`
3. Click **Post GRN & update stock**.

✅ A message says "GRN posted…"

## 8. Check the stock (Store Manager)
| Where | Expected |
|---|---|
| **Stock Balance** | PORM666 = **250 KG** |
| **Inventory → Stock Summary** | PORM666: Received **250**, Final **250** |
| Click the PORM666 row | History: "Received (GRN) … **+250**" |

## 9. (Optional) Use it in production
1. **Inventory → Recipes**: on a Finished Good, click **Edit recipe** and add PORM666 at `0.5` per unit.
2. **Create Bundle**: pick that Finished Good, quantity `100`, then click **Create Bundle & update stock**.
3. **Stock Summary**: PORM666 now shows Converted **50**, Final **200**.

## 10. (Optional) Store Executive: putaway
Menu: **Putaway** → **Confirm putaway** → enter **Final bin** → **Save & close entry**. The entry is now closed.

---

**If something stops you:** take a screenshot of that screen and send it.

**Common causes:**
- The SKU name on the PO doesn't match SKU Master exactly.
- The bill number was already used.
- The POD file is missing.
