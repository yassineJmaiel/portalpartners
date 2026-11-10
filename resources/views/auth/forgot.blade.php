@extends('auth.shell')
@section('form')
<form class="auth-body" method="post" action="/forgot">@csrf
  <header class="auth-head"><span class="auth-badge">{{ icon('refresh', 20) }}</span><h2>{{ t('Reset your password') }}</h2><p class="muted">{{ t('Your portal administrator can send you a link to set a new password. Enter your e-mail and we will ask them.') }}</p></header>
  @if (session('sent'))
    <div class="note note-green">{{ icon('check') }}<div>{{ t('If an active account uses this address, a message is on its way. Check with your portal administrator if nothing arrives.') }}</div></div>
  @else
    <div class="field"><div class="lbl-row"><label class="lbl" for="fp-email">{{ t('E-mail') }}</label></div>
      <div class="ii">{{ icon('mail', 18, 'ii-l') }}<input id="fp-email" name="email" type="email" dir="ltr" autocomplete="username" spellcheck="false" required autofocus></div></div>
    <button class="btn btn-p btn-block">{{ t('Send') }}</button>
  @endif
  <div class="auth-links"><a class="lnk" href="/login">{{ icon('back', 15, 'flip') }}{{ t('Back to sign-in') }}</a></div>
</form>
@endsection
