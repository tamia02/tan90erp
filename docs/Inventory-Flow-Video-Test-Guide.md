# Inventory Flow – Manual Test & Video Guide

**Flow shown:** Vendor → GRN (already built) → **RM & PM stock → Create Bundle (production) → Finished Goods → Dispatch**, plus **Trading goods** (bought and sold as-is).

**The rule every number follows:**

> **Final Inventory = Current + Received − (Converted + Wastage) − Dispatched**
> (Finished Goods also add what was **Produced**.)

Total time: about 10 minutes. The numbers below match the local demo data, which already has **690 KG of PCM Raw Compound** received through GRN.

---

## 0. Before you record

1. Start XAMPP → **MySQL**, then in the project folder run `php artisan serve`.
   To record from a clean start (it clears only Inventory test data – GRN stock is kept), first run `php artisan inventory:reset-demo` and answer **yes**.
2. Open http://127.0.0.1:8000 and log in as **Super Admin**: click the *Super Admin* card, or use `admin@tan90.test` / `demo123`.
3. In the left menu, under **All Modules**, click **Inventory (RM → FG → Dispatch)**.

*Say:* "The new Inventory module has 4 steps, shown as tabs at the top: Items & Recipes, Create Bundle, Outward Shipment and Stock Summary."

---

## 1. Items & Recipes – tell the system what each item is

Click tab **1. Items & Recipes** and add these 4 items with the form on the left:

| SKU / item name | Type | Unit | Current stock |
|---|---|---|---|
| `PCM Raw Compound (TN-1 Grade)` – **pick it from the suggestions** | Raw Material (RM) | KG | 0 |
| `Pouch 500gm` | Packaging Material (PM) | NOS | 1000 |
| `500gm PCM Pouch - Blue` | Finished Good (FG) | NOS | 0 |
| `Hand Gloves (PPE) — Bulk Pack` – **pick it from the suggestions** (it uses a long dash —) | Trading Good | BOX | 300 |

✅ **Check:** PCM Raw Compound shows **690 KG in stock**, even though you typed 0. That 690 came from the GRN, so received goods count automatically.

> ⚠️ The SKU name must match the PO/GRN name exactly. That's why the box suggests existing names – always pick from the list.
> Made a typo? Click **Remove** on that row. An unused item is deleted; an item that already has stock history is **archived** (hidden, history kept) and can be restored.

*Say:* "Current stock is what we already had. Anything received through GRN is added automatically."

**Add the recipe:** on the `500gm PCM Pouch - Blue` row, click **Recipe**, then add:
- PCM Raw Compound (TN-1 Grade) → **0.5** (KG per 1 pouch)
- Pouch 500gm → **1** (per 1 pouch)

✅ **Check:** the Recipe column shows "0.5 KG PCM Raw Compound…, 1 NOS Pouch 500gm".

---

## 2. Create Bundle – make Finished Goods from RM + PM

Click tab **2. Create Bundle (Production)**.

1. Finished Good: **500gm PCM Pouch - Blue**
2. Quantity to make: **1000**
3. Wastage on PCM Raw Compound: **10**

✅ **Check the preview table before saving:**

| Material | Will be used | Wastage | In stock | Left after |
|---|---|---|---|---|
| PCM Raw Compound | 500 KG | 10 | 690 KG | **180 KG** |
| Pouch 500gm | 1000 NOS | 0 | 1000 NOS | **0 NOS** |

4. Click **Create Bundle & update stock**.

✅ **Check:** a green message appears – "PRD-00001: made 1000 NOS of 500gm PCM Pouch - Blue…" – and the entry shows under *Recent production*.

**Safety test (show the client):** try to make **1** more. It is **blocked** with "Not enough Pouch 500gm: need 1 NOS, only 0 in stock". Nothing is saved.

*Say:* "The recipe decides how much raw and packaging material is used. Wastage is recorded separately, and you can never use more than you have."

---

## 3. Outward Shipment – the GST way (Tax Invoice / Delivery Challan, IRN, E-Way Bill)

