<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GrnPostingService already knew qc_hold_qty (from QC's split) but never
 * recorded its value anywhere on the FinanceRecord it creates -- the
 * displayed "Invoice − Deductions" never reconciled with "Final Payable"
 * whenever a delivery had any held quantity, since that value vanished
 * with no line item. Confirmed live: a 700-unit delivery split into 600
 * accepted / 50 hold / 30 defective / 20 rejected showed Invoice ₹29,400,
 * Deductions ₹2,100 (defective+rejected only), Final Payable ₹25,200 --
 * 29,400 − 2,100 = 27,300, not 25,200. The missing ₹2,100 was the 50 held
 * units' value (50 × ₹42), computed into final_payable correctly but
 * never surfaced as a deduction line for Finance to audit against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_records', function (Blueprint $table) {
            $table->decimal('deduction_hold', 14, 2)->default(0)->after('deduction_missing');
        });
    }

    public function down(): void
    {
        Schema::table('finance_records', function (Blueprint $table) {
            $table->dropColumn('deduction_hold');
        });
    }
};
