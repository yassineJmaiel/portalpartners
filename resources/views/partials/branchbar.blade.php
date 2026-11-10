{{-- in the combined view: which branch this record belongs to, and a way to act on it --}}
@if (me()->all && ! empty($id))
  <form class="branchbar" role="note" method="post" action="/me/provider">@csrf<input type="hidden" name="providerId" value="{{ $id }}">
    {{ icon('layers', 17) }}<span>{{ t('This record belongs to {b}. To reply, appeal or change it, switch to that branch.', ['b' => me()->branchName($id)]) }}</span>
    <button class="btn btn-p btn-sm">{{ t('Switch to {b}', ['b' => me()->branchName($id)]) }}</button>
  </form>
@endif
