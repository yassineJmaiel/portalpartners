@extends('layouts.app')
@php
  use App\Portal\Vocab;
  $ctx = me();
  $tabs = ['people' => t('People'), 'roles' => t('Roles'), 'signins' => t('Sign-in history'), 'audit' => t('Audit trail'), 'outbox' => t('Messages sent'), 'pilot' => t('Pilot')];
  $deviceOf = function (?string $ua) {
      if (! $ua) { return '—'; }
      $browser = str_contains($ua, 'Edg/') ? 'Edge' : (str_contains($ua, 'Chrome/') ? 'Chrome' : (str_contains($ua, 'Firefox/') ? 'Firefox' : (str_contains($ua, 'Safari/') ? 'Safari' : 'Browser')));
      $system = str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Android') ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (str_contains($ua, 'Mac OS') ? 'Mac' : (str_contains($ua, 'Linux') ? 'Linux' : ''))));
      return $system ? "{$browser} · {$system}" : $browser;
  };
  $ratings = [['easy', 'Easy', 'green'], ['ok', 'OK', 'blue'], ['hard', 'Hard', 'amber']];
@endphp
@section('title', t('Team').' · VSahha Provider Portal')
@section('live', '0')
@section('content')
<x-page-head icon="users" :title="t('Team')" :sub="t('Who can use the portal for {o}, and what each role can do.', ['o' => $ctx->organisation()->name])">
  <x-slot:actions><button type="button" class="btn btn-p" data-dialog="invite-dialog">{{ icon('plus', 17) }}{{ t('Invite') }}</button></x-slot:actions>
</x-page-head>
<x-tabs :value="$tab" :tabs="$tabs" />
@if ($link)
  <div class="note note-{{ $link['transmitted'] ? 'green' : 'amber' }}">{{ icon($link['transmitted'] ? 'check' : 'info') }}<div>
    @if ($link['kind'] === 'invite'){{ $link['transmitted'] ? t('The invitation was e-mailed.') : t('No e-mail service is connected, so the invitation was recorded but not sent. Give this link to the person:') }}
    @else{{ $link['transmitted'] ? t('The link was e-mailed.') : t('No e-mail service is connected. Give this link to the person (valid 24 hours):') }}@endif
    @if ($link['link'])<input readonly value="{{ $link['link'] }}" style="width:100%;margin-top:8px" data-select-on-focus>@endif
  </div></div>
@endif
@if ($tab === 'people')
  <x-card :pad="false"><div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>{{ t('Person') }}</th><th>{{ t('Role') }}</th><th>{{ t('Status') }}</th><th>{{ t('Sign-in code') }}</th><th>{{ t('Last sign-in') }}</th><th></th></tr></thead>
    <tbody>@foreach ($users as $u)
      <tr>
        <td><b>{{ $u->name }}</b><div class="sub">{{ $u->email }}</div></td>
        <td><form method="post" action="/team/{{ $u->id }}" data-autosubmit>@csrf<select name="role" aria-label="{{ t('Role of {name}', ['name' => $u->name]) }}" @disabled($u->id === $ctx->user->id)>@foreach (array_keys(Vocab::ROLES) as $r)<option value="{{ $r }}" @selected($u->role === $r)>{{ t($r) }}</option>@endforeach</select></form></td>
        <td>{{ pill(t($u->status), $u->status === 'Active' ? 'green' : ($u->status === 'Invited' ? 'blue' : 'red')) }}</td>
        <td>@if (in_array($u->role, Vocab::MFA_REQUIRED, true))<span class="muted" style="font-size:12px">{{ t('Required by the role') }}</span>
          @else<form method="post" action="/team/{{ $u->id }}" data-autosubmit>@csrf<input type="hidden" name="mfa" value="0"><label class="check"><input type="checkbox" name="mfa" value="1" @checked($u->mfa)>{{ t('On') }}</label></form>@endif</td>
        <td>@if ($u->last_login_at){{ ago($u->last_login_at) }}@else<span class="muted">{{ t('never') }}</span>@endif</td>
        <td class="r"><div class="row end" style="gap:6px">
          <form method="post" action="/team/{{ $u->id }}/reset">@csrf<button class="btn btn-s btn-sm">{{ t('Reset link') }}</button></form>
          @if ($u->id !== $ctx->user->id)
            <form method="post" action="/team/{{ $u->id }}">@csrf
              @if ($u->status === 'Suspended')<button class="btn btn-s btn-sm" name="status" value="Active">{{ t('Reactivate') }}</button>@else<button class="btn btn-danger btn-sm" name="status" value="Suspended">{{ t('Suspend') }}</button>@endif
            </form>
          @endif
        </div></td>
      </tr>
    @endforeach</tbody>
  </table></div></x-card>
