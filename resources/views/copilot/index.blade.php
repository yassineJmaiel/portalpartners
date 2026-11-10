@extends('layouts.app')
@php
  use App\Portal\Copilot;
  $ctx = me();
  $tr = fn ($x) => Copilot::tr($x);
@endphp
@section('title', t('Copilot').' · VSahha Provider Portal')
@section('live', '0')
@section('content')
<x-page-head icon="spark" :title="t('Copilot')" :sub="t('Ask about this branch\'s claims, pre-authorizations, GOPs, payments and patients\' benefits. Every answer shows the records it comes from.')">
  <x-slot:actions>
    <span class="cp-mode">{{ icon('lock', 14) }}{{ t('Answers from your records') }}</span>
    @if (count($turns))<form method="post" action="/copilot/clear">@csrf<button class="btn btn-s btn-sm">{{ icon('refresh', 15) }}{{ t('New conversation') }}</button></form>@endif
  </x-slot:actions>
</x-page-head>
<section class="card cp">
  <div class="cp-thread" aria-live="polite">
    @if (! count($turns))
      <div class="cp-hello">
        <span class="cp-orb"><img src="/brand/vsahha-symbol-white.svg" alt="" width="34" height="34"></span>
        <h2>{{ t('What would you like to know?') }}</h2>
        <p>{{ t("I read only {provider}'s own records, and I never submit, change or delete anything.", ['provider' => $ctx->providerLabel()]) }}</p>
        <form method="post" action="/copilot" class="cp-start">@csrf
          @foreach (Copilot::STARTERS as $s)<button name="question" value="{{ t($s) }}">{{ icon('spark', 15) }}{{ t($s) }}</button>@endforeach
        </form>
        <ul class="cp-rules">
          <li>{{ icon('check', 14) }}{{ t("Answers only from your claims, pre-authorizations, payments and patients' plans") }}</li>
          <li>{{ icon('check', 14) }}{{ t('Every answer lists the record IDs it comes from') }}</li>
          <li>{{ icon('check', 14) }}{{ t("No clinical advice, no other providers, no members' bank details") }}</li>
        </ul>
      </div>
    @endif
    @foreach ($turns as $x)
      @php $a = $x['answer']; $cites = $a['citations'] ?? []; $refused = $a['kind'] === 'refusal'; @endphp
      <div class="cp-turn">
        <div class="cp-q">{{ $x['question'] }}</div>
        <div class="cp-a {{ $refused ? 'refused' : '' }} {{ $a['kind'] === 'not-found' ? 'nf' : '' }}">
          @if ($refused)<div class="cp-flag">{{ icon('shield', 15) }}{{ t('Outside what Copilot can answer') }}</div>@endif
          <div class="cp-body">
            @foreach ($a['blocks'] as $b)
              @if ($b['type'] === 'p')<p>{{ Copilot::linked($tr($b['t']), $cites) }}</p>
              @elseif ($b['type'] === 'list')<ul>@foreach ($b['items'] as $it)<li>{{ Copilot::linked($tr($it['t']), $cites) }}</li>@endforeach</ul>
              @elseif ($b['type'] === 'facts')<dl class="cp-facts">@foreach ($b['rows'] as [$k, $v])<div><dt>{{ $tr($k) }}</dt><dd>{{ $tr($v) }}</dd></div>@endforeach</dl>
              @elseif ($b['type'] === 'terms')<div class="cp-terms">@foreach ($b['items'] as $it)<span>{{ $tr($it) }}</span>@endforeach</div>
              @endif
            @endforeach
          </div>
          @if ($cites)
            <div class="cp-cites"><small>{{ t('From your records') }}</small>
              @foreach ($cites as $c)
                @if (! empty($c['link']))
                  <a class="cp-cite" href="{{ $c['link'] }}" @if (Copilot::opensApart($c['link'])) target="_blank" rel="noreferrer" @endif>{{ icon(Copilot::KIND_ICON[$c['kind']] ?? 'doc', 14) }}<b dir="ltr">{{ $c['id'] }}</b><i>{{ $tr($c['label']) }}</i>{{ icon('arrow', 13, 'go flip') }}</a>
                @else
                  <span class="cp-cite">{{ icon(Copilot::KIND_ICON[$c['kind']] ?? 'doc', 14) }}<b dir="ltr">{{ $c['id'] }}</b><i>{{ $tr($c['label']) }}</i></span>
                @endif
              @endforeach
            </div>
          @endif
          @if (! empty($a['suggestions']))
            <form method="post" action="/copilot" class="cp-sugg">@csrf
              @foreach ($a['suggestions'] as $s)<button name="question" value="{{ $tr($s) }}">{{ $tr($s) }}</button>@endforeach
            </form>
          @endif
          <div class="cp-meta"><span>{{ $refused ? t('No record was read') : t('Straight from your records') }}</span><span dir="ltr">{{ $a['id'] }}</span></div>
        </div>
      </div>
    @endforeach
    <div id="end"></div>
  </div>
  <form class="cp-ask" method="post" action="/copilot">@csrf
    <textarea name="question" rows="1" maxlength="600" placeholder="{{ t('Ask about a claim, a GOP, a payment or a patient\'s benefits…') }}" aria-label="{{ t('Your question') }}" data-submit-on-enter required>{{ old('question') }}</textarea>
    <button class="btn btn-p">{{ icon('send', 16) }}{{ t('Ask') }}</button>
  </form>
</section>
@endsection
