@extends('layouts.app')
@php
  use App\Portal\Vocab;
  $ctx = me();
  $stage = Vocab::claimStage($c['workflowStatus']);
  $canSubmit = $ctx->can('claim.submit');
  $canAppeal = $ctx->can('appeal.submit');
  $appealable = array_values(array_filter($c['lines'], fn ($l) => ! empty($l['appealable'])));
  $open = array_merge([], ...array_map(fn ($a) => in_array($a['status'], ['Received', 'Under review'], true) ? ($a['lineIds'] ?? []) : [], $c['appeals'] ?? []));
  $selectable = array_values(array_filter($appealable, fn ($l) => ! in_array($l['id'], $open, true)));
  $cur = $c['currency'] ?? null;
  $unknown = ($c['diagnosisCertainty'] ?? null) === Vocab::UNKNOWN_ILLNESS;
  $review = $c['medicalReview'] ?? null;
  $docName = fn ($d) => ($d['name'] ?? null) === ($d['id'] ?? null) ? t(Vocab::documentLabel($d['id'] ?? '')) : ($d['name'] ?? '');
  $tlIcon = fn ($title) => preg_match('/Paid|payment/i', $title) ? 'bank' : (preg_match('/information/i', $title) ? 'chat' : (str_starts_with($title, 'Decided') ? 'check' : 'clock'));
@endphp
@section('title', $c['id'].' · VSahha Provider Portal')
@section('live', ! empty($c['infoRequest']) ? '0' : '1')
@section('content')
<div>
  <a class="crumb" href="/claims">{{ icon('back', 15, 'flip') }}{{ t('Claims') }}</a>
  @include('partials.branchbar', ['id' => $c['providerId'] ?? null])
  <section class="hero">
    <span class="tile t-{{ $stage['tone'] }}">{{ icon('file', 24) }}</span>
    <div style="min-width:0">
      <div class="row"><h1 class="mono">{{ $c['id'] }}</h1>{{ claim_pill($c['workflowStatus']) }}
        @if ($unknown)<span class="tag-unknown">{{ t('Unknown Illness') }}</span>@endif
        @if (! empty($c['presence'])){{ pill(! empty($c['presence']['verified']) ? t('Presence verified') : t('Presence recorded'), ! empty($c['presence']['verified']) ? 'green' : 'teal', true, t('Checked in {when}', ['when' => day_time($c['presence']['at'] ?? null)])) }}@endif</div>
      <p>{{ $c['memberName'] }} · {{ $c['memberId'] }} · {{ t($c['type'] ?? '') }} · {{ t('treated {d}', ['d' => day($c['treatmentDate'] ?? null)]) }} · {{ t('invoice {n}', ['n' => ($c['invoiceNo'] ?? null) ?: '—']) }}</p>
      <p>{{ t($stage['desc']) }} {{ t('CRM status') }}: <b>{{ t(Vocab::claimStatusLine($c['workflowStatus'])) }}</b>
        @if (! empty($c['sla'])) ·
          @if (! empty($c['sla']['paused'])){{ t('Service clock paused (waiting on you)') }}
          @elseif (($c['sla']['state'] ?? null) === 'Overdue')<span style="color:var(--red);font-weight:700">{{ t("Past VSahha's {n}-day target (due {d})", ['n' => $c['sla']['target'], 'd' => day($c['sla']['due'])]) }}</span>
          @else{{ t("VSahha's target: {d}", ['d' => day($c['sla']['due'] ?? null)]) }}@endif
        @endif</p>
    </div>
    <div class="acts row">
      <button type="button" class="btn btn-s" data-doc="/claims/{{ rawurlencode($c['id']) }}/acknowledgement" data-doc-title="{{ t('Acknowledgement {id}', ['id' => $c['id']]) }}">{{ icon('receipt', 17) }}{{ t('Acknowledgement') }}</button>
      @if (! empty($c['eobId']))<button type="button" class="btn btn-s" data-doc="/documents/eob/{{ rawurlencode($c['eobId']) }}" data-doc-title="{{ $c['eobId'] }}">{{ icon('file', 17) }}{{ t('EOB') }}</button>@endif
      @if (! empty($c['payment']))<a class="btn btn-s" href="/payments/{{ rawurlencode($c['payment']['reference']) }}">{{ icon('bank') }}{{ $c['payment']['reference'] }}</a>@endif
      @if ($appealable && $canAppeal)<button type="button" class="btn btn-p" data-dialog="appeal-dialog">{{ icon('scale', 17) }}{{ t('Appeal') }}</button>@endif
      @if ($canSubmit && $c['workflowStatus'] === 'Submitted for Validation')<button type="button" class="btn btn-danger btn-sm" data-dialog="withdraw-dialog">{{ t('Withdraw') }}</button>@endif
    </div>
  </section>
</div>

