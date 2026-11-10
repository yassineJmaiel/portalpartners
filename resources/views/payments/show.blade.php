@extends('layouts.app')
@php
  $sum = fn ($k) => array_sum(array_map(fn ($c) => (float) ($c[$k] ?? 0), $p['claims'] ?? []));
  $eobOf = fn ($id) => collect($p['eobs'] ?? [])->firstWhere('claimId', $id);
@endphp
@section('title', $p['reference'].' · VSahha Provider Portal')
@section('content')
<div>
  <a class="crumb" href="/payments">{{ icon('back', 15, 'flip') }}{{ t('Payments') }}</a>
  @include('partials.branchbar', ['id' => $p['payeeId'] ?? null])
  <section class="hero">
    <span class="tile t-green">{{ icon('bank', 24) }}</span>
    <div><div class="row"><h1 class="mono">{{ $p['reference'] }}</h1>{{ pill(t('Paid'), 'green') }}</div>
      <p>{{ day($p['paymentDate']) }} · {{ t($p['method'] ?? '') }} · {{ ($p['runId'] ?? null) ?: t('single settlement') }}{{ ! empty($p['proof']['bankReference']) ? ' · '.t('bank reference').' '.$p['proof']['bankReference'] : '' }}</p></div>
    <div class="acts row">
      @if (! empty($p['proof']))<button type="button" class="btn btn-s" data-doc="/documents/proof/{{ rawurlencode($p['id']) }}" data-doc-title="{{ $p['proof']['ref'] }}">{{ icon('receipt', 17) }}{{ t('Proof of payment') }}</button>@endif
      <button type="button" class="btn btn-s" data-doc="/payments/{{ rawurlencode($p['id']) }}/remittance" data-doc-title="{{ t('Remittance {r}', ['r' => $p['reference']]) }}">{{ icon('print', 17) }}{{ t('Remittance (PDF)') }}</button>
      <a class="btn btn-s" href="/payments/export/csv/{{ rawurlencode($p['id']) }}">{{ icon('download', 17) }}CSV</a>
      <a class="btn btn-s" href="/payments/export/xlsx/{{ rawurlencode($p['id']) }}">{{ icon('download', 17) }}Excel</a>
    </div>
  </section>
</div>
<div class="money-strip" style="grid-template-columns:repeat(4,minmax(0,1fr))">
  <div><span>{{ t('Billed') }}</span><b class="num">{{ money($sum('billed'), $p['currency']) }}</b></div>
  <div><span>{{ t('Approved') }}</span><b class="num">{{ money($sum('approved'), $p['currency']) }}</b></div>
  <div><span>{{ t('Member pays') }}</span><b class="num">{{ money($sum('memberShare'), $p['currency']) }}</b></div>
  <div class="hl"><span>{{ t('Paid to you') }}</span><b class="num">{{ money($p['amount'], $p['currency']) }}</b></div>
</div>
<x-card :title="t('Remittance')" :pad="false">
  <div class="tbl-wrap" style="margin-top:10px"><table class="tbl">
    <thead><tr><th>{{ t('Claim') }}</th><th>{{ t('Patient') }}</th><th>{{ t('Treated') }}</th><th>{{ t('Invoice') }}</th><th class="r">{{ t('Claimed') }}</th><th class="r">{{ t('Approved') }}</th><th class="r">{{ t('Not approved') }}</th><th class="r">{{ t('Member pays') }}</th><th class="r">{{ t('Paid') }}</th><th>{{ t('EOB') }}</th></tr></thead>
    <tbody>@foreach ($p['claims'] as $c)
      @php $eob = $eobOf($c['id']); @endphp
      <tr>
        <td><a class="lnk mono" href="/claims/{{ rawurlencode($c['id']) }}">{{ $c['id'] }}</a>@foreach ($c['reasons'] ?? [] as $r)<div class="reason">{{ $r['code'] }} · {{ $r['text'] }}</div>@endforeach</td><td>{{ $c['memberName'] ?? '' }}</td><td>{{ day($c['treatmentDate'] ?? null) }}</td><td>{{ ($c['invoiceNo'] ?? null) ?: '—' }}</td>
        <td class="r num">{{ money($c['billed'] ?? null) }}</td><td class="r num">{{ money($c['approved'] ?? null) }}</td><td class="r num">{{ ($c['rejected'] ?? null) !== null ? money($c['rejected']) : '—' }}</td><td class="r num">{{ money($c['memberShare'] ?? null) }}</td><td class="r num"><b>{{ money($c['payable'] ?? null) }}</b></td>
        <td>@if ($eob)<button type="button" class="doclink" data-doc="/documents/eob/{{ rawurlencode($eob['id']) }}" data-doc-title="{{ $eob['id'] }}">{{ icon('file', 14) }}{{ $eob['id'] }}</button>@endif</td>
      </tr>
    @endforeach</tbody>
  </table></div>
</x-card>
@include('partials.notes', ['panel' => $notes])
<div class="note note-teal">{{ icon('shield') }}<div>{{ t('Every EOB and proof of payment carries a QR code. Anyone can scan it to confirm the document was issued by Virtual Sahha Solutions — without seeing any medical data.') }}</div></div>
@endsection
