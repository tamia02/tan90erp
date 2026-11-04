<?php

namespace App\Http\Controllers\Tan90\MasterData;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExternalMasterDataController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $kind = $request->string('kind', 'addresses')->value();
        abort_unless(in_array($kind, ['addresses', 'contacts'], true), 404);
        $table = $kind === 'addresses' ? 'tan90_master_addresses' : 'tan90_master_contact_people';
        $query = DB::table($table);

        if ($request->filled('q')) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->string('q')->value()).'%';
            $query->where(function ($filter) use ($term, $kind): void {
                $filter->where('partner_name', 'like', $term);
                if ($kind === 'contacts') {
                    $filter->orWhere('display_name', 'like', $term)->orWhere('email', 'like', $term);
                } else {
                    $filter->orWhere('city', 'like', $term)->orWhere('state', 'like', $term)->orWhere('postal_code', 'like', $term);
                }
            });
        }

        $records = $query->orderBy('partner_name')->orderBy('id')->paginate(50)->withQueryString();
        $summary = DB::table('tan90_master_source_rows')
            ->select('source_file', 'dataset', DB::raw('COUNT(*) as total'), DB::raw('SUM(canonical_id IS NOT NULL) as linked'))
            ->groupBy('source_file', 'dataset')->orderBy('source_file')->get();

        return view('tan90.master-data.source-data', compact('kind', 'records', 'summary'));
    }
}
