@extends('layouts.app')
@php
  use App\Portal\Vocab;
  $ctx = me();
  $manage = $ctx->roleCan('team.manage');
  $tabs = $ctx->all ? array_filter(['notifications' => t('Notifications'), 'integrations' => $manage ? t('Integrations') : null, 'security' => t('Security')])
    : array_filter(['organisation' => t('Organisation'), 'contracts' => t('Contracts & tariffs'), 'bank' => $seesBank ? t('Bank details') : null, 'requests' => t('Requests'), 'notifications' => t('Notifications'),
      'integrations' => $manage ? t('Integrations') : null, 'privacy' => $manage ? t('Data & privacy') : null, 'security' => t('Security')]);
  $p = $profile;
  $kv = fn ($rows) => array_filter($rows);
  $years = fn ($n) => $n >= 365 ? t('{n} years', ['n' => round($n / 365 * 10) / 10]) : t('{n} days', ['n' => $n]);
  $eventLabel = ['claim.decided' => 'A claim is decided', 'payment.released' => 'A payment is released', 'gop.issued' => 'A GOP is issued or revised', 'info.requested' => 'VSahha needs information'];
@endphp
@section('title', t('Settings').' · VSahha Provider Portal')
@section('live', '0')
@section('content')
<x-page-head icon="gear" :title="t('Settings')" :sub="$ctx->all ? t('A branch\'s own details, contracts and bank account are shown when that branch is chosen in the menu.') : t('Your organisation as VSahha records it. To correct something, message the provider desk.')" />
@if ($ctx->all)
  <x-card :title="t('Branches in this portal')">@foreach ($ctx->providers as $b)<div class="row" style="font-size:13px"><b class="mono">{{ $b->id }}</b>{{ $b->label }}</div>@endforeach</x-card>
@endif
<x-tabs :value="$tab" :tabs="$tabs" />

@if ($tab === 'organisation' && $p)
  <div class="grid-side">
    <x-card :title="$p['name']" :sub="$p['code'] ?? null">
      <dl class="kv kv-2">
        @foreach ([[t('Type'), t($p['type'] ?? '')], [t('Network status'), t($p['standing']['networkStatus'] ?? '')], [t('Standing'), t($p['standing']['status'] ?? '')], [t('Specialties'), implode(', ', $p['specialties'] ?? [])],
          [t('Address'), implode(', ', array_filter([$p['address'] ?? null, $p['postalCode'] ?? null, $p['city'] ?? null, $p['country'] ?? null]))], [t('Phone'), $p['phone'] ?? null], [t('Emergency phone'), $p['emergencyPhone'] ?? null], [t('E-mail'), $p['email'] ?? null], [t('Website'), $p['website'] ?? null],
          [t('Opening hours'), $p['workingHours'] ?? null], [t('Languages'), implode(', ', $p['languages'] ?? [])], [t('Licence'), ! empty($p['licenseNumber']) ? $p['licenseNumber'].' · '.t('until {d}', ['d' => day($p['licenseExpiry'] ?? null)]) : null],
          [t('Accreditation'), ! empty($p['accreditation']) ? $p['accreditation'].' · '.t('until {d}', ['d' => day($p['accreditationExpiry'] ?? null)]) : null], [t('Capabilities'), implode(', ', $p['capabilities'] ?? [])]] as [$k, $v])
          <div><dt>{{ $k }}</dt><dd>@if ($v){{ $v }}@else<span class="muted">{{ t('Not recorded') }}</span>@endif</dd></div>
        @endforeach
      </dl>
    </x-card>
    <div class="stack">
      <x-card :title="t('Branches in this portal')">@foreach ($ctx->providers as $b)<div class="row" style="font-size:13px"><b class="mono">{{ $b->id }}</b>{{ $b->label }}</div>@endforeach</x-card>
      @if (empty($p['standing']['canSubmit']))<div class="note note-red">{{ icon('alert') }}<div>{{ $p['standing']['reason'] ?? '' }}</div></div>@endif
    </div>
  </div>
