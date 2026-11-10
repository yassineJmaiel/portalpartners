@php
  $DOC_TYPES = ['invoice' => 'Invoice', 'detailed_bill' => 'Detailed bill', 'receipt' => 'Receipt', 'medical_report' => 'Medical report', 'discharge_summary' => 'Discharge summary', 'prescription' => 'Prescription', 'lab_result' => 'Laboratory result', 'imaging_report' => 'Imaging report', 'operative_note' => 'Operative note', 'referral_letter' => 'Referral letter', 'admission_request' => 'Admission request', 'quotation' => 'Quotation / estimate', 'member_id' => 'Member card', 'other' => 'Other document'];
  $LABEL = ['patientName' => 'Patient name', 'dob' => 'Date of birth', 'cardNo' => 'Card number', 'memberId' => 'Member ID', 'invoiceNo' => 'Invoice number', 'serviceDate' => 'Service date', 'admissionDate' => 'Admission date', 'dischargeDate' => 'Discharge date', 'physician' => 'Physician', 'issuer' => 'Issued by', 'total' => 'Total', 'currency' => 'Currency', 'taxNumber' => 'Tax number', 'paymentMethod' => 'Payment method', 'validity' => 'Valid for', 'modality' => 'Imaging', 'bodyPart' => 'Body part', 'procedure' => 'Procedure', 'conclusion' => 'Conclusion', 'validUntil' => 'Valid until', 'policyNo' => 'Policy number', 'diagnosisText' => 'Diagnosis as written'];
  $COMMON = ['patientName', 'dob', 'cardNo', 'memberId', 'serviceDate', 'physician', 'issuer'];
  $BY_TYPE = ['invoice' => ['invoiceNo', 'total', 'currency', 'taxNumber', 'diagnosisText'], 'detailed_bill' => ['invoiceNo', 'total', 'currency', 'taxNumber'], 'receipt' => ['invoiceNo', 'total', 'currency', 'paymentMethod'], 'quotation' => ['invoiceNo', 'total', 'currency', 'validity'], 'prescription' => ['diagnosisText'], 'lab_result' => ['conclusion'], 'imaging_report' => ['modality', 'bodyPart', 'conclusion', 'diagnosisText'], 'operative_note' => ['procedure', 'admissionDate', 'diagnosisText'], 'discharge_summary' => ['admissionDate', 'dischargeDate', 'diagnosisText', 'conclusion'], 'medical_report' => ['diagnosisText', 'conclusion'], 'referral_letter' => ['diagnosisText'], 'admission_request' => ['admissionDate', 'diagnosisText'], 'member_id' => ['validUntil', 'policyNo'], 'other' => ['invoiceNo', 'total', 'currency', 'diagnosisText']];
  $NUMBER_LABEL = ['receipt' => 'Receipt number', 'quotation' => 'Quotation number'];
  $f = $doc['fields'];
  $fieldList = array_merge($COMMON, $BY_TYPE[$doc['type']] ?? $BY_TYPE['other']);
  $labelOf = fn ($k) => ($k === 'invoiceNo' ? ($NUMBER_LABEL[$doc['type']] ?? null) : null) ?: $LABEL[$k];
  $whyTitle = preg_match('/^Titled “(.*)”$/u', (string) $doc['typeWhy'], $m) || preg_match('/^“(.*)” at the top$/u', (string) $doc['typeWhy'], $m) ? $m[1] : null;
  $found = [];
  if (! empty($f['medications'])) { $found[] = plural(count($f['medications']), '{n} medicine', '{n} medicines'); }
  if (! empty($f['tests'])) { $found[] = plural(count($f['tests']), '{n} test', '{n} tests'); $off = count(array_filter($f['tests'], fn ($x) => ! empty($x['flag']))); if ($off) { $found[] = t('{n} out of range', ['n' => $off]); } }
  if (! empty($f['lines'])) { $found[] = plural(count($f['lines']), '{n} line', '{n} lines'); }
  if (! empty($f['total'])) { $found[] = t('total').' '.money($f['total']['value'], $f['currency']['value'] ?? null); }
  if (! empty($f['modality'])) { $found[] = ! empty($f['bodyPart']) ? "{$f['modality']['value']} · {$f['bodyPart']['value']}" : $f['modality']['value']; }
  $page = $doc['pages'][0] ?? 1;
  $isPdf = (bool) preg_match('/\.pdf$/i', (string) $doc['name']);
  $p = $doc['patient'];
  $dx = $doc['diagnosis'];
  $blocks = array_filter($doc['signals'], fn ($s) => $s['severity'] === 'block');
  $statusTone = $doc['status'] === 'Filed' ? 'green' : ($doc['status'] === 'Duplicate' ? 'red' : (in_array($doc['status'], ['Auto-matched', 'Ready to file'], true) ? 'teal' : 'amber'));
  $filed = (bool) $doc['draftId'];
  $possible = array_merge($dx['codes'] ?? [], $dx['suggestions'] ?? []);
  $dxShow = ($f['diagnosisText'] ?? null) ?: ($f['icdPrinted'][0] ?? null) ?: (! empty($dx['snippet']) ? ['snippet' => $dx['snippet']] : null);
