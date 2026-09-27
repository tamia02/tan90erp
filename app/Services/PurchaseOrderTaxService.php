<?php

namespace App\Services;

use App\Models\Tan90\MasterData\HsnTaxRule;
use App\Models\Tan90\MasterData\LegalEntity;

class PurchaseOrderTaxService
{
    private const STATE_CODES = [
        'JAMMU AND KASHMIR' => '01', 'HIMACHAL PRADESH' => '02', 'PUNJAB' => '03',
        'CHANDIGARH' => '04', 'UTTARAKHAND' => '05', 'HARYANA' => '06',
        'DELHI' => '07', 'RAJASTHAN' => '08', 'UTTAR PRADESH' => '09',
        'BIHAR' => '10', 'SIKKIM' => '11', 'ARUNACHAL PRADESH' => '12',
        'NAGALAND' => '13', 'MANIPUR' => '14', 'MIZORAM' => '15',
        'TRIPURA' => '16', 'MEGHALAYA' => '17', 'ASSAM' => '18',
        'WEST BENGAL' => '19', 'JHARKHAND' => '20', 'ODISHA' => '21',
        'CHHATTISGARH' => '22', 'MADHYA PRADESH' => '23', 'GUJARAT' => '24',
        'DAMAN AND DIU' => '25', 'DADRA AND NAGAR HAVELI' => '26',
        'MAHARASHTRA' => '27', 'ANDHRA PRADESH' => '37', 'KARNATAKA' => '29',
        'GOA' => '30', 'LAKSHADWEEP' => '31', 'KERALA' => '32',
        'TAMIL NADU' => '33', 'PUDUCHERRY' => '34', 'ANDAMAN AND NICOBAR ISLANDS' => '35',
        'TELANGANA' => '36', 'LADAKH' => '38',
    ];

    /** Common GST/state abbreviations accepted in PO address forms. */
    private const STATE_ALIASES = [
        'AN' => '35', 'AP' => '37', 'AR' => '12', 'AS' => '18', 'BR' => '10',
        'CG' => '22', 'CH' => '04', 'DD' => '25', 'DL' => '07', 'DN' => '26',
        'GA' => '30', 'GJ' => '24', 'HP' => '02', 'HR' => '06', 'JH' => '20',
        'JK' => '01', 'KA' => '29', 'KL' => '32', 'LA' => '38', 'LD' => '31',
        'MH' => '27', 'ML' => '17', 'MN' => '14', 'MP' => '23', 'MZ' => '15',
        'NL' => '13', 'OD' => '21', 'OR' => '21', 'PB' => '03', 'PY' => '34',
        'RJ' => '08', 'SK' => '11', 'TN' => '33', 'TR' => '16', 'TS' => '36',
        'UK' => '05', 'UP' => '09', 'WB' => '19',
    ];

    public function companyState(): ?string
    {
        $entity = LegalEntity::query()
            ->where('status', 'active')
            ->where('approval_status', 'approved')
            ->whereNotNull('gstin')
            ->orderBy('id')
            ->first();

        return $entity?->state ?: $this->stateFromGstin($entity?->gstin);
    }

    public function rateForHsn(?string $hsn): ?float
    {
        $hsn = trim((string) $hsn);
        if ($hsn === '') {
            return null;
        }

        $rate = HsnTaxRule::query()
            ->where('hsn', $hsn)
            ->where('status', 'active')
            ->where('approval_status', 'approved')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->orderByDesc('effective_from')
            ->value('gst_rate');

        return $rate === null ? null : (float) rtrim((string) $rate, '%');
    }

    /** @return array{taxable: float, cgst: float, sgst: float, igst: float, rate: float, place_of_supply: ?string} */
    public function breakdown(float $gross, float $discount, float $rate, ?string $placeOfSupply, bool $reverseCharge = false): array
    {
        $taxable = max(round($gross - $discount, 2), 0);
        $empty = ['taxable' => $taxable, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'rate' => $rate, 'place_of_supply' => $placeOfSupply];
        if ($reverseCharge || $rate <= 0) {
            return $empty;
        }

        $companyCode = $this->stateCode($this->companyState());
        $placeCode = $this->stateCode($placeOfSupply);
        if (! $companyCode || ! $placeCode) {
            return $empty;
        }

        $tax = round($taxable * $rate / 100, 2);
        if ($companyCode === $placeCode) {
            $half = round($tax / 2, 2);

            return array_merge($empty, ['cgst' => $half, 'sgst' => round($tax - $half, 2)]);
        }

        return array_merge($empty, ['igst' => $tax]);
    }

    public function stateCode(?string $state): ?string
    {
        $state = strtoupper(trim((string) $state));
        if ($state === '') {
            return null;
        }

        if (preg_match('/^\\d{2}$/', $state)) {
            return $state;
        }

        return self::STATE_CODES[$state] ?? self::STATE_ALIASES[$state] ?? null;
    }

    private function stateFromGstin(?string $gstin): ?string
    {
        $prefix = substr((string) $gstin, 0, 2);

        return preg_match('/^\\d{2}$/', $prefix) ? $prefix : null;
    }
}
