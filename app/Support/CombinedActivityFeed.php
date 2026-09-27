<?php

namespace App\Support;

use App\Models\Access\AccessAuditLog;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Collection;

// A role's "Activity" used to mean two disconnected things: the module's own
// business events (gate entries, GRN posting, vendor submissions - AuditLogEntry)
// and, separately, Access Control's governance events (role assigned, permission
// overridden - AccessAuditLog) surfaced only via a standalone sidebar link. Client
// asked for one combined feed per role instead of two places to look.
class CombinedActivityFeed
{
    /**
     * @param  array<int, string>  $keywords  Matches against AuditLogEntry action/detail. Empty means no filter (everything matches).
     * @param  (callable(AuditLogEntry): bool)|null  $businessFilter  Extra per-row filter (e.g. vendor ownership).
     * @param  bool  $allGovernance  Show every Access Control governance event instead of only ones tied to this user (Admin view).
     * @return Collection<int, array{created_at: \Illuminate\Support\Carbon, title: string, detail: ?string, url: string}>
     */
    public static function forUser(User $user, array $keywords, ?callable $businessFilter = null, int $limit = 200, bool $allGovernance = false): Collection
    {
        $business = AuditLogEntry::with('subject')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($keywords) {
            $business = $business->filter(fn ($row) => collect($keywords)->contains(
                fn ($keyword) => str_contains($row->action, $keyword) || str_contains((string) $row->detail, $keyword)
            ));
        }

        if ($businessFilter) {
            $business = $business->filter($businessFilter);
        }

        $business = $business->map(fn ($row) => [
            'created_at' => $row->created_at,
            'title' => $row->action,
            'detail' => $row->detail,
            'url' => route('activity.detail', $row),
        ]);

        $governanceQuery = AccessAuditLog::query();
        if (! $allGovernance) {
            $governanceQuery->where(function ($query) use ($user) {
                $query->where('actor_id', $user->id)
                    ->orWhere(function ($query) use ($user) {
                        $query->where('target_type', User::class)->where('target_id', $user->id);
                    });
            });
        }

        $governance = $governanceQuery->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => [
                'created_at' => $log->created_at,
                'title' => str($log->action)->replace(['.', '_'], ' ')->headline()->toString(),
                'detail' => $log->reason,
                'url' => route('access.activity.show', $log),
            ]);

        return $business->concat($governance)->sortByDesc('created_at')->values();
    }
}
