{!! $title !!}

@foreach ($paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@if ($action)
{!! $action[0] !!} : {!! $action[1] !!}

@endif
@if ($footer)
{!! $footer !!}
@endif
