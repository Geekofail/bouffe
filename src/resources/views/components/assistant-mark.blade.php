{{-- Marque des contenus proposés par l'assistant culinaire (lot 33) : jamais confondus avec les vôtres. --}}
@props(['text' => 'Proposé par l\'assistant'])
<span {{ $attributes->class('inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800') }}>
    <x-icon name="sparkles" class="size-3.5" /> {{ $text }}
</span>
