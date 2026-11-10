@extends('layouts.app')
@php
  $ctx = me();
  $can = fn ($c) => $ctx->roleCan($c);
  $st = $profile['standing'] ?? [];
  $hour = (int) gmdate('H');
  $greeting = $hour < 12 ? t('Good morning') : ($hour < 18 ? t('Good afternoon') : t('Good evening'));
  $total = $claimsTotal ?: 1;
  $stageShow = [['received', 'Received', 'var(--blue)', 'claimed'], ['review', 'In review', 'var(--blue)', 'claimed'], ['action', 'Action needed from you', 'var(--amber)', 'nothing waiting'],
    ['medical', 'With medical team', 'var(--violet)', null], ['decided', 'Decided', 'var(--red)', null], ['scheduled', 'Payment scheduled', 'var(--teal)', 'approved'], ['paid', 'Paid', 'var(--green)', 'paid'], ['closed', 'Closed', 'var(--faint)', null]];
  $taskIcon = ['claim-info' => 'chat', 'pa-info' => 'stetho', 'bill-gop' => 'receipt', 'gop-expiring' => 'clip', 'accreditation' => 'badge', 'licence' => 'badge', 'drafts' => 'edit', 'handoff' => 'users'];
  $heroCards = array_values(array_filter([
    $can('payment.view') ? ['wallet', 'green', money($money['paid'], $money['currency']), t('Paid to you')] : null,
    $can('claim.view') ? ['file', 'blue', $stages['received']['count'] + $stages['review']['count'], t('In review')] : null,
    $can('claim.view') ? ['alert', 'amber', $stages['action']['count'], t('Action needed from you')] : null,
  ]));
@endphp
@section('title', t('Home').' · VSahha Provider Portal')
@section('content')
<section class="welcome">
  <div class="w-copy">
    <h1>{{ $greeting }}, {{ $profile['name'] ?? '' }}</h1>
    <p>{{ long_date() }} · {{ t('here is what needs you today') }}</p>
    <div class="chips">
      <span class="chip"><span class="d" style="background:{{ ! empty($st['canSubmit']) ? '#5BE3A8' : '#FF8FA1' }}"></span>{{ t(($st['status'] ?? null) ?: 'Active') }}</span>
      <span class="chip"><span class="d" style="background:#7FE3F0"></span>{{ t(($st['networkStatus'] ?? null) ?: 'Not recorded') }}</span>
      <span class="chip"><span class="d live"></span>{{ t('Synced with VSahha {time}', ['time' => clock_of($syncedAt)]) }}</span>
    </div>
  </div>
  <div class="scene" aria-hidden="true" data-tilt="10">
    <div class="orb"><div class="orb-rings"><span class="ring r1"></span><span class="ring r2"></span><span class="ring r3"></span><span class="spark s1"></span><span class="spark s2"></span><span class="spark s3"></span></div>
      <div class="orb-core"><img src="/brand/vsahha-symbol-white.svg" height="44" width="30" alt=""></div></div>
    @foreach (array_slice($heroCards, 0, 3) as $i => [$ic, $tone, $value, $label])
      <div class="float-card fc{{ $i + 1 }}"><span class="fc-ic t-{{ $tone }}">{{ icon($ic, 15) }}</span><div><b>{{ $value }}</b><small>{{ $label }}</small></div></div>
    @endforeach
  </div>
</section>

