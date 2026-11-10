@extends('layouts.app')
@section('title', $id.' · VSahha Provider Portal')
@section('content')
<x-card>
  <div class="empty" style="padding:40px 20px">
    <span class="empty-i t-green" style="width:64px;height:64px">{{ icon('check', 30) }}</span>
    <b style="font-size:20px">{{ t('Claim {id} received by VSahha', ['id' => $id]) }}</b>
    <p>{{ t('VSahha has received it. You will be told here when VSahha decides, asks for information, or pays.') }}</p>
    @if ($unknown)<div class="note note-amber">{{ icon('stetho') }}<div>{{ t('Filed as Unknown Illness with {code}. The VSahha medical team will review the diagnosis.', ['code' => $unknown]) }}</div></div>@endif
    <div class="row" style="justify-content:center">
      <a class="btn btn-p" href="/claims/{{ rawurlencode($id) }}">{{ t('Open the claim') }}</a>
      <button type="button" class="btn btn-s" data-doc="/claims/{{ rawurlencode($id) }}/acknowledgement" data-doc-title="{{ t('Acknowledgement {id}', ['id' => $id]) }}">{{ icon('receipt', 17) }}{{ t('Acknowledgement') }}</button>
      <a class="btn btn-s" href="/claims/new">{{ t('New claim') }}</a>
    </div>
  </div>
</x-card>
@endsection
