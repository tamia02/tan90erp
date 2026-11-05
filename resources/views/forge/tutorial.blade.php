<x-app-layout>
    @include('access-control._style')
    <style>
        .forge-tutorial { --ft-ink:#17324d; --ft-muted:#607487; --ft-line:#d7e1e8; --ft-blue:#edf4fa; --ft-teal:#e8f4f1; --ft-amber:#fff4df; color:#263746; }
        .forge-tutorial * { min-width:0; }
        .forge-tutorial .ft-card { border:1px solid var(--ft-line); border-radius:1rem; background:#fff; padding:1.1rem; }
        .forge-tutorial .ft-hero { background:linear-gradient(115deg,#edf4fa 0%,#fff 62%); border-color:#cad9e3; }
        .forge-tutorial h1,.forge-tutorial h2,.forge-tutorial h3 { color:#10283d; }
        .forge-tutorial .ft-muted { color:var(--ft-muted); }
        .forge-tutorial .ft-diagram { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1rem; align-items:stretch; }
        .forge-tutorial .ft-node { position:relative; min-height:114px; padding:.85rem; border:1px solid #c8d8e3; border-radius:.8rem; background:var(--ft-blue); color:var(--ft-ink); text-decoration:none; display:flex; flex-direction:column; justify-content:center; gap:.35rem; transition:background-color .15s,border-color .15s; }
        .forge-tutorial .ft-node:hover,.forge-tutorial .ft-node:focus-visible { background:#e0edf6; border-color:#547c99; outline:3px solid #a8c4d8; outline-offset:2px; }
        .forge-tutorial .ft-node strong { font-size:.95rem; }
        .forge-tutorial .ft-node span { color:#4a6072; font-size:.82rem; line-height:1.35; }
        .forge-tutorial .ft-node::after { content:'→'; position:absolute; right:-.83rem; top:50%; z-index:1; transform:translate(50%,-50%); color:#56758d; font-size:1.25rem; font-weight:700; }
        .forge-tutorial .ft-node:nth-child(4)::after,.forge-tutorial .ft-node:nth-child(8)::after { content:''; }
        .forge-tutorial .ft-decision { border-color:#e2c57e; background:var(--ft-amber); }
        .forge-tutorial .ft-quality { border-color:#b4d8ce; background:var(--ft-teal); }
        .forge-tutorial .ft-branch { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; margin-top:.8rem; }
        .forge-tutorial .ft-branch > div { border-radius:.8rem; padding:.8rem 1rem; background:#f5f7f9; border:1px dashed #ccd6de; }
        .forge-tutorial .ft-branch > div:last-child { background:#edf7f2; border-color:#b7d9c9; }
        .forge-tutorial .ft-step { scroll-margin-top:1rem; border-left:4px solid #668aa4; }
        .forge-tutorial .ft-step:nth-of-type(even) { border-left-color:#168267; }
        .forge-tutorial .ft-step h3 { font-size:1.05rem; font-weight:750; }
        .forge-tutorial .ft-facts { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.75rem; margin-top:.8rem; }
        .forge-tutorial .ft-facts > div { padding:.75rem; border-radius:.7rem; background:#f5f7f9; }
        .forge-tutorial .ft-facts h4 { margin-bottom:.25rem; color:#526a7d; font-size:.72rem; font-weight:750; letter-spacing:.04em; text-transform:uppercase; }
        .forge-tutorial .ft-facts p { margin:0; font-size:.9rem; line-height:1.5; overflow-wrap:anywhere; }
        .forge-tutorial .ft-table-wrap { max-width:100%; overflow-x:auto; }
        .forge-tutorial table { width:100%; min-width:620px; border-collapse:collapse; }
        .forge-tutorial th,.forge-tutorial td { border-bottom:1px solid #e2e8ee; padding:.65rem .7rem; text-align:left; vertical-align:top; font-size:.88rem; }
        .forge-tutorial th { background:#edf2f6; color:#17324d; font-weight:750; }
        .forge-tutorial .ft-checklist { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.55rem .9rem; }
        .forge-tutorial .ft-checklist label { display:flex; align-items:flex-start; gap:.55rem; padding:.55rem .65rem; border:1px solid #e0e7ec; border-radius:.65rem; font-size:.9rem; }
        .forge-tutorial input[type=checkbox] { margin-top:.18rem; accent-color:#168267; }
        .forge-tutorial a.ft-inline { color:#245e83; text-decoration:underline; text-underline-offset:2px; }
        @media(max-width:900px) { .forge-tutorial .ft-diagram { grid-template-columns:repeat(2,minmax(0,1fr)); } .forge-tutorial .ft-node:nth-child(2)::after,.forge-tutorial .ft-node:nth-child(6)::after { content:''; } .forge-tutorial .ft-node:nth-child(4)::after { content:'↓'; top:auto; right:50%; bottom:-1rem; transform:translate(50%,50%); } .forge-tutorial .ft-node:nth-child(8)::after { content:'↓'; top:auto; right:50%; bottom:-1rem; transform:translate(50%,50%); } }
        @media(max-width:600px) { .forge-tutorial .ft-diagram { grid-template-columns:1fr; gap:.7rem; } .forge-tutorial .ft-node { min-height:80px; } .forge-tutorial .ft-node::after,.forge-tutorial .ft-node:nth-child(2)::after,.forge-tutorial .ft-node:nth-child(4)::after,.forge-tutorial .ft-node:nth-child(6)::after,.forge-tutorial .ft-node:nth-child(8)::after { content:'↓'; top:auto; right:50%; bottom:-.8rem; transform:translate(50%,50%); } .forge-tutorial .ft-node:last-child::after { content:''; } .forge-tutorial .ft-branch,.forge-tutorial .ft-facts,.forge-tutorial .ft-checklist { grid-template-columns:1fr; } }
        @media(prefers-reduced-motion:reduce) { .forge-tutorial .ft-node { transition:none; } }
    </style>

    <main class="forge-tutorial access-shell max-w-7xl mx-auto space-y-5">
        <header class="ft-card ft-hero">
            <p class="ft-muted text-sm">Forge / Production Modules / Tutorial</p>
            <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold">Production Tutorial</h1>
                    <p class="ft-muted mt-2 max-w-3xl">Learn how a customer order moves through planning, materials, production, quality, finished goods and dispatch. Select a diagram step to jump to its explanation.</p>
                </div>
                <a class="access-btn access-btn-primary" href="{{ route('forge.production-flow') }}">Open live production flow</a>
            </div>
        </header>

        <section class="ft-card space-y-3" aria-labelledby="ft-diagram-title">
            <div>
                <h2 id="ft-diagram-title" class="text-xl font-bold">Clickable process diagram</h2>
                <p class="ft-muted text-sm mt-1">The order enters production only for its shortage. Existing eligible finished goods are allocated first.</p>
            </div>
            <nav class="ft-diagram" aria-label="Production process steps">
                <a class="ft-node" href="#ft-order"><strong>1. Customer order</strong><span>Customer, site, FG warehouse and ordered SKU</span></a>
                <a class="ft-node ft-decision" href="#ft-atp"><strong>2. Validate and check stock</strong><span>Release order and run ATP allocation</span></a>
                <a class="ft-node ft-decision" href="#ft-plan"><strong>3. Stock decision</strong><span>Available stock or production shortage?</span></a>
                <a class="ft-node" href="#ft-work-order"><strong>4. Plan and work order</strong><span>Approved recipe, exact SKU and site mapping</span></a>
                <a class="ft-node" href="#ft-materials"><strong>5. RM and PM issue</strong><span>Reserve eligible lots and record handover</span></a>
                <a class="ft-node ft-quality" href="#ft-make"><strong>6. Mix, fill and seal</strong><span>Record operations, output and losses</span></a>
                <a class="ft-node ft-quality" href="#ft-quality"><strong>7. Quality checks</strong><span>Pressure/leak test and classify units</span></a>
                <a class="ft-node" href="#ft-consumption"><strong>8. Consumption & FG records</strong><span>System reconciles BOM and posts accepted FG</span></a>
                <a class="ft-node" href="#ft-fg"><strong>9. FG store and bin</strong><span>Receive the batch and put it away before dispatch packing</span></a>
            </nav>
            <div class="ft-branch" aria-label="Stock decision outcomes">
                <div><strong>Enough eligible FG</strong><p class="ft-muted text-sm mt-1">Allocate stock to the order, then continue to pick and pack.</p></div>
                <div><strong>Shortage remains</strong><p class="ft-muted text-sm mt-1">Create a plan for the missing quantity, produce it, then receive and put it away.</p></div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <strong>After FG is allocated</strong>
                <p class="ft-muted text-sm mt-1">Logistics creates a pick wave, confirms picking, packs and seals handling units, loads a shipment and releases dispatch. Shipment release deducts stock once. Delivery/POD and Finance review follow as separate handoffs.</p>
            </div>
        </section>

        <section class="space-y-3" aria-labelledby="ft-steps-title">
            <div><h2 id="ft-steps-title" class="text-xl font-bold">Step explanations</h2><p class="ft-muted text-sm mt-1">Each step lists its owner, inputs, system checks and expected result.</p></div>

            <article id="ft-order" class="ft-card ft-step">
                <h3>Step 1 — Create a customer order</h3><p class="ft-muted text-sm mt-1">Owner: Store Manager or a user granted customer-order create access.</p>
                <div class="ft-facts"><div><h4>Inputs</h4><p>Customer, destination, site, FG warehouse, requested date, temperature requirement, finished good, quantity and unit of measure (UOM).</p></div><div><h4>System checks</h4><p>The FG warehouse must be active, classified as Finished Goods and assigned to the selected site. The product must be an active, approved finished good with a site mapping.</p></div><div><h4>Result</h4><p>A draft order with order lines that point to the mapped Inventory FG SKU.</p></div></div>
            </article>

            <article id="ft-atp" class="ft-card ft-step">
                <h3>Step 2 — Validate, release and allocate</h3><p class="ft-muted text-sm mt-1">Owner: Store Manager or authorized order releaser.</p>
                <div class="ft-facts"><div><h4>Inputs</h4><p>A valid order header and at least one complete product line.</p></div><div><h4>System checks</h4><p>ATP means Available to Promise. The system checks eligible stock for the exact SKU, site and FG warehouse, allocates existing stock first and calculates any shortage.</p></div><div><h4>Result</h4><p>Allocated order quantity and a production plan for only the unmet demand, if any.</p></div></div>
            </article>

            <article id="ft-plan" class="ft-card ft-step">
                <h3>Step 3 — Decide whether production is needed</h3>
                <div class="ft-facts"><div><h4>Enough stock</h4><p>Order moves to fulfilment. No new production should be created just to duplicate available stock.</p></div><div><h4>Shortage</h4><p>The approved plan target covers the remaining shortage after allocations.</p></div><div><h4>Blocker to check</h4><p>If no plan appears, review order release, product approval, stock allocation and the order-line mapping.</p></div></div>
            </article>

            <article id="ft-work-order" class="ft-card ft-step">
                <h3>Step 4 — Approve the plan and create a work order</h3><p class="ft-muted text-sm mt-1">Owner: Planner or Production Manager.</p>
                <div class="ft-facts"><div><h4>Inputs</h4><p>Approved shortage plan, finished good, recipe or BOM, site and warehouses.</p></div><div><h4>System checks</h4><p>The recipe/BOM must be active, approved and belong to the finished good. Match the Inventory FG SKU, UOM, site, RM warehouse and FG warehouse to the approved mapping.</p></div><div><h4>Result</h4><p>A released work order (WO) for a traceable batch and target quantity.</p></div></div>
            </article>

            <article id="ft-materials" class="ft-card ft-step">
                <h3>Step 5 — Request and issue raw and packaging materials</h3><p class="ft-muted text-sm mt-1">Owner: Production requests; authorized Store Manager or material issuer approves and issues.</p>
                <div class="ft-facts"><div><h4>Inputs</h4><p>Work order, recipe components, required quantities and mapped RM/PM warehouse.</p></div><div><h4>System checks</h4><p>FIFO selects the oldest eligible lot first. Stock is site and warehouse scoped. Record the handover start/end and manpower.</p></div><div><h4>Result</h4><p>Issued material is posted once to the inventory ledger and linked to the WO.</p></div></div>
            </article>

            <article id="ft-make" class="ft-card ft-step">
                <h3>Step 6 — Mix, fill and seal</h3><p class="ft-muted text-sm mt-1">Owner: Mixing and production operators.</p>
                <div class="ft-facts"><div><h4>Inputs</h4><p>Choose manual mixing or a mixer machine, then record one complete PCM mix. Enter one continuous feed quantity for the work order, filling/sealing start and end times, machine mode, output, people, power, idle time, breakdown reasons and recoverable solution collected from a loss.</p></div><div><h4>System checks</h4><p>The mix and solution feed are each recorded once. Liquid loss from mixing is counted in the same order's solution; recovered PCM from filling/sealing is recorded separately for same-order reuse. A pause is downtime, not another pour. Sealing cannot exceed filled output. Recovered solution never enters a warehouse.</p></div><div><h4>Result</h4><p>Round-by-round operation history and production output awaiting approval.</p></div></div>
            </article>

            <article id="ft-quality" class="ft-card ft-step">
                <h3>Step 7 — Complete pressure and leak quality checks</h3><p class="ft-muted text-sm mt-1">Owner: QC / Quality Head for inspection and release.</p>
                <div class="ft-facts"><div><h4>Capture</h4><p>Test start/end time, manpower, accepted quantity, rejected quantity, rework quantity, PCM recovered, pressure, leak and physical check results.</p></div><div><h4>System checks</h4><p>QC accounts for all output. A rework result resumes the same work order for another production and QC round. Recovered mixed PCM remains inside that work order for reuse and never enters a warehouse ledger.</p></div><div><h4>Result</h4><p>Quality release opens the physical bundle-count step; FG stock is not posted yet.</p></div></div>
            </article>

            <article id="ft-consumption" class="ft-card ft-step">
                <h3>Step 8 — Count bundles and record consumption</h3><p class="ft-muted text-sm mt-1">Owner: Production / Store Manager counts physical bundles; the system reconciles the BOM.</p>
                <div class="ft-facts"><div><h4>Capture</h4><p>Real bundle count, units per bundle, loose accepted units, defective disposition, start/end time and people involved. The bundle total must equal QC accepted units.</p></div><div><h4>System checks</h4><p>RM/PM used = approved Inventory BOM quantity per unit × approved output across work-order rounds. Unused issued quantities return to the RM/PM warehouse. If BOM use exceeds issue, bundle confirmation stops before FG posting.</p></div><div><h4>Result</h4><p>One physical pack-out record, consumption audit, and one FG batch containing only accepted units.</p></div></div>
            </article>

            <article id="ft-fg" class="ft-card ft-step">
                <h3>Step 9 — Receive FG into the store bin before dispatch packing</h3><p class="ft-muted text-sm mt-1">Owner: FG Store or a user with receive and putaway access.</p>
                <div class="ft-facts"><div><h4>Capture</h4><p>Suggested master bin, putaway start/end time, and manpower. The system applies FIFO to eligible lots, with expiry-aware FEFO ordering when expiry dates are present.</p></div><div><h4>System checks</h4><p>Bin must be active, approved and in the mapped FG warehouse. The suggestion prefers a bin used previously for the same finished good, exact inventory SKU and site; otherwise it offers the first eligible bin in that warehouse. Putaway must not post FG a second time.</p></div><div><h4>Result</h4><p>Available binned FG lot, allocation to the linked sales order, and the next Logistics/Finance handoffs.</p></div></div>
            </article>

            <article id="ft-dispatch" class="ft-card ft-step">
                <h3>Step 10 — Pick, pack, dispatch and hand off to Finance</h3><p class="ft-muted text-sm mt-1">Owner: Logistics and Dispatch; Finance reviews the resulting handoff.</p>
                <div class="ft-facts"><div><h4>Inputs</h4><p>Allocated order, pick wave, pick tasks, sealed handling units, shipment and transporter details.</p></div><div><h4>System checks</h4><p>Confirm picked and packed quantities. Release the shipment only after loading. Shipment release is the stock deduction point; delivery proof (POD) closes the later delivery step.</p></div><div><h4>Result</h4><p>Shipment moves to transit; delivery/POD and Finance review remain separately traceable.</p></div></div>
            </article>
        </section>

        <section class="ft-card space-y-3" aria-labelledby="ft-access-title">
            <div><h2 id="ft-access-title" class="text-xl font-bold">Role access guide</h2><p class="ft-muted text-sm mt-1">The Stage Store Manager session has the access below. Other actions require their own permission grants and site scope.</p></div>
            <div class="ft-table-wrap"><table><thead><tr><th>Role or module</th><th>Access / responsibility</th><th>Important boundary</th></tr></thead><tbody>
                <tr><td>Store Manager</td><td>Customer orders view/create/release; plans view/create/approve; work orders view/create/release; RM/PM issue; production records/approval; QC and batch actions granted to this Stage role; FG inventory view, receive and putaway.</td><td>FG receive is a temporary user-specific Stage demo grant. It does not change the global Store Manager role. Logistics wave, pick, pack, dispatch, delivery/POD and returns remain separate permissions.</td></tr>
                <tr><td>Planner / Production Manager</td><td>Plan, work-order and production approval; review automatic consumption and FG records.</td><td>Actual access depends on the user's configured permissions and site scope.</td></tr>
                <tr><td>Production Operator</td><td>Material requests, mixing, filling, sealing and job-card records where granted.</td><td>Recording output does not automatically grant release or approval rights.</td></tr>
                <tr><td>Quality role</td><td>IPQC/final QC records, test release and batch trace where granted.</td><td>Quality release gates FG posting; failed tests or open holds stop the batch.</td></tr>
                <tr><td>FG Store / inventory receiver</td><td>Receive a released batch and/or put away an FG lot.</td><td>On Stage, FG receive is granted to the Store Manager role with location scope. Putaway remains a separate permission and is checked against the mapped FG warehouse.</td></tr>
                <tr><td>Logistics / Dispatch</td><td>Waves, picking, packing, shipment loading/release, delivery/POD and returns, when assigned.</td><td>These Flow actions are not granted to the tested Store Manager account.</td></tr>
            </tbody></table></div>
        </section>

        <section class="ft-card space-y-3" aria-labelledby="ft-check-title">
            <div><h2 id="ft-check-title" class="text-xl font-bold">Tester checklist</h2><p class="ft-muted text-sm mt-1">Use Stage-labelled demo records. Keep physical stock and customer data out of synthetic tests.</p></div>
            <div class="ft-checklist">
                <label><input type="checkbox"><span>Selected site and active FG warehouse belong together.</span></label>
                <label><input type="checkbox"><span>Finished good, recipe/BOM and exact FG SKU are approved and mapped.</span></label>
                <label><input type="checkbox"><span>Order quantity, UOM and requested date are accepted.</span></label>
                <label><input type="checkbox"><span>ATP allocates available stock before planning a shortage.</span></label>
                <label><input type="checkbox"><span>RM/PM request and FIFO issue reconcile to the work order.</span></label>
                <label><input type="checkbox"><span>Mixing is recorded before filling and sealing.</span></label>
                <label><input type="checkbox"><span>Production approval precedes final QC.</span></label>
                <label><input type="checkbox"><span>Leak and pressure tests pass before quality release.</span></label>
                <label><input type="checkbox"><span>Automatic consumption reconciles the approved BOM; the FG batch equals QC accepted quantity.</span></label>
                <label><input type="checkbox"><span>FG is received once, then put away in a mapped master bin.</span></label>
                <label><input type="checkbox"><span>Order allocation reflects only eligible binned FG.</span></label>
                <label><input type="checkbox"><span>Dispatch is completed only by a role with logistics access.</span></label>
            </div>
            <p class="text-sm"><strong>When a dropdown is empty:</strong> check active status, approval status, selected site, warehouse type, exact SKU mapping and role scope. A Central Distribution warehouse is not an FG warehouse.</p>
        </section>

        <section class="ft-card" aria-labelledby="ft-glossary-title">
            <h2 id="ft-glossary-title" class="text-xl font-bold">Glossary</h2>
            <div class="ft-table-wrap mt-3"><table><thead><tr><th>Term</th><th>Meaning</th></tr></thead><tbody>
                <tr><td>ATP</td><td>Available to Promise — stock the ERP can allocate to a customer order now.</td></tr>
                <tr><td>Allocation</td><td>Quantity reserved for an order line; it is not the same as shipment or delivery.</td></tr>
                <tr><td>BOM</td><td>Bill of Materials — components and quantities needed to make a product.</td></tr>
                <tr><td>CAPA</td><td>Corrective and Preventive Action — fixes for a defect and actions to reduce recurrence.</td></tr>
                <tr><td>Continuous feed</td><td>The prepared PCM solution enters the filling line once as a continuous flow. A pause is recorded as downtime, not as another pour.</td></tr>
                <tr><td>FEFO</td><td>First Expired, First Out — allocate the eligible lot that expires soonest. This remains the rule for non-PCM finished goods.</td></tr>
                <tr><td>FIFO</td><td>First In, First Out — issue the oldest eligible material lot first. PCM finished-good lots also use FIFO while keeping expiry and quality checks.</td></tr>
                <tr><td>FG</td><td>Finished Goods — saleable output after required production and quality checks.</td></tr>
                <tr><td>FG lot</td><td>Traceable inventory quantity from a finished batch, including its warehouse/bin and quality status.</td></tr>
                <tr><td>IPQC</td><td>In-Process Quality Control — quality checkpoints during the manufacturing process.</td></tr>
                <tr><td>Master bin</td><td>Approved storage location used to find a lot inside a warehouse.</td></tr>
                <tr><td>OEE</td><td>Overall Equipment Effectiveness — a measure of equipment availability, performance and quality.</td></tr>
                <tr><td>POD</td><td>Proof of Delivery — evidence that the customer received the shipment.</td></tr>
                <tr><td>PM / RM</td><td>Packaging Material / Raw Material — packaging inputs and ingredients/process inputs.</td></tr>
                <tr><td>PCM</td><td>Phase Change Material — the prepared thermal solution used in this product. Mixed or QC-recovered PCM stays inside its work order and is not stored in a warehouse.</td></tr>
                <tr><td>QC</td><td>Quality Control — inspection and accept/reject decision for production output.</td></tr>
                <tr><td>SKU</td><td>Stock Keeping Unit — the inventory code used to track one item.</td></tr>
                <tr><td>UOM</td><td>Unit of Measure — for example EA, KG or L. Units must match across order, recipe and inventory.</td></tr>
                <tr><td>WO</td><td>Work Order — approved instruction and record for making a target quantity.</td></tr>
                <tr><td>Wave</td><td>A grouped set of order lines and pick tasks prepared for warehouse picking.</td></tr>
            </tbody></table></div>
        </section>
    </main>
</x-app-layout>
