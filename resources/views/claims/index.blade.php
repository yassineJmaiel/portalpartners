@extends('layouts.app')
@php
  use App\Http\Controllers\ClaimsController as CC;
  use App\Portal\Vocab;
  $ctx = me();
  $canSubmit = $ctx->can('claim.submit');
  $tabs = [];
  foreach (CC::TABS as $k => $l) { $tabs[$k] = [t($l), $counts[$k]]; }
  $qs = request()->only(['tab', 'q', 'from', 'to', 'min', 'max']);
  $sourceLabel = fn ($s) => match ($s) { 'intake' => t('Prepared by Smart Intake'), 'preauth' => t('From a pre-authorization'), 'bulk' => t('From a CSV file'), default => t('Started by hand') };
@endphp
@section('title', t('Claims').' · VSahha Provider Portal')
@section('content')
<x-page-head icon="file" :title="t('Claims')" :sub="t('Every claim you sent to VSahha, where it is, and what was decided.')">
  <x-slot:actions>
    @if ($ctx->can('intake.use'))<a class="btn btn-s" href="/intake">{{ icon('upload') }}{{ t('Upload invoices') }}</a>@endif
    @if ($canSubmit)<a class="btn btn-s" href="/claims/bulk">{{ icon('layers') }}{{ t('From a CSV file') }}</a><a class="btn btn-p" href="/claims/new">{{ icon('plus') }}{{ t('New claim') }}</a>@endif
  </x-slot:actions>
</x-page-head>
<form class="filters" method="get" action="/claims" id="claims-filter">
  <x-tabs :value="$tab" :tabs="$tabs" />
  <span class="sp"></span>
  <input type="hidden" name="tab" value="{{ $tab }}">
  <div class="input">{{ icon('search', 16) }}<input name="q" value="{{ request('q') }}" placeholder="{{ t('Claim, patient, invoice, ICD-10…') }}" aria-label="{{ t('Search claims') }}"></div>
  @if ($tab !== 'drafts')
    <button type="button" class="btn btn-s btn-sm" data-toggle="more-filters">{{ icon('layers', 15) }}{{ t('Filters') }}</button>
    <a class="btn btn-s btn-sm" href="/claims/export?{{ http_build_query($qs) }}">{{ icon('download', 15) }}{{ t('Export') }}</a>
  @endif
  @if ($tab !== 'drafts')
    <div class="filters filters-more" id="more-filters" style="width:100%" @unless (request('from') || request('to') || request('min') || request('max')) hidden @endunless>
      <label class="field"><span class="lbl">{{ t('Treated from') }}</span><input type="date" name="from" value="{{ request('from') }}"></label>
      <label class="field"><span class="lbl">{{ t('to') }}</span><input type="date" name="to" value="{{ request('to') }}"></label>
      <label class="field"><span class="lbl">{{ t('Amount from') }}</span><input type="number" min="0" name="min" value="{{ request('min') }}"></label>
      <label class="field"><span class="lbl">{{ t('to') }}</span><input type="number" min="0" name="max" value="{{ request('max') }}"></label>
      <button class="btn btn-s btn-sm">{{ t('Apply') }}</button>
      @if ($filtered)<a class="btn btn-s btn-sm" href="/claims?tab={{ $tab }}">{{ t('Clear') }}</a>@endif
    </div>
  @endif
  {{-- saved views live in this browser, per person --}}
  <div class="row" id="saved-views" style="gap:6px;width:100%" data-t-saved="{{ t('Saved views') }}" data-t-delete="{{ t('Delete') }}" data-t-name="{{ t('Name this view') }}" data-t-save="{{ t('Save this view') }}" data-t-done="{{ t('View saved') }}" data-filtered="{{ $filtered ? '1' : '' }}"></div>
</form>

