"""End-to-end browser test of BOTH Tan90 ERP flows on the LOCAL site.

Flow 1: Store Manager PO -> release -> Guard gate entry -> approval -> unloading
        (with POD) -> QC -> GRN -> putaway -> stock shows in Stock Balance + Inventory.
Flow 2: Inventory -> top up packaging stock -> Create Bundle (Zoho recipe) ->
        Outward Shipment (B2B sale: upload Invoice/IRN/E-Way Bill, then dispatch;
        challan transfer over stock must be blocked) -> Stock Summary.

Usage (from the project folder; needs Python + Playwright, MySQL and `php artisan serve`):
    python scripts/e2e/both_flows.py            # both flows, headless
    python scripts/e2e/both_flows.py --watch    # open a real browser and go slowly
    python scripts/e2e/both_flows.py --flow1    # only Flow 1
    python scripts/e2e/both_flows.py --flow2    # only Flow 2
    python scripts/e2e/both_flows.py --base http://127.0.0.1:8000

Never point this at the live site: it creates real POs, gate entries, GRNs, productions
and dispatches. Screenshots of every step land in scripts/e2e/output/.
"""
import argparse
import re
import os
import sys
import time
from datetime import date, timedelta

from playwright.sync_api import sync_playwright

sys.stdout.reconfigure(encoding="utf-8")  # Windows console: print arrows etc. safely

ap = argparse.ArgumentParser()
ap.add_argument("--base", default="http://127.0.0.1:8000")
ap.add_argument("--watch", action="store_true", help="show the browser and slow down")
ap.add_argument("--flow1", action="store_true")
ap.add_argument("--flow2", action="store_true")
args = ap.parse_args()
if "tan.bookmytimes.in" in args.base:
    sys.exit("Refusing to run against the live site - use your local site.")

BASE = args.base.rstrip("/")
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "output")
os.makedirs(OUT, exist_ok=True)
TS = time.strftime("%H%M%S")
PO, BILL = f"PO E2E {TS}", f"E2E-BILL-{TS}"
# Real Zoho items: FG recipe = 0.717 N-Tetradecane + 1 panel per unit.
RM, PM, FG = "N-Tetradecane", "1200gm Blue Panel (SP-2124)", "Tan90 TN+5 1.2Kg Thermal Panel (Organic)"
POD = os.path.join(OUT, "pod.png")
with open(POD, "wb") as f:  # tiny valid PNG used as the POD proof file
    f.write(bytes.fromhex("89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000000020001e221bc330000000049454e44ae426082"))

results = []


def settle(page, ms=800):
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(ms)


def messages(page):
    return " | ".join(t.strip() for t in page.locator("div[role=status]").all_inner_texts() if t.strip())


def wait_for_text(page, text, timeout=30000):
    page.wait_for_function("t => document.body.innerText.includes(t)", arg=text, timeout=timeout)


def login(page, role):
    page.context.clear_cookies()
    page.goto(f"{BASE}/role-login/{role}")
    settle(page, 300)


def tick_all(page):
    for box in page.locator("input[type=checkbox]").all():
        if box.is_visible() and not box.is_checked():
            box.check()


def upload(page, model, path):
    """Pick a file and wait until Livewire has finished uploading it (clicking Save earlier loses the file)."""
    sel = f"[wire\\:model='{model}']"
    page.evaluate("() => { window.__upDone = false; document.addEventListener('livewire-upload-finish', () => window.__upDone = true, {once: true}); }")
    page.set_input_files(sel, path)
    page.wait_for_function("() => window.__upDone === true", timeout=30000)
    settle(page, 300)


