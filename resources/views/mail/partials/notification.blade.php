{{-- Corpo comum dos emails de notificação (doc 10): título, corpo PT,
     secções opcionais (digest/agregado), botão para o front e nota final.
     Os modelos por tipo (`mail/task-due-soon`, `mail.daily-digest`, …) são
     pontos de extensão: incluem este parcial para o conteúdo viver num só
     sítio. --}}
<x-mail::message>
# {{ $title }}

{{ $body }}

@if (! empty($sections))
@foreach ($sections as $section)
**{{ $section['label'] }}**

@foreach ($section['items'] as $item)
- {{ $item }}
@endforeach

@endforeach
@endif

<x-mail::button :url="$actionUrl">{{ $actionLabel }}</x-mail::button>

<x-slot:subcopy>
    @include('mail.partials.subcopy')
</x-slot:subcopy>
</x-mail::message>
