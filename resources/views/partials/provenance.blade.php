{{-- where a value came from: $f (source, confidence, page, snippet) --}}
@php
  $source = $f['source'] ?? 'rule';
  $conf = (float) ($f['confidence'] ?? 0);
  $who = $source === 'ai' ? 'AI' : ($source === 'ocr' ? 'OCR' : ($source === 'person' ? t('Entered') : t('Read')));
  $tone = $source === 'person' ? 'green' : ($conf >= 0.9 ? 'teal' : ($conf >= 0.7 ? 'blue' : 'amber'));
@endphp
@if ($source === 'crm')
  <span class="prov t-green" @if (! empty($f['read'])) title="{{ t('Read on the document: {v}', ['v' => $f['read']['value'] ?? '']) }}" @endif>{{ t('Member file') }}</span>
@else
  <span class="prov t-{{ $tone }}" @if (! empty($f['snippet'])) title="“{{ $f['snippet'] }}”" @endif>{{ $who }}@if ($source !== 'person') · {{ round($conf * 100) }}%@endif @if (! empty($f['page'])) · p.{{ $f['page'] }}@endif</span>
@endif
