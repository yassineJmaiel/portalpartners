{{-- appeal decided lines: $claim, $selectable --}}
@php use App\Portal\Vocab; @endphp
<div class="layer" id="appeal-dialog" hidden>
  <form class="modal modal-lg" method="post" action="/appeals" enctype="multipart/form-data" role="dialog" aria-modal="true" aria-label="{{ t('Appeal the decision on {c}', ['c' => $claim['id']]) }}">@csrf
    <input type="hidden" name="claimId" value="{{ $claim['id'] }}">
    <header><div><h3>{{ t('Appeal the decision on {c}', ['c' => $claim['id']]) }}</h3><p>{{ t('VSahha answers appeals within 20 days. Explain why the decision should change and attach new evidence.') }}</p></div>
      <button class="x" type="button" aria-label="{{ t('Close') }}" data-dialog-close>{{ icon('x') }}</button></header>
    <div class="modal-b">
      @if (! $selectable)<div class="note note-grey">{{ icon('info') }}<div>{{ t('No line on this claim can be appealed: either nothing was reduced, an appeal is already open, or the reason cannot be appealed.') }}</div></div>@endif
      @foreach ($selectable as $l)
        <label class="check" style="padding:10px;border:1px solid var(--line2);border-radius:12px">
          <input type="checkbox" name="lineIds[]" value="{{ $l['id'] }}" checked>
          <div><b>{{ $l['description'] ?? '' }}</b><div class="muted" style="font-size:12.5px">{{ t($l['decision']) }} · {{ $l['reasonCode'] ?? '' }} {{ ! empty(Vocab::REJECTION_REASONS[$l['reasonCode'] ?? '']) ? '— '.t(Vocab::REJECTION_REASONS[$l['reasonCode']]) : '' }} · {{ money($l['money']['approved'] ?? null, $claim['currency'] ?? null) }} / {{ money($l['claimed'] ?? null, $claim['currency'] ?? null) }}</div><div class="reason">{{ $l['reason'] ?? '' }}</div></div>
        </label>
      @endforeach
      <label class="field"><span class="lbl">{{ t('Why should the decision change?') }}</span><textarea name="reason" rows="5" minlength="20" required></textarea><span class="hint">{{ t('A few sentences. Cite the contract, the plan or the clinical facts.') }}</span></label>
      <label class="drop compact">{{ icon('upload', 18) }}<div><b>{{ t('Attach new evidence') }}</b></div><input type="file" name="files[]" multiple hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.heic" data-file-names></label>
      <div class="files" data-file-list></div>
    </div>
    <footer><button type="button" class="btn btn-s" data-dialog-close>{{ t('Cancel') }}</button><button class="btn btn-p" @disabled(! $selectable)>{{ t('Send the appeal') }}</button></footer>
  </form>
</div>
