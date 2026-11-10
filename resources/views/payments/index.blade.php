@extends('layouts.app')
@php
  $ctx = me();
  $tabs = ['payments' => t('Payments'), 'receivables' => t('Receivables & ageing'), 'reconcile' => t('Reconcile my bank')];
@endphp
@section('title', t('Payments & documents').' · VSahha Provider Portal')
@section('live', $tab === 'reconcile' ? '0' : '1')
@section('content')
<x-page-head icon="wallet" :title="t('Payments & documents')" :sub="t('Every payment, its remittance, the EOB of each claim and the proof of payment — verifiable by QR.')" />
<x-tabs :value="$tab" :tabs="$tabs" />
@if ($tab === 'payments')
  @php $total = array_sum(array_column($payments, 'amount')); $first = $payments[0] ?? null; @endphp
  <div class="grid2" style="grid-template-columns:repeat(3,minmax(0,1fr))">
    <div class="stat"><span>{{ t('Paid to you') }}</span><b class="num">{{ money($total, $first['currency'] ?? null) }}</b><small>{{ t('{n} payments', ['n' => count($payments)]) }}</small></div>
    <div class="stat"><span>{{ t('Last payment') }}</span><b class="num">{{ $first ? money($first['amount'], $first['currency']) : '—' }}</b><small>{{ $first ? day($first['paymentDate']) : t('none yet') }}</small></div>
    <div class="stat"><span>{{ t('With proof of payment') }}</span><b class="num">{{ count(array_filter($payments, fn ($p) => ! empty($p['proof']))) }}/{{ count($payments) }}</b><small>{{ t('every payment should have one') }}</small></div>
  </div>
  <x-card :pad="false" :title="t('Payments')">
    @if ($payments)<x-slot:action><div class="row" style="gap:6px"><a class="btn btn-s btn-sm" href="/payments/export/csv">{{ icon('download', 15) }}{{ t('Remittance CSV') }}</a><a class="btn btn-s btn-sm" href="/payments/export/xlsx">{{ icon('download', 15) }}{{ t('Remittance Excel') }}</a></div></x-slot:action> @endif
    @if (! $payments)
      <x-empty icon="bank" :title="t('No payments yet')">{{ t('Payments appear here as soon as VSahha releases them.') }}</x-empty>
    @else
      <div class="tbl-wrap" style="margin-top:10px"><table class="tbl">
        <thead><tr><th>{{ t('Payment') }}</th><th>{{ t('Date') }}</th><th>{{ t('Method') }}</th><th>{{ t('Claims') }}</th><th class="r">{{ t('Amount') }}</th><th>{{ t('Documents') }}</th></tr></thead>
        <tbody>@foreach ($payments as $p)
          <tr>
            <td><div class="id mono"><a href="/payments/{{ rawurlencode($p['reference']) }}">{{ $p['reference'] }}</a></div><div class="sub">{{ ($p['runId'] ?? null) ?: t('single settlement') }}</div>@if ($ctx->all)<span class="branch-tag">{{ icon('home', 11) }}{{ $ctx->branchName($p['payeeId'] ?? null) }}</span>@endif</td>
            <td>{{ day($p['paymentDate']) }}</td><td>{{ t($p['method'] ?? '') }}</td>
            <td>@foreach ($p['claims'] as $c)<div class="mono" style="font-size:12.5px">{{ $c['id'] }} · {{ $c['memberName'] ?? '' }}</div>@endforeach</td>
            <td class="r num"><b>{{ money($p['amount'], $p['currency']) }}</b></td>
            <td><div class="row" style="gap:6px">
              @foreach ($p['eobs'] as $e)<button type="button" class="doclink" data-doc="/documents/eob/{{ rawurlencode($e['id']) }}" data-doc-title="{{ $e['id'] }}">{{ icon('file', 14) }}{{ $e['id'] }}</button>@endforeach
              @if (! empty($p['proof']))<button type="button" class="doclink" data-doc="/documents/proof/{{ rawurlencode($p['id']) }}" data-doc-title="{{ $p['proof']['ref'] }}">{{ icon('receipt', 14) }}{{ t('Proof') }}</button>@else{{ pill(t('Proof pending'), 'amber') }}@endif
            </div></td>
          </tr>
        @endforeach</tbody>
      </table></div>
    @endif
  </x-card>
