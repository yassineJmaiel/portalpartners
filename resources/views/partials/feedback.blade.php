{{-- "How was this page?": records the page, not its content; staff are asked not to write patient details --}}
<div class="layer" id="feedback-dialog" hidden>
  <form class="modal modal-sm" method="post" action="/feedback" role="dialog" aria-modal="true" aria-label="{{ t('How was this page?') }}">@csrf
    <input type="hidden" name="page" value="/{{ request()->path() }}">
    <header><div><h3>{{ t('How was this page?') }}</h3></div><button class="x" type="button" aria-label="{{ t('Close') }}" data-dialog-close>{{ icon('x') }}</button></header>
    <div class="modal-b">
      <div class="row">@foreach (['easy' => 'Easy', 'ok' => 'OK', 'hard' => 'Hard'] as $k => $l)<label class="check"><input type="radio" name="rating" value="{{ $k }}">{{ t($l) }}</label>@endforeach</div>
      <label class="field" style="margin-top:12px"><span class="lbl">{{ t('Comment') }}</span><textarea name="body" maxlength="2000" rows="3"></textarea><span class="hint">{{ t('Please do not write patient details here.') }}</span></label>
    </div>
    <footer><button type="button" class="btn btn-s" data-dialog-close>{{ t('Cancel') }}</button><button class="btn btn-p">{{ t('Send') }}</button></footer>
  </form>
</div>
