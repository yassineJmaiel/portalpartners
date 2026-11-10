{{-- $tabs: id => label or [label, count]; each tab is a link, so a page can be bookmarked --}}
@props(['tabs', 'value', 'param' => 'tab', 'base' => null])
<div class="tabs" role="tablist">
  @foreach ($tabs as $id => $tb)
    @php [$label, $count] = is_array($tb) ? $tb : [$tb, null]; @endphp
    <a role="tab" href="{{ ($base ?? url()->current()).'?'.http_build_query(array_filter(array_merge(request()->except(['page', $param]), [$param => $id]), fn ($v) => $v !== null && $v !== '')) }}" aria-selected="{{ $value === $id ? 'true' : 'false' }}" class="{{ $value === $id ? 'on' : '' }}">{{ $label }}@if ($count !== null)<em>{{ $count }}</em>@endif</a>
  @endforeach
</div>
