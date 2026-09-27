<?php

use App\Enums\Role;

/* Legacy Tan90 portal contract: routes are the hard boundary; this catalogue
   drives button/export decisions and documents each role's data scope. */
return [
    Role::Guard->value => ['screens' => ['guard.dashboard', 'guard.scan', 'guard.entries'], 'actions' => ['gate.create', 'gate.view'], 'exports' => ['gate_entries_masked'], 'scope' => 'own gate entries; PO masked except final digit'],
    Role::Vendor->value => ['screens' => ['vendor.dashboard', 'vendor.purchase-orders', 'vendor.submissions', 'vendor.grn-report', 'vendor.claims', 'vendor.stock'], 'actions' => ['po.accept', 'po.reject', 'submission.create', 'grn.accept', 'grn.escalate', 'cn_dn.upload'], 'exports' => ['own_po', 'own_grn'], 'scope' => 'own vendor POs, submissions and GRNs only'],
    Role::StoreExec->value => ['screens' => ['unloading.dashboard', 'gate-entries.index', 'unloading.desk', 'unloading.putaway', 'unloading.dock-scheduling', 'unloading.history'], 'actions' => ['dock.assign', 'unloading.start', 'unloading.complete', 'putaway.confirm'], 'exports' => ['gate_entries', 'grn'], 'scope' => 'facility operational records'],
    Role::Qc->value => ['screens' => ['qc.dashboard', 'gate-entries.index', 'qc.queue', 'qc.history', 'qc.holds'], 'actions' => ['qc.record', 'qc.hold.resolve'], 'exports' => ['gate_entries', 'qc_history'], 'scope' => 'facility QC queue and evidence'],
    Role::StoreManager->value => ['screens' => ['grn.dashboard', 'grn.po-control-tower', 'gate-entries.index', 'store-manager.entry-approvals', 'admin.po', 'grn.check', 'grn.register', 'grn.stock-balance', 'grn.ledger', 'grn.ledger-history', 'grn.bins'], 'actions' => ['po.create', 'po.release', 'entry.approve', 'grn.post', 'stock.export', 'bin.transfer'], 'exports' => ['po', 'gate_entries', 'grn', 'stock_ledger', 'stock_updates'], 'scope' => 'full procurement and warehouse facility records'],
    Role::Finance->value => ['screens' => ['finance.dashboard', 'gate-entries.index', 'finance.review', 'finance.claims', 'finance.reports'], 'actions' => ['payable.review', 'claim.review'], 'exports' => ['finance', 'grn'], 'scope' => 'financial records and payable basis'],
    Role::Admin->value => ['screens' => ['*'], 'actions' => ['*'], 'exports' => ['*'], 'scope' => 'all authorized operational records'],
];
