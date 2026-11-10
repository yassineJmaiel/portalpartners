@extends('layouts.app')
@section('title', t('From a CSV file').' · VSahha Provider Portal')
@section('content')
<x-page-head icon="layers" :title="t('Claims from a file')" :sub="t('One row per service line; rows with the same invoice number become one claim. Nothing is sent to VSahha from here: rows that pass the check become drafts for you to review and submit.')" />
<a class="crumb" href="/claims">{{ icon('back', 15, 'flip') }}{{ t('Claims') }}</a>
<x-card>
  <form method="post" action="/claims/bulk" enctype="multipart/form-data" class="stack" style="gap:12px">@csrf
    <label class="drop">{{ icon('upload', 26) }}<div><b>{{ t('Choose your CSV file') }}</b><span>{{ t('Columns: {c}', ['c' => implode(', ', App\Portal\Csv::BULK_COLUMNS)]) }}</span></div><input type="file" name="csv" accept=".csv,text/csv" hidden data-submit-on-change></label>
    <div class="row"><a class="btn btn-s btn-sm" href="/claims/bulk/template">{{ icon('download', 15) }}{{ t('Download the template') }}</a></div>
  </form>
</x-card>
@if ($result)
  <x-card :title="t('{n} rows read', ['n' => $result['rows']])" :sub="plural(count(array_filter($result['claims'], fn ($v) => ! empty($v['draftId']))), '{n} draft ready', '{n} drafts ready')" :pad="false">
    @foreach ($result['ignored'] as $x)<div class="note note-grey" style="margin:10px 20px">{{ icon('info') }}<div>{{ t('Row {n}', ['n' => $x['row']]) }}: {{ $x['why'] }}</div></div>@endforeach
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>{{ t('Invoice') }}</th><th>{{ t('Patient') }}</th><th>{{ t('Verdict') }}</th><th>{{ t('What was found') }}</th><th></th></tr></thead>
      <tbody>@foreach ($result['claims'] as $v)
        <tr>
          <td class="id mono">{{ ($v['invoiceNo'] ?? '') ?: '—' }}<div class="sub">{{ t('rows {r}', ['r' => implode(', ', $v['rows'])]) }}</div></td>
          <td>{{ ($v['memberName'] ?? null) ?: ($v['memberRef'] ?? '') }}</td>
          <td>{{ pill(t($v['verdict']), ! empty($v['draftId']) ? 'green' : 'red') }}@if (isset($v['score']))<div class="sub">{{ t('score {n}', ['n' => $v['score']]) }}</div>@endif</td>
          <td>
            @foreach ($v['problems'] as $p)<div class="sla">{{ icon('x', 13) }}{{ $p }}</div>@endforeach
            @foreach ($v['warnings'] ?? [] as $p)<div class="sub" style="color:var(--amber)">{{ $p }}</div>@endforeach
            @if (! empty($v['missingDocuments']))<div class="sub">{{ plural($v['missingDocuments'], '{n} document to attach on the draft', '{n} documents to attach on the draft') }}</div>@endif
          </td>
          <td class="r">@if (! empty($v['draftId']))<a class="btn btn-s btn-sm" href="/claims/new?draft={{ $v['draftId'] }}">{{ t('Open the draft') }}</a>@endif</td>
        </tr>
      @endforeach</tbody>
    </table></div>
  </x-card>
@endif
@endsection