def run(name, fn, page):
    n = len(results) + 1
    try:
        note = fn(page) or ""
        page.screenshot(path=os.path.join(OUT, f"{n:02d}-ok.png"))
        results.append(("PASS", name, note))
        print(f"PASS  {name}  {note}", flush=True)
        return True
    except Exception as e:  # noqa: BLE001
        shot = os.path.join(OUT, f"{n:02d}-FAIL.png")
        try:
            page.screenshot(path=shot, timeout=60000)
        except Exception:  # noqa: BLE001
            shot = "(no screenshot)"
        errors = [t for t in page.locator("[role=alert], [style*='status-critical']").all_inner_texts() if t.strip() and t.strip() != "Log out"][:4]
        results.append(("FAIL", name, str(e).splitlines()[0][:200]))
        print(f"FAIL  {name}  {str(e).splitlines()[0][:200]} | on screen: {errors} | {shot}", flush=True)
        return False


# ---------------- Flow 1 ----------------
def create_po(page):
    login(page, "storeManager")
    page.goto(f"{BASE}/admin/po"); settle(page)
    page.click("[wire\\:click='openForm']"); settle(page)
    page.select_option("[wire\\:model\\.live='purchaseNature']", "Raw Material"); settle(page)
    page.fill("[wire\\:model\\.live\\.debounce\\.250ms='vendorSearch']", "Sharp"); settle(page)
    page.locator("[wire\\:click^='selectVendor']").first.click(); settle(page)
    page.fill("[wire\\:model='poNumber']", PO)
    page.fill("[wire\\:model='validUpto']", (date.today() + timedelta(days=30)).isoformat())
    if page.locator("[wire\\:model='zohoFields.cf_purchase_nature']").count():
        page.select_option("[wire\\:model='zohoFields.cf_purchase_nature']", "Inward Stock")
    for field, value in [("billingBuilding", "Tan90 Plant"), ("billingStreet", "MIDC Road"), ("billingCity", "Thane"), ("billingState", "Maharashtra")]:
        page.fill(f"[wire\\:model\\.live='{field}']", value); settle(page, 300)
    page.fill("[wire\\:model='billingZip']", "400604")
    page.fill("[wire\\:model='items.0.product']", RM)
    page.fill("[wire\\:model='items.0.quantity']", "100")
    page.select_option("[wire\\:model='items.0.uom']", "LITER")
    page.fill("[wire\\:model='items.0.listPrice']", "50")
    page.click("[wire\\:click='saveAndSubmit']"); settle(page, 1500)
    page.goto(f"{BASE}/admin/po"); settle(page)
    assert page.get_by_text(PO).count(), "PO not in the list after saving"
    return PO


def release_po(page):
    page.once("dialog", lambda d: d.accept())
    page.locator("tr", has_text=PO).first.locator("[wire\\:click^='releasePo']").click()
    wait_for_text(page, "released to")
    return messages(page)[:90]


def gate_entry(page):
    login(page, "guard")
    page.goto(f"{BASE}/guard/scan"); settle(page)
    page.get_by_placeholder("Bill number or PO number").fill(PO); settle(page, 800)
    page.get_by_role("button", name="Fetch").first.click()
    wait_for_text(page, "Matched")
    page.get_by_placeholder("Bill number or PO number").fill(BILL); settle(page, 500)  # don't Fetch again
    page.fill("[wire\\:model='driverName']", "E2E Driver")
    page.fill("[wire\\:model='driverPhone']", "9876543210")
    page.fill("[wire\\:model='vehicleNumber']", "MH 04 GT 5521")
    tick_all(page)
    page.get_by_role("button", name="Save Inward Entry").click(); settle(page, 2500)
    return f"bill {BILL}"


def approve(page):
    login(page, "storeManager")
    page.goto(f"{BASE}/entry-approvals"); settle(page)
    # The smallest block that holds both this run's PO number and an Approve button.
    card = page.locator(f"xpath=//*[contains(normalize-space(.), '{PO}')][.//button[normalize-space()='Approve entry']]").last
    card.get_by_role("button", name="Approve entry").click()
    page.wait_for_function("() => /approved|No inward dock/.test(document.body.innerText)")
    if "No inward dock" in page.inner_text("body"):  # docks still held by unfinished earlier entries
        raise AssertionError("no free inward dock - finish or close earlier in-progress unloading entries")
    return messages(page)[:90]


