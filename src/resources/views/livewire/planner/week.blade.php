<div>
    @include('livewire.planner.week.header')

    @include('livewire.planner.week.banners')

    @include('livewire.planner.week.grid')

    {{-- ============================================================ Envies (14.5) --}}
    <div class="mt-6 max-w-xl print:hidden">
        <livewire:planner.wish-box :compact="true" />
    </div>

    @include('livewire.planner.week.meal-detail')

    @include('livewire.planner.week.copy-week')

    <livewire:planner.meal-picker />
    <livewire:planner.occasion-editor />
</div>
