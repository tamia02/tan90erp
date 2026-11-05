<form method="post" action="{{ route('forge.workorders.operations.start',['workOrder'=>$wo,'operation'=>$startOperation??$operation]) }}" class="pw-actions">@csrf
@if(isset($contextId))<input type="hidden" name="context_id" value="{{ $contextId }}">@endif
<x-production-field name="manpower" label="People" min="1" step="1" :required="true" />
<button class="pw-btn pw-primary">Start {{ \App\Services\Forge\WorkerProduction::OPERATIONS[$startOperation??$operation][2] }}</button>
</form>
