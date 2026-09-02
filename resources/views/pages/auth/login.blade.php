<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Continue securely with your Microsoft account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($teamInvitation)
            <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Log in')" />
        @endif


        <flux:button
            variant="primary"
            :href="route('microsoft.redirect')"
            class="w-full"
            data-test="microsoft-login-button"
        >
            {{ __('Continue with Microsoft') }}
        </flux:button>

    </div>
</x-layouts::auth>
