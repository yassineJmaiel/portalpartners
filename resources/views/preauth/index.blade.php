@extends('layouts.app')
@php
  use App\Http\Controllers\PreauthController as PC;
  $ctx = me();
  $tabs = [];
  foreach (PC::TABS as $k => $l) { $tabs[$k] = [t($l), $counts[$k]]; }
@endphp
@section('title', t('Pre-authorizations').' · VSahha Provider Portal')
@section('content')
<x-page-head icon="clip" :title="t('Pre-authorizations')" :sub="t('Requests for treatment that needs VSahha\'s agreement first, and the guarantees of payment (GOP) they produce.')">
  @if ($ctx->can('preauth.submit'))<x-slot:actions><a class="btn btn-p" href="/preauth/new">{{ icon('plus') }}{{ t('Request pre-authorization') }}</a></x-slot:actions> @endif
</x-page-head>
<form class="filters" method="get" action="/preauth">
  <x-tabs :value="$tab" :tabs="$tabs" />
  <span class="sp"></span><input type="hidden" name="tab" value="{{ $tab }}">
  <div class="input">{{ icon('search', 16) }}<input name="q" value="{{ request('q') }}" placeholder="{{ t('Request, patient, GOP…') }}" aria-label="{{ t('Search pre-authorizations') }}"></div>
</form>
@if (count($drafts) && $ctx->can('preauth.submit'))
  <x-card :title="t('Drafts')" :pad="false"><div class="list">@foreach ($drafts as $d)
    <a class="li" href="/preauth/new?draft={{ $d->id }}" style="grid-template-columns:minmax(0,1fr) auto"><div><div class="id">{{ $d->title }}</div><div class="who">{{ day_time($d->updated_at) }}</div></div>{{ icon('chev', 15, 'flip') }}</a>
  @endforeach</div></x-card>
@endif
<x-card :pad="false">
  @if (! $rows)
    <x-empty icon="clip" :title="t('Nothing in this list')" />
  @else
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>{{ t('Request') }}</th><th>{{ t('Patient') }}</th><th>{{ t('Urgency') }}</th><th>{{ t('Stage') }}</th><th>{{ t('GOP') }}</th><th class="r">{{ t('Requested') }}</th><th class="r">{{ t('Authorised') }}</th></tr></thead>
      <tbody>@foreach ($rows as $p)
        <tr class="click" data-href="/preauth/{{ rawurlencode($p['id']) }}">
          <td><div class="id mono"><a href="/preauth/{{ rawurlencode($p['id']) }}">{{ $p['ref'] }}</a></div><div class="sub">{{ t('submitted {d}', ['d' => day_time(($p['submittedAt'] ?? null) ?: ($p['requestDate'] ?? null))]) }}</div></td>
          <td>{{ $p['memberName'] }}<div class="sub">{{ $p['presentingComplaint'] ?? '' }}</div>@if ($ctx->all)<span class="branch-tag">{{ icon('home', 11) }}{{ $ctx->branchName($p['providerId'] ?? null) }}</span>@endif</td>
          <td>{{ pill(t($p['urgency']), $p['urgency'] === 'Urgent' ? 'red' : ($p['urgency'] === 'High' ? 'amber' : 'muted')) }}</td>
          <td>{{ pa_pill($p['status'], ! empty($p['partly'])) }}@if (! empty($p['infoRequired']))<div class="sla" style="color:var(--amber);margin-top:4px">{{ icon('chat', 13) }}{{ t('Information requested') }}</div>@endif</td>
          <td>@if (! empty($p['gop']))<b class="mono">{{ $p['gop']['gopNumber'] }}</b><div class="sub" @if (($p['gop']['expiresInDays'] ?? 999) <= 30) style="color:var(--amber)" @endif>{{ ($p['gop']['expiresInDays'] ?? 0) < 0 ? t('expired {d}', ['d' => day($p['gop']['validUntil'] ?? null)]) : t('valid until {d}', ['d' => day($p['gop']['validUntil'] ?? null)]) }}</div>@else<span class="muted">—</span>@endif</td>
          <td class="r num">{{ money($p['requested'], $p['currency']) }}</td>
          <td class="r num"><b>{{ ! empty($p['authorised']) ? money($p['authorised'], $p['currency']) : '—' }}</b>@if (! empty($p['billed']))<div class="sub">{{ t('billed') }}</div>@endif</td>
        </tr>
      @endforeach</tbody>
    </table></div>
  @endif
</x-card>
@endsection
