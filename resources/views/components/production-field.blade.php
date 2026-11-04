@props(['name','label','type'=>'number','value'=>null,'required'=>false,'min'=>'0','step'=>'0.001'])
@php($errorKey=str_replace(['[',']'],['.',''],$name))
<label class="pw-field">{{ $label }}<input class="pw-input" type="{{ $type }}" name="{{ $name }}" value="{{ old($errorKey,$value) }}" @if($type==='number') min="{{ $min }}" step="{{ $step }}" @endif @if($type==='datetime-local')step="1"@endif @required($required) {{ $attributes }}>@if(isset($errors) && $errors->has($errorKey))<span class="pw-field-error">{{ $errors->first($errorKey) }}</span>@endif</label>
