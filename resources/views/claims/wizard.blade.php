@extends('layouts.app')
@php
  use App\Http\Controllers\ClaimsController as CC;
  use App\Portal\Vocab;
  $ctx = me();
  $rootOf = array_flip(Vocab::CLAIM_TYPES)[$d['type']] ?? 'OP';
  $inpatient = in_array($rootOf, Vocab::INPATIENT_ROOTS, true);
  $unknown = ($d['diagnosisCertainty'] ?? null) === Vocab::UNKNOWN_ILLNESS;
  $issues = $check['issues'] ?? [];
  $blocks = array_values(array_filter($issues, fn ($i) => $i['severity'] === 'block'));
  $warns = array_values(array_filter($issues, fn ($i) => $i['severity'] === 'warn'));
  $required = $check['documents']['required'] ?? [];
  $lineReq = $check['documents']['lines'] ?? [];
  $nodes = $plan['nodes'] ?? [];
  $ai = $d['aiFields'] ?? [];
  $confirmed = $d['aiConfirmed'] ?? [];
  $markOf = fn ($k, $value) => $value !== null && $value !== '' && ! empty($ai[$k]) && (string) ($ai[$k]['value'] ?? '') === (string) $value && ! in_array($k, $confirmed, true) ? $ai[$k] : null;
  $lineVal = fn ($l, $k) => ['description' => $l['description'] ?? '', 'quantity' => (float) ($l['quantity'] ?? 0), 'amount' => (float) ($l['claimedAmount'] ?? 0), 'tariffCode' => ($l['tariffCode'] ?? null) ?: null][$k];
  $lineMark = fn ($l, $k) => isset($l['ai'][$k]) && (string) (is_numeric($l['ai'][$k]) ? (float) $l['ai'][$k] : $l['ai'][$k]) === (string) $lineVal($l, $k) && ! in_array($k, $l['aiConfirmed'] ?? [], true) ? $l['ai'] : null;
  $unconfirmed = $review['unconfirmed'] ?? [];
  $total = array_sum(array_map(fn ($l) => (float) ($l['claimedAmount'] ?? 0), $d['lines']));
  $canNext2 = ! array_filter($d['lines'], fn ($l) => empty($l['code']) || ! ((float) ($l['claimedAmount'] ?? 0) > 0));
@endphp
@section('title', t('New claim').' · VSahha Provider Portal')
@section('live', '0')
@section('content')
<x-page-head icon="file" :title="t('New claim').(! empty($d['memberName']) ? ' · '.$d['memberName'] : '')"
  :sub="! empty($d['intakeId']) ? t('Prepared by Smart Intake — check every field marked “Read” or “AI” before you submit.') : (! empty($d['preAuthId']) ? t('Pre-filled from the authorised pre-authorization {r}.', ['r' => $d['preAuthRef'] ?? '']) : t('Direct billing to VSahha.'))">
  <x-slot:actions>
    @if ($savedAt)<span class="muted" style="font-size:12.5px">{{ t('Draft saved {time}', ['time' => clock_of($savedAt)]) }}</span>@endif
    <button class="btn btn-s" form="wiz" name="nav" value="save">{{ icon('edit') }}{{ t('Save draft') }}</button>
  </x-slot:actions>
</x-page-head>
<a class="crumb" href="/claims">{{ icon('back', 15, 'flip') }}{{ t('Claims') }}</a>
<ol class="steps" data-stepper>
  @foreach (CC::STEPS as $i => $s)
    <li class="{{ $i < $step ? 'done' : ($i === $step ? 'now' : '') }}"><button type="submit" form="wiz" name="nav" value="go:{{ $i }}" @disabled($i > $step)><span class="c">@if ($i < $step){{ icon('check', 13) }}@else{{ $i + 1 }}@endif</span>{{ t($s) }}</button></li>
  @endforeach
</ol>
<template data-check-icon>{{ icon('check', 13) }}</template>

