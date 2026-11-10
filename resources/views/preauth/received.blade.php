@extends('layouts.app')
@section('title', t('Pre-authorizations').' · VSahha Provider Portal')
@section('content')
<x-card><div class="empty" style="padding:40px 20px">
  <span class="empty-i t-green" style="width:64px;height:64px">{{ icon('check', 30) }}</span>
  <b style="font-size:20px">{{ t('{r} is with the VSahha medical team', ['r' => $done['ref'] ?? $id]) }}</b>
  @if ($done)<p>{{ t('Cover, benefits and authority were checked: {o}. You will be notified when a decision or a question arrives.', ['o' => t($done['outcome'] ?? '')]) }}</p>@endif
  <div class="row" style="justify-content:center"><a class="btn btn-p" href="/preauth/{{ rawurlencode($id) }}">{{ t('Open the request') }}</a></div>
</div></x-card>
@endsection