<section class="grid-a">
  <div class="stack">
    @if ($ctx->can('eligibility.check'))
      <x-card class="elig">
        <h2><span class="tile t-teal">{{ icon('shield') }}</span>{{ t("Check a patient's cover") }}</h2>
        <form class="erow" method="post" action="/eligibility">@csrf
          <label class="field"><span class="lbl">{{ t('Card number or member ID') }}</span>
            <div class="input">{{ icon('scan', 17, '', 'color:var(--teal)') }}<input name="q" placeholder="VS-4410-2201-8845" aria-label="{{ t('Card number or member ID') }}" required></div></label>
          <label class="field"><span class="lbl">{{ t('Service date') }}</span><input type="date" name="date" value="{{ portal_today() }}"></label>
          <button class="btn btn-p">{{ t('Check') }}</button>
        </form>
        <p class="muted" style="margin-top:12px;font-size:12.5px">{{ t('Or search by name and date of birth on the') }} <a class="lnk" href="/eligibility?mode=name">{{ t('Eligibility page') }}</a>.</p>
      </x-card>
      <x-card :title="t('Today\'s check-ins')" :sub="$checkIns ? plural(count($checkIns), '{n} patient', '{n} patients') : ''" :pad="false">
        @if (! $checkIns)
          <div class="empty-line" style="padding:4px 20px 16px">{{ icon('user') }}{{ t('No one checked in yet today. Check cover above, then check the patient in.') }}</div>
        @else
          <div class="list">@foreach ($checkIns as $c)
            <a class="li" href="/claims/new?member={{ urlencode($c['memberId']) }}" style="grid-template-columns:minmax(0,1fr) auto">
              <div><div class="id">{{ $c['memberName'] }}</div><div class="who">{{ $c['memberId'] }} · {{ clock_of($c['at']) }} · {{ $c['by'] }}</div></div>
              {{ pill($c['verified'] ? t('Presence verified') : t('Presence recorded'), $c['verified'] ? 'green' : 'teal') }}
            </a>
          @endforeach</div>
        @endif
      </x-card>
    @endif
    <div class="quick quick-col">
      @if ($ctx->can('intake.use'))<a class="qa" data-tilt="9" href="/intake"><span class="tile t-teal">{{ icon('upload', 21) }}</span><div><b>{{ t('Upload invoices') }}</b><span>{{ t('Smart Intake files them for you') }}</span></div>{{ icon('chev', 16, 'go flip') }}</a>@endif
      @if ($ctx->can('claim.submit'))<a class="qa" data-tilt="9" href="/claims/new"><span class="tile t-blue">{{ icon('plus', 21) }}</span><div><b>{{ t('New claim') }}</b><span>{{ t('Direct billing') }}</span></div>{{ icon('chev', 16, 'go flip') }}</a>@endif
      @if ($ctx->can('preauth.submit'))<a class="qa" data-tilt="9" href="/preauth/new"><span class="tile t-violet">{{ icon('stetho', 21) }}</span><div><b>{{ t('Request pre-authorization') }}</b><span>{{ t('Only what the plan covers') }}</span></div>{{ icon('chev', 16, 'go flip') }}</a>@endif
      @if ($can('payment.view'))<a class="qa" data-tilt="9" href="/payments?tab=reconcile"><span class="tile t-green">{{ icon('bank', 21) }}</span><div><b>{{ t('Reconcile my bank') }}</b><span>{{ t('Match payments to claims') }}</span></div>{{ icon('chev', 16, 'go flip') }}</a>@endif
    </div>
  </div>
  <x-card :title="t('Needs your attention')" :sub="$tasks ? plural(count($tasks), '{n} item', '{n} items') : ''">
    <div style="margin:-8px -8px -10px">
      @foreach ($tasks as $x)
        <div class="task">
          <span class="tile t-{{ $x['tone'] }}">{{ icon($taskIcon[$x['kind']] ?? 'info') }}</span>
          <div style="min-width:0"><b>{{ $x['title'] }}</b><p>{{ $x['text'] }}</p>
            @if (! empty($x['until']))<span class="when t-{{ $x['tone'] }}">{{ icon('clock', 12) }}{{ t('Until {d}', ['d' => day($x['until'])]) }}</span>@endif</div>
          @if ($x['kind'] === 'bill-gop' && $ctx->can('claim.submit'))
            <form method="post" action="/preauth/{{ rawurlencode($x['caseId']) }}/to-claim">@csrf<button class="btn btn-p btn-sm">{{ t('Create claim') }}</button></form>
          @else
            <a class="btn btn-s btn-sm" href="{{ $x['link'] }}">{{ t($x['action'] ?? 'Open') }}</a>
          @endif
        </div>
      @endforeach
      @if (! $tasks)<div class="empty-line">{{ icon('check') }}{{ t('Nothing needs you right now.') }}</div>@endif
      @if ($tasks && ! $infoRequests)<div class="empty-line">{{ icon('check') }}{{ t('No information requests from VSahha are waiting on you.') }}</div>@endif
    </div>
  </x-card>
</section>