Click tab **3. Outward Shipment**. Choose the **purpose**; the blue box then tells you which documents are needed and why.

| Purpose | Receiver | Documents |
|---|---|---|
| Sales Order | customer **with** GSTIN (B2B) | Tax Invoice + e-Invoice IRN + E-Way Bill |
| Sales Order | customer **without** GSTIN (B2C) | Tax Invoice; E-Way Bill only above Rs 50,000 |
| Transfer / Job Work | **different** GSTIN | Tax Invoice + IRN + E-Way Bill |
| Transfer / Job Work | **same** GSTIN | Delivery Challan; E-Way Bill only above Rs 50,000 or inter-state job work |

**A. B2B sale of a Finished Good**
- Purpose **Sales Order**, From **Chennai**, Customer **Pharma Distribution Demo** (has GSTIN)
- Item **500gm PCM Pouch - Blue**, Qty **500**, Rate **100** → blue box: *Tax Invoice + e-Invoice IRN + E-Way Bill*
- **Create outward shipment** → ✅ "OUT-0000x created … Add the documents below". No *Mark dispatched* button yet, stock unchanged.
- Your team makes the invoice, IRN and E-Way Bill on the government portals, then fills in the numbers and uploads the files → **Save documents** → ✅ "all documents in - ready to dispatch".
- **Mark dispatched** → ✅ "dispatched - stock has been deducted". Stock goes down only now.

**B. Transfer to a branch with the same GSTIN** (no tax invoice)
- Purpose **Transfer Order**, From **Chennai**, To **Chennai**, small value → *Delivery Challan (print challan only)*. It is ready at once: **Print challan**, then **Mark dispatched**.

**Safety test:** a shipment for more than you have in stock → **Mark dispatched** is ✅ **blocked**: "Only … in stock." Nothing is deducted.

> Sales can only include Finished Goods and Trading Goods. Transfers and Job Work may also send raw or packaging material.

---

## 4. Stock Summary – the formula, per item

Click tab **4. Stock Summary**. ✅ It should show exactly:

| Item | Current | + Received (GRN) | + Produced | − Converted | − Wastage | − Dispatched | **= Final** |
|---|---|---|---|---|---|---|---|
| PCM Raw Compound (RM) | 0 | 690 | 0 | 500 | 10 | 0 | **180** |
| Pouch 500gm (PM) | 1000 | 0 | 0 | 1000 | 0 | 0 | **0** |
| 500gm PCM Pouch - Blue (FG) | 0 | 0 | 1000 | 0 | 0 | 500 | **500** |
| Hand Gloves (Trading) | 300 | 0 | 0 | 0 | 0 | 100 | **200** |

Worked example to read out: **0 + 690 − (500 + 10) − 0 = 180 KG**.

**History:** click the **PCM Raw Compound** row. You'll see every movement with date and reference:
Received (GRN) GATE-1001 **+690** · Converted PRD-00001 **−500** · Wastage PRD-00001 **−10**.

Other things to show: the **type filter** ("Raw Material (RM)" only), **Search**, and **Export CSV**.

---

## 5. (Optional) Show the link to the vendor flow

Receive more PCM Raw Compound through the normal flow: Guard gate entry → Unloading → QC → **GRN Check → Post GRN**. Then open the Stock Summary again. **+ Received** and **Final** go up by the accepted quantity, with no extra step.

---

## Quick checklist

- [ ] GRN stock appears as "Received" automatically
- [ ] Recipe drives RM/PM usage; wastage is deducted separately
- [ ] Production is blocked when material is short
- [ ] FG added on production, reduced on dispatch
- [ ] Trading goods dispatch without production
- [ ] RM/PM can't be sold (transfers/job work may send them); you can't dispatch more than is in stock
- [ ] B2B sale can't be dispatched until Invoice, IRN and E-Way Bill are uploaded
- [ ] Stock Summary matches the formula; History shows every movement

**Who can use it:** Super Admin and Store Manager (including anyone with Store Manager as an extra role). Other roles get "no access".