@if (! empty($c['infoRequest']) && $canSubmit)
  <form class="banner amber" style="flex-direction:column;align-items:stretch;color:var(--ink)" method="post" action="/claims/{{ rawurlencode($c['id']) }}/reply" enctype="multipart/form-data">@csrf
    <div class="row" style="color:var(--amber)">{{ icon('chat') }}<b>{{ t('VSahha needs information') }}</b><span class="sp"></span><span style="font-size:12px">{{ day_time($c['infoRequest']['at'] ?? null) }}</span></div>
    <p style="font-weight:600">“{{ $c['infoRequest']['message'] }}”</p>
    <p class="muted" style="font-size:12.5px">{{ t("VSahha's service clock is paused until you reply.") }}</p>
    <label class="field"><span class="lbl">{{ t('Your reply') }}</span><textarea name="message" placeholder="{{ t('Say what you are sending…') }}" required>{{ old('message') }}</textarea></label>
    <label class="drop compact">{{ icon('upload', 18) }}<div><b>{{ t('Attach the documents requested') }}</b></div><input type="file" name="files[]" multiple hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.heic" data-file-names></label>
    <div class="files" data-file-list></div>
    <div class="row end"><button class="btn btn-p">{{ icon('send', 17) }}{{ t('Send to VSahha') }}</button></div>
  </form>
@endif
@if (! empty($c['memberReimbursement']))
  <div class="note note-grey">{{ icon('lock') }}<div>{{ t("This is a member reimbursement claim: VSahha pays the member directly. You can follow its status; the member's payment and EOB are private to the member.") }}</div></div>
@endif

@if (! empty($c['money']))
  <div class="money-strip">
    <div><span>{{ t('Claimed') }}</span><b class="num">{{ money($c['claimed'], $cur) }}</b></div>
    <div><span>{{ t('Approved') }}</span><b class="num">{{ money($c['money']['approved'], $cur) }}</b></div>
    <div><span>{{ t('Not approved') }}</span><b class="num">{{ money($c['money']['rejected'], $cur) }}</b></div>
    <div><span>{{ t('Member pays') }}</span><b class="num">{{ money($c['money']['memberShare'], $cur) }}</b></div>
    <div class="hl"><span>{{ ($c['money']['paid'] ?? null) !== null ? t('Paid to you') : t('VSahha pays you') }}</span><b class="num">{{ money($c['money']['paid'] ?? $c['money']['insurerPayable'], $cur) }}</b></div>
  </div>
@endif

