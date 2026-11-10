@extends('layouts.app')
@php
  use App\Http\Controllers\PreauthController as PC;
  use App\Portal\Vocab;
  $nodes = $plan['nodes'] ?? [];
  $cats = array_values(array_filter($nodes, fn ($n) => $n['level'] === 'Category'));
  $cur = ($plan['currency'] ?? null) ?: 'USD';
@endphp
@section('title', t('Request pre-authorization').' · VSahha Provider Portal')
@section('live', '0')
@section('content')
<x-page-head icon="clip" :title="t('Request pre-authorization').(! empty($d['memberName']) ? ' · '.$d['memberName'] : '')" :sub="t('Only the benefits sold in the member\'s plan are offered. The medical team answers within the urgency\'s target.')">
  <x-slot:actions><button class="btn btn-s" form="wiz" name="nav" value="save">{{ icon('edit') }}{{ t('Save draft') }}</button></x-slot:actions>
</x-page-head>
<a class="crumb" href="/preauth">{{ icon('back', 15, 'flip') }}{{ t('Pre-authorizations') }}</a>
<ol class="steps" data-stepper>
  @foreach (PC::STEPS as $i => $s)
    <li class="{{ $i < $step ? 'done' : ($i === $step ? 'now' : '') }}"><button form="wiz" name="nav" value="go:{{ $i }}" @disabled($i > $step)><span class="c">@if ($i < $step){{ icon('check', 13) }}@else{{ $i + 1 }}@endif</span>{{ t($s) }}</button></li>
  @endforeach
