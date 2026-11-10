@php
  use App\Portal\Lang;
  $ctx = me();
  $nav = [
    ['/', 'Home', 'home', null], ['/eligibility', 'Eligibility', 'shield', 'eligibility.check'], ['/claims', 'Claims', 'file', 'claim.view'], ['/preauth', 'Pre-authorizations', 'clip', 'preauth.view'],
    ['/intake', 'Smart Intake', 'spark', 'intake.use'], ['/payments', 'Payments & documents', 'wallet', 'payment.view'], ['/copilot', 'Copilot', 'bot', 'one-branch'],
    'Follow-up', ['/appeals', 'Appeals', 'scale', 'claim.view'], ['/messages', 'Messages', 'chat', null], ['/insights', 'Insights', 'chart', 'insights.view'],
    'Organisation', ['/team', 'Team', 'users', 'team.manage'], ['/settings', 'Settings', 'gear', null],
  ];
  $path = '/'.trim(request()->path(), '/');
  $section = '/'.explode('/', trim($path, '/'))[0];
  $profile = $ctx->profile();
  $standing = $profile['standing'] ?? null;
  $unread = $ctx->unread();
  $synced = App\Portal\Crm::lastOk();
  $error = session('portal_error');
@endphp
<!doctype html>
<html lang="{{ Lang::current() }}" dir="{{ Lang::dir() }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'VSahha Provider Portal')</title>
  <meta name="theme-color" content="#0B4A4C">
  <link rel="icon" href="/brand/vsahha-app-icon.svg" type="image/svg+xml">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Kufi+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css">
  <link rel="stylesheet" href="/css/portal.css">
</head>
<body data-live="@yield('live', '1')" data-uid="{{ $ctx->user->id }}"
  data-t-search="{{ t('Search') }}" data-t-nothing="{{ t('Nothing found') }}" data-t-only="{{ t("Only your organisation's records are searched.") }}"
  data-t-close="{{ t('Close') }}" data-t-loading="{{ t('Loading…') }}" data-t-print="{{ t('Print') }}" data-t-newtab="{{ t('New tab') }}" data-t-nomatch="{{ t('No match. Type a code like M54.5, or a word.') }}"
  data-t-usecode="{{ t('Use this code') }}" data-t-unknown="{{ t('Unknown Illness — illness, unspecified (the medical team will review)') }}">