@if ($tab === 'drafts')
  <x-card :pad="false">
    @if (! $drafts)
      <x-empty icon="edit" :title="t('No drafts')">{{ t('Claims you start, and claims Smart Intake prepares, wait here until you submit them.') }}</x-empty>
    @else
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>{{ t('Draft') }}</th><th>{{ t('Prepared by') }}</th><th>{{ t('Updated') }}</th><th></th></tr></thead>
        <tbody>@foreach ($drafts as $d)
          <tr @if ($canSubmit) class="click" data-href="/claims/new?draft={{ $d->id }}" @endif>
            <td><b>{{ $d->title }}</b><div class="sub">{{ $sourceLabel($d->source) }}@if (str_contains((string) $d->title, Vocab::UNKNOWN_ILLNESS)) · <span class="tag-unknown">{{ t('Unknown Illness') }}</span>@endif</div></td>
            <td>{{ $d->by ?: '—' }}</td><td>{{ day_time($d->updated_at) }}</td>
            <td class="r">@if ($canSubmit)<form method="post" action="/drafts/{{ $d->id }}/delete">@csrf<button class="btn btn-danger btn-sm">{{ icon('trash', 15) }}{{ t('Delete') }}</button></form>@endif</td>
          </tr>
        @endforeach</tbody>
      </table></div>
    @endif
  </x-card>
@else
  <x-card :pad="false">
    @if (! $rows)
      <x-empty icon="file" :title="request('q') ? t('No claim matches') : t('Nothing in this list')">{{ request('q') ? t('Try another word.') : t('Claims move between these lists as VSahha works on them.') }}</x-empty>
    @else
      <div class="tbl-wrap"><table class="tbl claim-cards">
        <thead><tr><th>{{ t('Claim') }}</th><th>{{ t('Patient') }}</th><th>{{ t('Treated') }}</th><th>{{ t('Stage') }}</th><th>{{ t('Notes') }}</th><th class="r">{{ t('Amount') }}</th></tr></thead>
        <tbody>@foreach ($rows as $c)
          <tr class="click" data-href="/claims/{{ rawurlencode($c['id']) }}">
            <td><div class="id mono"><a href="/claims/{{ rawurlencode($c['id']) }}">{{ $c['id'] }}</a></div><div class="sub">{{ ($c['invoiceNo'] ?? null) ?: t('No invoice number') }}</div></td>
            <td>{{ $c['memberName'] }}<div class="sub">{{ t($c['type'] ?? '') }}</div>@if ($ctx->all)<span class="branch-tag">{{ icon('home', 11) }}{{ $ctx->branchName($c['providerId'] ?? null) }}</span>@endif</td>
            <td data-l="{{ t('Treated') }}">{{ day($c['treatmentDate'] ?? null) }}</td>
            <td>{{ claim_pill($c['workflowStatus']) }}<div class="sub" style="margin-top:4px">{{ t(Vocab::claimStatusLine($c['workflowStatus'])) }}</div></td>
            <td>
              @if (! empty($c['infoRequest']))<div class="sla" style="color:var(--amber)">{{ icon('chat', 13) }}{{ t('VSahha needs information') }}</div>@endif
              @if (($c['sla']['state'] ?? null) === 'Overdue')<div class="sla">{{ icon('alert', 13) }}{{ t("Past VSahha's {n}-day target", ['n' => $c['sla']['target']]) }}</div>@endif
              @if (($c['diagnosisCertainty'] ?? null) === Vocab::UNKNOWN_ILLNESS)<span class="tag-unknown">{{ t('Unknown Illness') }}</span>@endif
              @if (! empty($c['memberReimbursement']))<div class="sub">{{ t('Member reimbursement') }}</div>@endif
              @if (! empty($c['preAuthId']))<div class="sub">{{ t('Linked to a pre-authorization') }}</div>@endif
            </td>
            <td class="r">
              @if (! empty($c['money'])){{ amt($c['money']['paid'] ?? $c['money']['insurerPayable'], $c['currency'], ($c['money']['paid'] ?? null) !== null ? 'paid' : 'payable') }}
              @else{{ amt($c['claimed'], $c['currency'], 'claimed') }}@endif
            </td>
          </tr>
        @endforeach</tbody>
      </table></div>
    @endif
  </x-card>
@endif
@endsection
