<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Create roles
        $rootRole = Role::firstOrCreate(['name' => 'root']);
        Role::firstOrCreate(['name' => 'analista']);
        Role::firstOrCreate(['name' => 'administrativo']);

        // Create permissions
        $permissions = collect([
            'reg_i',
            'reg_ii',
            'reg_iii',
            'reg_iv',
            'reg_va',
            'reg_vb',
            'reg_vi',
            'reg_general',
            'abs_patrimonial',
            'abs_integridad',
            'abs_transito',
            'abs_robo',
            'abs_imputado',
            'abs_sexual',
            'abs_desaparecido',
            'abs_extorsion_y_secuestro',
            'abs_narcomenudeo',
            'abs_homicidios',
            'abs_general',
            'abs_servicio',
            'abs_hemeroteca',
            'abs_hemeroteca_edit',
        ])->map(static fn (string $permissionName): Permission => Permission::firstOrCreate(['name' => $permissionName]));

        $rootRole->syncPermissions($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
