@extends('auth.shell')
@php $error = session('portal_error'); $reset = ($invite->kind ?? null) === 'reset'; @endphp
@section('form')
<form class="auth-body" method="post">@csrf
  <header class="auth-head"><span class="auth-badge">{{ icon($reset ? 'refresh' : 'user', 20) }}</span><small class="auth-eyebrow">{{ t('Provider Portal') }}</small>
    <h2>{{ $reset ? t('Set a new password') : t('Welcome to the VSahha Provider Portal') }}</h2>@if ($invite)<p class="muted">{{ $invite->name }} · {{ $invite->email }}</p>@endif</header>
  @if ($error)<x-error-box :message="$error['message']" />@endif
  @if (! $invite)
    <x-error-box :message="t('This link has expired or was already used. Ask your portal administrator for a new one.')" />
    <a class="btn btn-p btn-block" href="/login">{{ t('Go to sign-in') }}</a>
  @else
    <div class="field"><div class="lbl-row"><label class="lbl" for="iv-pw">{{ t('New password') }}</label></div>
      <div class="ii">{{ icon('lock', 18, 'ii-l') }}<input id="iv-pw" name="password" type="password" autocomplete="new-password" required></div><span class="hint">{{ t('At least 10 characters, with letters and numbers.') }}</span></div>
    <div class="field"><div class="lbl-row"><label class="lbl" for="iv-pw2">{{ t('Repeat the password') }}</label></div>
      <div class="ii">{{ icon('lock', 18, 'ii-l') }}<input id="iv-pw2" name="password_confirmation" type="password" autocomplete="new-password" required></div></div>
    <button class="btn btn-p btn-block">{{ t('Set password') }}</button>
  @endif
</form>
@endsection