</ol>
<template data-check-icon>{{ icon('check', 13) }}</template>
<form id="wiz" data-soft data-steps novalidate method="post" action="/preauth/new" enctype="multipart/form-data" class="stack">@csrf
  <input type="hidden" name="draftId" value="{{ $draftId }}"><input type="hidden" name="step" value="{{ $step }}">
  <section data-step="0" @if ($step !== 0) hidden @endif>
    <x-card :title="t('Which member?')">
      <div class="erow"><label class="field"><span class="lbl">{{ t('Card number or member ID') }}</span><div class="input">{{ icon('scan', 17) }}<input name="lookup" value="{{ $lookup }}" placeholder="VS-4410-2201-8845"></div></label><span></span><button class="btn btn-p" name="nav" value="find">{{ t('Find') }}</button></div>
      @if ($elig)
        <div class="result {{ $elig['eligible'] ? '' : 'bad' }}"><span class="ok {{ $elig['eligible'] ? 't-green' : 't-red' }}">{{ icon($elig['eligible'] ? 'check' : 'x', 24) }}</span>
          <div style="min-width:0"><b>{{ $elig['member']['name'] }}</b><div class="meta">{{ $elig['member']['id'] }} · {{ t($elig['member']['relation'] ?? '') }} · {{ t('born {d}', ['d' => day($elig['member']['dob'] ?? null)]) }}</div>
            <div class="checks">@foreach ($elig['checks'] as $c)<span class="{{ $c['ok'] ? 't-green' : 't-red' }}" title="{{ $c['detail'] }}">{{ icon($c['ok'] ? 'check' : 'x', 13) }}{{ t($c['label']) }}</span>@endforeach</div>
            @if (! $elig['eligible'])<div class="meta" style="color:var(--red);margin-top:6px;font-weight:600">{{ $elig['summary'] }}</div>@endif</div></div>
      @endif
      <div class="wiz-foot"><span></span><button class="btn btn-p" name="nav" value="go:1" @disabled(empty($d['memberId']))>{{ t('Next') }}{{ icon('arrow', 16, 'flip') }}</button></div>
    </x-card>
  </section>
  <section data-step="1" @if ($step !== 1) hidden @endif>
    <x-card :title="t('Physician and urgency')">
      <div class="form">
        <label class="field"><span class="lbl">{{ t('Requesting physician') }}</span><input name="d[physicianName]" value="{{ $d['physicianName'] }}" placeholder="Dr. …"></label>
        <label class="field"><span class="lbl">{{ t('Specialty') }}</span><input name="d[physicianSpecialty]" value="{{ $d['physicianSpecialty'] }}"></label>
        <div class="field wide"><span class="lbl">{{ t('Urgency') }}</span><div class="row">@foreach (Vocab::PA_URGENCY as $u => $hours)
          <label class="check" style="border:1.5px solid {{ $d['urgency'] === $u ? 'var(--teal)' : 'var(--line)' }};border-radius:12px;padding:10px 14px;background:{{ $d['urgency'] === $u ? 'var(--mint)' : '#fff' }}"><input type="radio" name="d[urgency]" value="{{ $u }}" @checked($d['urgency'] === $u)><div><b>{{ t($u) }}</b><div class="muted" style="font-size:12px">{{ t('answer within {h} h', ['h' => $hours]) }}</div></div></label>
        @endforeach</div></div>
        <label class="field wide"><span class="lbl">{{ t('Presenting complaint') }}<i aria-hidden="true"> *</i></span><textarea name="d[presentingComplaint]" placeholder="{{ t('What brings the patient, since when…') }}">{{ $d['presentingComplaint'] }}</textarea></label>
        <label class="field wide"><span class="lbl">{{ t('Clinical history') }}</span><textarea name="d[clinicalHistory]" placeholder="{{ t('Treatment already tried, relevant history…') }}">{{ $d['clinicalHistory'] }}</textarea></label>
      </div>
      <div class="wiz-foot"><button class="btn btn-s" name="nav" value="go:0">{{ t('Back') }}</button><button class="btn btn-p" name="nav" value="go:2">{{ t('Next') }}{{ icon('arrow', 16, 'flip') }}</button></div>
    </x-card>
  </section>
  <section data-step="2" @if ($step !== 2) hidden @endif>
    <x-card :title="t('Treatments requested')">
      @foreach ($d['treatments'] as $i => $x)
        @php
          $bens = array_values(array_filter($nodes, fn ($n) => $n['level'] === 'Benefit' && $n['category'] === ($x['categoryId'] ?? '')));
          $subs = array_values(array_filter($nodes, fn ($n) => $n['level'] === 'Sub-Benefit' && str_starts_with($n['code'], ($x['benefitCode'] ?? '').'-')));
        @endphp
        <div class="lineedit" style="grid-template-columns:repeat(3,minmax(0,1fr))">
          <label class="field"><span class="lbl">{{ t('Description') }}<i aria-hidden="true"> *</i></span><input name="tx[{{ $i }}][description]" value="{{ $x['description'] }}" placeholder="{{ t('e.g. MRI lumbar spine') }}"></label>
          <label class="field"><span class="lbl">{{ t('Category in the plan') }}<i aria-hidden="true"> *</i></span><select name="tx[{{ $i }}][categoryId]" data-submit-on-change><option value="">{{ t('Choose…') }}</option>@foreach ($cats as $c)<option value="{{ $c['code'] }}" @selected(($x['categoryId'] ?? '') === $c['code'])>{{ $c['code'] }} · {{ $c['name'] }}</option>@endforeach</select></label>
          <label class="field"><span class="lbl">{{ t('Benefit') }}</span><select name="tx[{{ $i }}][benefitCode]" data-submit-on-change @disabled(! $bens)><option value="">{{ $bens ? t('Whole category') : t('No benefit under this category') }}</option>@foreach ($bens as $b)<option value="{{ $b['code'] }}" @selected(($x['benefitCode'] ?? '') === $b['code'])>{{ $b['code'] }} · {{ $b['name'] }}{{ ($b['preAuth'] ?? null) === 'Required' ? ' · '.t('pre-auth') : '' }}</option>@endforeach</select></label>
          @if ($subs)<label class="field"><span class="lbl">{{ t('Sub-benefit') }}</span><select name="tx[{{ $i }}][subBenefitCode]"><option value="">{{ t('None') }}</option>@foreach ($subs as $s)<option value="{{ $s['code'] }}" @selected(($x['subBenefitCode'] ?? '') === $s['code'])>{{ $s['code'] }} · {{ $s['name'] }}</option>@endforeach</select></label>@endif
          <div class="field"><span class="lbl">{{ t('Diagnosis (ICD-10)') }}<i aria-hidden="true"> *</i></span>@include('partials.icd', ['name' => "tx[{$i}][icd10]", 'value' => $x['icd10'] ?? '', 'certaintyName' => "tx[{$i}][certainty]", 'certainty' => ! empty($x['unknown']) ? Vocab::UNKNOWN_ILLNESS : 'Confirmed', 'unknown' => ! empty($x['unknown'])])</div>
          <label class="field"><span class="lbl">CPT</span><input name="tx[{{ $i }}][cpt]" value="{{ $x['cpt'] ?? '' }}" placeholder="72148"></label>
          <label class="field"><span class="lbl">{{ t('Requested amount') }} ({{ $cur }})<i aria-hidden="true"> *</i></span><input type="number" step="0.01" name="tx[{{ $i }}][requestedAmount]" value="{{ $x['requestedAmount'] ?? '' }}"></label>
          <label class="field"><span class="lbl">{{ t('Planned date') }}</span><input type="date" name="tx[{{ $i }}][serviceDate]" value="{{ $x['serviceDate'] ?? '' }}"></label>
          <div class="field"><span class="lbl">{{ t('Quantity / sessions / days') }}</span><div class="row" style="flex-wrap:nowrap">
            <input type="number" placeholder="{{ t('qty') }}" name="tx[{{ $i }}][requestedQuantity]" value="{{ $x['requestedQuantity'] ?? '' }}">
            <input type="number" placeholder="{{ t('sessions') }}" name="tx[{{ $i }}][requestedSessions]" value="{{ $x['requestedSessions'] ?? '' }}">
            <input type="number" placeholder="{{ t('days') }}" name="tx[{{ $i }}][requestedDays]" value="{{ $x['requestedDays'] ?? '' }}"></div></div>
          <label class="field wide"><span class="lbl">{{ t('Clinical justification') }}</span><textarea rows="2" name="tx[{{ $i }}][clinicalJustification]">{{ $x['clinicalJustification'] ?? '' }}</textarea></label>
          <div style="grid-column:1/-1" class="row end"><button class="btn btn-danger btn-sm" name="nav" value="removeTx:{{ $i }}" @disabled(count($d['treatments']) === 1)>{{ icon('trash', 15) }}{{ t('Remove') }}</button></div>
        </div>
      @endforeach
      <button class="btn btn-s btn-sm" name="nav" value="addTx" style="margin-top:10px">{{ icon('plus', 15) }}{{ t('Add a treatment') }}</button>
      <div class="wiz-foot"><button class="btn btn-s" name="nav" value="go:1">{{ t('Back') }}</button><button class="btn btn-p" name="nav" value="go:3">{{ t('Next') }}{{ icon('arrow', 16, 'flip') }}</button></div>
    </x-card>
  </section>
  <section data-step="3" @if ($step !== 3) hidden @endif>
    <x-card :title="t('Supporting documents')" :sub="t('reports, imaging, quotation, admission request')">
      <label class="drop">{{ icon('upload', 26) }}<div><b>{{ t('Drop files here or choose them') }}</b><span>{{ t('PDF, photos or scans · up to 20 MB each') }}</span></div><input type="file" name="files[]" multiple hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.heic" data-submit-on-change></label>
      @foreach ($d['documents'] as $x)
        <div class="docreq ok"><div><b>{{ $x['name'] }}</b></div><div class="row"><select name="docType[{{ $x['ref'] }}]">@foreach (Vocab::PA_DOC_TYPES as $k)<option value="{{ $k }}" @selected(($x['type'] ?? null) === $k)>{{ t($k) }}</option>@endforeach</select><button class="btn btn-danger btn-sm" name="nav" value="removeDoc:{{ $x['ref'] }}" aria-label="{{ t('Remove') }}">{{ icon('trash', 15) }}</button></div></div>
      @endforeach
      <div class="wiz-foot"><button class="btn btn-s" name="nav" value="go:2">{{ t('Back') }}</button><button class="btn btn-p" name="nav" value="go:4">{{ t('Review') }}{{ icon('arrow', 16, 'flip') }}</button></div>
    </x-card>
  </section>
  <section data-step="4" @if ($step !== 4) hidden @endif>
    <x-card :title="t('VSahha\'s checks')" :sub="t('the same pipeline your request will go through')">
      @if ($checkError)<x-error-box :message="$checkError->getMessage()" :details="$checkError->details" />@endif
      @if ($check)
        <div class="note note-{{ $check['canSubmit'] ? ($check['outcome'] === 'Eligibility Confirmed' ? 'green' : 'amber') : 'red' }}">{{ icon($check['canSubmit'] ? 'check' : 'alert') }}<div><b>{{ t($check['outcome']) }}</b>{{ empty($check['standing']['canSubmit']) ? ($check['standing']['reason'] ?? '') : '' }}</div></div>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ t('Stage') }}</th><th>{{ t('Check') }}</th><th>{{ t('Result') }}</th><th>{{ t('Detail') }}</th></tr></thead>
          <tbody>@foreach ($check['checks'] as $c)<tr><td>{{ t($c['stage']) }}</td><td><b>{{ $c['label'] }}</b></td><td>{{ pill(t($c['result']), $c['result'] === 'Pass' ? 'green' : ($c['result'] === 'Fail' ? 'red' : 'amber')) }}</td><td class="sub">{{ $c['detail'] }}</td></tr>@endforeach</tbody></table></div>
        <h3 style="font-size:14px;margin-top:14px">{{ t('Estimate — not a decision') }}</h3>
        @foreach ($check['estimates']['rows'] as $r)
          <div class="row" style="font-size:13px;padding:6px 0;border-bottom:1px solid var(--line2)"><b>{{ $r['description'] }}</b>{{ pill(t($r['coverageStatus'] ?? ''), ($r['coverageStatus'] ?? null) === 'Covered' ? 'green' : 'amber') }}<span class="sp"></span>{{ t('VSahha {a} · member {b}', ['a' => money($r['insurerResponsibility'] ?? null, $r['currency'] ?? null), 'b' => money($r['memberResponsibility'] ?? null, $r['currency'] ?? null)]) }}</div>
        @endforeach
      @endif
      <div class="wiz-foot"><button class="btn btn-s" name="nav" value="go:3">{{ t('Back') }}</button><button class="btn btn-p" name="nav" value="submit" @disabled(empty($check['canSubmit']))>{{ icon('send', 17) }}{{ t('Submit to VSahha') }}</button></div>
    </x-card>
  </section>
</form>
@endsection