def unload(page):
    login(page, "storeExec")
    page.goto(f"{BASE}/unloading/desk"); settle(page)
    page.get_by_role("button", name="Allot & auto-locate bay").first.click(); settle(page, 1500)
    page.get_by_role("button", name="Open start step").first.click(); settle(page)
    page.fill("[wire\\:model='manpowerCount']", "2")
    page.get_by_role("button", name="Start unloading").first.click(); settle(page, 1500)
    if page.get_by_role("button", name="Open completion checklist").count():
        page.get_by_role("button", name="Open completion checklist").first.click(); settle(page)
    page.fill("[wire\\:model='boxCount']", "100")
    unit = page.locator("[wire\\:model='packingUnit']")
    if unit.evaluate("e => e.tagName") == "SELECT":
        unit.select_option(index=1)
    else:
        unit.fill("Drum")
    upload(page, "podFile", POD)
    tick_all(page)
    page.get_by_role("button", name="Complete unloading & send to QC Check").first.click()
    wait_for_text(page, "sent to QC")
    return messages(page)[:90]


def qc(page):
    login(page, "qc")
    page.goto(f"{BASE}/qc/queue"); settle(page)
    page.get_by_role("button", name="QC Check").first.click(); settle(page, 1200)
    page.locator("input[wire\\:model='accepted']").fill("100")  # QC types the accepted quantity
    tick_all(page)
    page.get_by_role("button", name="Submit QC Check & send to GRN Check").click()
    wait_for_text(page, "sent to GRN Check")
    return messages(page)[:90]


def grn(page):
    login(page, "storeManager")
    page.goto(f"{BASE}/grn/check"); settle(page)
    page.get_by_role("button", name="Post GRN").first.click(); settle(page)
    page.get_by_placeholder("e.g. BHW-PCM-A1").fill("E2E-BIN-1")
    page.get_by_role("button", name="Post GRN & update stock").click()
    wait_for_text(page, "GRN posted")
    return messages(page)[:90]


def putaway(page):
    login(page, "storeExec")
    page.goto(f"{BASE}/unloading/putaway"); settle(page)
    page.get_by_role("button", name="Confirm putaway").first.click(); settle(page)
    page.fill("[wire\\:model='finalBin']", "E2E-BIN-1")
    page.get_by_role("button", name="Save & close entry").first.click()
    wait_for_text(page, "Putaway confirmed")
    return messages(page)[:90]


def summary_row(page, name):
    page.goto(f"{BASE}/inventory"); settle(page)
    page.get_by_placeholder("Search items…").fill(name); settle(page, 1500)
    return " ".join(c.strip() for c in page.locator("tbody tr", has_text=name).first.inner_text().split("\t"))


def check_received(page):
    login(page, "storeManager")
    page.goto(f"{BASE}/grn/stock-balance"); settle(page)
    assert page.get_by_text(RM).count(), f"{RM} not in Stock Balance"
    return "Inventory: " + summary_row(page, RM)


