@extends('layouts.app')
@section('title', t('Appeals').' · VSahha Provider Portal')
@section('content')
<x-page-head icon="scale" :title="t('Appeals')" :sub="t('Ask VSahha to review a decision. Appeals are answered within 20 days.')" />
<x-card :title="t('Your appeals')" :pad="false">
  @if (! $appeals)
    <x-empty icon="scale" :title="t('No appeals')">{{ t('When a line is reduced or declined for a reason that allows it, you can appeal from the claim\'s page.') }}</x-empty>
  @else
    <div class="tbl-wrap" style="margin-top:10px"><table class="tbl">
      <thead><tr><th>{{ t('Appeal') }}</th><th>{{ t('Claim') }}</th><th>{{ t('Lines') }}</th><th>{{ t('Status') }}</th><th>{{ t('Answer due') }}</th></tr></thead>
      <tbody>@foreach ($appeals as $a)
        <tr class="click" data-href="/claims/{{ rawurlencode($a['claimId']) }}">
          <td><div class="id mono">{{ $a['id'] }}</div><div class="sub">{{ t('received {d}', ['d' => day($a['receivedAt'] ?? null)]) }} · {{ $a['submittedBy'] ?? '' }}</div></td>
          <td class="mono">{{ $a['claimId'] }}</td>
          <td>@foreach ($a['lines'] ?? [] as $l)<div style="font-size:12.5px">{{ $l['id'] }} · {{ $l['description'] ?? '' }} · {{ $l['reasonCode'] ?? '' }}</div>@endforeach</td>
          <td>{{ pill(t($a['status']), ['Upheld' => 'green', 'Declined' => 'red', 'Partly upheld' => 'teal'][$a['status']] ?? 'blue') }}@if (! empty($a['decision']))<div class="sub" style="margin-top:4px">{{ $a['decision'] }}</div>@endif</td>
          <td>@if (! empty($a['decidedAt']))<span class="muted">{{ t('answered {d}', ['d' => day($a['decidedAt'])]) }}</span>@else{{ day($a['dueAt'] ?? null) }}@endif</td>
        </tr>
      @endforeach</tbody>
    </table></div>
  @endif
</x-card>
<x-card :title="t('Claims with reduced or declined lines')" :pad="false">
  @if (! $candidates)
    <x-empty icon="check" :title="t('None')">{{ t('No decided claim of yours has a reduced or declined line.') }}</x-empty>
  @else
    <div class="list">@foreach ($candidates as $c)
      <a class="li" href="/claims/{{ rawurlencode($c['id']) }}" style="grid-template-columns:minmax(0,1fr) auto auto">
        <div><div class="id mono">{{ $c['id'] }}</div><div class="who">{{ $c['memberName'] }} · {{ t($c['decisionStatus']) }} · {{ t('treated {d}', ['d' => day($c['treatmentDate'] ?? null)]) }}</div></div>
        <span class="amt num">{{ money($c['money']['approved'] ?? null, $c['currency']) }}<small>{{ t('of {a} claimed', ['a' => money($c['claimed'], $c['currency'])]) }}</small></span>
        <span class="lnk">{{ t('Open to appeal') }}{{ icon('chev', 15, 'flip') }}</span>
      </a>
    @endforeach</div>
  @endif
</x-card>
<div class="note note-grey">{{ icon('info') }}<div>{{ t('A decline for confirmed fraud or misrepresentation (RJ-13) cannot be appealed through the portal.') }}</div></div>
@endsection
