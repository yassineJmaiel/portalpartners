@extends('layouts.app')
@php
  $ctx = me();
  $sel = $res['selected'] ?? null;
  $here = fn (array $x = []) => '/eligibility?'.http_build_query(array_filter(array_merge(['check' => $checkId, 'mode' => $mode, 's' => $wasScanned ? 1 : null], $x)));
  $cur = $res['policy']['currency'] ?? null;
  $canCheckIn = $res && $res['eligible'] && $res['serviceDate'] === portal_today();
@endphp
@section('title', t('Eligibility').' · VSahha Provider Portal')
@section('content')
<x-page-head icon="shield" :title="t('Eligibility')" :sub="t('Is this person covered on this date, and what does their plan include? Every check is recorded.')" />
<div class="grid-side">
  <div class="stack">
    <x-card>
      <x-tabs param="mode" :value="$mode" :tabs="['card' => t('Card number or member ID'), 'name' => t('Name and date of birth')]" />
      <form class="form3" style="margin-top:16px;align-items:end" method="post" action="/eligibility">@csrf
        <input type="hidden" name="mode" value="{{ $mode }}">
        @if ($mode === 'card')
          <label class="field"><span class="lbl">{{ t('Card number or member ID') }}</span>
            <div class="input">{{ icon('scan', 17, '', 'color:var(--teal)') }}<input name="q" value="{{ $q }}" placeholder="VS-4410-2201-8845" autofocus>
              <button type="button" class="lnk" data-scan-card title="{{ t('Scan the card') }}" data-t-noscan="{{ t('This browser cannot read codes from the camera. Type the card number instead.') }}" data-t-nocam="{{ t('The camera could not be opened. Allow camera access, or type the card number.') }}">{{ icon('scan', 16) }}</button></div></label>
          <input type="hidden" name="scanned" value="" id="scanned-card">
        @else
          <label class="field"><span class="lbl">{{ t('Full name') }}</span><input name="name" value="{{ $name }}" placeholder="Leila Mansour"></label>
          <label class="field"><span class="lbl">{{ t('Date of birth') }}</span><input type="date" name="dob" value="{{ $dob }}"></label>
        @endif
        <label class="field"><span class="lbl">{{ t('Service date') }}</span><input type="date" name="date" value="{{ $date }}"></label>
        <div><button class="btn btn-p">{{ icon('shield', 17) }}{{ t('Check cover') }}</button></div>
      </form>
      <p class="muted" style="font-size:12px;margin-top:10px">{{ t('A name alone never finds a member: give the date of birth too. Look-ups are limited per hour to protect member data.') }}</p>
    </x-card>

    @if ($error)<x-error-box :message="$error->getMessage()" :details="$error->details" />@endif
    @if ($res)
      @php $m = $res['member']; $holder = ($m['mainPolicyHolderId'] ?? null) && $m['mainPolicyHolderId'] !== $m['id'] ? $m['mainPolicyHolderId'] : null; @endphp
      <div class="result {{ $res['eligible'] ? '' : 'bad' }}">
        <span class="ok {{ $res['eligible'] ? 't-green' : 't-red' }}">{{ icon($res['eligible'] ? 'check' : 'x', 24) }}</span>
        <div style="min-width:0">
          <b>{{ $m['name'] }}</b>
          <div class="meta">{{ $m['id'] }} · {{ t($m['relation'] ?? '') }} · {{ t('born {d}', ['d' => day($m['dob'])]) }}</div>
          @if (! empty($m['memberPartnerId']) || $holder)
            <div class="meta">{{ ! empty($m['memberPartnerId']) ? t('Member Partner ID {id}', ['id' => $m['memberPartnerId']]) : '' }}{{ ! empty($m['memberPartnerId']) && $holder ? ' · ' : '' }}{{ $holder ? t('Main Policy Holder ID {id}', ['id' => $holder]) : '' }}</div>
          @endif
          <div class="meta">{{ ! empty($res['policy']) ? "{$res['policy']['id']} {$res['policy']['name']}" : t('No policy') }} · {{ ($res['insurer']['name'] ?? null) ?: t('Insurer not recorded') }}</div>
          <div class="meta">{{ t('Cover {a} – {b}', ['a' => day($res['policy']['start'] ?? null), 'b' => day($res['policy']['end'] ?? null)]) }} · {{ t('You are {n} for this member', ['n' => t($res['network'])]) }}</div>
          <div class="checks">@foreach ($res['checks'] as $c)<span class="{{ $c['ok'] ? 't-green' : 't-red' }}" title="{{ $c['detail'] }}">{{ icon($c['ok'] ? 'check' : 'x', 13) }}{{ t($c['label']) }}</span>@endforeach</div>
          @if (! $res['eligible'])<div class="meta" style="color:var(--red);margin-top:6px;font-weight:600">{{ $res['summary'] }}</div>@endif
        </div>
        <button type="button" class="lnk" data-doc="/eligibility/{{ $res['checkId'] }}/certificate" data-doc-title="{{ t('Eligibility certificate') }}">{{ icon('download', 16) }}{{ t('Certificate') }}</button>
      </div>

      @if ($canCheckIn)
        @if ($checkIn)
          <div class="note note-{{ $checkIn['verified'] ? 'green' : 'teal' }}">{{ icon('check') }}<div>{{ t('{name} checked in at {time}.', ['name' => $m['name'], 'time' => clock_of($checkIn['at'])]) }}
            {{ $checkIn['consentAt'] ? t('Presence verified: the patient confirmed with the code sent to their phone.') : ($checkIn['verified'] ? t('Presence verified: the card was scanned.') : t('Presence recorded: the card number was typed.')) }}</div></div>
          @if (! $checkIn['verified'])
            @if ($consent && $consent['checkIn'] === $checkIn['id'] || $checkIn['consentPending'])
              <form class="consent" method="post" action="/check-ins/{{ $checkIn['id'] }}/consent/verify">@csrf
                @if ($consent)
                  <p style="font-size:13.5px">{{ $consent['transmitted'] ? t('A code was sent to {phone}. Ask the patient to read it to you.', ['phone' => $consent['sentTo']]) : t('No SMS service is connected, so the code was not sent to {phone}.', ['phone' => $consent['sentTo']]) }}
                    @if ($consent['demoCode']) {{ t('For this demo it is shown here:') }} <b class="demo-code" dir="ltr">{{ $consent['demoCode'] }}</b>@endif</p>
                @endif
                <div class="row">
                  <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" aria-label="{{ t('Code from the patient') }}" style="width:130px;letter-spacing:3px" dir="ltr" required>
                  <button class="btn btn-p btn-sm">{{ t('Confirm presence') }}</button>
                  <button class="lnk" formaction="/check-ins/{{ $checkIn['id'] }}/consent" formnovalidate>{{ t('Send a new code') }}</button>
                </div>
              </form>
            @else
              <form class="row" style="margin-top:8px" method="post" action="/check-ins/{{ $checkIn['id'] }}/consent">@csrf
                <button class="btn btn-s btn-sm">{{ icon('lock', 15) }}{{ t('Verify presence with a code by SMS') }}</button>
                <span class="muted" style="font-size:12.5px">{{ t('The patient receives a code on the phone VSahha has on file and reads it to you.') }}</span>
              </form>
            @endif
          @endif
        @else
          <form class="row" style="margin-top:10px" method="post" action="/check-ins">@csrf
            <input type="hidden" name="checkId" value="{{ $res['checkId'] }}"><input type="hidden" name="method" value="{{ $wasScanned ? 'card scanned' : 'card number typed' }}">
            <button class="btn btn-p btn-sm">{{ icon('user', 15) }}{{ t('Check in {name}', ['name' => explode(' ', $m['name'])[0]]) }}</button>
            <span class="muted" style="font-size:12.5px">{{ $wasScanned ? t('The card was scanned: presence will be verified.') : t('Scan the card to verify presence; a typed number is only recorded.') }}</span>
          </form>
        @endif
      @endif

      <x-card :title="t('Checks on {d}', ['d' => day($res['serviceDate'])])">
        @foreach ($res['checks'] as $c)
          <div class="row" style="padding:8px 0;border-bottom:1px solid var(--line2);align-items:flex-start">
            <span class="pill t-{{ $c['ok'] ? 'green' : 'red' }}">{{ icon($c['ok'] ? 'check' : 'x', 12) }}</span>
            <div><b>{{ t($c['label']) }}</b><div class="muted" style="font-size:13px">{{ $c['detail'] }}</div></div>
          </div>
        @endforeach
        <div class="row" style="margin-top:14px">
          @if ($ctx->can('claim.submit') && $res['eligible'])<a class="btn btn-p btn-sm" href="/claims/new?member={{ $m['id'] }}">{{ icon('plus', 15) }}{{ t('New claim for this patient') }}</a>@endif
          @if ($ctx->can('preauth.submit') && $res['eligible'])<a class="btn btn-s btn-sm" href="/preauth/new?member={{ $m['id'] }}">{{ icon('stetho', 15) }}{{ t('Request pre-authorization') }}</a>@endif
        </div>
      </x-card>
    @endif
    @if (! $res && ! $error)
      <x-card><x-empty icon="shield" :title="t('Check a patient before treatment')">{{ t('Scan or type the member card number. The answer shows the four checks VSahha runs on every claim: member active, policy in force, covered on the date, enrolled before the date.') }}</x-empty></x-card>
    @endif
  </div>

  <x-card :title="t('Recent checks')" :pad="false">
    <div class="list">
      @foreach ($history as $h)
        <button type="button" class="li" style="grid-template-columns:minmax(0,1fr) auto" data-doc="/eligibility/{{ $h->id }}/certificate" data-doc-title="{{ t('Eligibility certificate') }}">
          <div><div class="id">{{ $h->member_name }}</div><div class="who">{{ day($h->service_date) }} · {{ $h->by }} · {{ clock_of($h->at) }}</div></div>
          {{ pill($h->eligible ? t('Covered') : t('Not covered'), $h->eligible ? 'green' : 'red') }}
        </button>
      @endforeach
      @if (! count($history))<div class="empty"><b>{{ t('No checks yet') }}</b></div>@endif
    </div>
  </x-card>
