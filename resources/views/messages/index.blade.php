@extends('layouts.app')
@section('title', t('Messages').' · VSahha Provider Portal')
@section('live', '0')
@section('content')
<x-page-head icon="chat" :title="t('Messages')" :sub="t('Write to VSahha\'s provider desk, claims team and medical team.')" />
<div class="grid-side" style="grid-template-columns:360px minmax(0,1fr)">
  <x-card :title="t('Conversations')" :pad="false">
    <div class="list">
      <a class="li" style="grid-template-columns:auto minmax(0,1fr)" href="/messages?t=new"><span class="tile t-teal" style="width:32px;height:32px;border-radius:9px">{{ icon('plus', 16) }}</span><div class="id">{{ t('New message to VSahha') }}</div></a>
      @foreach ($threads as $th)
        <a class="li" style="grid-template-columns:minmax(0,1fr) auto;{{ $sel === $th['key'] ? 'background:var(--mint)' : '' }}" href="/messages?t={{ urlencode($th['key']) }}">
          <div style="min-width:0"><div class="id">{{ ($th['subjectId'] ?? null) ?: t('General') }}</div><div class="who" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ ($th['last']['from'] ?? null) === 'provider' ? t('You').': ' : 'VSahha: ' }}{{ $th['last']['body'] ?? '' }}</div></div>
          <div style="text-align:end"><span class="who">{{ ago($th['last']['at'] ?? null) }}</span>@if (($th['last']['from'] ?? null) === 'vsahha')<div>{{ pill(t('Reply'), 'teal') }}</div>@endif</div>
        </a>
      @endforeach
      @if (! $threads)<x-empty icon="chat" :title="t('No conversations yet')" />@endif
    </div>
  </x-card>
  <x-card :title="$sel === 'new' ? t('New message') : ($thread ? (($thread['subjectId'] ?? null) ?: t('General')) : t('Choose a conversation'))">
    @if (! empty($thread['subjectId']))<x-slot:action><a class="lnk" href="/{{ $thread['subjectType'] === 'claim' ? 'claims' : 'preauth' }}/{{ rawurlencode($thread['subjectId']) }}">{{ t('Open the record') }}{{ icon('chev', 15, 'flip') }}</a></x-slot:action> @endif
    @if ($sel === 'new')
      @if (me()->can('message.send'))
        <form method="post" action="/messages">@csrf<input type="hidden" name="subjectType" value="general">
          <label class="field"><span class="lbl">{{ t('Message') }}</span><textarea name="body" rows="6" required></textarea><span class="hint">{{ t('For a question about a claim or a pre-authorization, write from its page so the right team gets it.') }}</span></label>
          <div class="row end" style="margin-top:10px"><button class="btn btn-p">{{ icon('send', 17) }}{{ t('Send') }}</button></div>
        </form>
      @else
        <x-empty icon="chat" :title="t('Your role cannot open this page')" />
      @endif
    @elseif ($thread)
      @include('partials.thread', ['messages' => $thread['messages'], 'subjectType' => $thread['subjectType'], 'subjectId' => $thread['subjectId'] ?? null])
    @else
      <x-empty icon="chat" :title="t('Choose a conversation on the left')" />
    @endif
  </x-card>
</div>
@endsection
