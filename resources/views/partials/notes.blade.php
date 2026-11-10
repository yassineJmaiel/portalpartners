{{-- TEAM NOTES: $panel from NotesController::panel. Only the organisation sees these, never VSahha. --}}
@php
  $ctx = me();
  $open = collect($panel['notes'])->filter(fn ($n) => $n['kind'] === 'handoff' && ! $n['doneAt'])->last();
  $team = $panel['team'];
  $mentionable = collect($team)->where('id', '!=', $ctx->user->id)->map(fn ($u) => ['name' => $u['name'], 'role' => t($u['role'])])->values();
  $names = collect($team)->whereIn('id', collect($panel['notes'])->pluck('mentions')->flatten()->all())->pluck('name')->sortByDesc(fn ($n) => mb_strlen($n))->values()->all();
  $withMentions = function (?string $body) use ($names) {
      $html = e((string) $body);
      foreach ($names as $n) {
          $html = str_replace('@'.e($n), '<b class="mention">@'.e($n).'</b>', $html);
      }

      return new Illuminate\Support\HtmlString($html);
  };
@endphp
<x-card :title="t('Team notes')" :sub="t('Only your organisation sees these — never VSahha')">
  <div class="notes">
    @if ($open)
      <div class="handoff-now">{{ icon('users', 17) }}
        <div><b>{{ $open['assignee']['id'] === $ctx->user->id ? t('Handed to you by {name}', ['name' => $open['author']['name']]) : t('Handed to {name}', ['name' => $open['assignee']['name']]) }}</b><span>{{ ago($open['at']) }}</span></div>
        @if (($open['assignee']['id'] === $ctx->user->id || $open['author']['id'] === $ctx->user->id) && $panel['canWrite'])
          <form method="post" action="/handoffs/{{ $open['id'] }}/done">@csrf<button class="btn btn-s btn-sm">{{ icon('check', 15) }}{{ t('Mark done') }}</button></form>
        @endif
      </div>
    @endif
    @if (! $panel['notes'])<p class="muted notes-empty">{{ t('No notes yet. Leave one for a colleague, mention them with @, or hand the record over.') }}</p>@endif
    <ol class="note-list">
      @foreach ($panel['notes'] as $n)
        <li class="{{ $n['kind'] }}">
          <span class="av">{{ initials($n['author']['name']) }}</span>
          <div>
            <header><b>{{ $n['author']['name'] }}</b><span>{{ ago($n['at']) }}</span>
              @if ($n['kind'] === 'handoff'){{ pill($n['doneAt'] ? t('Hand-off done') : t('Handed to {name}', ['name' => $n['assignee']['name'] ?? '—']), $n['doneAt'] ? 'green' : 'violet') }}@endif</header>
            @if ($n['body'])<p>{{ $withMentions($n['body']) }}</p>@endif
            @if ($n['kind'] === 'handoff' && $n['doneAt'])<small>{{ t('Done by {name} · {when}', ['name' => $n['doneBy'] ?: '—', 'when' => ago($n['doneAt'])]) }}</small>@endif
          </div>
        </li>
      @endforeach
    </ol>
    @if ($panel['canWrite'])
      <form class="note-new" method="post" action="/notes/{{ $panel['type'] }}/{{ rawurlencode($panel['id']) }}">@csrf
        <div class="note-box">
          <textarea name="body" rows="2" maxlength="2000" placeholder="{{ t('Write a note — type @ to mention a colleague') }}" aria-label="{{ t('Team note') }}"
            data-mentions='@json($mentionable)'></textarea>
        </div>
        <div class="note-actions">
          <label class="handto">{{ icon('users', 15) }}<select name="assignTo" aria-label="{{ t('Hand off to') }}">
            <option value="">{{ t('No hand-off') }}</option>
            @foreach ($team as $u)@if ($u['canNote'])<option value="{{ $u['id'] }}">{{ $u['id'] === $ctx->user->id ? t('Hand off to myself') : t('Hand off to {name}', ['name' => $u['name']]) }}</option>@endif @endforeach
          </select></label>
          <span class="sp"></span>
          <button class="btn btn-p btn-sm">{{ icon('send', 15) }}{{ t('Add note') }}</button>
        </div>
      </form>
    @else
      <p class="muted notes-empty">{{ t('Your role can read team notes but not write them.') }}</p>
    @endif
  </div>
</x-card>
