<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-col items-center gap-4 text-center">
        <flux:icon.clock class="size-12! text-amber-500 dark:text-amber-400" />

        <x-auth-header
            title="Thanks for signing up"
            description="Your account has been created and is waiting for an administrator to approve it."
        />
    </div>

    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
        <p>
            An administrator must approve your account before you can log in.
            We'll send you an email as soon as your account has been reviewed.
        </p>
    </div>

    <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
        Know your account is approved?
        <x-text-link href="{{ route('login') }}">Log in</x-text-link>
    </div>
</div>
