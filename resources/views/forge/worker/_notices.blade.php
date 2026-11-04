@if(session('status'))<div class="pw-notice" role="status">{{ session('status') }}</div>@endif
@if(isset($errors) && $errors->any())<div class="pw-error" role="alert"><strong>Check the highlighted fields.</strong><ul class="list-disc pl-5 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
