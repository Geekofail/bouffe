{{--
    Notifications éphémères.
    - Côté serveur : session()->flash('status', 'Message') ou ->with('status', …)
    - Côté Livewire : $this->dispatch('notify', message: 'Message', type: 'success'|'warning')
    - Avec un bouton (lot 21) : …, action: ['label' => 'Annuler', 'event' => 'undo-meal-stock', 'params' => ['mealId' => 12]]
      Le message reste alors 8 secondes, et le bouton envoie l'événement Livewire indiqué.
    - Durée choisie (lot 30, « Annuler » : 10 secondes) : …, duration: 10000 — une barre montre le temps qui reste.
--}}
<div x-data="{
        toasts: [],
        push(message, type = 'success', action = null, duration = null) {
            const id = Date.now() + Math.random();
            const ms = duration ?? (action ? 8000 : 4000);
            this.toasts.push({ id, message, type, action, ms });
            setTimeout(() => this.dismiss(id), ms);
        },
        dismiss(id) {
            this.toasts = this.toasts.filter(t => t.id !== id);
        },
        run(toast) {
            window.Livewire?.dispatch(toast.action.event, toast.action.params ?? {});
            this.dismiss(toast.id);
        },
     }"
     x-init="@if (session('status')) push(@js(session('status'))) @endif"
     x-on:notify.window="push($event.detail.message, $event.detail.type ?? 'success', $event.detail.action ?? null, $event.detail.duration ?? null)"
     class="pointer-events-none fixed inset-x-0 bottom-20 z-50 print:hidden flex flex-col items-center gap-2 px-4 md:top-4 md:bottom-auto md:items-end"
     aria-live="polite">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-transition.opacity
             class="pointer-events-auto relative flex w-full max-w-sm items-center gap-3 overflow-hidden rounded-lg bg-stone-900 px-4 py-3 text-sm text-white shadow-lg">
            <template x-if="toast.action">
                <span class="toast-timer absolute inset-x-0 bottom-0 h-0.5 origin-left bg-brand-300" x-bind:style="'animation-duration: ' + toast.ms + 'ms'" aria-hidden="true"></span>
            </template>
            <span x-show="toast.type === 'success'" class="text-herb-500"><x-icon name="success" class="size-5" /></span>
            <span x-show="toast.type === 'warning'" class="text-amber-400"><x-icon name="warning" class="size-5" /></span>
            <span x-text="toast.message" class="flex-1"></span>
            <template x-if="toast.action">
                <button type="button" x-on:click="run(toast)" x-text="toast.action.label"
                        class="shrink-0 rounded-md px-2 py-1 text-sm font-semibold text-brand-300 hover:bg-white/10"></button>
            </template>
        </div>
    </template>
</div>