@elseif ($tab === 'roles')
  <div class="grid2">@foreach (Vocab::ROLES as $id => [$desc, $can])
    <x-card :title="t($id)" :sub="in_array($id, Vocab::MFA_REQUIRED, true) ? t('sign-in code required') : ''">
      <p class="muted" style="font-size:13px;margin-bottom:8px">{{ t($desc) }}</p>
      @foreach ($can ?? array_keys(Vocab::CAPABILITIES) as $c)<div class="row" style="font-size:13px;padding:2px 0">{{ icon('check', 14, '', 'color:var(--green)') }}{{ t(Vocab::CAPABILITIES[$c]) }}</div>@endforeach
    </x-card>
  @endforeach</div>
@elseif ($tab === 'signins')
  <x-card :pad="false">
    @if (! count($signins))<x-empty :title="t('No sign-ins yet')" />@else
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ t('When') }}</th><th>{{ t('Who') }}</th><th>{{ t('Outcome') }}</th><th>{{ t('From') }}</th></tr></thead>
        <tbody>@foreach ($signins as $s)<tr><td>{{ day_time($s->at) }}</td><td>{{ $s->name ?: $s->email }}</td><td>{{ pill(t($s->outcome), str_contains($s->outcome, 'signed in') ? 'green' : (str_contains($s->outcome, 'code sent') ? 'blue' : 'amber')) }}</td><td class="sub">{{ $s->ip }} · {{ $deviceOf($s->ua) }}</td></tr>@endforeach</tbody></table></div>
    @endif
  </x-card>
@elseif ($tab === 'audit')
  <x-card :pad="false">
    @if (! count($audit))<x-empty :title="t('Nothing recorded yet')" />@else
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ t('When') }}</th><th>{{ t('Who') }}</th><th>{{ t('Action') }}</th><th>{{ t('Subject') }}</th><th>{{ t('Detail') }}</th><th>{{ t('Before → after') }}</th><th>{{ t('Device') }}</th></tr></thead>
        <tbody>@foreach ($audit as $a)<tr><td>{{ day_time($a->at) }}</td><td>{{ $a->name }}</td><td><b>{{ $a->action }}</b></td><td class="mono">{{ $a->subject ?: '—' }}</td><td class="sub" style="max-width:380px;overflow:hidden;text-overflow:ellipsis">{{ $a->detail }}</td><td class="sub" style="max-width:320px;overflow:hidden;text-overflow:ellipsis">{{ $a->before_state || $a->after_state ? ($a->before_state ?: '—').' → '.($a->after_state ?: '—') : '' }}</td><td class="sub">{{ $deviceOf($a->device) }}</td></tr>@endforeach</tbody></table></div>
    @endif
  </x-card>
@elseif ($tab === 'outbox')
  <div class="note note-grey">{{ icon('info') }}<div>{{ t('Every e-mail the portal sends goes through one place. When no e-mail service is connected, messages are recorded here as not sent — the portal never claims to have sent them.') }}</div></div>
  <x-card :pad="false">
    @if (! count($outbox))<x-empty :title="t('No messages')" />@else
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ t('When') }}</th><th>{{ t('To') }}</th><th>{{ t('Subject') }}</th><th>{{ t('Sent?') }}</th></tr></thead>
        <tbody>@foreach ($outbox as $m)<tr><td>{{ day_time($m->at) }}</td><td>{{ $m->recipient }}</td><td>{{ $m->subject }}</td><td>{!! $m->transmitted ? pill(t('Sent'), 'green') : pill(t('Recorded, not sent'), 'amber', true, $m->reason) !!}</td></tr>@endforeach</tbody></table></div>
    @endif
  </x-card>