@elseif ($tab === 'contracts')
  @if (! $contracts)<x-card><x-empty icon="file" :title="t('No contract on file')" /></x-card>@endif
  @foreach ($contracts as $c)
    <x-card :title="($c['number'] ?? null) ?: $c['id']" :sub="day($c['start'] ?? null).' – '.(! empty($c['end']) ? day($c['end']) : t('open-ended'))" :pad="false">
      <x-slot:action>{{ pill(! empty($c['expiringSoon']) ? t('Expiring soon') : t($c['status'] ?? ''), ($c['status'] ?? null) === 'Active' ? (! empty($c['expiringSoon']) ? 'amber' : 'green') : 'muted') }}</x-slot:action>
      <div class="card-b"><dl class="kv kv-3">
        @foreach ([[t('Tier'), $c['tier'] ?? null], [t('Participation'), $c['participation'] ?? null], [t('Settlement'), $c['settlement'] ?? null], [t('Discount'), ($c['discount'] ?? null) !== null ? "{$c['discount']}%" : null], [t('Currency'), $c['currency'] ?? null], [t('Services'), implode(', ', $c['services'] ?? [])]] as [$k, $v])
          <div><dt>{{ $k }}</dt><dd>@if ($v){{ $v }}@else<span class="muted">{{ t('Not recorded') }}</span>@endif</dd></div>
        @endforeach
      </dl></div>
      @if (! empty($c['lines']))
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ t('Tariff code') }}</th><th>{{ t('Service') }}</th><th>{{ t('Unit') }}</th><th class="r">{{ t('List tariff') }}</th><th class="r">{{ t('Your contracted price') }}</th></tr></thead>
          <tbody>@foreach ($c['lines'] as $l)<tr><td class="mono"><b>{{ $l['code'] }}</b></td><td>{{ ($l['name'] ?? null) ?: '—' }}</td><td>{{ ($l['unit'] ?? null) ?: '—' }}</td><td class="r num">{{ ($l['listTariff'] ?? null) !== null ? money($l['listTariff'], $c['currency'] ?? null) : '—' }}</td><td class="r num"><b>{{ money($l['agreed'] ?? null, $c['currency'] ?? null) }}</b></td></tr>@endforeach</tbody></table></div>
      @endif
    </x-card>
  @endforeach
@elseif ($tab === 'bank' && $seesBank && $p)
  @php $b = $p['billing'] ?? []; @endphp
  <div class="grid2">
    <x-card :title="t('Where VSahha pays you')">
      <dl class="kv kv-1">
        @foreach ([[t('Bank'), $b['bankName'] ?? null], [t('Account holder'), $b['accountHolder'] ?? null], ['IBAN', $b['ibanMasked'] ?? null], ['SWIFT / BIC', $b['swift'] ?? null], [t('Payment terms'), $b['paymentTerms'] ?? null], [t('Invoice currency'), $b['invoiceCurrency'] ?? null], [t('Tax number'), $b['taxNumber'] ?? null]] as [$k, $v])
          <div><dt>{{ $k }}</dt><dd>@if ($v){{ $v }}@else<span class="muted">{{ t('Not recorded') }}</span>@endif</dd></div>
        @endforeach
      </dl>
      <div class="note note-grey" style="margin-top:12px">{{ icon('lock') }}<div>{{ t('Bank details are never changed directly in the portal. A change is a request with a signed letter; VSahha checks it, and payments keep using the current account until it is approved.') }}</div></div>
    </x-card>
    @if ($ctx->can('bank.request'))
      <x-card :title="t('Request a change of bank details')" :sub="t('VSahha approves it before any payment uses the new account')">
        <form method="post" action="/settings/bank-change" enctype="multipart/form-data">@csrf
          <div class="form">
            <label class="field"><span class="lbl">{{ t('Bank') }}</span><input name="bankName" value="{{ old('bankName') }}" required></label>
            <label class="field"><span class="lbl">{{ t('Account holder') }}</span><input name="accountHolder" value="{{ old('accountHolder') }}" required></label>
            <label class="field"><span class="lbl">IBAN</span><input name="iban" value="{{ old('iban') }}" placeholder="TN59 …" required></label>
            <label class="field"><span class="lbl">SWIFT / BIC</span><input name="swift" value="{{ old('swift') }}"></label>
            <div class="field wide"><span class="lbl">{{ t('Signed letter from your bank or management') }} *</span>
              <label class="drop compact">{{ icon('upload', 18) }}<div><b>{{ t('Attach the letter') }}</b></div><input type="file" name="letter[]" hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.heic" data-file-names required></label><div class="files" data-file-list></div></div>
          </div>
          <div class="row end" style="margin-top:12px"><button class="btn btn-p">{{ t('Send the request') }}</button></div>
        </form>
      </x-card>
    @else
      <x-card><x-empty icon="lock" :title="t('Only a Provider Admin can request this change')" /></x-card>
    @endif
  </div>
