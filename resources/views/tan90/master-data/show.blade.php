@extends('tan90.master-data.layout')

@section('title', $record->{$config['primary']} ?? $config['singular'])
@section('page-title', $config['singular'] . ' Detail')
@section('page-subtitle', $record->{$config['code']} ?? '')

@section('content')
  <div class="page-head">
    <div class="page-title">
      <p class="eyebrow">{{ $config['title'] }} / Detail</p>
      <h2>{{ $record->{$config['primary']} ?? $config['singular'] }}</h2>
      <p class="code">{{ $record->{$config['code']} ?? '' }}</p>
    </div>
    <div class="page-actions">
      <a class="btn btn-ghost" href="{{ route('tan90.master-data.index', $entity) }}">← Back</a>

      @can('update', $record)
        @if (! empty($config['fields']) && $record->status !== 'archived')
          <a class="btn btn-secondary" href="{{ route('tan90.master-data.edit', [$entity, $record->id]) }}">Edit</a>
        @endif
      @endcan

      @if (empty($config['no_approval']))
        @can('submit', $record)
          @if (in_array($record->approval_status, ['draft', 'rejected']))
            <form method="POST" action="{{ route('tan90.master-data.submit', [$entity, $record->id]) }}">
              @csrf <button class="btn btn-secondary" type="submit">Submit for Approval</button>
            </form>
          @endif
        @endcan
        @can('approve', $record)
          @if (in_array($record->approval_status, ['review', 'pending']))
            <form method="POST" action="{{ route('tan90.master-data.approve', [$entity, $record->id]) }}">
              @csrf <button class="btn btn-success" type="submit">Approve</button>
            </form>
            <form method="POST" action="{{ route('tan90.master-data.reject', [$entity, $record->id]) }}">
              @csrf <button class="btn btn-danger" type="submit">Reject</button>
            </form>
          @endif
        @endcan
      @endif

      @if (isset($record->gstin))
        @can('update', $record)
          <form method="POST" action="{{ route('tan90.master-data.verify-gst', [$entity, $record->id]) }}">
            @csrf <button class="btn btn-ghost" type="submit">Verify GST</button>
          </form>
        @endcan
      @endif

      @if ($entity === 'integration-connections')
        @can('settings', $config['model'])
          <form method="POST" action="{{ route('tan90.master-data.integration-connections.test', $record->id) }}">
            @csrf <button class="btn btn-ghost" type="submit">Test Connection</button>
          </form>
        @endcan
      @endif

      @if ($entity === 'data-quality-rules')
        <a class="btn btn-ghost" href="{{ route('tan90.master-data.data-quality.index') }}">View Detected Issues</a>
      @endif

      @if ($record->status === 'archived')
        @can('restore', $record)
          @if (empty($config['no_soft_delete']))
            <form method="POST" action="{{ route('tan90.master-data.restore', [$entity, $record->id]) }}">
              @csrf <button class="btn btn-success" type="submit">Restore</button>
            </form>
          @endif
        @endcan
      @else
        @can('delete', $record)
          <form method="POST" action="{{ route('tan90.master-data.destroy', [$entity, $record->id]) }}" data-confirm="{{ empty($config['no_soft_delete']) ? 'Archive this record?' : 'Delete this record? This cannot be undone.' }}">
            @csrf @method('DELETE')
            <button class="btn btn-danger" type="submit">{{ empty($config['no_soft_delete']) ? 'Archive' : 'Delete' }}</button>
          </form>
        @endcan
      @endif
    </div>
  </div>

  @if ($missingDocuments && in_array($record->approval_status, ['draft', 'rejected']))
    <div class="card" style="margin-bottom:14px;padding:12px 16px;border-left:3px solid var(--warning)">
      Submission is blocked until these required documents are uploaded: <strong>{{ implode(', ', $missingDocuments) }}</strong>.
    </div>
  @endif

  @if ($workflowProgress && $workflowProgress->tan90_approval_workflow_id && $workflowProgress->status === 'pending')
    <section class="card" style="margin-bottom:14px">
      <div class="card-head"><div><h3>{{ $workflowProgress->workflow->name }}</h3><p>Multi-step approval in progress</p></div></div>
      <div class="card-body">
        <div class="tabs">
          @foreach ($workflowSteps as $step)
            <span class="tab {{ $step->step_order === $workflowProgress->current_step_order ? 'active' : '' }}">
              {{ $step->step_order }}. {{ $step->step_role }}
              {{ $step->step_order < $workflowProgress->current_step_order ? ' ✓' : '' }}
            </span>
          @endforeach
        </div>
        <p style="color:var(--muted);font-size:11px;margin:0">
          Awaiting approval from the <strong>{{ $workflowProgress->currentStep()?->step_role ?? 'next' }}</strong> role.
          Any approver may act if that role isn't provisioned in this system yet.
        </p>
      </div>
    </section>
  @endif

  <div class="detail-layout">
    <section class="card">
      <div class="card-head">
        <div><h3>Record Summary</h3><p>Current values</p></div>
        @include('tan90.master-data.partials.status-badge', ['value' => $record->approval_status ?? $record->status])
      </div>
      <div class="card-body">
        <div class="detail-summary">
          @foreach ($record->getAttributes() as $key => $value)
            @continue(in_array($key, ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by']))
            <div class="detail-field">
              <span>{{ Str::title(str_replace('_', ' ', preg_replace('/_id$/', '', $key))) }}</span>
              <strong>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : ($value ?? '—') }}</strong>
            </div>
          @endforeach
        </div>
      </div>
    </section>
    <aside class="card">
      <div class="card-head"><div><h3>Control Information</h3><p>Governance and audit metadata</p></div></div>
      <div class="card-body">
        <div class="timeline">
          <div class="timeline-item"><span class="timeline-dot"></span><h4>Created</h4><p>{{ $record->created_at?->format('d M Y, H:i') }}</p></div>
          <div class="timeline-item"><span class="timeline-dot"></span><h4>Last Updated</h4><p>{{ $record->updated_at?->format('d M Y, H:i') }}<br>Status: {{ Str::title($record->approval_status ?? $record->status) }}</p></div>
          @if (isset($record->version))
            <div class="timeline-item"><span class="timeline-dot"></span><h4>Version</h4><p>v{{ $record->version }}</p></div>
          @endif
        </div>
      </div>
    </aside>
  </div>

  <section class="grid grid-2" style="margin-top:15px">
    <article class="card">
      <div class="card-head"><div><h3>Change Requests</h3><p>Critical-field changes pending or applied</p></div></div>
      <div class="card-body">
        @forelse ($changeRequests as $cr)
          <a class="nav-item" href="{{ route('tan90.master-data.change-requests.show', $cr->id) }}">
            <span class="nav-label">{{ $cr->request_no }} · {{ implode(', ', array_keys($cr->proposed_changes)) }}</span>
            @include('tan90.master-data.partials.status-badge', ['value' => $cr->approval_status])
          </a>
        @empty
          <div class="empty-state"><div><div class="empty-icon">↔</div><h3>No change requests</h3><p>Critical-field edits will appear here once submitted.</p></div></div>
        @endforelse
      </div>
    </article>
    <article class="card">
      <div class="card-head"><div><h3>Record Audit</h3><p>Immutable history for this record</p></div></div>
      <div class="card-body">
        @if ($auditTrail->count())
          <div class="timeline">
            @foreach ($auditTrail as $log)
              <div class="timeline-item">
                <span class="timeline-dot"></span>
                <h4>{{ $log->event }} · {{ $log->user?->name ?? 'System' }}</h4>
                <p>{{ $log->occurred_at?->format('d M Y, H:i') }}<br>{{ $log->summary }}</p>
              </div>
            @endforeach
          </div>
        @else
          <div class="empty-state"><div><div class="empty-icon">AU</div><h3>No audit events</h3><p>A new entry will appear on the next change.</p></div></div>
        @endif
      </div>
    </article>
  </section>

  <section class="card" style="margin-top:15px">
    <div class="card-head">
      <div><h3>Attachments</h3><p>{{ $documentRule ? 'Required by ' . $documentRule->name : 'Supporting documents for this record' }}</p></div>
    </div>
    <div class="card-body">
      @if ($documentRule)
        <div class="chip" style="margin-bottom:12px">
          <span>Mandatory:</span> <strong>{{ implode(', ', $documentRule->mandatoryLabels()) ?: 'None' }}</strong>
        </div>
      @endif

      @can('update', $record)
        <form method="POST" action="{{ route('tan90.master-data.attachments.store', [$entity, $record->id]) }}" enctype="multipart/form-data" class="form-grid" style="margin-bottom:16px">
          @csrf
          <label class="field">
            <span class="field-label">Document Label</span>
            @if ($documentRule && $documentRule->mandatoryLabels())
              <select name="document_label" required>
                <option value="">Select document type</option>
                @foreach (array_merge($documentRule->mandatoryLabels(), $documentRule->optionalLabels()) as $label)
                  <option value="{{ $label }}">{{ $label }}</option>
                @endforeach
              </select>
            @else
              <input type="text" name="document_label" placeholder="e.g. GST Certificate" required>
            @endif
          </label>
          <label class="field">
            <span class="field-label">File</span>
            <input type="file" name="file" required>
          </label>
          <div class="full">
            <button class="btn btn-secondary" type="submit">Upload</button>
          </div>
        </form>
      @endcan

      @forelse ($attachments as $attachment)
        <div class="mobile-record-field" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div>
            <strong>{{ $attachment->document_label }}</strong>
            <span style="color:var(--muted);font-size:10px"> · {{ $attachment->original_filename }} · {{ $attachment->uploader?->name }} · {{ $attachment->created_at->format('d M Y') }}</span>
          </div>
          @can('update', $record)
            <form method="POST" action="{{ route('tan90.master-data.attachments.destroy', $attachment->id) }}" data-confirm="Remove this attachment?">
              @csrf @method('DELETE')
              <button class="btn btn-sm btn-danger" type="submit">Remove</button>
            </form>
          @endcan
        </div>
      @empty
        <div class="empty-state"><div><div class="empty-icon">📎</div><h3>No attachments yet</h3></div></div>
      @endforelse
    </div>
  </section>
@endsection