@else
  @php $d = $pilot; $total = array_sum($d['ratings']); @endphp
  <form class="row" method="get" action="/team" data-autosubmit><input type="hidden" name="tab" value="pilot"><b>{{ t('Pilot') }}</b><span class="muted" style="font-size:13px">{{ t('since {d}', ['d' => day_time($d['since'])]) }}</span><span class="sp"></span>
    <select name="days" aria-label="{{ t('Period') }}">@foreach ([7, 14, 28, 90] as $n)<option value="{{ $n }}" @selected($d['days'] === $n)>{{ t('Last {n} days', ['n' => $n]) }}</option>@endforeach</select></form>
  <div class="grid2 stats5">
    @foreach ($ratings as [$id, $label])<div class="stat"><span>{{ t($label) }}</span><b>{{ $d['ratings'][$id] }}</b><small>{{ $total ? t('{p}% of answers', ['p' => round($d['ratings'][$id] / $total * 100)]) : t('no answers yet') }}</small></div>@endforeach
    <div class="stat"><span>{{ t('Sign-ins') }}</span><b>{{ $d['signedIn'] }}</b><small>{{ t('{n} attempts', ['n' => $d['signins']]) }}</small></div>
    <div class="stat"><span>{{ t('Active people') }}</span><b>{{ $d['active']->sum('n') }}</b><small>{{ $d['active']->map(fn ($a) => t($a->role).' '.$a->n)->implode(' · ') ?: '—' }}</small></div>
  </div>
  <div class="grid2">
    <x-card :title="t('What people did')" :sub="t('counted from the audit trail')" :pad="false"><div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ t('Action') }}</th><th class="r">{{ t('Times') }}</th><th class="r">{{ t('People') }}</th></tr></thead>
      <tbody>@foreach ($d['usage'] as $u)<tr><td>{{ t($u['label']) }}</td><td class="r num">{{ $u['count'] }}</td><td class="r num">{{ $u['people'] }}</td></tr>@endforeach</tbody></table></div></x-card>
    <x-card :title="t('Feedback')" :pad="false">
      @if (! count($d['feedback']))
        <x-empty icon="chat" :title="t('No feedback yet')">{{ t('Everyone can send it from the speech-bubble button at the top of every page.') }}</x-empty>
      @else
        <div class="list">@foreach ($d['feedback'] as $f)
          @php $r = collect($ratings)->firstWhere(0, $f->rating); @endphp
          <div class="li" style="grid-template-columns:minmax(0,1fr) auto">
            <div style="min-width:0"><div class="row" style="gap:6px">@if ($r){{ pill(t($r[1]), $r[2]) }}@endif<span class="mono" style="font-size:12px">{{ $f->page }}</span></div>
              @if ($f->body)<p style="font-size:13px;margin-top:4px">{{ $f->body }}</p>@endif
              <div class="who">{{ $f->name }} · {{ t($f->role) }} · {{ day_time($f->at) }}</div></div>
            <form method="post" action="/feedback/{{ $f->id }}" data-autosubmit>@csrf<select name="status" aria-label="{{ t('Status of this feedback') }}">@foreach (['New', 'Read', 'Done'] as $s)<option value="{{ $s }}" @selected($f->status === $s)>{{ t($s) }}</option>@endforeach</select></form>
          </div>
        @endforeach</div>
      @endif
    </x-card>
  </div>
@endif
@endsection
@section('dialogs')
  <div class="layer" id="invite-dialog" hidden>
    <form class="modal modal-md" method="post" action="/team" role="dialog" aria-modal="true" aria-label="{{ t('Invite a team member') }}">@csrf
      <header><div><h3>{{ t('Invite a team member') }}</h3></div><button class="x" type="button" aria-label="{{ t('Close') }}" data-dialog-close>{{ icon('x') }}</button></header>
      <div class="modal-b"><div class="form">
        <label class="field"><span class="lbl">{{ t('Name or desk') }}</span><input name="name" placeholder="{{ t('e.g. Billing desk 2') }}"></label>
        <label class="field"><span class="lbl">{{ t('E-mail') }}</span><input type="email" name="email" required></label>
        <label class="field wide"><span class="lbl">{{ t('Role') }}</span><select name="role">@foreach (Vocab::ROLES as $id => [$desc])<option value="{{ $id }}" @selected($id === 'Billing Officer')>{{ t($id) }} — {{ t($desc) }}</option>@endforeach</select></label>
      </div></div>
      <footer><button type="button" class="btn btn-s" data-dialog-close>{{ t('Cancel') }}</button><button class="btn btn-p">{{ t('Send the invitation') }}</button></footer>
    </form>
  </div>
@endsection
