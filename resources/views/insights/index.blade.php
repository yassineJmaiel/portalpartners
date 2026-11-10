@extends('layouts.app')
@php
  use App\Http\Controllers\FollowUpController as FC;
  use App\Portal\Vocab;
  $pct = fn ($a, $b) => $b ? round($a / $b * 100).'%' : '—';
  $maxM = max(1, ...array_merge([1], array_column($d['monthly'], 'claims')));
  $maxR = max(1, ...array_merge([1], array_column($d['reasons'], 'lines')));
@endphp
@section('title', t('Insights').' · VSahha Provider Portal')
@section('content')
<x-page-head icon="chart" :title="t('Insights')" :sub="t('Your claims at VSahha since {d}. Each figure shows what it is counted on.', ['d' => $d['from'] ? substr($d['from'], 0, 10) : '—'])" />
<div class="grid2 stats5">
  <div class="stat"><span>{{ t('Approved in full') }}</span><b>{{ $pct($d['approvedInFull'], $d['decided']) }}</b><small>{{ t('{a} of {b} decided claims', ['a' => $d['approvedInFull'], 'b' => $d['decided']]) }}</small></div>
  <div class="stat"><span>{{ t('First-pass approval') }}</span><b>{{ $pct($d['firstPass'], $d['firstOf']) }}</b><small>{{ t('{a} of {b} decided claims: approved in full, no information request, no appeal', ['a' => $d['firstPass'], 'b' => $d['firstOf']]) }}</small></div>
  <div class="stat"><span>{{ t('Clean claims') }}</span><b>{{ $pct($d['clean'], $d['of']) }}</b><small>{{ t('{a} of {b} needed no information request', ['a' => $d['clean'], 'b' => $d['of']]) }}</small></div>
  <div class="stat"><span>{{ t('Days to decision') }}</span><b>{{ $d['decisionDays'] ?? '—' }}</b><small>{{ $d['decisionOf'] ? t('median of {n} claims', ['n' => $d['decisionOf']]) : t('not measurable yet') }}</small></div>
  <div class="stat"><span>{{ t('Days to payment') }}</span><b>{{ $d['payDays'] ?? '—' }}</b><small>{{ $d['payOf'] ? t('median of {n} paid claims', ['n' => $d['payOf']]) : t('not measurable yet') }}</small></div>
</div>
<div class="grid-b">
  <x-card :title="t('Why lines were reduced or declined')" :sub="t('and what avoids it next time')">
    @if (! $d['reasons'])
      <x-empty icon="check" :title="t('No reduced or declined line')">{{ t('Every decided line was approved in full.') }}</x-empty>
    @endif
    @foreach ($d['reasons'] as $r)
      <div style="padding:10px 0;border-bottom:1px solid var(--line2)">
        <div class="hbar" style="padding:0"><span><b>{{ $r['code'] }}</b> · {{ t(Vocab::REJECTION_REASONS[$r['code']] ?? $r['code']) }}</span><div class="track"><i style="width:{{ $r['lines'] / $maxR * 100 }}%"></i></div><b class="num" style="text-align:end">{{ $r['lines'] }}</b></div>
        <p class="muted" style="font-size:12.5px;margin-top:4px">{{ icon('info', 13) }} {{ t(FC::COACH[$r['code']] ?? '') }} {{ $r['amount'] ? '· '.t('{a} not paid', ['a' => money($r['amount'])]) : '' }}</p>
      </div>
    @endforeach
  </x-card>
  <x-card :title="t('Claims per month')">
    @if (! $d['monthly'])
      <x-empty icon="chart" :title="t('No claims yet')" />
    @else
      <div class="bars">@foreach ($d['monthly'] as $m)
        <div class="b" title="{{ $m['claims'] }} · {{ money($m['claimed']) }}"><b style="font-size:12px">{{ $m['claims'] }}</b><i style="height:{{ $m['claims'] / $maxM * 100 }}%"></i><span>{{ substr($m['month'], 5) }}/{{ substr($m['month'], 2, 2) }}</span></div>
      @endforeach</div>
    @endif
    <p class="muted" style="font-size:12px;margin-top:8px">{{ t('{n} claims in total · {a} decided · {b} partly approved · {c} not payable', ['n' => $d['claims'], 'a' => $d['decided'], 'b' => $d['partly'], 'c' => $d['rejected']]) }}</p>
  </x-card>
</div>
<x-card :title="t('Smart Intake accuracy')" :sub="t('how often the reader\'s value was kept by a person')" :pad="false">
  @if (! $d['intake'])
    <x-empty icon="spark" :title="t('Not measured yet')">{{ t('Accuracy appears once documents read by Smart Intake have been reviewed.') }}</x-empty>
  @else
    <div class="tbl-wrap" style="margin-top:10px"><table class="tbl">
      <thead><tr><th>{{ t('Document type') }}</th><th>{{ t('Field') }}</th><th class="r">{{ t('Reviewed') }}</th><th class="r">{{ t('Kept as read') }}</th></tr></thead>
      <tbody>@foreach ($d['intake'] as $r)
        @php $share = $r['accepted'] / max(1, $r['reviewed']); @endphp
        <tr><td>{{ t($r['docType']) }}</td><td>{{ $r['field'] }}</td><td class="r num">{{ $r['reviewed'] }}</td><td class="r">{{ pill(round($share * 100).'%', $share >= 0.9 ? 'green' : ($share >= 0.7 ? 'blue' : 'amber')) }}</td></tr>
      @endforeach</tbody>
    </table></div>
  @endif
</x-card>
<div class="note note-grey">{{ icon('info') }}<div>{{ t('These figures are your own claims at VSahha. No industry benchmark is shown, because none has been configured with a source.') }}</div></div>
@endsection
