{{--
    Champ de formulaire avec libellé, aide et message d'erreur.
    <x-field label="Nom" for="name" error="form.name" help="…"> <input …> </x-field>
--}}
@props(['label', 'for' => null, 'error' => null, 'help' => null, 'optional' => false])

<div {{ $attributes }}>
    <label @if ($for) for="{{ $for }}" @endif class="form-label">
        {{ $label }}
        @if ($optional) <span class="font-normal text-stone-500">(facultatif)</span> @endif
    </label>
    {{ $slot }}
    @if ($help)
        <p class="mt-1 text-xs text-stone-500">{{ $help }}</p>
    @endif
    @if ($error)
        @error($error) <p class="form-error">{{ $message }}</p> @enderror
    @endif
</div>