@elseif ($tab === 'requests')
  <x-card :pad="false">
    @if (! $requests)<x-empty icon="info" :title="t('No requests')" />@else
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ t('Request') }}</th><th>{{ t('About') }}</th><th>{{ t('Sent') }}</th><th>{{ t('Status') }}</th></tr></thead>
        <tbody>@foreach ($requests as $r)
          <tr><td><b class="mono">{{ $r['id'] }}</b><div class="sub">{{ t($r['kind']) }}</div></td>
            <td>{{ $r['subjectId'] ?? '' }}{{ ! empty($r['payload']['ibanMasked']) ? " · {$r['payload']['ibanMasked']}" : '' }}{{ ! empty($r['payload']['newValidUntil']) ? ' · '.t('until {d}', ['d' => day($r['payload']['newValidUntil'])]) : '' }}{{ ! empty($r['payload']['newAmount']) ? " · {$r['payload']['newAmount']}" : '' }}</td>
            <td>{{ day_time($r['at'] ?? null) }}</td>
            <td>{{ pill(t($r['status']), $r['status'] === 'Approved' ? 'green' : ($r['status'] === 'Declined' ? 'red' : 'blue')) }}@if (! empty($r['note']))<div class="sub">{{ $r['note'] }}</div>@endif</td></tr>
        @endforeach</tbody></table></div>
    @endif
  </x-card>
@elseif ($tab === 'notifications')
  <x-card :title="t('E-mail me when…')" :sub="t('every notification also appears in the bell')" :pad="false">
    @if (! $emailConnected)<div class="card-b" style="padding-bottom:0"><div class="note note-amber">{{ icon('mail') }}<div>{{ t('No e-mail service is connected yet. Your choices are kept, and e-mails are recorded as not sent until one is connected.') }}</div></div></div>@endif
    <div class="list">@foreach ($prefs as $n)
      <form class="li" style="grid-template-columns:minmax(0,1fr) auto" method="post" action="/settings/notify">@csrf<input type="hidden" name="type" value="{{ $n['type'] }}"><input type="hidden" name="email" value="{{ $n['email'] ? 0 : 1 }}">
        <div class="id" style="font-family:inherit">{{ t($n['label']) }}</div>
        <button role="switch" aria-checked="{{ $n['email'] ? 'true' : 'false' }}" class="sw {{ $n['email'] ? 'on' : '' }}" aria-label="{{ t($n['label']) }}"><i></i></button>
      </form>
    @endforeach</div>
  </x-card>
@elseif ($tab === 'integrations' && $manage)
  <div class="grid2">
    <x-card :title="t('Send events to your own system')" :sub="t('your hospital information system or accounting software')">
      <p class="muted" style="font-size:13px;margin-bottom:10px">{{ t('Each event is a POST with a JSON body, signed with your secret: header x-vsahha-signature = t=<time>,v1=HMAC-SHA256(secret, t.body). Only record IDs, statuses and amounts are sent — no medical text.') }}</p>
      <form method="post" action="/settings/endpoints">@csrf
        <label class="field"><span class="lbl">{{ t('Address (https)') }}</span><input name="url" placeholder="https://his.example-clinic.tn/vsahha/events" dir="ltr" required></label>
        <div style="margin-top:10px">@foreach ($events as $ev)<label class="check"><input type="checkbox" name="events[]" value="{{ $ev }}" @checked(in_array($ev, ['claim.decided', 'payment.released'], true))>{{ t($eventLabel[$ev] ?? $ev) }} <code style="font-size:11.5px">{{ $ev }}</code></label>@endforeach</div>
        <div class="row end" style="margin-top:12px"><button class="btn btn-p">{{ icon('plus', 17) }}{{ t('Add the endpoint') }}</button></div>
      </form>
      @if ($created)<div class="note note-green" style="margin-top:12px">{{ icon('lock') }}<div><b>{{ t('Copy the secret now — it is not shown again.') }}</b><code dir="ltr" style="display:block;margin-top:6px;word-break:break-all">{{ $created }}</code></div></div>@endif
    </x-card>
    <x-card :title="t('Your endpoints')" :pad="false">
      @if (! count($endpoints))
        <x-empty icon="external" :title="t('No endpoint yet')">{{ t('Events stay in the portal until you add one.') }}</x-empty>
      @else
        <div class="list">@foreach ($endpoints as $x)
          <div class="li" style="grid-template-columns:minmax(0,1fr) auto">
            <div style="min-width:0"><div class="id" dir="ltr" style="overflow:hidden;text-overflow:ellipsis">{{ $x->url }}</div>
              <div class="who">{{ implode(' · ', json_of($x->events, [])) }} · {{ t('secret') }} {{ substr(decrypt($x->secret), 0, 6) }}…</div>
              <div class="who">{{ ! $x->last ? t('Nothing sent yet') : ($x->last->transmitted ? t('Last event delivered {when}', ['when' => day_time($x->last->at)]) : t('Last event not delivered: {why}', ['why' => $x->last->reason ?: '—'])) }}</div></div>
            <div class="row" style="gap:6px">
              <form method="post" action="/settings/endpoints/{{ $x->id }}/test">@csrf<button class="btn btn-s btn-sm">{{ icon('send', 15) }}{{ t('Test') }}</button></form>
              <form method="post" action="/settings/endpoints/{{ $x->id }}/delete">@csrf<button class="btn btn-danger btn-sm" aria-label="{{ t('Remove') }}">{{ icon('trash', 15) }}</button></form>
            </div>
          </div>
        @endforeach</div>
      @endif
    </x-card>
  </div>