</div>

@if ($res)
  <x-card :title="t('Benefits in this member\'s plan')" :sub="$cur" :pad="false">
    <form style="padding:12px 20px 0" method="get" action="/eligibility" data-autosubmit>
      @foreach (['check' => $checkId, 'mode' => $mode, 's' => $wasScanned ? 1 : null] as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
      <label class="field"><span class="lbl">{{ t('See the limits that apply to one benefit') }}</span>
        <select name="benefit">
          <option value="">{{ t('Choose a benefit…') }}</option>
          @foreach ($res['benefits'] as $b)<option value="{{ $b['code'] }}" @selected($benefit === $b['code'])>{{ $b['level'] === 'Category' ? '' : ($b['level'] === 'Benefit' ? '— ' : '—— ') }}{{ $b['code'] }} · {{ $b['name'] }}</option>@endforeach
        </select></label>
    </form>
    @if ($sel)
      <div style="padding:14px 20px 0">
        <div class="note note-teal">{{ icon('info') }}<div><b>{{ $sel['code'] }} · {{ $sel['name'] }}</b>
          {{ t('Pre-authorization') }}: <b style="display:inline">{{ t($sel['preAuth']) }}</b> · {{ t('Waiting period') }}: {{ $sel['waiting']['served'] === true ? t('served') : ($sel['waiting']['served'] === false ? t('not served') : t('not recorded')) }} — {{ $sel['waiting']['detail'] }}</div></div>
        <table class="tbl" style="margin-top:10px">
          <thead><tr><th>{{ t('Limit') }}</th><th class="r">{{ t('Limit') }}</th><th class="r">{{ t('Used or reserved') }}</th><th class="r">{{ t('Remaining') }}</th></tr></thead>
          <tbody>@foreach ($sel['limits'] as $l)
            <tr><td>{{ $l['label'] }}</td><td class="r num">{{ $l['unlimited'] ? t('Unlimited') : money($l['limit'], $sel['currency']) }}</td><td class="r num">{{ money($l['used'], $sel['currency']) }}</td><td class="r num"><b>{{ $l['unlimited'] ? '—' : money($l['remaining'], $sel['currency']) }}</b></td></tr>
          @endforeach</tbody>
        </table>
      </div>
    @endif
    <div class="tbl-wrap" style="margin-top:12px">
      <table class="tbl">
        <thead><tr><th>{{ t('Benefit') }}</th><th>{{ t('Pre-auth') }}</th><th class="r">{{ t('Co-insurance') }}</th><th class="r">{{ t('Co-pay') }}</th><th class="r">{{ t('Deductible') }}</th><th class="r">{{ t('Annual limit') }}</th><th>{{ t('Waiting period') }}</th></tr></thead>
        <tbody>@foreach ($res['benefits'] as $b)
          <tr class="click" data-href="{{ $here(['benefit' => $b['code']]) }}">
            <td style="padding-inline-start:{{ $b['level'] === 'Category' ? 16 : ($b['level'] === 'Benefit' ? 30 : 44) }}px">@if ($b['level'] === 'Category')<b>{{ $b['name'] }}</b>@else{{ $b['name'] }}@endif<div class="sub">{{ $b['code'] }}</div></td>
            <td>@if ($b['preAuth'] === 'Required'){{ pill(t('Required'), 'violet') }}@elseif ($b['preAuth'] === 'Not required')<span class="muted">{{ t('Not required') }}</span>@else{{ pill(t($b['preAuth']), 'amber') }}@endif</td>
            <td class="r num">{{ $b['coinsurance'] !== null ? "{$b['coinsurance']}%" : '—' }}</td>
            <td class="r num">{{ ! empty($b['copay']) ? (($b['copayType'] ?? null) === 'Percentage' ? "{$b['copay']}%" : money($b['copay'], $cur)) : '—' }}</td>
            <td class="r num">{{ ! empty($b['deductible']) ? money($b['deductible'], $cur) : '—' }}</td>
            <td class="r num">{{ $b['unlimited'] ? t('Unlimited') : ($b['annualLimit'] !== null ? money($b['annualLimit'], $cur) : '—') }}</td>
            <td>@if ($b['waiting']['served'] === false){{ pill(t('Not served'), 'amber') }}@else<span class="muted" style="font-size:12px">{{ $b['waiting']['served'] ? t('Served') : t('Not recorded') }}</span>@endif</td>
          </tr>
        @endforeach</tbody>
      </table>
    </div>
    <p class="muted" style="font-size:12px;padding:10px 20px 18px">{{ t("These are the plan's terms. What VSahha pays is decided when the claim is assessed.") }}</p>
  </x-card>
@endif
@endsection
