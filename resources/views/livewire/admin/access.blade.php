<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Support\PermissionMatrix;
use Flux\Flux;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

new class extends Component {
    /**
     * The editable permission matrix: [role => [permission => bool]].
     */
    public array $matrix = [];

    /**
     * Load the current matrix from the database.
     */
    public function mount(): void
    {
        $this->matrix = $this->loadMatrix();
    }

    /**
     * Group the permission cases under their section headings.
     *
     * @return array<string, array<int, Permission>>
     */
    public function permissionGroups(): array
    {
        $groups = [];

        foreach (Permission::cases() as $permission) {
            $group = match (true) {
                str_starts_with($permission->value, 'users.'),
                str_starts_with($permission->value, 'roles.') => 'Users',
                str_starts_with($permission->value, 'walks.') => 'Walks',
                str_starts_with($permission->value, 'reports.') => 'Reports',
                default => 'Other',
            };

            $groups[$group][] = $permission;
        }

        return $groups;
    }

    /**
     * Whether the matrix on the page differs from what is stored.
     */
    public function hasChanges(): bool
    {
        return $this->matrix !== $this->loadMatrix();
    }

    /**
     * Discard unsaved changes by reloading from the database.
     */
    public function discard(): void
    {
        $this->matrix = $this->loadMatrix();
    }

    /**
     * Reset the matrix back to its defaults.
     */
    public function resetToDefaults(): void
    {
        $this->ensureSuperAdmin();

        PermissionMatrix::reset();

        $this->matrix = $this->loadMatrix();

        $this->modal('reset-defaults')->close();

        Flux::toast(variant: 'success', text: 'Permissions reset to defaults');
    }

    /**
     * Persist the edited matrix.
     */
    public function save(): void
    {
        $this->ensureSuperAdmin();

        $nameToValue = [];

        foreach (Permission::cases() as $permission) {
            $nameToValue[$permission->name] = $permission->value;
        }

        foreach (PermissionMatrix::configurableRoles() as $role) {
            $roleModel = RoleModel::findByName($role->value);

            $permissions = collect($this->matrix[$role->value] ?? [])
                ->filter(fn (mixed $granted) => (bool) $granted)
                ->keys()
                ->filter(fn (string $name) => isset($nameToValue[$name]))
                ->map(fn (string $name) => $nameToValue[$name])
                ->values()
                ->all();

            $roleModel->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->matrix = $this->loadMatrix();

        Flux::toast(variant: 'success', text: 'Permissions saved');
    }

    /**
     * Authorize the current user as a super admin.
     */
    private function ensureSuperAdmin(): void
    {
        abort_unless(auth()->user()->hasRole(Role::SuperAdmin->value), 403);
    }

    /**
     * Build the matrix keyed by role value and permission case name.
     *
     * @return array<string, array<string, bool>>
     */
    private function loadMatrix(): array
    {
        $matrix = [];

        foreach (PermissionMatrix::configurableRoles() as $role) {
            $roleModel = RoleModel::findByName($role->value);
            $granted = $roleModel->permissions->pluck('name')->all();

            foreach (Permission::cases() as $permission) {
                $matrix[$role->value][$permission->name] = in_array($permission->value, $granted, true);
            }
        }

        return $matrix;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Access control</flux:heading>
            <flux:subheading size="lg">Choose what each role can do. Super admins always have every permission.</flux:subheading>
        </div>

        <flux:modal.trigger name="reset-defaults">
            <flux:button variant="ghost" icon="arrow-path">Reset to defaults</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:callout icon="information-circle" text="Role changes for individual users are made on the Users page." />

    <flux:card class="overflow-hidden">
        {{-- Desktop: permission rows as a grid with one column per role. --}}
        <div class="hidden md:block">
            <div class="grid grid-cols-[minmax(0,1fr)_8rem_8rem_8rem] items-center gap-x-6 border-b border-zinc-200 px-6 py-3 dark:border-zinc-700">
                <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Permission</div>
                <div class="text-center text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Super Admin</div>

                @foreach (\App\Support\PermissionMatrix::configurableRoles() as $role)
                    <div class="text-center text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $role->label() }}</div>
                @endforeach
            </div>

            @foreach ($this->permissionGroups() as $group => $permissions)
                <div class="px-6 pt-5 pb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">{{ $group }}</div>

                @foreach ($permissions as $permission)
                    <div class="grid grid-cols-[minmax(0,1fr)_8rem_8rem_8rem] items-center gap-x-6 border-t border-zinc-100 px-6 py-3 dark:border-zinc-800">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-zinc-800 dark:text-white">{{ $permission->label() }}</div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $permission->description() }}</div>
                        </div>

                        <div class="flex justify-center">
                            <flux:tooltip content="Always granted">
                                <flux:switch checked disabled />
                            </flux:tooltip>
                        </div>

                        @foreach (\App\Support\PermissionMatrix::configurableRoles() as $role)
                            <div class="flex justify-center">
                                <flux:switch wire:model="matrix.{{ $role->value }}.{{ $permission->name }}" />
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endforeach
        </div>

        {{-- Mobile: each permission as a stacked card, role switches in a row. --}}
        <div class="md:hidden">
            @foreach ($this->permissionGroups() as $group => $permissions)
                <div class="px-6 pt-5 pb-1 text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">{{ $group }}</div>

                @foreach ($permissions as $permission)
                    <div class="border-t border-zinc-100 px-6 py-4 dark:border-zinc-800">
                        <div class="text-sm font-semibold text-zinc-800 dark:text-white">{{ $permission->label() }}</div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $permission->description() }}</div>

                        <div class="mt-4 flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <flux:tooltip content="Always granted">
                                    <flux:switch checked disabled />
                                </flux:tooltip>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">Super Admin</span>
                            </div>

                            @foreach (\App\Support\PermissionMatrix::configurableRoles() as $role)
                                <div class="flex items-center gap-2">
                                    <flux:switch wire:model="matrix.{{ $role->value }}.{{ $permission->name }}" />
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $role->label() }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    </flux:card>

    @if ($this->hasChanges())
        <div class="sticky bottom-0 z-10 mt-6 flex items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
            <span class="text-sm text-zinc-500 dark:text-zinc-400">You have unsaved changes.</span>

            <div class="flex items-center gap-2">
                <flux:button variant="ghost" wire:click="discard">Discard</flux:button>
                <flux:button variant="primary" wire:click="save">Save changes</flux:button>
            </div>
        </div>
    @endif

    <flux:modal name="reset-defaults" class="md:max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Reset permissions to defaults?</flux:heading>
                <flux:subheading>This restores the default permission matrix for Admin and User, and grants Super Admin every permission.</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="resetToDefaults">Reset to defaults</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
