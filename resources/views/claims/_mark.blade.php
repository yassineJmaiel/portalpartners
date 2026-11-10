{{-- a value a reader filled in, marked with where it came from until a person confirms it or changes it --}}
@if ($f)
  <span class="ai-mark">@include('partials.provenance', ['f' => $f])
    <button class="prov t-green" name="nav" value="{{ $nav }}" aria-label="{{ t('Confirm this value') }}" title="{{ t('Confirm this value') }}">{{ icon('check', 11) }}</button></span>
@endif
