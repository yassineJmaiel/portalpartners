@php use App\Portal\Lang; @endphp
<!doctype html>
<html lang="{{ Lang::current() }}" dir="{{ Lang::dir() }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>VSahha Provider Portal</title>
  <link rel="icon" href="/brand/vsahha-app-icon.svg" type="image/svg+xml">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Kufi+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/css/app.css">
  <link rel="stylesheet" href="/css/portal.css">
</head>
<body>
<div class="auth">
  <aside class="auth-art">
    <div class="auth-brand"><img class="logo" src="/brand/vsahha-logo-horizontal-white.svg" width="330" alt="Vsahha"><i>{{ t('Provider Portal') }}</i></div>
    <div>
      <h1>{{ t("Your patients' cover, claims and payments in one place.") }}</h1>
      <p>{{ t('For hospitals, clinics, pharmacies, laboratories and doctors in the VSahha network.') }}</p>
      <ul>@foreach ([t("Check a patient's cover in seconds"), t('Drop invoices — the reader files them under the right patient'), t('Follow every claim and pre-authorization to payment'), t('Download EOBs and proofs of payment, verifiable by QR')] as $x)
        <li>{{ icon('check') }} {{ $x }}</li>@endforeach</ul>
    </div>
    <div class="auth-scene" aria-hidden="true">
      <div class="world">
        <div class="orbit"><span class="orbit-line"></span>
          @foreach ([['hospital', 'Hospital'], ['heart', 'Clinic'], ['pill', 'Pharmacy'], ['flask', 'Laboratory'], ['stetho', 'Doctor']] as $i => [$ic, $l])
            <div class="arm" style="--i:{{ $i }}"><span class="moon"><span class="planet">{{ icon($ic, 16) }}<b>{{ t($l) }}</b></span></span></div>
          @endforeach
        </div>
        <div class="stack3d">
          <div class="sheet sh1"><div class="sh-top"><span class="sh-chip">GOP</span><span class="sh-ok">{{ icon('check', 12) }}</span></div><i class="ln w80"></i><i class="ln w60"></i><i class="ln w70"></i><div class="sh-foot"><span class="sh-qr"></span><span class="sh-stamp">{{ t('Authorised') }}</span></div></div>
          <div class="sheet sh2"><div class="sh-top"><span class="sh-chip c2">EOB</span></div><i class="ln w70"></i><i class="ln w90"></i><i class="ln w50"></i><div class="sh-bars"><i style="height:40%"></i><i style="height:70%"></i><i style="height:55%"></i><i style="height:85%"></i></div></div>
          <div class="sheet sh3"><div class="sh-top"><span class="sh-chip c3">{{ icon('shield', 12) }}</span><b>{{ t('Covered') }}</b></div><div class="sh-checks">@for ($k = 0; $k < 4; $k++)<span>{{ icon('check', 11) }}</span>@endfor</div></div>
        </div>
      </div>
      <span class="glow g1"></span><span class="glow g2"></span>
    </div>
    <small style="opacity:.75">© {{ date('Y') }} Virtual Sahha Solutions</small>
  </aside>
  <main class="auth-form">
    <div class="auth-col">
      <form class="auth-lang" role="group" aria-label="{{ t('Language') }}" method="post" action="/language">@csrf {{ icon('globe', 16) }}
        @foreach (['en' => 'English', 'fr' => 'Français', 'ar' => 'العربية'] as $k => $l)
          <button name="language" value="{{ $k }}" lang="{{ $k }}" class="{{ Lang::current() === $k ? 'on' : '' }}" aria-pressed="{{ Lang::current() === $k ? 'true' : 'false' }}">{{ $l }}</button>
        @endforeach
      </form>
      <div class="auth-stage"><span class="auth-halo" aria-hidden="true"></span>
        <div class="auth-card">
          <img class="logo auth-logo" src="/brand/vsahha-logo-horizontal-color.svg" width="240" alt="Vsahha">
          @yield('form')
        </div>
      </div>
    </div>
  </main>
</div>
<script src="/js/app.js"></script>
</body>
</html>