<div class="app">
  <a class="skip" href="#main">{{ t('Skip to content') }}</a>
  <aside class="side" id="side" aria-label="{{ t('Main menu') }}">
    <div class="side-fx" aria-hidden="true"><i class="o1"></i><i class="o2"></i><i class="o3"></i><i class="mesh"></i></div>
    <div class="side-in">
      <a class="brand" href="/"><img class="logo" src="/brand/vsahha-logo-name-white.svg" width="172" alt="Vsahha"><i>{{ t('Provider Portal') }}</i></a>
      <div class="org">
        <div class="av">{{ initials(($profile['name'] ?? null) ?: $ctx->organisation()->name) }}</div>
        <div style="min-width:0;flex:1">
          <b>{{ ($profile['name'] ?? null) ?: $ctx->organisation()->name }}</b>
          @if (count($ctx->providers) > 1)
            <form method="post" action="/me/provider" data-autosubmit>@csrf
              <select name="providerId" aria-label="{{ t('Branch') }}">
                <option value="ALL" @selected($ctx->all)>{{ t('All branches') }}</option>
                @foreach ($ctx->providers as $p)<option value="{{ $p->id }}" @selected(! $ctx->all && $ctx->providerId === $p->id)>{{ $p->label ?: $p->id }}</option>@endforeach
              </select>
            </form>
          @else
            <span>{{ $ctx->providerLabel() }}</span>
          @endif
        </div>
      </div>
      <nav class="nav">
        @foreach ($nav as $n)
          @if (is_string($n))
            <small>{{ t($n) }}</small>
          @elseif ($n[3] === null || ($n[3] === 'one-branch' ? ! $ctx->all : $ctx->roleCan($n[3])))
            <a href="{{ $n[0] }}" class="{{ $section === $n[0] ? 'on' : '' }}" @if ($section === $n[0]) aria-current="page" @endif><span class="ni">{{ icon($n[2]) }}</span>{{ t($n[1]) }}</a>
          @endif
        @endforeach
      </nav>
      <div class="help"><b>{{ t('Need help?') }}</b>{{ t('VSahha provider desk · Mon–Sat 07:30–21:00') }}<br><a class="lnk" href="/messages">{{ t('Write to us') }}</a></div>
    </div>
  </aside>
  <div class="scrim" data-menu-close aria-hidden="true"></div>
  <div class="main">
    <header class="top" id="top">
      <button type="button" class="iconbtn menu-btn" aria-label="{{ t('Menu') }}" data-menu>{{ icon('menu') }}</button>
      <a class="top-mark" href="/" aria-label="Vsahha"><img src="/brand/vsahha-symbol-color.svg" height="30" width="21" alt=""></a>
      <button type="button" class="search" data-palette aria-label="{{ t('Search') }}">{{ icon('search') }}<span class="s-long">{{ t('Search a claim, card number, invoice or GOP…') }}</span><span class="s-short">{{ t('Search') }}</span><kbd>Ctrl K</kbd></button>
      <span class="sp"></span>
      <span class="chip livechip {{ App\Portal\Crm::reachable() ? 'live' : 'crm' }}" id="livechip" data-live-label="{{ t('Live with VSahha') }}" data-down-label="{{ t('VSahha unreachable') }}"><span class="d"></span><span class="livechip-t">{{ App\Portal\Crm::reachable() ? t('Live with VSahha') : t('VSahha unreachable') }}@if ($synced) · <time datetime="{{ $synced }}" title="{{ t('Last read from VSahha {time}', ['time' => day_time($synced)]) }}">{{ clock_of($synced) }}</time>@endif</span></span>
      <form class="lang" method="post" action="/language" data-autosubmit>@csrf {{ icon('globe', 16) }}
        <select name="language" aria-label="{{ t('Language') }}">
          <option value="en" @selected(Lang::current() === 'en')>EN</option><option value="fr" @selected(Lang::current() === 'fr')>FR</option><option value="ar" @selected(Lang::current() === 'ar')>ع</option>
        </select>
      </form>
      <button type="button" class="iconbtn" aria-label="{{ t('How was this page?') }}" title="{{ t('How was this page?') }}" data-dialog="feedback-dialog">{{ icon('chat') }}</button>
      <div style="position:relative" data-dropdown>
        <button type="button" class="iconbtn" aria-label="{{ t('Notifications') }}" data-dropdown-toggle data-notifications="/notifications">{{ icon('bell') }}<span class="dot" id="bell-count" @if (! $unread) hidden @endif>{{ $unread > 9 ? '9+' : $unread }}</span></button>
        <div class="notif" hidden data-dropdown-menu>
          <header>{{ t('Notifications') }}<span class="sp"></span>
            <form method="post" action="/notifications/read">@csrf<button class="lnk" @if (! $unread) hidden @endif>{{ t('Mark all as read') }}</button></form></header>
          <div id="notif-rows"><div class="loading">{{ t('Loading…') }}</div></div>
        </div>
      </div>
      <div class="me" data-dropdown>
        <button type="button" data-dropdown-toggle aria-haspopup="menu">
          <span class="av">{{ initials($ctx->user->name) }}</span>
          <div><b>{{ $ctx->user->name }}</b><span>{{ t($ctx->user->role) }}</span></div>
        </button>
        <div class="drop-menu" role="menu" hidden data-dropdown-menu>
          <div class="who"><b>{{ $ctx->user->name }}</b><span>{{ $ctx->user->email }}</span><span style="display:block">{{ $ctx->organisation()->name }}</span></div>
          <a href="/settings?tab=security">{{ icon('lock') }}{{ t('Security and sign-in') }}</a>
          <form method="post" action="/logout">@csrf<button type="submit">{{ icon('logout') }}{{ t('Sign out') }}</button></form>
        </div>
      </div>
    </header>
    <main class="page" id="main" tabindex="-1">
      @if ($ctx->all)
        <div class="banner teal" role="status">{{ icon('layers') }}<div><b>{{ t('All branches — combined view') }}</b><div>{{ t('Lists and figures cover every branch. To submit or change anything, choose one branch in the menu.') }}</div></div></div>
      @endif
      @if ($standing && empty($standing['canSubmit']) && ! $ctx->all)
        <div class="banner red" role="status">{{ icon('alert') }}<div><b>{{ t('New claims and pre-authorizations are paused') }}</b><div>{{ $standing['reason'] ?? '' }}</div></div></div>
      @endif
      @if (! empty($profile['unreachable']))
        <x-error-box :message="t('The VSahha CRM is not reachable right now. Figures may be missing; nothing you submit is lost.')" />
      @endif
      @if ($error)<x-error-box :message="$error['message']" :details="$error['details'] ?? []" />@endif
      @yield('content')
      <div class="foot"><span>© {{ date('Y') }} Virtual Sahha Solutions · {{ t('Provider Portal') }}</span><span>{{ t('Figures come from the VSahha CRM') }}</span></div>
    </main>
  </div>
</div>

<div class="palette" id="palette" hidden>
  <div class="box" role="dialog" aria-label="{{ t('Search') }}">
    <div class="input">{{ icon('search') }}<input id="palette-q" placeholder="{{ t('Search a claim, card number, invoice or GOP…') }}" autocomplete="off"></div>
    <div class="res" id="palette-res"></div>
  </div>
</div>

<div class="layer doc-layer" id="doc-layer" hidden>
  <div class="doc-bar" role="toolbar"><b id="doc-title"></b><span class="sp"></span>
    <button type="button" id="doc-print">{{ icon('print') }}{{ t('Print') }}</button>
    <a id="doc-open" target="_blank" rel="noopener">{{ icon('external') }}{{ t('New tab') }}</a>
    <button type="button" id="doc-close" aria-label="{{ t('Close') }}">{{ icon('x') }}</button></div>
  <div class="doc-sheet"><iframe id="doc-frame" title="document"></iframe></div>
</div>

@include('partials.feedback')
@yield('dialogs')
<div class="toasts" role="status" aria-live="polite" id="toasts">
  @if (session('toast'))<div class="toast t-{{ session('toast')['tone'] }}">{{ icon(session('toast')['tone'] === 'red' ? 'alert' : 'check') }}{{ session('toast')['text'] }}</div>@endif
</div>
<script src="/js/app.js"></script>
@stack('scripts')
</body>
</html>
