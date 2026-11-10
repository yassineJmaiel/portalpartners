@extends(App\Portal\Ctx::has() ? 'layouts.app' : 'layouts.bare')
@section('content')
  @if ($status === 403)
    <x-card><div class="empty"><b>{{ t('Your role cannot open this page') }}</b><p>{{ t('Ask your portal administrator if you need it.') }}</p></div></x-card>
  @else
    @if (! empty($back))<a class="crumb" href="{{ $back }}">{{ icon('back', 15, 'flip') }}{{ t('Back') }}</a>@endif
    <x-error-box :message="$message" :details="$details ?? []" />
    @if ($status === 404)<div class="row"><a class="btn btn-p" href="/">{{ t('Go home') }}</a></div>@endif
  @endif
@endsection