@elseif ($tab === 'privacy' && $manage)
  @php $d = $privacy; @endphp
  <div class="grid2">
    <x-card :title="t('How long the portal keeps its own data')" :sub="t('claims, decisions and payments are kept by VSahha\'s CRM')" :pad="false">
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ t('What') }}</th><th class="r">{{ t('Kept for') }}</th></tr></thead>
        <tbody>@foreach ($d['retention'] as $r)<tr><td>{{ t($r['label']) }}</td><td class="r">{{ $r['days'] ? $years($r['days']) : t('Kept') }}</td></tr>@endforeach</tbody></table></div>
      <div class="card-b"><p class="muted" style="font-size:12.5px">{{ $d['lastRun'] ? t('Last applied {when}.', ['when' => day_time($d['lastRun']['at'])]) : t('Not applied yet: it runs a minute after the server starts, then every day.') }} {{ t('A removed document stays listed, marked as removed.') }}</p></div>
    </x-card>
    <div class="stack">
      <x-card :title="t('Protection')">
        <dl class="kv kv-1">
          <div><dt>{{ t('Documents at rest') }}</dt><dd>AES-256-GCM · {{ t($d['keyFromEnv'] ? "key from the server's key manager" : 'key kept in the data folder (development)') }}</dd></div>
          <div><dt>{{ t('Virus scanning') }}</dt><dd>{{ $d['scanner'] }}</dd></div>
          <div><dt>{{ t('E-mail') }}</dt><dd>{{ in_array('email', $d['transports'], true) ? t('Connected') : t('Not connected — recorded as not sent') }}</dd></div>
          <div><dt>{{ t('SMS') }}</dt><dd>{{ in_array('sms', $d['transports'], true) ? t('Connected') : t('Not connected — recorded as not sent') }}</dd></div>
          <div><dt>{{ t('Documents') }}</dt><dd>{{ t('{n} stored · {r} removed under the policy', ['n' => $d['files'], 'r' => $d['removed']]) }}</dd></div>
        </dl>
        @if (! $d['clamav'])<div class="note note-amber" style="margin-top:12px">{{ icon('shield') }}<div>{{ t('Only the built-in checks scan uploads. Connect ClamAV (CLAMD_HOST) for full virus scanning.') }}</div></div>@endif
      </x-card>
      <x-card :title="t('Background work')" :sub="t('readings, e-mails and events, retried when they fail')">
        @foreach ($d['jobs'] as $j)<div class="row" style="font-size:13px"><span class="mono">{{ $j['kind'] }}</span>{{ pill(t($j['status']), $j['status'] === 'failed' ? 'red' : ($j['status'] === 'done' ? 'green' : 'blue')) }}<span class="sp"></span>{{ $j['n'] }}</div>@endforeach
        @if (! $d['jobs'])<p class="muted" style="font-size:13px">{{ t('Nothing yet.') }}</p>@endif
        @foreach ($d['failedJobs'] as $j)<div class="reason" style="color:var(--red)">{{ $j['kind'] }} · {{ day_time($j['updated_at']) }} · {{ $j['last_error'] }}</div>@endforeach
      </x-card>
    </div>
  </div>
@elseif ($tab === 'security')
  <div class="grid2">
    <x-card :title="t('Your sign-in')">
      <dl class="kv kv-1"><div><dt>{{ t('E-mail') }}</dt><dd>{{ $ctx->user->email }}</dd></div><div><dt>{{ t('Role') }}</dt><dd>{{ t($ctx->user->role) }}</dd></div></dl>
      @if (in_array($ctx->user->role, Vocab::MFA_REQUIRED, true))
        <div class="note note-teal" style="margin-top:12px">{{ icon('lock') }}<div>{{ t('Your role always asks for a 6-digit code at sign-in, unless you trusted this computer.') }}</div></div>
      @else
        <form method="post" action="/settings/security" data-autosubmit style="margin-top:12px">@csrf<input type="hidden" name="mfa" value="0"><label class="check"><input type="checkbox" name="mfa" value="1" @checked($ctx->user->mfa)>{{ t('Ask me for a 6-digit code when I sign in') }}</label></form>
      @endif
    </x-card>
    <x-card :title="t('Sessions')">
      <p class="muted" style="font-size:13px">{{ t('A session ends after 30 minutes without activity, or 8 hours in all (7 days if you chose to stay signed in). Your administrator can suspend an account, which ends its sessions at once.') }}</p>
      <form method="post" action="/logout">@csrf<button class="btn btn-danger" style="margin-top:12px">{{ icon('logout', 17) }}{{ t('Sign out') }}</button></form>
    </x-card>
  </div>
@endif
@endsection
