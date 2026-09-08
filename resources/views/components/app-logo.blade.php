<flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
    <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
        <x-app-logo-icon class="size-8 fill-current text-accent dark:text-black" />
    </x-slot>
</flux:brand>
