@extends('layouts.app')
@php
  use App\Portal\Vocab;
  $ctx = me();
  $stage = Vocab::paStage($p['status'], ! empty($p['partly']));
  $gop = $p['gop'] ?? null;
  $canSubmit = $ctx->can('preauth.submit');
  $used = (float) ($p['utilisation']['claimed'] ?? 0);
  $at = Vocab::paJourneyIndex($p['status'], (bool) $gop);
@endphp
@section('title', $p['ref'].' · VSahha Provider Portal')
@section('live', ! empty($p['infoRequired']) ? '0' : '1')
@section('content')
<div>
  <a class="crumb" href="/preauth">{{ icon('back', 15, 'flip') }}{{ t('Pre-authorizations') }}</a>
  @include('partials.branchbar', ['id' => $p['providerId'] ?? null])
  <section class="hero">
    <span class="tile t-{{ $stage['tone'] }}">{{ icon('clip', 24) }}</span>
    <div>
      <div class="row"><h1 class="mono">{{ $p['ref'] }}</h1>{{ pa_pill($p['status'], ! empty($p['partly'])) }}{{ pill(t($p['urgency']).' · '.t('{h} h target', ['h' => ($p['slaHours'] ?? null) ?: (Vocab::PA_URGENCY[$p['urgency']] ?? 72)]), $p['urgency'] === 'Urgent' ? 'red' : ($p['urgency'] === 'High' ? 'amber' : 'muted')) }}</div>
      <p>{{ $p['memberName'] }} · {{ $p['memberId'] }} · {{ ($p['physicianName'] ?? null) ?: t('physician not recorded') }}{{ ! empty($p['physicianSpecialty']) ? ", {$p['physicianSpecialty']}" : '' }} · {{ t('submitted {d}', ['d' => day_time($p['submittedAt'] ?? null)]) }}</p>
      <p>{{ $p['presentingComplaint'] ?? '' }}</p>
    </div>
    <div class="acts row">
      @if ($gop && empty($p['billed']) && $ctx->can('claim.submit'))<form method="post" action="/preauth/{{ rawurlencode($p['id']) }}/to-claim">@csrf<button class="btn btn-p">{{ icon('receipt', 17) }}{{ t('Bill it') }}</button></form>@endif
      @if ($gop)<button type="button" class="btn btn-s" data-doc="/documents/gop/{{ rawurlencode($gop['gopNumber']) }}" data-doc-title="{{ $gop['gopNumber'] }} v{{ $gop['currentVersion'] }}">{{ icon('file', 17) }}{{ t('GOP') }}</button>@endif
    </div>
  </section>
</div>
<x-card><div class="journey" aria-label="{{ t('Progress') }}">
  @foreach (Vocab::PA_JOURNEY as $i => $s)
    @php $done = $i < $at || ($i === $at && $i === 4); @endphp
    <div class="jstep {{ $done ? 'done' : ($i === $at ? 'now' : '') }}"><span class="c">@if ($done){{ icon('check', 13) }}@endif</span>{{ t($s) }}</div>
  @endforeach
</div></x-card>

@if (! empty($p['infoRequired']) && $canSubmit)
  <form class="banner amber" style="flex-direction:column;align-items:stretch;color:var(--ink)" method="post" action="/preauth/{{ rawurlencode($p['id']) }}/reply" enctype="multipart/form-data">@csrf
    <div class="row" style="color:var(--amber)">{{ icon('stetho') }}<b>{{ t('The medical team needs information') }}</b></div>
    @foreach ($p['treatmentLines'] as $x)@if (! empty($x['infoRequested']))<p><b>{{ $x['description'] }}:</b> “{{ implode(' ', $x['infoRequested']) }}”</p>@endif @endforeach
    <label class="field"><span class="lbl">{{ t('Your answer') }}</span><textarea name="response" placeholder="{{ t('Answer the question, and attach what the medical team asked for…') }}" required>{{ old('response') }}</textarea></label>
    <label class="drop compact">{{ icon('upload', 18) }}<div><b>{{ t('Attach reports, results or letters') }}</b></div><input type="file" name="files[]" multiple hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.heic" data-file-names></label>
    <div class="files" data-file-list></div>
    <label class="field"><span class="lbl">{{ t('Document type') }}</span><select name="docType">@foreach (Vocab::PA_DOC_TYPES as $k)<option value="{{ $k }}">{{ t($k) }}</option>@endforeach</select></label>
    <div class="row end"><button class="btn btn-p">{{ icon('send', 17) }}{{ t('Send to the medical team') }}</button></div>
  </form>