@elseif ($tab === 'receivables')
  @php $max = max(1, ...array_column($recv['buckets'], 'amount')); @endphp
  <x-card :title="t('What VSahha still owes, by age')" :sub="t('direct-billing claims not yet paid')">
    @foreach ($recv['buckets'] as $b)
      <div class="hbar"><span><b>{{ t($b['label']) }}</b> <span class="muted">· {{ t('{n} claims', ['n' => $b['count']]) }}</span></span>
        <div class="track"><i style="width:{{ $b['amount'] / $max * 100 }}%;{{ $b['from'] > 60 ? 'background:var(--red)' : ($b['from'] > 30 ? 'background:var(--amber)' : '') }}"></i></div>
        <b class="num" style="text-align:end">{{ money($b['amount']) }}</b></div>
    @endforeach
    <p class="muted" style="font-size:12px;margin-top:8px">{{ t('Amounts are what VSahha decided to pay where a decision exists, otherwise what you claimed.') }} {{ t($recv['note']) }}</p>
  </x-card>
  <x-card :pad="false" :title="t('Open claims')">
    @if (! $recv['rows'])
      <x-empty icon="check" :title="t('Nothing is owed to you')" />
    @else
      <div class="tbl-wrap" style="margin-top:10px"><table class="tbl">
        <thead><tr><th>{{ t('Claim') }}</th><th>{{ t('Stage') }}</th><th class="r">{{ t('Age') }}</th><th class="r">{{ t('Amount') }}</th><th>{{ t('Expected') }}</th></tr></thead>
        <tbody>@foreach ($recv['rows'] as $r)
          <tr class="click" data-href="/claims/{{ rawurlencode($r['id']) }}">
            <td><div class="id mono">{{ $r['id'] }}</div><div class="sub">{{ $r['memberName'] }} · {{ t('received {d}', ['d' => day($r['receivedAt'])]) }}</div></td>
            <td>{{ t($r['stage']) }}</td><td class="r num">{{ t('{n} days', ['n' => $r['age']]) }}</td>
            <td class="r">{{ amt($r['amount'], $r['currency'], $r['basis']) }}</td>
            <td>@if ($r['expected'])<span>{{ day($r['expected']) }} <span class="sub">({{ t('expected') }})</span></span>@else<span class="muted">{{ t('after the decision') }}</span>@endif</td>
          </tr>
        @endforeach</tbody>
      </table></div>
    @endif
  </x-card>
@else
  <x-card :title="t('Check your bank statement against VSahha\'s payments')" :sub="t('nothing is uploaded to VSahha')">
    <p class="muted" style="font-size:13px;margin-bottom:10px">{{ t('Paste your statement as CSV (date, description, amount — any order, comma or semicolon), or choose the file. Lines are matched by the PAY reference in the description, otherwise by the same amount within 5 days.') }}</p>
    <form method="post" action="/payments/reconcile" enctype="multipart/form-data">@csrf
      <label class="field"><span class="lbl">{{ t('Statement') }}</span><textarea name="csv" rows="7" placeholder="2026-08-06;VSAHHA PAY-2026-090211;623,00&#10;2026-08-06;VIR VSAHHA;623.00" style="font-family:monospace;font-size:12.5px">{{ old('csv') }}</textarea></label>
      <div class="row" style="margin-top:10px">
        <label class="btn btn-s btn-sm">{{ icon('upload', 15) }}{{ t('Choose a CSV file') }}<input type="file" name="file" accept=".csv,.txt" hidden data-submit-on-change></label>
        <span class="sp"></span><button class="btn btn-p">{{ t('Match') }}</button>
      </div>
    </form>
  </x-card>
  @if ($res)
    <div class="grid2">
      <x-card :title="t('Matched ({n})', ['n' => count($res['matched'])])">
        @foreach ($res['matched'] as $m)<div class="row" style="font-size:13px;padding:6px 0;border-bottom:1px solid var(--line2)">{{ pill($m['payment']['reference'], 'green') }}{{ day($m['bank']['date']) }} · {{ money($m['bank']['amount']) }}<span class="sp"></span><span class="muted">{{ t('by {how}', ['how' => t($m['how'])]) }}</span></div>@endforeach
        @if (! $res['matched'])<p class="muted">{{ t('No line matched.') }}</p>@endif
      </x-card>
      <div class="stack">
        <x-card :title="t('On your statement, not from VSahha ({n})', ['n' => count($res['unmatchedBank'])])">@foreach ($res['unmatchedBank'] as $b)<div style="font-size:13px;padding:4px 0">{{ day($b['date']) }} · {{ money($b['amount']) }} · <span class="muted">{{ $b['text'] }}</span></div>@endforeach</x-card>
        <x-card :title="t('Paid by VSahha, not on your statement ({n})', ['n' => count($res['unmatchedPayments'])])">
          @foreach ($res['unmatchedPayments'] as $p)<div class="row" style="font-size:13px;padding:4px 0"><b class="mono">{{ $p['reference'] }}</b>{{ day($p['paymentDate']) }}<span class="sp"></span>{{ money($p['amount'], $p['currency']) }}</div>@endforeach
          @if ($res['unmatchedPayments'])<div class="note note-amber">{{ icon('info') }}<div>{{ t('A payment missing from your statement may not have arrived yet, or may have been booked under another reference. Message VSahha with the payment reference if it is older than a week.') }}</div></div>@endif
        </x-card>
      </div>
    </div>
  @endif
@endif
@endsection
