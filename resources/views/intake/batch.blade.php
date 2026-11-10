@extends('layouts.app')
@php
  use App\Portal\Lang;
  $b = $batch;
  $list = $documents;
  $kinds = fn ($d) => array_column($d['signals'], 'kind');
  $ready = array_filter($list, fn ($d) => ! empty($d['patient']['memberId']) && $d['status'] !== 'Filed' && ! in_array('duplicate-file', $kinds($d), true));
  $unknown = count(array_filter($list, fn ($d) => ! empty($d['diagnosis']['unknown'])));
  $toCheck = array_sum(array_map(fn ($d) => count(array_filter($kinds($d), fn ($k) => in_array($k, ['check', 'disagree'], true))), $list));
@endphp
@section('title', t('Smart Intake').' · VSahha Provider Portal')
@section('live', '0')
@section('content')
<x-page-head icon="spark" :title="t('Smart Intake')" :sub="$b['id'].' · '.day_time($b['createdAt'])">
  <x-slot:actions><a class="btn btn-s" href="/intake">{{ icon('upload') }}{{ t('New upload') }}</a></x-slot:actions>
</x-page-head>
@if ($b['status'] === 'Reading')
  <x-card><div class="loading" data-batch-status="/intake/{{ $b['id'] }}/status">{{ t('Matching patients and diagnoses with VSahha… You can leave this page; the documents will be here when you come back.') }}</div>
    <noscript><a class="btn btn-s" href="/intake/{{ $b['id'] }}">{{ t('Refresh') }}</a></noscript></x-card>
@elseif ($b['status'] === 'Failed')
  <x-card><div class="note note-red">{{ icon('alert') }}<div>{{ t('This upload could not be read: {why}', ['why' => $b['error'] ?: '—']) }}</div></div></x-card>
@else
  <div class="chips">
    <span class="chip">{{ t('{n} documents', ['n' => count($list)]) }}</span>
    <span class="chip"><span class="d" style="background:var(--green)"></span>{{ t('{n} filed under a patient automatically', ['n' => count(array_filter($list, fn ($d) => ($d['patient']['status'] ?? null) === 'auto'))]) }}</span>
    <span class="chip"><span class="d" style="background:var(--amber)"></span>{{ t('{n} need a person', ['n' => count(array_filter($list, fn ($d) => empty($d['patient']['memberId'])))]) }}</span>
    @if ($unknown)<span class="chip"><span class="d" style="background:var(--amber)"></span>{{ t('{n} Unknown Illness', ['n' => $unknown]) }}</span>@endif
    @if ($toCheck)<span class="chip"><span class="d" style="background:var(--amber)"></span>{{ plural($toCheck, '{n} value to check', '{n} values to check') }}</span>@endif
    <span class="chip"><span class="d" style="background:var(--faint)"></span>{{ t('Reader') }}: {{ $b['engine'] }}</span>
  </div>
  @foreach ($notes as $n)<div class="note note-grey">{{ icon('info') }}<div>{{ $n }}</div></div>@endforeach
  @if ($filed)
    <x-card :title="t('Filed into {n} claim draft(s)', ['n' => count($filed['drafts'])])">
      @if (! empty($filed['skipped']))<div class="note note-amber"><div>{{ t($filed['skipped']) }}</div></div>@endif
      @foreach ($filed['drafts'] as $d)
        <a class="li" href="/claims/new?draft={{ $d['id'] }}" style="grid-template-columns:minmax(0,1fr) auto auto">
          <div><div class="id">{{ $d['title'] }}</div><div class="who">{{ t('{n} documents', ['n' => $d['documents']]) }}{{ $d['preAuthId'] ? ' · '.t('linked to an authorised pre-authorization') : '' }}{{ $d['gopChoices'] ? ' · '.t('several open GOPs cover this condition: choose one in the claim') : '' }}</div></div>
          @if ($d['unknown'])<span class="tag-unknown">{{ t('Unknown Illness') }}</span>@elseif ($d['needsCode']){{ pill(t('Choose the ICD-10 code'), 'amber') }}@else{{ pill(t('Diagnosis confirmed'), 'green') }}@endif
          <span class="lnk">{{ t('Review and submit') }}{{ icon('chev', 15, 'flip') }}</span>
        </a>
      @endforeach
    </x-card>
  @endif
  @foreach ($list as $doc)@include('intake._doc', ['doc' => $doc])@endforeach
  @if (! $list)<x-card><x-empty icon="doc" :title="t('No document was read from this upload')" /></x-card>@endif
  <form class="row end" method="post" action="/intake/{{ $b['id'] }}/file">@csrf
    <span class="muted" style="font-size:12.5px">{{ t('Same patient, same condition and dates within 7 days become one claim. Its diagnosis is the confirmed code, or Unknown Illness.') }}</span>
    <button class="btn btn-p" @disabled(! $ready)>{{ icon('layers') }}{{ t('File {n} document(s) into claims', ['n' => count($ready)]) }}</button>
  </form>
@endif
@endsection
@push('scripts')<script src="/js/intake.js"></script>@endpush
