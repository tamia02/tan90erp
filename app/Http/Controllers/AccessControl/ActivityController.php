<?php

namespace App\Http\Controllers\AccessControl;

use App\Http\Controllers\Controller;
use App\Models\Access\AccessAuditLog;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    private const HIDDEN_FIELDS = ['id', 'created_at', 'updated_at', 'password', 'remember_token'];

    public function __construct(private AccessControlService $access) {}

    public function index(Request $request)
    {
        abort_unless($this->access->can($request->user(), 'access.activity.view'), 403);
        $query = AccessAuditLog::latest('created_at');
        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->string('action').'%');
        }

        $logs = $query->paginate(20)->withQueryString();

        $actors = User::whereIn('id', $logs->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return view('access-control.activity', ['logs' => $logs, 'actors' => $actors]);
    }

    public function show(Request $request, AccessAuditLog $log)
    {
        abort_unless($this->access->can($request->user(), 'access.activity.view'), 403);

        $target = $this->resolveTarget($log);

        return view('access-control.activity-detail', [
            'log' => $log,
            'actorName' => $log->actor_id ? User::find($log->actor_id)?->name : null,
            'targetLabel' => $log->target_type ? str(class_basename($log->target_type))->headline()->toString() : null,
            'targetFields' => $target ? $this->describe($target) : [],
            'beforeFields' => $log->before_json ? $this->describeArray($log->before_json) : [],
            'afterFields' => $log->after_json ? $this->describeArray($log->after_json) : [],
        ]);
    }

    private function resolveTarget(AccessAuditLog $log): ?Model
    {
        if (! $log->target_type || ! $log->target_id || ! class_exists($log->target_type)) {
            return null;
        }

        return $log->target_type::find($log->target_id);
    }

    /** @return array<int, array{label: string, value: string}> */
    private function describe(Model $target): array
    {
        return $this->describeArray($target->toArray());
    }

    /** @return array<int, array{label: string, value: string}> */
    private function describeArray(array $data): array
    {
        return collect($data)
            ->except(self::HIDDEN_FIELDS)
            ->map(fn ($value, $key) => [
                'label' => str($key)->replace('_', ' ')->headline()->toString(),
                'value' => $this->formatValue($value),
            ])
            ->values()
            ->all();
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return empty($value) ? '—' : json_encode($value);
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $value)) {
            return \Illuminate\Support\Carbon::parse($value)->format('d M Y, H:i');
        }

        return (string) $value;
    }
}
