@props(['icon' => 'doc', 'title', 'sub' => null, 'actions' => null])
<section class="ph"><span class="pg-ic" aria-hidden="true">{{ icon($icon, 22) }}</span>
  <div><h1>{{ $title }}</h1>@if ($sub)<p>{{ $sub }}</p>@endif</div>
  @if ($actions)<div class="acts">{{ $actions }}</div>@endif
</section>
