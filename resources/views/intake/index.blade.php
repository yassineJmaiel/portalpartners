@extends('layouts.app')
@section('title', t('Smart Intake').' · VSahha Provider Portal')
@section('live', '0')
@section('content')
<x-page-head icon="spark" :title="t('Smart Intake')" :sub="t('Drop a pile of documents. Each one is read, filed under the right patient and diagnosis, and checked — you confirm, then submit.')" />
<x-card>
  <form method="post" action="/intake" enctype="multipart/form-data" data-no-soft data-intake-upload data-t-preparing="{{ t('Preparing the reader for scans (first time only)…') }}" data-t-reading="{{ t('Reading the scan {n} — {p}%', ['n' => '{n}', 'p' => '{p}']) }}" data-t-sending="{{ t('Sending to be matched with VSahha…') }}">@csrf
    <input type="hidden" name="ocr">
    <label class="drop">{{ icon('upload', 26) }}<div><b>{{ t('Drop invoices, reports and prescriptions — several files, several patients') }}</b><span>{{ t('PDF, photos and scans, up to 20 MB each') }}</span></div>
      <input type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.heic,.txt" data-file-names data-submit-on-pick></label>
    <div class="files" data-file-list></div>
    <label class="check" style="margin-top:10px"><input type="checkbox" name="read_scans" value="1">{{ t('Read scans and photos in this browser. This fetches the reader and its language files once; left off, you enter their details by hand.') }}</label>
    <div class="loading" data-stage hidden style="margin-top:10px"></div>
    <div class="row end" style="margin-top:12px"><button class="btn btn-p">{{ icon('upload', 17) }}{{ t('Read these documents') }}</button></div>
  </form>
  <div class="row" style="margin-top:12px;font-size:12.5px"><span class="muted">{{ icon('info', 14) }} {{ t('Nothing is sent to VSahha until you review the documents and submit the claims.') }}</span></div>
  <div class="note note-grey" style="margin-top:12px">{{ icon('info') }}<div>{{ t('Text PDFs are read by rules. Scans and photos are read only if you tick the box above. Check each value; what cannot be read is yours to enter.') }}</div></div>
</x-card>
<div class="grid2">
  <x-card :title="t('How documents are filed')">
    <div class="timeline">
      @foreach ([['doc', t('Read'), t('PDF text is read on the page; scans and photos in your browser, if you ask for it. Every value shows where it was read.')],
        ['user', t('Patient'), t('Linked automatically when one member matches: by the exact card number or member ID, or by name and date of birth. Their member ID, full name and date of birth are then filled from the member file. If anything contradicts it, you decide.')],
        ['stetho', t('Diagnosis'), t("A printed ICD-10 code is checked against VSahha's catalogue. A diagnosis that cannot be read with 70% confidence becomes Unknown Illness — you choose the code, R69 suggested.")],
        ['alert', t('Checks'), t("Same file sent twice, invoice already claimed, totals that do not add up, a PDF edited after creation, another clinic's tax number, a date outside cover.")]] as [$i, $a, $b])
        <div class="tl"><span class="c">{{ icon($i, 12) }}</span><div><b>{{ $a }}</b><span>{{ $b }}</span></div></div>
      @endforeach
    </div>
  </x-card>
  <x-card :title="t('Earlier uploads')" :pad="false">
    @if (! $batches)
      <x-empty icon="spark" :title="t('No uploads yet')" />
    @else
      <div class="list">@foreach ($batches as $b)
        <a class="li" href="/intake/{{ $b['id'] }}" style="grid-template-columns:minmax(0,1fr) auto">
          <div><div class="id">{{ $b['id'] }}</div><div class="who">{{ day_time($b['createdAt']) }} · {{ $b['engine'] }}</div></div>
          @if ($b['status'] === 'Reading'){{ pill(t('Reading…'), 'blue') }}@elseif ($b['status'] === 'Failed'){{ pill(t('Not read'), 'red') }}@else{{ pill(t('{a}/{b} filed', ['a' => $b['filed'], 'b' => $b['docs']]), $b['filed'] === $b['docs'] ? 'green' : 'amber') }}@endif
        </a>
      @endforeach</div>
    @endif
  </x-card>
</div>
@endsection
@push('scripts')<script src="/js/intake.js"></script>@endpush
