@props(['icon' => 'info', 'title'])
<div class="empty"><span class="empty-i">{{ icon($icon, 22) }}</span><b>{{ $title }}</b>@if (trim($slot))<p>{{ $slot }}</p>@endif</div>