<form id="wiz" data-soft data-steps novalidate method="post" action="/claims/new" enctype="multipart/form-data">@csrf
  <input type="hidden" name="draftId" value="{{ $draftId }}"><input type="hidden" name="step" value="{{ $step }}">
  <div class="grid-side">
    <div class="stack">
      <section data-step="0" @if ($step !== 0) hidden @endif>
        <x-card :title="t('Who was treated?')">
          <div class="erow">
            <label class="field"><span class="lbl">{{ t('Card number or member ID') }}</span><div class="input">{{ icon('scan', 17) }}<input name="lookup" value="{{ $lookup }}" placeholder="VS-4410-2201-8845"></div></label>
            <label class="field"><span class="lbl">{{ t('Treatment date') }}</span><input type="date" name="d[treatmentDate]" value="{{ $d['treatmentDate'] ?? '' }}" data-only-visible @disabled($step !== 0)></label>
            <button class="btn btn-p" name="nav" value="find">{{ t('Find') }}</button>
          </div>
          @if ($elig && ! empty($d['memberId']))
            <div class="result {{ $elig['eligible'] ? '' : 'bad' }}">
              <span class="ok {{ $elig['eligible'] ? 't-green' : 't-red' }}">{{ icon($elig['eligible'] ? 'check' : 'x', 24) }}</span>
              <div style="min-width:0"><b>{{ $elig['member']['name'] }}</b>
                <div class="meta">{{ $elig['member']['id'] }} · {{ t($elig['member']['relation'] ?? '') }} · {{ t('born {d}', ['d' => day($elig['member']['dob'] ?? null)]) }}</div>
                <div class="meta">{{ ! empty($elig['policy']) ? "{$elig['policy']['id']} {$elig['policy']['name']}" : t('No policy') }} · {{ ($elig['insurer']['name'] ?? null) ?: t('Insurer not recorded') }}</div>
                <div class="checks">@foreach ($elig['checks'] as $c)<span class="{{ $c['ok'] ? 't-green' : 't-red' }}" title="{{ $c['detail'] }}">{{ icon($c['ok'] ? 'check' : 'x', 13) }}{{ t($c['label']) }}</span>@endforeach</div>
                @if (! $elig['eligible'])<div class="meta" style="color:var(--red);margin-top:6px;font-weight:600">{{ $elig['summary'] }}</div>@endif
              </div>
            </div>
            @if (! $elig['eligible'])<div class="note note-red">{{ icon('alert') }}<div>{{ t('This member is not covered on the treatment date. VSahha will not accept the claim. Check the date or the card.') }}</div></div>@endif
          @endif
          <div class="wiz-foot"><span></span><button class="btn btn-p" name="nav" value="go:1" @disabled(empty($d['memberId']))>{{ t('Next') }}{{ icon('arrow', 16, 'flip') }}</button></div>
        </x-card>
      </section>

      <section data-step="1" @if ($step !== 1) hidden @endif>
        <x-card :title="t('The encounter')">
          <div class="form">
            <label class="field"><span class="lbl">{{ t('Type of care') }}<i aria-hidden="true"> *</i>@include('claims._mark', ['f' => $markOf('type', $d['type']), 'nav' => 'confirm:type'])</span>
              <select name="d[type]" data-submit-on-change>@foreach (Vocab::CLAIM_TYPES as $label)<option value="{{ $label }}" @selected($d['type'] === $label)>{{ t($label) }}</option>@endforeach</select></label>
            <label class="field"><span class="lbl">{{ t('Invoice number') }}<i aria-hidden="true"> *</i>@include('claims._mark', ['f' => $markOf('invoiceNo', $d['invoiceNo'] ?? ''), 'nav' => 'confirm:invoiceNo'])</span><input name="d[invoiceNo]" value="{{ $d['invoiceNo'] ?? '' }}" placeholder="INV-…"></label>
            <label class="field"><span class="lbl">{{ t('Treatment date') }}<i aria-hidden="true"> *</i>@include('claims._mark', ['f' => $markOf('treatmentDate', $d['treatmentDate'] ?? ''), 'nav' => 'confirm:treatmentDate'])</span><input type="date" name="d[treatmentDate]" value="{{ $d['treatmentDate'] ?? '' }}"></label>
            <label class="field"><span class="lbl">{{ t('Treating physician') }}@include('claims._mark', ['f' => $markOf('physicianName', $d['physicianName'] ?? ''), 'nav' => 'confirm:physicianName'])</span><input name="d[physicianName]" value="{{ $d['physicianName'] ?? '' }}" placeholder="Dr. …"></label>
            @if ($inpatient)
              <label class="field"><span class="lbl">{{ t('Admission date') }}@include('claims._mark', ['f' => $markOf('admissionDate', $d['admissionDate'] ?? ''), 'nav' => 'confirm:admissionDate'])</span><input type="date" name="d[admissionDate]" value="{{ $d['admissionDate'] ?? '' }}"></label>
              <label class="field"><span class="lbl">{{ t('Discharge date') }}@include('claims._mark', ['f' => $markOf('dischargeDate', $d['dischargeDate'] ?? ''), 'nav' => 'confirm:dischargeDate'])</span><input type="date" name="d[dischargeDate]" value="{{ $d['dischargeDate'] ?? '' }}"></label>
            @endif
            <div class="field wide"><span class="lbl">{{ t('Diagnosis (ICD-10)') }}@include('claims._mark', ['f' => $markOf('icd', $d['icd'] ?? ''), 'nav' => 'confirm:icd'])</span>
              @if (empty($d['icd']) && ! empty($d['dxSuggestions']))
                <div class="row" style="margin-bottom:8px"><span class="muted" style="font-size:12.5px">{{ t('The report says “{w}”. Possible codes:', ['w' => $d['diagnosis'] ?? '']) }}</span>
                  @foreach ($d['dxSuggestions'] as $s)<button class="pill t-blue" style="border:0" title="{{ $s['why'] ?? '' }}" name="nav" value="suggest:{{ $s['code'] }}">{{ $s['code'] }} · {{ $s['title'] ?? '' }} · {{ round(($s['confidence'] ?? 0) * 100) }}%</button>@endforeach
                </div>
              @endif
              @include('partials.icd', ['name' => 'd[icd]', 'value' => $d['icd'] ?? '', 'titleName' => 'd[diagnosis]', 'titleValue' => $d['diagnosis'] ?? '', 'certaintyName' => 'd[diagnosisCertainty]', 'certainty' => $d['diagnosisCertainty'] ?? 'Confirmed', 'unknown' => $unknown])
              <span class="hint">{{ $unknown ? t('Unknown Illness: pick the closest code you can justify; R69 when nothing more is known.') : t('Choose the main diagnosis. If the report cannot be read, choose Unknown Illness.') }}</span>
            </div>
            <div class="field wide"><span class="lbl">{{ t('Secondary diagnoses (ICD-10)') }}</span>
              <div class="row" style="flex-wrap:nowrap;gap:6px"><div style="flex:1;min-width:0">@include('partials.icd', ['name' => 'secondary_add', 'value' => '', 'placeholder' => t('Add a secondary diagnosis')])</div><button class="btn btn-s btn-sm" name="nav" value="stay">{{ icon('plus', 15) }}</button></div>
              @if (! empty($d['secondaryIcd']))<div class="files">@foreach ($d['secondaryIcd'] as $c)<span class="file mono">{{ $c }}<button aria-label="{{ t('Remove {c}', ['c' => $c]) }}" name="nav" value="removeSecondary:{{ $c }}">{{ icon('x', 12) }}</button></span>@endforeach</div>@endif
              <span class="hint">{{ t('Optional: other conditions treated at the same visit.') }}</span>
            </div>
          </div>
          @if ($unknown)
            <div class="unknownbox" style="margin-top:14px">
              <h4>{{ icon('stetho') }}{{ t('Unknown Illness') }}</h4>
              <p style="font-size:13px;margin-top:6px">{{ t(Vocab::UNKNOWN_REASONS[($d['unknownReason'] ?? null) ?: 'low-confidence'] ?? '') }} {{ t("The claim is still filed under {p}. VSahha's medical team reviews the diagnosis and re-codes it; you will see the change here.", ['p' => ($d['memberName'] ?? null) ?: ($d['memberId'] ?? '')]) }}</p>
              <div class="row" style="margin-top:8px">
                <label class="field"><span class="lbl">{{ t('Why') }}</span><select name="d[unknownReason]">@foreach (Vocab::UNKNOWN_REASONS as $k => $text)<option value="{{ $k }}" @selected((($d['unknownReason'] ?? null) ?: 'low-confidence') === $k)>{{ t($text) }}</option>@endforeach</select></label>
                <button class="btn btn-s btn-sm" name="nav" value="known">{{ t('The diagnosis is known after all') }}</button>
              </div>
            </div>
          @endif
          <div class="wiz-foot"><button class="btn btn-s" name="nav" value="go:0">{{ t('Back') }}</button><button class="btn btn-p" name="nav" value="go:2">{{ t('Next') }}{{ icon('arrow', 16, 'flip') }}</button></div>
        </x-card>
      </section>

      <section data-step="2" @if ($step !== 2) hidden @endif>
        <x-card :title="t('What was done')" :sub="t('one line per service, at the amount on your invoice')">
          @foreach ($d['lines'] as $i => $l)
            @php $est = $check['estimate']['lines'][$i] ?? null; @endphp
            <div class="lineedit">
              <label class="field"><span class="lbl">{{ t("Benefit in the member's plan") }}<i aria-hidden="true"> *</i></span>
                <select name="lines[{{ $i }}][code]">
                  <option value="">{{ t('Choose…') }}</option>
                  @foreach ($nodes as $n)<option value="{{ $n['code'] }}" @selected(($l['code'] ?? '') === $n['code'])>{{ $n['level'] === 'Category' ? '' : ($n['level'] === 'Benefit' ? '— ' : '—— ') }}{{ $n['code'] }} · {{ $n['name'] }}{{ ($n['preAuth'] ?? null) === 'Required' ? ' · '.t('pre-auth') : '' }}</option>@endforeach
                </select></label>
              <label class="field"><span class="lbl">{{ t('Description') }}@include('claims._mark', ['f' => $lineMark($l, 'description'), 'nav' => "confirmLine:{$i}:description"])</span><input name="lines[{{ $i }}][description]" value="{{ $l['description'] ?? '' }}"></label>
              <label class="field"><span class="lbl">{{ t('Quantity') }}@include('claims._mark', ['f' => $lineMark($l, 'quantity'), 'nav' => "confirmLine:{$i}:quantity"])</span><input type="number" min="1" name="lines[{{ $i }}][quantity]" value="{{ $l['quantity'] ?? 1 }}"></label>
              <label class="field"><span class="lbl">{{ t('Amount') }} ({{ ($plan['currency'] ?? null) ?: 'USD' }})<i aria-hidden="true"> *</i>@include('claims._mark', ['f' => $lineMark($l, 'amount'), 'nav' => "confirmLine:{$i}:amount"])</span><input type="number" min="0" step="0.01" name="lines[{{ $i }}][claimedAmount]" value="{{ $l['claimedAmount'] ?? '' }}"></label>
              <button class="btn btn-danger btn-sm" name="nav" value="removeLine:{{ $i }}" aria-label="{{ t('Remove line') }}" @disabled(count($d['lines']) === 1)>{{ icon('trash', 15) }}</button>
              <label class="field"><span class="lbl">{{ t('Contracted tariff code') }}@include('claims._mark', ['f' => $lineMark($l, 'tariffCode'), 'nav' => "confirmLine:{$i}:tariffCode"])</span>
                <select name="lines[{{ $i }}][tariffCode]"><option value="">{{ t('None') }}</option>@foreach ($tariffs as $x)<option value="{{ $x['code'] }}" @selected(($l['tariffCode'] ?? '') === $x['code'])>{{ $x['code'] }} · {{ $x['name'] ?? '' }} · {{ money($x['agreed'] ?? null) }}</option>@endforeach</select>
                <span class="hint">{{ t('Optional: your contract prices this service.') }}</span></label>
              <label class="field"><span class="lbl">{{ t('Service date') }}</span><input type="date" name="lines[{{ $i }}][serviceDate]" value="{{ ($l['serviceDate'] ?? '') ?: ($d['treatmentDate'] ?? '') }}"></label>
              <div class="field"><span class="lbl">{{ t('Line diagnosis (ICD-10)') }}</span>@include('partials.icd', ['name' => "lines[{$i}][icd]", 'value' => $l['icd'] ?? '', 'placeholder' => ($d['icd'] ?? '') ?: t('Same as the claim')])
                @if (empty($l['icd']))<span class="hint">{{ t("Only if it differs from the claim's diagnosis.") }}</span>@endif</div>
              @if (! empty($est['gop']['candidates']))
                <label class="field"><span class="lbl">{{ t('Pre-authorization (GOP)') }}</span><select name="lines[{{ $i }}][gopId]"><option value="">{{ t('Not linked') }}</option>@foreach ($est['gop']['candidates'] as $g)<option value="{{ $g['id'] }}" @selected(($l['gopId'] ?? '') === $g['id'])>{{ $g['id'] }} · {{ money($g['approved'] ?? null) }} · {{ t('until {d}', ['d' => day($g['validUntil'] ?? null)]) }}</option>@endforeach</select></label>
              @endif
              @if ($est && ($est['payable'] ?? null) !== null)
                <div style="grid-column:1/-1;font-size:12.5px" class="muted">{{ t('Estimate — not a decision') }}: {{ t('VSahha would pay {a}, the member {b}', ['a' => money($est['payable'], $check['estimate']['currency'] ?? null), 'b' => money($est['memberShare'] ?? null, $check['estimate']['currency'] ?? null)]) }}{{ ! empty($est['gop']) && ($est['gop']['state'] ?? null) !== 'Not required' ? ' · '.($est['gop']['detail'] ?? '') : '' }}</div>
              @endif
            </div>
          @endforeach
          <button class="btn btn-s btn-sm" name="nav" value="addLine" style="margin-top:10px">{{ icon('plus', 15) }}{{ t('Add a line') }}</button>
          <div class="wiz-foot"><button class="btn btn-s" name="nav" value="go:1">{{ t('Back') }}</button><button class="btn btn-p" name="nav" value="go:3">{{ t('Next') }}{{ icon('arrow', 16, 'flip') }}</button></div>
        </x-card>
      </section>

      <section data-step="3" @if ($step !== 3) hidden @endif>
        <x-card :title="t('Documents')" :sub="t('what VSahha requires for this type of care')">
          @if ($checkError)<x-error-box :message="$checkError->getMessage()" />@endif
          @foreach ($required as $r)
            @php $held = array_values(array_filter($d['documents'], fn ($x) => ($x['id'] ?? null) === $r['id'])); @endphp
            <div class="docreq {{ $held ? 'ok' : '' }}">
              <div><b>{{ t($r['label']) }}</b>@foreach ($held as $h)<div class="muted" style="font-size:12px">{{ icon('check', 12) }} {{ $h['name'] }}</div>@endforeach</div>
              <label class="drop compact">{{ icon('upload', 18) }}<div><b>{{ $held ? t('Replace') : t('Attach') }}</b></div><input type="file" name="doc[{{ $r['id'] }}]" hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.heic" data-submit-on-change></label>
            </div>
          @endforeach
          @foreach ($lineReq as $i => $lr)
            @foreach ($lr['required'] as $r)
              @php $line = $d['lines'][$i] ?? []; $held = array_values(array_filter($line['documents'] ?? [], fn ($x) => ($x['id'] ?? null) === $r['id'])); @endphp
              <div class="docreq {{ $held ? 'ok' : '' }}">
                <div><b>{{ t($r['label']) }}</b> <span class="muted">· {{ t('line {n}', ['n' => $i + 1]) }}: {{ $line['description'] ?? '' }}</span>@foreach ($held as $h)<div class="muted" style="font-size:12px">{{ icon('check', 12) }} {{ $h['name'] }}</div>@endforeach</div>
                <label class="drop compact">{{ icon('upload', 18) }}<div><b>{{ $held ? t('Replace') : t('Attach') }}</b></div><input type="file" name="linedoc[{{ $i }}][{{ $r['id'] }}]" hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.heic" data-submit-on-change></label>
              </div>
            @endforeach
          @endforeach
          <h3 style="font-size:14px;margin:16px 0 8px">{{ t('Other documents') }}</h3>
          <label class="drop">{{ icon('upload', 26) }}<div><b>{{ t('Reports, results, prescriptions…') }}</b><span>{{ t('PDF, photos or scans · up to 20 MB each') }}</span></div><input type="file" name="other[]" multiple hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.heic" data-submit-on-change></label>
          <div class="files">
            @foreach ($d['documents'] as $x)
              @if (! in_array($x['id'] ?? null, array_column($required, 'id'), true))
                <span class="file">{{ $x['name'] }} · {{ t(Vocab::documentLabel($x['id'] ?? '')) }}
                  <select name="otherType[{{ $x['ref'] }}]" style="border:0;font-size:11px">@foreach (['medical_report', 'discharge_summary', 'lab_results', 'imaging_report', 'prescription', 'detailed_bill'] as $k)<option value="{{ $k }}" @selected(($x['id'] ?? null) === $k)>{{ t(Vocab::documentLabel($k)) }}</option>@endforeach</select>
                  <button name="nav" value="removeDoc:{{ $x['ref'] }}" aria-label="{{ t('Delete') }}">{{ icon('x', 12) }}</button></span>
              @endif
            @endforeach
          </div>
          <div class="wiz-foot"><button class="btn btn-s" name="nav" value="go:2">{{ t('Back') }}</button><button class="btn btn-p" name="nav" value="go:4">{{ t('Check the claim') }}{{ icon('arrow', 16, 'flip') }}</button></div>
        </x-card>
      </section>

      <section data-step="4" @if ($step !== 4) hidden @endif>
        <x-card :title="t('Clean Claim Check')" :sub="t('the checks VSahha will run, before you send')">
          @if ($checkError)<x-error-box :message="$checkError->getMessage()" :details="$checkError->details" />@endif
          @if ($check)
            @php $tone = $check['score'] >= 80 ? 'var(--green)' : ($check['score'] >= 50 ? 'var(--amber)' : 'var(--red)'); @endphp
            <div class="score">
              <span class="ring" style="background:conic-gradient({{ $tone }} {{ $check['score'] * 3.6 }}deg, var(--line2) 0)"><span style="width:58px;height:58px;border-radius:50%;background:#fff;display:grid;place-items:center">{{ $check['score'] }}</span></span>
              <div><b style="font-size:16px">{{ $check['canSubmit'] ? ($issues ? t('Ready to send, with {n} point(s) to check', ['n' => count($issues)]) : t('Clean claim — ready to send')) : t('{n} thing(s) to fix before sending', ['n' => count($blocks)]) }}</b>
                <p class="muted" style="font-size:13px">{{ t('Clean-claim score out of 100. Blocking issues stop the submission; warnings are worth a look.') }}</p></div>
            </div>
            <div class="issues" style="margin-top:14px">
              @foreach ($issues as $x)
                <div class="issue t-{{ $x['severity'] === 'block' ? 'red' : ($x['severity'] === 'warn' ? 'amber' : 'blue') }}">{{ icon($x['severity'] === 'block' ? 'x' : ($x['severity'] === 'warn' ? 'alert' : 'info')) }}<div>{{ $x['text'] }}@if (! empty($x['fix']))<small>{{ $x['fix'] }}</small>@endif</div></div>
              @endforeach
              @if (! $issues)<div class="note note-green">{{ icon('check') }}<div>{{ t('Every check passed.') }}</div></div>@endif
            </div>
            @if (($check['estimate']['payable'] ?? null) !== null)
              <div class="money-strip" style="margin-top:16px;grid-template-columns:repeat(3,minmax(0,1fr))">
                <div><span>{{ t('You claim') }}</span><b class="num">{{ money($check['estimate']['claimed'] ?? null, $check['estimate']['currency'] ?? null) }}</b></div>
                <div><span>{{ t('Member would pay') }}</span><b class="num">{{ money($check['estimate']['memberShare'] ?? null, $check['estimate']['currency'] ?? null) }}</b></div>
                <div class="hl"><span>{{ t('VSahha would pay') }}</span><b class="num">{{ money($check['estimate']['payable'], $check['estimate']['currency'] ?? null) }}</b></div>
              </div>
            @endif
            <p class="muted" style="font-size:12px;margin-top:8px"><b>{{ t('Estimate — not a decision') }}.</b> {{ t("The amounts come from VSahha's benefit engine for this claim as entered. The decision is made by VSahha's claims team.") }}</p>
            @if ($unconfirmed)
              <div class="note note-amber">{{ icon('spark') }}<div>{{ plural(count($unconfirmed), '{n} value filled in from the documents is not confirmed yet.', '{n} values filled in from the documents are not confirmed yet.') }} <button class="lnk" name="nav" value="confirmAll">{{ t('I have checked them all') }}</button></div></div>
            @endif
            @if ($check['canSubmit'] && $warns)
              <label class="check" style="margin-top:12px;padding:10px 12px;border-radius:12px;background:var(--amber-bg)"><input type="checkbox" name="ack" value="1" required>
                {{ count($warns) === 1 ? t('I have read the point above and want to send the claim as it is.') : t('I have read the {n} points above and want to send the claim as it is.', ['n' => count($warns)]) }}</label>
            @endif
          @endif
          <div class="wiz-foot">
            <button class="btn btn-s" name="nav" value="go:3" formnovalidate>{{ t('Back') }}</button>
            <button class="btn btn-p" name="nav" value="submit" @disabled(empty($check['canSubmit']))>{{ icon('send', 17) }}{{ t('Submit to VSahha') }}</button>
          </div>
        </x-card>
      </section>
    </div>

    <div class="stack" data-wiz-side>
      <x-card :title="t('Summary')">
        <dl class="kv kv-1">
          <div><dt>{{ t('Patient') }}</dt><dd>@if (! empty($d['memberName'])){{ $d['memberName'] }} · {{ $d['memberId'] }}@include('claims._mark', ['f' => $markOf('member', $d['memberId'] ?? ''), 'nav' => 'confirm:member'])@else<span class="muted">{{ t('Not recorded') }}</span>@endif</dd></div>
          <div><dt>{{ t('Cover') }}</dt><dd>@if ($elig)@if ($elig['eligible']){{ t('Covered on {d}', ['d' => day($elig['serviceDate'])]) }}@else<span style="color:var(--red)">{{ $elig['summary'] }}</span>@endif @else<span class="muted">{{ t('Not recorded') }}</span>@endif</dd></div>
          <div><dt>{{ t('Type of care') }}</dt><dd>{{ t($d['type']) }}</dd></div>
          <div><dt>{{ t('Invoice') }}</dt><dd>{{ ($d['invoiceNo'] ?? '') ?: t('Not recorded') }}</dd></div>
          <div><dt>{{ t('Diagnosis') }}</dt><dd>{{ ($d['icd'] ?? '') ?: t('Not recorded') }} @if ($unknown)<span class="tag-unknown">{{ t('Unknown Illness') }}</span>@endif</dd></div>
          <div><dt>{{ t('Lines') }}</dt><dd>{{ count($d['lines']) }} · {{ money($total, $plan['currency'] ?? null) }}</dd></div>
          @if (! empty($d['preAuthId']))<div><dt>{{ t('Pre-authorization') }}</dt><dd>{{ ($d['preAuthRef'] ?? null) ?: $d['preAuthId'] }}</dd></div>@endif
          <div><dt>{{ t('Presence') }}</dt><dd>@if ($visit){{ pill(($visit->method === 'card scanned' || $visit->consent_at) ? t('Verified at check-in') : t('Recorded at check-in'), ($visit->method === 'card scanned' || $visit->consent_at) ? 'green' : 'teal') }}@else{{ t('No check-in on the treatment date') }}@endif</dd></div>
        </dl>
      </x-card>
      @if ($check && $step >= 2)
        <x-card :title="t('Live check')">
          <div class="row"><b style="font-size:24px">{{ $check['score'] }}</b><span class="muted">/100</span><span class="sp"></span>{{ $blocks ? pill(t('{n} to fix', ['n' => count($blocks)]), 'red') : pill(t('Can be sent'), 'green') }}</div>
        </x-card>
      @endif
    </div>
  </div>
</form>
@endsection
