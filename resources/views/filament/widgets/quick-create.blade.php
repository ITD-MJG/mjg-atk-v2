<x-filament-widgets::widget>
    <x-filament::section heading="Quick Create">
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
            @foreach($this->getActions() as $action)
                <x-filament::button
                    tag="a"
                    :href="$action['url']"
                    :icon="$action['icon']"
                    color="{{ $action['color'] }}"
                    class="w-full"
                >
                    {{ $action['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