# ---------------- Flow 2 ----------------
def pm_stock(page):
    """Test data: +60 packaging panels (Items no longer has an opening-stock box - real
    stock comes from GRN), then check the item's stock history panel in the browser."""
    import subprocess
    php = os.environ.get("PHP_BIN", r"C:\php84\php.exe")
    out = subprocess.run([php, "artisan", "tinker", "--execute",
                          f"$i=App\\Models\\Inventory\\InventoryItem::where('sku', {PM!r})->firstOrFail(); $i->increment('opening_qty', 60); echo (int) $i->fresh()->opening_qty;"],
                         capture_output=True, text=True, cwd=os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
    login(page, "storeManager")
    page.goto(f"{BASE}/inventory/items"); settle(page)
    page.get_by_placeholder("Search by item name or code…").fill("SP-2124"); settle(page, 1500)
    page.locator("tr", has_text="SP-2124").first.locator("button").first.click()
    wait_for_text(page, "Stock history:")
    return f"test stock now {out.stdout.strip().splitlines()[-1] if out.stdout.strip() else '?'} | history panel opens"

def bundle(page):
    page.goto(f"{BASE}/inventory/production"); settle(page)
    page.select_option("[wire\\:model\\.live='fgItemId']", label=FG); settle(page)
    page.fill("[wire\\:model='qty']", "50")
    page.locator("tr", has_text=RM).locator("input").fill("1")
    page.wait_for_timeout(1000)
    page.get_by_role("button", name="Create Bundle & update stock").click()
    wait_for_text(page, ": made 50 ")  # the success message, not an older PRD- row
    return messages(page)[:110]


def outward_form(page, purpose, qty, rate, expect=" created ("):
    page.goto(f"{BASE}/inventory/dispatch"); settle(page)
    page.locator(f"input[type=radio][value='{purpose}']").check(); settle(page)
    page.locator("[wire\\:model\\.live='fromLocationId']").select_option(index=1); settle(page)
    if purpose == "sales":  # first customer that has a GSTIN = B2B
        cust = page.locator("[wire\\:model\\.live='customerId']")
        cust.select_option(label=[o for o in cust.locator("option").all_inner_texts() if "no GSTIN" not in o and "Select" not in o][0])
    else:  # transfer to the same location = same GSTIN = Delivery Challan
        dest = page.locator("[wire\\:model\\.live='toLocationId']")
        dest.select_option(value=page.locator("[wire\\:model\\.live='fromLocationId']").input_value())
    settle(page)
    page.fill("[wire\\:model\\.blur='lines.0.item']", FG)
    page.fill("[wire\\:model\\.live\\.debounce\\.500ms='lines.0.qty']", str(qty))
    page.fill("[wire\\:model\\.live\\.debounce\\.500ms='lines.0.rate']", str(rate)); settle(page, 1200)
    page.get_by_role("button", name="Create outward shipment").click()
    wait_for_text(page, expect)  # the flash (not an older shipment card) or the stock error


def dispatch(page):
    """Store Manager makes the B2B shipment + documents; the truck then goes through
    Guard pass -> dock -> loading -> Guard exit, which dispatches it and deducts stock."""
    page.on("dialog", lambda d: d.accept())
    login(page, "storeManager")
    outward_form(page, "sales", 20, 100)
    shipment = re.search(r"(OUT-\d+) created", page.inner_text("body")).group(1)
    today, later = date.today().isoformat(), (date.today() + timedelta(days=2)).isoformat()
    for field, value in [("invoice_number", f"INV-{TS}"), ("invoice_date", today), ("irn", f"IRN{TS}abc"), ("irn_ack_number", f"ACK{TS}"),
                         ("ewb_number", f"33{TS}0000"), ("ewb_valid_until", later)]:
        page.fill(f"[wire\\:model='doc.{field}']", value)
    upload(page, "invoiceFile", POD)
    upload(page, "ewbFile", POD)
    page.get_by_role("button", name="Save documents").click()
    wait_for_text(page, "the Guard can dispatch it")
    if page.get_by_role("button", name="Mark dispatched").count():
        raise AssertionError("Outward page must not dispatch by hand any more")

    # 1. Truck arrives: Guard makes the outward pass for this shipment.
    login(page, "guard")
    page.goto(f"{BASE}/guard/scan"); settle(page)
    page.get_by_role("button", name="Outward", exact=True).first.click(); settle(page, 1200)
    page.locator("[wire\\:key^='ready-shipment-']", has_text=shipment).first.click(); settle(page, 1200)
    page.fill("#driverName", "E2E Driver"); page.fill("#vehicleNumber", f"MH 04 E2E {TS[-4:]}")
    page.get_by_role("button", name="Save Outward Entry").click()
    wait_for_text(page, "Gate Pass Created Successfully")
    gate = re.search(r"(GP-OUT-\d{4}-\d+)", page.inner_text("body")).group(1)
    entry_url = page.get_by_role("link", name="View Entry").get_attribute("href")

    # 2. Store Manager approves and assigns the dock.
    login(page, "storeManager")
    page.goto(f"{BASE}/entry-approvals"); settle(page)
    card = page.locator(f"xpath=//*[contains(normalize-space(.), '{gate}')][.//button[normalize-space()='Approve & assign dock']]").last
    card.get_by_role("button", name="Approve & assign dock").click(); settle(page, 1200)
    page.get_by_role("button", name="Confirm approval").click()
    wait_for_text(page, f"{gate} approved and assigned")

    # 3. Store Executive loads the truck.
    login(page, "storeExec")
    page.goto(f"{BASE}/unloading/desk"); settle(page)
    card = page.locator(f"xpath=//*[contains(normalize-space(.), '{gate}')][.//button[normalize-space()='Start loading']]").last
    card.get_by_role("button", name="Start loading").click()
    wait_for_text(page, f"{gate} loading started")
    card = page.locator(f"xpath=//*[contains(normalize-space(.), '{gate}')][.//button[normalize-space()='Open completion checklist']]").last
    card.get_by_role("button", name="Open completion checklist").click(); settle(page, 1200)
    for box in page.locator("input[type=checkbox][wire\\:model^='documents']").all():
        if box.is_visible() and not box.is_checked():
            box.check()
    page.get_by_role("button", name="ready for exit").click()
    wait_for_text(page, f"{gate} loaded and documents shared")

    # 4. Truck leaves: Guard confirms exit = dispatch.
    login(page, "guard")
    page.goto(entry_url if entry_url.startswith("http") else BASE + entry_url); settle(page)
    page.get_by_role("button", name="Confirm exit").click()
    wait_for_text(page, "stock has been deducted")
    ok = f"{shipment} -> {gate}: " + messages(page)[:70]

    # More than is in stock: shown while typing and blocked at Create, before any document is made.
    login(page, "storeManager")
    outward_form(page, "transfer", 99999, 0, expect="make or receive more first")
    return ok + " | over-stock blocked at create"


def final(page):
    return " || ".join(summary_row(page, n) for n in (RM, PM, FG))


FLOW1 = [("1. Store Manager creates PO (Zoho required fields)", create_po), ("2. Store Manager releases PO", release_po),
         ("3. Guard gate entry", gate_entry), ("4. Store Manager approves entry", approve),
         ("5. Store Executive unloads with POD", unload), ("6. QC accepts 100", qc), ("7. Store Manager posts GRN", grn),
         ("8. Store Executive putaway", putaway), ("9. Stock shows in Stock Balance + Inventory", check_received)]
FLOW2 = [("10. Packaging stock + item history panel", pm_stock), ("11. Create Bundle from Zoho recipe", bundle),
         ("12. Outward: shipment + documents -> Guard pass -> dock -> loading -> Guard exit dispatches", dispatch), ("13. Stock Summary", final)]
steps = (FLOW1 if not args.flow2 else []) + (FLOW2 if not args.flow1 else [])

with sync_playwright() as p:
    browser = p.chromium.launch(headless=not args.watch, slow_mo=350 if args.watch else 0)
    page = browser.new_page(viewport={"width": 1400, "height": 950})
    page.set_default_timeout(30000)
    for name, fn in steps:
        if not run(name, fn, page) and fn in dict(FLOW1).values():
            print("Flow 1 stopped - later Flow 1 steps depend on this one.", flush=True)
            steps_left = [s for s in steps if s[1] in dict(FLOW2).values()]
            for n2, f2 in steps_left:
                run(n2, f2, page)
            break
    browser.close()

passed = sum(1 for r in results if r[0] == "PASS")
print(f"\nSUMMARY: {passed} passed, {len(results) - passed} failed. Screenshots: {OUT}")
sys.exit(0 if passed == len(results) else 1)
