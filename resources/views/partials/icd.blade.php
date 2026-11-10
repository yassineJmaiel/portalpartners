{{-- ICD-10 picker: the catalogue, plus any well-formed code, plus Unknown Illness when $certaintyName is given. Works as a plain text field without scripts. --}}
<div class="combo" data-icd-picker data-allow-unknown="{{ ! empty($certaintyName) ? '1' : '' }}">
  <div class="input {{ ! empty($unknown) ? 'warn' : '' }}">{{ icon('search', 16) }}
    <input name="{{ $name }}" value="{{ $value }}" autocomplete="off" placeholder="{{ $placeholder ?? t('Search a code or a word, e.g. J18.9 or pneumonia') }}" aria-label="{{ t('ICD-10 diagnosis') }}">
    @if (! empty($unknown))<span class="tag-unknown">{{ t('Unknown Illness') }}</span>@endif
  </div>
  @if (! empty($titleName))<input type="hidden" name="{{ $titleName }}" value="{{ $titleValue ?? '' }}" data-icd-title>@endif
  @if (! empty($certaintyName))<input type="hidden" name="{{ $certaintyName }}" value="{{ $certainty ?? 'Confirmed' }}" data-icd-certainty>@endif
  <div class="combo-list" role="listbox" hidden></div>
</div>
