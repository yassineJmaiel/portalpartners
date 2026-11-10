@props(['title' => null, 'sub' => null, 'pad' => true, 'action' => null])
<section {{ $attributes->merge(['class' => 'card']) }}>
  @if ($title || $action)
    <header class="card-h">
      @if ($title)<h2>{{ $title }}</h2>@endif
      @if ($sub)<span class="sub">{{ $sub }}</span>@endif
      @if ($action)<div class="card-a">{{ $action }}</div>@endif
    </header>
  @endif
  <div class="{{ $pad ? 'card-b' : '' }}">{{ $slot }}</div>
</section>