<div class="grid-side">
  <div class="stack">
    <x-card :title="t('Service lines')" :sub="! empty($c['money']) ? '' : t('amounts appear once VSahha decides')">
      <div class="lines">
        <div class="ln head"><span>{{ t('Service') }}</span><span class="r hide-m">{{ t('Claimed') }}</span><span class="r hide-m">{{ t('Approved') }}</span><span class="r hide-m">{{ t('Member pays') }}</span><span class="r hide-m">{{ t('VSahha pays') }}</span><span>{{ t('Decision') }}</span></div>
        @foreach ($c['lines'] as $l)
          <div class="ln">
            <div style="min-width:0">
              <b>{{ ($l['description'] ?? '') ?: ($l['benefit'] ?? '') }}</b>
              <div class="muted" style="font-size:12px">{{ $l['id'] }} · {{ ($l['benefit'] ?? null) ?: ($l['code'] ?? '') }} · {{ day($l['serviceDate'] ?? null) }} · ×{{ $l['quantity'] ?? 1 }}{{ ! empty($l['gop']['id']) ? " · {$l['gop']['id']}" : '' }}</div>
              @if (! empty($l['reason']))<div class="reason">{{ icon('info', 13) }} {{ $l['reason'] }}{{ ! empty($l['reasonCode']) ? " ({$l['reasonCode']})" : '' }}</div>@endif
              @if (! empty($l['missingDocuments']))<div class="reason" style="color:var(--amber)">{{ t('Missing') }}: {{ implode(', ', $l['missingDocuments']) }}</div>@endif
            </div>
            <span class="r num hide-m">{{ money($l['claimed'], $cur) }}</span>
            <span class="r num hide-m">{{ ! empty($l['money']) ? money($l['money']['approved'], $cur) : '—' }}</span>
            <span class="r num hide-m">{{ ! empty($l['money']) ? money($l['money']['memberShare'], $cur) : '—' }}</span>
            <span class="r num hide-m"><b>{{ ! empty($l['money']) ? money($l['money']['insurerPayable'], $cur) : '—' }}</b></span>
            <span>{{ pill(t(($l['decision'] ?? 'Pending') === 'Pending' ? 'Not decided' : $l['decision']), ['Approved' => 'green', 'Rejected' => 'red', 'Partially Approved' => 'amber'][$l['decision'] ?? ''] ?? 'muted') }}
              @if (! empty($l['appealable']))<div class="sub" style="margin-top:4px">{{ t('Can be appealed') }}</div>@endif</span>
          </div>
        @endforeach
      </div>
    </x-card>

    <x-card :title="t('Messages')" :sub="t('with the VSahha claims team')">
      @include('partials.thread', ['messages' => $c['messages'] ?? [], 'subjectType' => 'claim', 'subjectId' => $c['id']])
    </x-card>

    @include('partials.notes', ['panel' => $notes])

    @if (! empty($c['appeals']))
      <x-card :title="t('Appeals')">
        @foreach ($c['appeals'] as $a)
          <div class="row" style="padding:8px 0;border-bottom:1px solid var(--line2)">
            <b class="mono">{{ $a['id'] }}</b>{{ pill(t($a['status']), $a['status'] === 'Upheld' ? 'green' : ($a['status'] === 'Declined' ? 'red' : 'blue')) }}
            <span class="muted" style="font-size:12.5px">{{ implode(', ', $a['lineIds'] ?? []) }} · {{ t('received {d}', ['d' => day($a['receivedAt'] ?? null)]) }} · {{ t('answer due {d}', ['d' => day($a['dueAt'] ?? null)]) }}</span>
            @if (! empty($a['decision']))<div style="width:100%;font-size:13px">{{ $a['decision'] }}</div>@endif
          </div>
        @endforeach
      </x-card>
    @endif
  </div>

  <div class="stack">
    <x-card :title="t('Diagnosis')">
      <dl class="kv kv-1">
        <div><dt>{{ t('ICD-10') }}</dt><dd>@if (! empty($c['icd']))<span class="mono">{{ $c['icd'] }}</span>@else<span class="muted">{{ t('Not recorded') }}</span>@endif</dd></div>
        <div><dt>{{ t('As recorded') }}</dt><dd>{{ ($c['diagnosis'] ?? null) ?: t('Not recorded') }}</dd></div>
      </dl>
      @if ($unknown)
        <div class="note note-amber" style="margin-top:12px">{{ icon('stetho') }}<div>{{ t('Filed as Unknown Illness: the diagnosis could not be read with confidence. The VSahha medical team reviews it; this does not delay or reduce the claim by itself.') }}
          @if ($review) {{ t('Medical review') }}: <b style="display:inline">{{ t($review['status']) }}</b>{{ ! empty($review['recodedTo']) ? " — {$review['chosenIcd']} → {$review['recodedTo']}" : '' }}@endif</div></div>
      @elseif (! empty($review['recodedTo']))
        <div class="note note-green" style="margin-top:12px">{{ icon('check') }}<div>{{ t('Re-coded by the VSahha medical team: {a} → {b}', ['a' => $review['chosenIcd'] ?? '', 'b' => $review['recodedTo']]) }}</div></div>
      @endif
    </x-card>
    <x-card :title="t('Timeline')">
      <div class="timeline">@foreach ($c['timeline'] ?? [] as $e)
        <div class="tl"><span class="c">{{ icon($tlIcon($e['title']), 12) }}</span><div><b>{{ t($e['title']) }}</b><span>{{ day_time($e['at']) }}{{ ! empty($e['detail']) ? " · {$e['detail']}" : '' }}</span></div></div>
      @endforeach</div>
    </x-card>
    <x-card :title="t('Documents')">
      @foreach ($c['documents'] ?? [] as $d)<div class="row" style="padding:6px 0">{{ icon('doc', 16) }}<span style="font-size:13px">{{ $docName($d) }}</span></div>@endforeach
      @foreach ($c['lines'] as $l)@foreach ($l['documents'] ?? [] as $d)<div class="row" style="padding:6px 0">{{ icon('doc', 16) }}<span style="font-size:13px">{{ $docName($d) }} <span class="muted">· {{ $l['id'] }}</span></span></div>@endforeach @endforeach
      @if (! empty($c['missingDocuments']))<div class="note note-amber">{{ icon('alert') }}<div>{{ t('Missing') }}: {{ implode(', ', $c['missingDocuments']) }}</div></div>@endif
    </x-card>
  </div>
</div>
@endsection
@section('dialogs')
  @if ($appealable && $canAppeal)
    @include('appeals.dialog', ['claim' => $c, 'selectable' => $selectable])
  @endif
  @if ($canSubmit && $c['workflowStatus'] === 'Submitted for Validation')
    <div class="layer" id="withdraw-dialog" hidden>
      <form class="modal modal-sm" method="post" action="/claims/{{ rawurlencode($c['id']) }}/withdraw" role="dialog" aria-modal="true">@csrf
        <header><div><h3>{{ t('Withdraw {c}?', ['c' => $c['id']]) }}</h3></div><button class="x" type="button" aria-label="{{ t('Close') }}" data-dialog-close>{{ icon('x') }}</button></header>
        <div class="modal-b"><p>{{ t('VSahha will stop working on this claim. You can submit a corrected one afterwards.') }}</p></div>
        <footer><button type="button" class="btn btn-s" data-dialog-close>{{ t('Cancel') }}</button><button class="btn btn-danger">{{ t('Withdraw the claim') }}</button></footer>
      </form>
    </div>
  @endif
@endsection