@endphp
<article class="idoc" id="doc-{{ $doc['id'] }}" data-pages="{{ json_encode($doc['pages']) }}" data-ocr="{{ json_encode($doc['ocr'] ?? [], JSON_UNESCAPED_UNICODE) }}">
  <div class="pv">
    <div class="bar2">{{ icon('doc', 15) }}<b style="color:var(--ink)">{{ $doc['name'] }}</b><span>· {{ count($doc['pages']) > 1 ? t('page {p} of pages {all}', ['p' => '', 'all' => implode(', ', $doc['pages'])]) : t('pages {p}', ['p' => implode(', ', $doc['pages'])]) }}</span>@if (count($doc['pages']) > 1)<b data-page-label>{{ $page }}</b>@endif<span class="sp"></span><span>{{ $doc['engine'] }}</span></div>
    <div class="markbar" role="status" hidden data-t-read="{{ t('Read here: “{v}”', ['v' => '{v}']) }}"><span class="mark-key"></span><span data-read></span><button type="button" class="lnk" data-mark-clear>{{ t('Clear') }}</button></div>
    <div class="pdfpage">
      @if ($isPdf)
        <div class="pdfsheet" data-file="/files/{{ $doc['fileRef'] }}" data-title="{{ $doc['name'] }}" data-t-noview="{{ t('The page could not be drawn here. What was read from it is on the right.') }}"><div class="pdfcanvas"><div class="loading"></div></div></div>
      @else
        <div class="pdfsheet"><img src="/files/{{ $doc['fileRef'] }}" alt="{{ $doc['name'] }}"></div>
      @endif
    </div>
  </div>
  <form class="rv" method="post" action="/intake/docs/{{ $doc['id'] }}">@csrf
    <h3>
      <select name="type" aria-label="{{ t('Document type') }}" style="font-weight:800;border:1px solid var(--line);border-radius:8px;padding:4px 6px" data-review-on-change>
        @foreach ($DOC_TYPES as $k => $l)<option value="{{ $k }}" @selected($doc['type'] === $k)>{{ t($l) }}</option>@endforeach
      </select>
      <button class="lnk js-hide" name="do" value="type">{{ t('Change') }}</button>
      @if ($doc['typeConfidence'] !== null)<span class="prov {{ $doc['typeConfidence'] >= 0.75 ? 't-teal' : 't-amber' }}">{{ t('Detected') }} · {{ round($doc['typeConfidence'] * 100) }}%</span>@endif
      <span class="sp"></span>{{ pill(t($doc['status']), $statusTone) }}
    </h3>
    <p class="detected">
      @if ($whyTitle)<span>{{ t('Recognised from its title') }}: <b>“{{ $whyTitle }}”</b></span>@elseif ($doc['typeWhy'])<span>{{ t('Recognised from its wording') }}</span>@endif
      @foreach ($found as $s)<span class="chip">{{ $s }}</span>@endforeach
      @if ($doc['typeConfidence'] !== null && $doc['typeConfidence'] < 0.75)<span class="muted">{{ t('Not sure of the kind of document — check it above; changing it reads the document again.') }}</span>@endif
    </p>
    @foreach ($doc['signals'] as $i => $s)
      <div class="sig t-{{ $s['severity'] === 'block' ? 'red' : ($s['severity'] === 'info' ? 'grey' : 'amber') }}">
        {{ icon($s['severity'] === 'info' ? 'info' : 'alert', 15) }}<span style="flex:1">{{ App\Portal\Lang::say($s['text']) }}</span>
        @if (in_array($s['kind'], ['check', 'disagree'], true))
          <input type="hidden" name="checks[{{ $i }}]" value="{{ $s['text'] }}">
          <span class="sig-acts">@if (! empty($s['field']) && ! empty($f[$s['field']]) && ! array_is_list($f[$s['field']]))<button type="button" class="lnk" data-show="{{ json_encode($f[$s['field']], JSON_UNESCAPED_UNICODE) }}">{{ t('Show on the page') }}</button>@endif <button class="lnk" name="do" value="checked:{{ $i }}">{{ t('Checked') }}</button></span>
        @endif
      </div>
    @endforeach
    <div><b style="font-size:13px">{{ t('Patient') }}</b><div style="margin-top:6px">
      @if (! empty($p['memberId']))
        <div class="cand sel"><span class="tile t-green" style="width:34px;height:34px;border-radius:10px">{{ icon('check', 16) }}</span>
          <div><b>{{ ($p['memberName'] ?? null) ?: $p['memberId'] }}</b><small>{{ $p['memberId'] }} · {{ $p['status'] === 'auto' ? t('Linked automatically: one member matched at {s}%', ['s' => round(($p['score'] ?? 0) * 100)]) : t('Confirmed by a person') }}</small>
            @foreach ($p['evidence'] ?? [] as $e)<small>· {{ $e }}</small>@endforeach</div>
          @if (! $filed)<button class="lnk" name="do" value="unlink">{{ t('Change') }}</button>@endif
        </div>
      @else
        <div class="note note-amber">{{ icon('user') }}<div>{{ App\Portal\Lang::say($p['reason'] ?? '') }}</div></div>
        @foreach ($p['candidates'] ?? [] as $c)
          <button class="cand {{ ($p['suggested'] ?? null) === $c['id'] ? 'sel' : '' }}" name="do" value="member:{{ $c['id'] }}" style="width:100%;text-align:start">
            <span class="tile t-teal" style="width:34px;height:34px;border-radius:10px">{{ icon('user', 16) }}</span>
            <div><b>{{ $c['name'] }}</b><small>{{ $c['id'] }} · {{ $c['cardNo'] ?? '' }} · {{ t('born {d}', ['d' => day($c['dob'] ?? null)]) }}</small>@foreach ($c['evidence'] ?? [] as $e)<small>· {{ $e }}</small>@endforeach</div>
            {{ pill(round(($c['score'] ?? 0) * 100).'%', ($c['score'] ?? 0) >= 0.95 ? 'green' : (($c['score'] ?? 0) >= 0.7 ? 'blue' : 'muted')) }}
          </button>
        @endforeach
        <div class="row" style="align-items:flex-end;margin-top:8px">
          <label class="field" style="flex:1"><span class="lbl">{{ t('Or find the member by card number or ID') }}</span><input name="manual" placeholder="VS-…"></label>
          <button class="btn btn-s btn-sm" name="do" value="find">{{ t('Use') }}</button>
        </div>
      @endif
    </div></div>
    <div><b style="font-size:13px">{{ t('Diagnosis') }}</b><div style="margin-top:6px">
      @foreach ($possible as $s)<input type="hidden" name="titles[{{ $s['code'] }}]" value="{{ $s['title'] ?? '' }}">@endforeach
      <input type="hidden" name="titles[R69]" value="Illness, unspecified">
      @if (empty($dx['present']) && empty($dx['unknown']))
        <p class="muted" style="font-size:13px">{{ t('No diagnosis on this document — it is taken from the other documents of the same visit.') }}</p>
      @elseif (! empty($dx['needsCode']))
        <div class="row"><b>“{{ $dx['written'] }}”</b><span class="prov {{ ($dx['confidence'] ?? 0) >= 0.9 ? 't-teal' : 't-blue' }}">{{ t('Read') }} · {{ round(($dx['confidence'] ?? 0) * 100) }}%</span>@if ($dxShow)<button type="button" class="lnk" data-show="{{ json_encode($dxShow, JSON_UNESCAPED_UNICODE) }}">{{ t('Show on the page') }}</button>@endif</div>
        <p class="muted" style="font-size:12.5px;margin:4px 0 8px">{{ t('No ICD-10 code is printed on this document. Confirm the code it should have.') }}</p>
        @if (! empty($dx['suggestions']))
          <div class="row" style="margin:6px 0 10px"><span class="muted" style="font-size:12px">{{ t('Possible codes') }}:</span>
            @foreach ($dx['suggestions'] as $s)<button class="pill t-blue" style="border:0" title="{{ $s['why'] ?? '' }}" name="do" value="code:{{ $s['code'] }}:Confirmed">{{ $s['code'] }} · {{ $s['title'] ?? '' }} · {{ round(($s['confidence'] ?? 0) * 100) }}%</button>@endforeach
          </div>
        @endif
        @include('partials.icd', ['name' => 'icd', 'value' => '', 'titleName' => 'icdTitle', 'titleValue' => ''])
        <div class="row" style="margin-top:8px"><button class="btn btn-s btn-sm" name="do" value="diagnosis">{{ t('Use this code') }}</button><button class="lnk" name="do" value="code:R69:Unknown Illness">{{ t('Mark as Unknown Illness') }}</button></div>
      @elseif (empty($dx['unknown']))
        <div class="row">{{ pill($dx['code'] ?? '', 'green') }}<b>{{ ($dx['title'] ?? null) ?: ($dx['written'] ?? '') }}</b>
          @if (($dx['confirmedBy'] ?? null) === 'person')<span class="prov t-green">{{ t('Entered') }}</span>@else<span class="prov t-teal">{{ t('Read') }} · {{ round(($dx['confidence'] ?? 0) * 100) }}%</span>@endif
          <span class="sp"></span><button class="lnk" name="do" value="code:R69:Unknown Illness">{{ t('Mark as Unknown Illness') }}</button></div>
        @if (! empty($dx['written']))<p class="muted" style="font-size:12.5px;margin-top:4px">“{{ $dx['written'] }}” @if ($dxShow)<button type="button" class="lnk" data-show="{{ json_encode($dxShow, JSON_UNESCAPED_UNICODE) }}">{{ t('Show on the page') }}</button>@endif</p>@endif
      @else
        <div class="unknownbox">
          <h4>{{ icon('stetho') }}{{ t('Unknown Illness') }}</h4>
          <p style="font-size:13px;margin:4px 0 8px">{{ t(App\Portal\Vocab::UNKNOWN_REASONS[$dx['reason'] ?? ''] ?? ($dx['reasonText'] ?? '')) }} {{ t('The document stays filed under the patient. Choose the ICD-10 code for the claim; R69 is suggested when nothing more is known. The VSahha medical team reviews it.') }}</p>
          @if (! empty($dx['snippet']))<p class="muted" style="font-size:12.5px">{{ t('Unreadable part') }}: “{{ $dx['snippet'] }}” @if ($dxShow)<button type="button" class="lnk" data-show="{{ json_encode($dxShow, JSON_UNESCAPED_UNICODE) }}">{{ t('Show on the page') }}</button>@endif</p>@endif
          @if ($possible)
            <div class="row" style="margin:6px 0 10px"><span class="muted" style="font-size:12px">{{ t('Possible codes') }}:</span>
              @foreach ($possible as $s)<button class="pill t-blue" style="border:0" title="{{ ($s['why'] ?? null) ?: ($s['snippet'] ?? '') }}" name="do" value="code:{{ $s['code'] }}:Confirmed">{{ $s['code'] }} · {{ $s['title'] ?? '' }}</button>@endforeach
            </div>
          @endif
          {{-- a code chosen here keeps the claim as Unknown Illness: the provider picked it without being able to read the report --}}
          @include('partials.icd', ['name' => 'icd', 'value' => ($dx['code'] ?? null) ?: 'R69', 'titleName' => 'icdTitle', 'titleValue' => ($dx['title'] ?? null) ?: 'Illness, unspecified', 'certaintyName' => 'icdCertainty', 'certainty' => App\Portal\Vocab::UNKNOWN_ILLNESS, 'unknown' => true])
          <div class="row" style="margin-top:8px"><button class="btn btn-p btn-sm" name="do" value="diagnosis">{{ t('Keep as Unknown Illness with {c}', ['c' => ($dx['code'] ?? null) ?: 'R69']) }}</button>
            <span class="muted" style="font-size:12px">{{ t('Or pick a possible code above if you can read the diagnosis.') }}</span></div>
        </div>
      @endif
    </div></div>
    @if (! empty($f['medications']))
      <div><b style="font-size:13px">{{ t('Medicines prescribed') }}</b>
        <table class="tbl" style="margin-top:6px"><thead><tr><th>{{ t('Medicine') }}</th><th>{{ t('Strength') }}</th><th>{{ t('How to take it') }}</th><th>{{ t('For how long') }}</th><th></th></tr></thead>
          <tbody>@foreach ($f['medications'] as $m)<tr class="click" data-show="{{ json_encode(['value' => $m['name'], 'snippet' => $m['snippet'] ?? null, 'page' => $m['page'] ?? null], JSON_UNESCAPED_UNICODE) }}"><td><b>{{ $m['name'] }}</b>@if (! empty($m['form']))<span class="sub"> · {{ t($m['form']) }}</span>@endif</td><td>{{ ($m['strength'] ?? null) ?: '—' }}</td><td>{{ ($m['frequency'] ?? null) ?: (($m['instructions'] ?? null) ?: '—') }}</td><td>{{ ($m['duration'] ?? null) ?: '—' }}</td><td>@include('partials.provenance', ['f' => $m])</td></tr>@endforeach</tbody></table></div>
    @endif
    @if (! empty($f['tests']))
      <div><b style="font-size:13px">{{ t('Test results') }}</b>
        <table class="tbl" style="margin-top:6px"><thead><tr><th>{{ t('Test') }}</th><th class="r">{{ t('Result') }}</th><th>{{ t('Unit') }}</th><th>{{ t('Reference range') }}</th><th></th></tr></thead>
          <tbody>@foreach ($f['tests'] as $x)<tr class="click" data-show="{{ json_encode(['value' => $x['name'], 'snippet' => $x['snippet'] ?? null, 'page' => $x['page'] ?? null], JSON_UNESCAPED_UNICODE) }}"><td><b>{{ $x['name'] }}</b></td><td class="r num">{{ $x['value'] }}@if (! empty($x['doubt'])) {{ pill(t('Check'), 'amber') }}@elseif (! empty($x['flag'])) {{ pill(t($x['flag']), $x['flag'] === 'High' ? 'red' : 'amber') }}@endif</td><td>{{ ($x['unit'] ?? null) ?: '—' }}</td><td>{{ ($x['range'] ?? null) ?: '—' }}</td><td>@include('partials.provenance', ['f' => $x])</td></tr>@endforeach</tbody></table></div>
    @endif
    <details open>
      <summary style="cursor:pointer;font-weight:700;font-size:13px">{{ t('What was read') }} ({{ count(array_filter($fieldList, fn ($k) => ! empty($f[$k]))) }}/{{ count($fieldList) }} {{ t('fields') }}{{ ! empty($f['lines']) ? ', '.count($f['lines']).' '.t('lines') : '' }})</summary>
      <div class="form" style="margin-top:10px">
        @foreach ($fieldList as $k)
          <label class="field"><span class="lbl">{{ t($labelOf($k)) }}@if (! empty($f[$k])) <button type="button" class="prov-show" data-show="{{ json_encode($f[$k], JSON_UNESCAPED_UNICODE) }}" title="{{ t('Show on the page') }}">@include('partials.provenance', ['f' => $f[$k]])</button>@endif</span>
            <input name="fields[{{ $k }}]" value="{{ $f[$k]['value'] ?? '' }}" class="{{ empty($f[$k]) ? 'unread' : '' }}" placeholder="{{ empty($f[$k]) ? t('Not on the document — type it if needed') : '' }}" @if (! empty($f[$k])) data-show-on-focus="{{ json_encode($f[$k], JSON_UNESCAPED_UNICODE) }}" @endif></label>
        @endforeach
      </div>
      @if (! empty($f['lines']))
        <table class="tbl" style="margin-top:8px"><thead><tr><th>{{ t('Line') }}</th><th class="r">{{ t('Qty') }}</th><th class="r">{{ t('Amount') }}</th><th></th></tr></thead>
          <tbody>@foreach ($f['lines'] as $l)<tr class="click" data-show="{{ json_encode(['value' => $l['description'], 'snippet' => $l['snippet'] ?? null, 'page' => $l['page'] ?? null], JSON_UNESCAPED_UNICODE) }}"><td>{{ $l['description'] }}@if (! empty($l['code']))<span class="sub"> · {{ $l['code'] }}</span>@endif</td><td class="r">{{ $l['quantity'] }}</td><td class="r num">{{ money($l['amount']) }}</td><td>@include('partials.provenance', ['f' => $l])</td></tr>@endforeach</tbody></table>
      @endif
      <div class="row end" style="margin-top:8px"><button class="btn btn-p btn-sm" name="do" value="fields">{{ t('Save corrections') }}</button></div>
    </details>
    @if ($doc['draftId'])<a class="btn btn-s btn-sm" href="/claims/new?draft={{ $doc['draftId'] }}">{{ icon('file', 15) }}{{ t('Open its claim draft') }}</a>@endif
    @if ($blocks)<p class="muted" style="font-size:12px">{{ t('Documents with a blocking signal are not filed.') }}</p>@endif
  </form>
</article>
