@extends('auth.shell')
@php $error = session('portal_error'); @endphp
@section('form')
<div class="auth-body">
  <div class="auth-steps" aria-label="{{ t('Step {n} of {total}', ['n' => 2, 'total' => 2]) }}"><i class="on"></i><i class="on"></i><small>{{ t('Step {n} of {total}', ['n' => 2, 'total' => 2]) }}</small></div>
  <header class="auth-head"><span class="auth-badge">{{ icon('shield', 20) }}</span><h2>{{ t('Enter your sign-in code') }}</h2><p class="muted">{{ t('We sent a 6-digit code to {email}. It expires in 5 minutes.', ['email' => $challenge['sentTo']]) }}</p></header>
  @if (! empty($challenge['demoCode']))
    <div class="note note-amber">{{ icon('info') }}<div><b>{{ t('Demo mode') }}</b>{{ $challenge['transmitted'] ? '' : t('No e-mail service is connected, so the code was not sent. For this demo it is shown here:') }} <b class="demo-code" dir="ltr">{{ $challenge['demoCode'] }}</b></div></div>
  @endif
  @if ($error)<x-error-box :message="$error['message']" />@endif
  <form method="post" action="/login/code" id="code-form" style="display:contents">@csrf
    <div class="code-boxes" dir="ltr" data-code-boxes>
      @for ($i = 0; $i < 6; $i++)
        <input name="code[]" inputmode="numeric" autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}" maxlength="6" aria-label="{{ t('Digit {n}', ['n' => $i + 1]) }}" @if ($i === 0) autofocus @endif>
      @endfor
    </div>
    <label class="check"><input type="checkbox" name="trust" value="1" checked>{{ t('Trust this computer for 30 days') }}</label>
    <button class="btn btn-p btn-block">{{ t('Sign in') }}</button>
  </form>
  <div class="auth-links">
    <a class="lnk" href="/login?restart=1">{{ icon('back', 15, 'flip') }}{{ t('Back') }}</a>
    <form method="post" action="/login/resend">@csrf<button class="lnk">{{ icon('refresh', 15) }}{{ t('Send a new code') }}</button></form>
  </div>
</div>
@endsection
