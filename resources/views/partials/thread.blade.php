{{-- a conversation with VSahha on one subject: $messages, $subjectType, $subjectId --}}
<div class="msgs">
  @if (! $messages)<p class="muted" style="font-size:13px">{{ t('No messages yet. Write to VSahha about this {s}.', ['s' => t($subjectType === 'claim' ? 'claim' : 'request')]) }}</p>@endif
  @foreach ($messages as $m)
    <div class="msg {{ ($m['from'] ?? null) === 'provider' ? 'mine' : '' }}"><small>{{ ($m['from'] ?? null) === 'provider' ? ($m['author'] ?? '') : t('VSahha') }} · {{ day_time($m['at'] ?? null) }}</small>{{ $m['body'] ?? '' }}</div>
  @endforeach
  @if (me()->can('message.send'))
    <form class="composer" method="post" action="/messages">@csrf
      <input type="hidden" name="subjectType" value="{{ $subjectType }}"><input type="hidden" name="subjectId" value="{{ $subjectId }}">
      <label class="field"><span class="lbl">{{ t('Message') }}</span><textarea name="body" rows="3" required></textarea></label>
      <button class="btn btn-p">{{ icon('send', 17) }}{{ t('Send') }}</button>
    </form>
  @endif
</div>