@endif

<div class="grid-side">
  <div class="stack">
    <x-card :title="t('Treatments')" :pad="false">
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>{{ t('Treatment') }}</th><th>{{ t('Decision') }}</th><th class="r">{{ t('Requested') }}</th><th class="r">{{ t('Authorised') }}</th><th class="r">{{ t('Member pays') }}</th></tr></thead>
        <tbody>@foreach ($p['treatmentLines'] as $x)
          <tr>
            <td><b>{{ $x['description'] }}</b><div class="sub">{{ $x['icd10'] ?? '' }}{{ ! empty($x['cpt']) ? " · CPT {$x['cpt']}" : '' }} · {{ $x['benefitCode'] ?? '' }} · {{ day($x['serviceDate'] ?? null) }}{{ ! empty($x['requestedDays']) ? " · {$x['requestedDays']} ".t('days') : '' }}{{ ! empty($x['requestedSessions']) ? " · {$x['requestedSessions']} ".t('sessions') : '' }}</div>
              @if (! empty($x['rejectionReason']))<div class="reason" style="color:var(--red)">{{ $x['rejectionReason'] }}</div>@endif
              @if (! empty($x['partialReason']))<div class="reason">{{ $x['partialReason'] }}</div>@endif
              @if (! empty($x['conditions']))<div class="reason">{{ t('Conditions') }}: {{ $x['conditions'] }}</div>@endif</td>
            <td>{{ pill(t($x['decision'] === 'Pending' ? 'Not decided' : $x['decision']), ['Approved' => 'green', 'Rejected' => 'red', 'Partially Approved' => 'amber', 'Additional Information Required' => 'amber'][$x['decision']] ?? 'muted') }}</td>
            <td class="r num">{{ money($x['requestedAmount'] ?? null, $x['currency'] ?? null) }}</td>
            <td class="r num"><b>{{ ($x['approvedAmount'] ?? null) !== null ? money($x['approvedAmount'], $x['currency'] ?? null) : '—' }}</b></td>
            <td class="r num">{{ ($x['memberResponsibility'] ?? null) !== null ? money($x['memberResponsibility'], $x['currency'] ?? null) : '—' }}</td>
          </tr>
        @endforeach</tbody>
      </table></div>
    </x-card>
    <x-card :title="t('Messages')" :sub="t('with the VSahha medical team')">@include('partials.thread', ['messages' => $p['messages'] ?? [], 'subjectType' => 'preauth', 'subjectId' => $p['id']])</x-card>
    @include('partials.notes', ['panel' => $notes])
  </div>
  <div class="stack">
    @if ($gop)
      <x-card :title="t('Guarantee of payment')">
        @if ($canSubmit)<x-slot:action><button type="button" class="lnk" data-dialog="gop-dialog">{{ t('Request a change') }}</button></x-slot:action> @endif
        <dl class="kv kv-1">
          <div><dt>{{ t('Number') }}</dt><dd><span class="mono">{{ $gop['gopNumber'] }} · v{{ $gop['currentVersion'] }}</span></dd></div>
          <div><dt>{{ t('Valid') }}</dt><dd>{{ day($gop['validFrom'] ?? null) }} – {{ day($gop['validUntil'] ?? null) }}</dd></div>
          <div><dt>{{ t('Authorised') }}</dt><dd>{{ money($gop['approvedTotal'] ?? null, $gop['currency'] ?? null) }}</dd></div>
        </dl>
        @if (isset($gop['expiresInDays']) && $gop['expiresInDays'] >= 0 && $gop['expiresInDays'] <= 30)<div class="note note-amber" style="margin-top:12px">{{ icon('clock') }}<div>{{ t('Expires in {n} days. Treat and bill before then, or request an extension.', ['n' => $gop['expiresInDays']]) }}</div></div>@endif
        <div style="margin-top:12px">
          <div class="row" style="font-size:12.5px"><b>{{ t('Used by claims') }}</b><span class="sp"></span>{{ money($used, $gop['currency'] ?? null) }} / {{ money($gop['approvedTotal'] ?? null, $gop['currency'] ?? null) }}</div>
          <div class="hbar" style="grid-template-columns:1fr"><div class="track"><i style="width:{{ min(100, ! empty($gop['approvedTotal']) ? $used / $gop['approvedTotal'] * 100 : 0) }}%"></i></div></div>
          @foreach ($p['utilisation']['claims'] ?? [] as $c)<a class="lnk" href="/claims/{{ rawurlencode($c['id']) }}" style="display:block">{{ $c['id'] }} · {{ money($c['claimed'], $gop['currency'] ?? null) }}</a>@endforeach
        </div>
        @if (count($gop['versions'] ?? []) > 1)
          <div style="margin-top:12px"><b style="font-size:12.5px">{{ t('Versions') }}</b>@foreach ($gop['versions'] as $v)
            <div class="row" style="font-size:12.5px">v{{ $v['version'] }} · {{ day($v['issueDate'] ?? null) }} · {{ $v['reason'] ?? '' }}<span class="sp"></span><button type="button" class="lnk" data-doc="/documents/gop/{{ rawurlencode($gop['gopNumber']) }}?version={{ $v['version'] }}" data-doc-title="{{ $gop['gopNumber'] }} v{{ $v['version'] }}">{{ t('View') }}</button></div>
          @endforeach</div>
        @endif
      </x-card>
    @else
      <x-card :title="t('Guarantee of payment')"><p class="muted" style="font-size:13px">{{ t('Issued when the medical team authorises at least one treatment.') }}</p></x-card>
    @endif
    @if (! empty($p['requests']))
      <x-card :title="t('Your requests')">@foreach ($p['requests'] as $r)
        <div style="padding:4px 0"><div class="row" style="font-size:13px"><b>{{ $r['id'] }}</b>{{ t($r['kind']) }}<span class="sp"></span>{{ pill(t($r['status']), $r['status'] === 'Approved' ? 'green' : ($r['status'] === 'Declined' ? 'red' : 'blue')) }}</div>@if (! empty($r['note']))<div class="sub">{{ $r['note'] }}</div>@endif</div>
      @endforeach</x-card>
    @endif
    <x-card :title="t('Timeline')"><div class="timeline">@foreach ($p['timeline'] ?? [] as $e)
      <div class="tl"><span class="c">{{ icon('clock', 12) }}</span><div><b>{{ t($e['title']) }}</b><span>{{ day_time($e['at']) }}</span></div></div>
    @endforeach</div></x-card>
    <x-card :title="t('Documents')">
      @forelse ($p['documents'] ?? [] as $d)
        <div class="row" style="font-size:13px;padding:5px 0;flex-wrap:nowrap;align-items:flex-start">{{ icon('doc', 16) }}<div style="min-width:0"><div>{{ $d['name'] }}</div><div class="muted" style="font-size:12px">{{ t($d['type'] ?? '') }} · v{{ $d['version'] ?? 1 }}</div></div></div>
      @empty
        <p class="muted" style="font-size:13px">{{ t('No documents.') }}</p>
      @endforelse
    </x-card>
  </div>