@if ($can('claim.view'))
  <section class="grid-b">
    <x-card :title="t('Claims by stage')" :sub="t('all {n} claims', ['n' => $claimsTotal])">
      <x-slot:action><a class="lnk" href="/claims">{{ t('Open claims') }}{{ icon('chev', 15, 'flip') }}</a></x-slot:action>
      <div class="stages">
        @foreach ($stageShow as [$id, $label, $color, $sub])
          @php $s = $stages[$id]; @endphp
          <a class="stage {{ $s['count'] ? '' : 'zero' }}" data-tilt="12" href="/claims?stage={{ $id }}" style="--c:{{ $color }}">
            <div class="k"><span class="d" style="background:{{ $color }}"></span>{{ t($label) }}</div>
            <div class="v">{{ $s['count'] }}</div>
            <div class="s num">@if ($s['count'] && $sub){{ money($s['amount'], $money['currency']) }} {{ t($sub) }}@elseif (! $s['count'] && $id === 'action'){{ t('nothing waiting') }}@else&nbsp;@endif</div>
          </a>
        @endforeach
      </div>
      <div class="bar" aria-hidden="true">@foreach ($stageShow as [$id, , $color])@if ($stages[$id]['count'])<i style="width:{{ $stages[$id]['count'] / $total * 100 }}%;background:{{ $color }}"></i>@endif @endforeach</div>
      <div class="list" style="margin:0 -10px -12px">
        @foreach ($inProgress as $c)
          <a class="li" href="/claims/{{ rawurlencode($c['id']) }}">
            <div><div class="id mono">{{ $c['id'] }}</div><div class="who">{{ $c['memberName'] }} · {{ t($c['type']) }} · {{ t('treated {d}', ['d' => day($c['treatmentDate'], true)]) }}</div></div>
            <div class="hide-m">
              @if (($c['sla']['state'] ?? null) === 'Overdue')<span class="sla">{{ icon('alert', 13) }}{{ t("Past VSahha's {n}-day target", ['n' => $c['sla']['target']]) }}</span>@endif
              @if (! empty($c['infoRequest']))<span class="sla" style="color:var(--amber)">{{ icon('chat', 13) }}{{ t('Information requested') }}</span>@endif
              @if (! empty($c['money']))<div class="who">{{ t($c['decisionStatus']) }} · {{ t('member share {m}', ['m' => money($c['money']['memberShare'], $c['currency'])]) }}</div>@endif
              <div class="who">{{ t('received {d}', ['d' => day($c['receivedAt'])]) }}</div>
            </div>
            {{ claim_pill($c['workflowStatus']) }}
            @if (! empty($c['money'])){{ amt($c['money']['insurerPayable'], $c['currency'], 'payable') }}@else{{ amt($c['claimed'], $c['currency'], 'claimed') }}@endif
          </a>
        @endforeach
        @if (! $inProgress)<div class="empty-line">{{ icon('check') }}{{ t('Every claim is paid or closed.') }}</div>@endif
      </div>
    </x-card>
    @if ($can('payment.view'))
      <x-card :title="t('Money')" :sub="$money['currency']">
        <div class="money">
          <div class="mtile m-paid" data-tilt="8"><span class="k">{{ t('Paid to you') }}</span><span class="v">{{ money($money['paid']) }}<small>{{ $money['currency'] }}</small></span>
            <span class="s">{{ plural($money['paidCount'], '{n} payment', '{n} payments') }}{{ $money['bank'] ? " · {$money['bank']}" : '' }}{{ $money['lastPaid'] ? ' · '.t('last on {d}', ['d' => day($money['lastPaid'])]) : '' }}</span></div>
          <div class="mtile m-due" data-tilt="8"><span class="k">{{ t('Approved, awaiting payment') }}</span><span class="v">{{ money($money['awaiting']) }}<small>{{ $money['currency'] }}</small></span><span class="s">{{ plural($money['awaitingCount'], '{n} claim', '{n} claims') }}</span></div>
          <div class="mtile m-rev" data-tilt="8"><span class="k">{{ t('Claimed, awaiting a decision') }}</span><span class="v">{{ money($money['inReview']) }}<small>{{ $money['currency'] }}</small></span><span class="s">{{ plural($money['inReviewCount'], '{n} claim · amounts you billed, not yet decided', '{n} claims · amounts you billed, not yet decided') }}</span></div>
        </div>
      </x-card>
    @else
      <div></div>
    @endif
  </section>
@endif

@if ($can('payment.view'))
  <x-card :title="t('Latest payments')" :sub="t('with their EOB and proof of payment')" :pad="false">
    <x-slot:action><a class="lnk" href="/payments">{{ t('All payments') }}{{ icon('chev', 15, 'flip') }}</a></x-slot:action>
    <div class="tbl-wrap" style="margin-top:10px">
      <table class="tbl">
        <thead><tr><th>{{ t('Payment') }}</th><th>{{ t('Date') }}</th><th>{{ t('Claim') }}</th><th>{{ t('Patient') }}</th><th class="r">{{ t('Paid') }}</th><th>{{ t('Documents') }}</th></tr></thead>
        <tbody>
          @foreach ($payments as $p)
            <tr>
              <td class="id mono"><a href="/payments/{{ rawurlencode($p['reference']) }}">{{ $p['reference'] }}</a></td><td>{{ day($p['paymentDate']) }}</td>
              <td class="mono">{{ implode(', ', array_column($p['claims'], 'id')) }}</td><td>{{ implode(', ', array_column($p['claims'], 'memberName')) }}</td>
              <td class="r num"><b>{{ money($p['amount'], $p['currency']) }}</b></td>
              <td><div class="row" style="gap:6px">
                @foreach ($p['eobs'] as $e)<button type="button" class="doclink" data-doc="/documents/eob/{{ rawurlencode($e['id']) }}" data-doc-title="{{ $e['id'] }}">{{ icon('file', 14) }}{{ $e['id'] }}</button>@endforeach
                @if (! empty($p['proof']))<button type="button" class="doclink" data-doc="/documents/proof/{{ rawurlencode($p['id']) }}" data-doc-title="{{ t('Proof of payment') }} {{ $p['proof']['ref'] }}">{{ icon('receipt', 14) }}{{ t('Proof') }}</button>@endif
              </div></td>
            </tr>
          @endforeach
          @if (! $payments)<tr><td colspan="6"><div class="empty"><b>{{ t('No payments yet') }}</b><p>{{ t('Payments appear here as soon as VSahha releases them.') }}</p></div></td></tr>@endif
        </tbody>
      </table>
    </div>
  </x-card>
@endif
@endsection
