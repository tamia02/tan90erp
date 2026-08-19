<?php

namespace App\Console\Commands\Tan90\MasterData;

use App\Models\Tan90\MasterData\ApprovalProgress;
use App\Models\Tan90\MasterData\MasterAuditLog;
use App\Models\Tan90\MasterData\MasterChangeRequest;
use App\Models\Tan90\MasterData\SlaPolicy;
use App\Services\Tan90\MasterData\AuditLogger;
use App\Services\Tan90\MasterData\EntityRegistry;
use App\Services\Tan90\MasterData\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * `php artisan tan90:check-sla-breaches` - registered hourly by
 * MasterDataServiceProvider::boot() via the injected Schedule contract (no
 * edit to routes/console.php needed). Walks every pending ApprovalProgress
 * and pending/review MasterChangeRequest, matches it to a tan90_sla_policies
 * row by a loose text match on `applies_to`, and fires a one-time SLA_WARNING
 * / SLA_ESCALATE audit + notification per threshold crossed
 * (sla_warned_at/sla_escalated_at prevent re-firing every run).
 */
class CheckSlaBreaches extends Command
{
    protected $signature = 'tan90:check-sla-breaches';

    protected $description = 'Check pending Tan90 master-data approvals and change requests against configured SLA policies.';

    public function handle(EntityRegistry $registry, AuditLogger $auditLogger, NotificationDispatcher $notifications): int
    {
        $warned = 0;
        $escalated = 0;

        ApprovalProgress::where('status', 'pending')->get()->each(function (ApprovalProgress $progress) use ($registry, $auditLogger, $notifications, &$warned, &$escalated) {
            if (! $registry->has($progress->entity_type)) {
                return;
            }

            $entity = $registry->get($progress->entity_type);
            $policy = $this->matchPolicy($entity['title']);
            if (! $policy) {
                return;
            }

            // abs() because Carbon's diffInMinutes() returns a signed value here, and
            // now()->diffInMinutes($pastDate) comes back negative — without it, elapsed
            // time versus a threshold like ">= 1 hour" was always false for anything
            // actually in the past, so no warning/escalation could ever fire.
            $elapsedHours = abs(now()->diffInMinutes($progress->submitted_at)) / 60;
            $label = "{$entity['title']} #{$progress->entity_id}";

            if (! $progress->sla_warned_at && $policy->warningAtHours() !== null && $elapsedHours >= $policy->warningAtHours()) {
                $auditLogger->logSystem('SLA_WARNING', 'SLA Monitoring', "{$label} is past the {$policy->name} warning threshold ({$policy->warning_at}).");
                $notifications->sendToRole($policy->escalation_role ?? '', 'NT-SLA-EXPIRY', [
                    'record_code' => $label,
                    'summary' => "{$label} has been pending approval past the {$policy->name} warning threshold.",
                ]);
                $progress->update(['sla_warned_at' => now()]);
                $warned++;
            }

            if (! $progress->sla_escalated_at && $policy->escalateAtHours() !== null && $elapsedHours >= $policy->escalateAtHours()) {
                $auditLogger->logSystem('SLA_ESCALATE', 'SLA Monitoring', "{$label} breached the {$policy->name} SLA ({$policy->target}) and was escalated to {$policy->escalation_role}.");
                $notifications->sendToRole($policy->escalation_role ?? '', 'NT-SLA-EXPIRY', [
                    'record_code' => $label,
                    'summary' => "{$label} has breached its SLA and needs immediate attention.",
                ]);
                $progress->update(['sla_escalated_at' => now()]);
                $escalated++;
            }
        });

        $changePolicy = SlaPolicy::where('code', 'SLA-CRITICAL-CHANGE')->where('status', 'active')->first();
        if ($changePolicy) {
            MasterChangeRequest::whereIn('approval_status', ['pending', 'review'])->get()->each(function (MasterChangeRequest $cr) use ($changePolicy, $auditLogger, $notifications, &$warned, &$escalated) {
                $elapsedHours = abs(now()->diffInMinutes($cr->created_at)) / 60;

                if ($changePolicy->escalateAtHours() !== null && $elapsedHours >= $changePolicy->escalateAtHours()) {
                    // Change requests have no warned/escalated tracking column of their own;
                    // re-checking the audit log keeps this idempotent per request.
                    $alreadyLogged = MasterAuditLog::where('event', 'SLA_ESCALATE')
                        ->where('summary', 'like', "%{$cr->request_no}%")
                        ->exists();
                    if ($alreadyLogged) {
                        return;
                    }

                    $auditLogger->logSystem('SLA_ESCALATE', 'SLA Monitoring', "Change request {$cr->request_no} breached the {$changePolicy->name} SLA and was escalated to {$changePolicy->escalation_role}.");
                    $notifications->sendToRole($changePolicy->escalation_role ?? '', 'NT-SLA-EXPIRY', [
                        'record_code' => $cr->request_no,
                        'summary' => "Change request {$cr->request_no} has breached its SLA and needs immediate attention.",
                    ]);
                    $escalated++;
                }
            });
        }

        $this->info("SLA check complete: {$warned} newly warned, {$escalated} newly escalated.");

        return self::SUCCESS;
    }

    private function matchPolicy(string $entityTitle): ?SlaPolicy
    {
        // Word-overlap match rather than a straight substring check in either
        // direction: entity title "Vendor Master" and a policy's applies_to
        // "Vendor onboarding" don't contain each other as substrings, but they
        // share the significant word "vendor". Falls back to the SLA-MASTER-NEW
        // catch-all (matches the demo's "All new master records" policy) when
        // nothing more specific applies.
        $titleWords = $this->significantWords($entityTitle);

        $policy = SlaPolicy::where('status', 'active')->where('code', '!=', 'SLA-MASTER-NEW')->get()
            ->first(fn (SlaPolicy $p) => array_intersect($titleWords, $this->significantWords((string) $p->applies_to)));

        return $policy ?? SlaPolicy::where('code', 'SLA-MASTER-NEW')->where('status', 'active')->first();
    }

    /** @return string[] lowercase words longer than 3 chars, for a loose free-text match */
    private function significantWords(string $text): array
    {
        return collect(preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [])
            ->filter(fn ($word) => strlen($word) > 3)
            ->values()
            ->all();
    }
}