</div>
@endsection
@section('dialogs')
  @if ($gop && $canSubmit)
    <div class="layer" id="gop-dialog" hidden>
      <form class="modal modal-md" method="post" action="/preauth/{{ rawurlencode($p['id']) }}/gop-request" role="dialog" aria-modal="true">@csrf
        <header><div><h3>{{ t('Request a change to {g}', ['g' => $gop['gopNumber']]) }}</h3><p>{{ t('A GOP is never edited directly. VSahha reviews your request like a new one; the current GOP stays valid meanwhile.') }}</p></div><button class="x" type="button" aria-label="{{ t('Close') }}" data-dialog-close>{{ icon('x') }}</button></header>
        <div class="modal-b">
          <div class="row"><label class="check"><input type="radio" name="kind" value="validity" checked>{{ t('Extend the validity') }}</label><label class="check"><input type="radio" name="kind" value="amount">{{ t('Increase the amount') }}</label></div>
          <label class="field"><span class="lbl">{{ t('New end date') }}</span><input type="date" name="newValidUntil" min="{{ $gop['validUntil'] ?? '' }}"></label>
          <label class="field"><span class="lbl">{{ t('New total amount') }} ({{ $p['currency'] }})</span><input type="number" step="0.01" name="newAmount"></label>
          <label class="field"><span class="lbl">{{ t('Reason') }}</span><textarea name="reason" required></textarea><span class="hint">{{ t('Clinical reason, or what delayed the treatment.') }}</span></label>
        </div>
        <footer><button type="button" class="btn btn-s" data-dialog-close>{{ t('Cancel') }}</button><button class="btn btn-p">{{ t('Send the request') }}</button></footer>
      </form>
    </div>
  @endif
@endsection
