@props(['message', 'details' => []])
<div class="note note-red" role="alert">{{ icon('alert') }}<div><b>{{ App\Portal\Lang::say($message) }}</b>
  @if ($details)<ul>@foreach ($details as $d)<li>{{ is_string($d) ? $d : (($d['text'] ?? null) ?: (($d['label'] ?? null) ?: (($d['name'] ?? null) ? "{$d['name']} · {$d['id']}" : json_encode($d)))) }}</li>@endforeach</ul>@endif
</div></div>
