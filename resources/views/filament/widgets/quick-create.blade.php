<x-filament-widgets::widget>
    <x-filament::section heading="Quick Create">
        <div class="flex flex-col gap-2 sm:flex-row sm:gap-2">
            @foreach($this->getActions() as $action)
                <x-filament::button
                    tag="a"
                    :href="$action['url']"
                    :icon="$action['icon']"
                    color="{{ $action['color'] }}"
                    class="w-full sm:w-auto sm:flex-1"
                >
                    {{ $action['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
