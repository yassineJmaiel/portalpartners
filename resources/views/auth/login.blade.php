@extends('auth.shell')
@php
  $hour = (int) gmdate('H');
  $greeting = $hour < 12 ? t('Good morning') : ($hour < 18 ? t('Good afternoon') : t('Good evening'));
  $error = session('portal_error');
@endphp
@section('form')
<form class="auth-body" method="post" action="/login">@csrf
  <header class="auth-head"><span class="auth-badge">{{ icon('lock', 20) }}</span><small class="auth-eyebrow">{{ t('Provider Portal') }}</small><h2>{{ $greeting }}</h2><p class="muted">{{ t('Use the e-mail address your clinic registered with VSahha.') }}</p></header>
  @if ($error)<x-error-box :message="$error['message']" />@endif
  <div class="field"><div class="lbl-row"><label class="lbl" for="si-email">{{ t('E-mail') }}</label></div>
    <div class="ii">{{ icon('mail', 18, 'ii-l') }}<input id="si-email" name="email" type="email" dir="ltr" autocomplete="username" inputmode="email" spellcheck="false" value="{{ old('email') }}" required autofocus></div></div>
  <div class="field"><div class="lbl-row"><label class="lbl" for="si-password">{{ t('Password') }}</label><a class="lnk" href="/forgot">{{ t('Forgot your password?') }}</a></div>
    <div class="ii has-after">{{ icon('lock', 18, 'ii-l') }}<input id="si-password" name="password" type="password" autocomplete="current-password" required>
      <button type="button" class="ii-r" data-show-password aria-label="{{ t('Show password') }}">{{ icon('eye', 18) }}</button></div></div>
  <label class="check"><input type="checkbox" name="remember" value="1">{{ t('Keep me signed in for 7 days on this computer') }}</label>
  <button class="btn btn-p btn-block btn-shine">{{ t('Continue') }}{{ icon('arrow', 18, 'go flip') }}</button>
  <p class="auth-sec">{{ icon('shield', 18) }}{{ t('Roles that handle money or the team confirm with a 6‑digit code sent by e-mail.') }}</p>
  @if ($demo)
    <details class="demo-x">
      <summary><span class="demo-ic">{{ icon('users', 17) }}</span><span class="demo-t"><b>{{ t('Try a demo account') }}</b><small>{{ t('password') }} <code>{{ $demoPassword }}</code></small></span>{{ icon('chevd', 18, 'demo-chev') }}</summary>
      <ul class="demo-list">
        @foreach ($demoAccounts as $a)
          <li><button type="button" data-demo-email="{{ $a->email }}" data-demo-password="{{ $demoPassword }}">
            <span class="av">{{ initials($a->name) }}</span>
            <span class="who"><b>{{ t($a->role) }}</b><small>{{ $a->org }}</small><small class="em" dir="ltr">{{ $a->email }}</small></span>
            {{ icon('arrow', 16, 'flip') }}</button></li>
        @endforeach
      </ul>
      <p class="demo-note">{{ t('This sign-in page protects the demo. A real deployment connects your identity provider.') }}</p>
    </details>
  @endif
</form>
@endsection
