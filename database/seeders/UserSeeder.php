<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Crear usuario administrador
        $user = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrador',
                'telefono' => '5551234567',
                'usuario' => 'admin',
                'password' => Hash::make('administrador'),
                'rol' => 'Root',
                'status' => 1,
            ],
        );

        // Asignar rol root
        $user->assignRole('root');

        $hector = User::updateOrCreate(
            ['usuario' => 'hector.medrano'],
            [
                'name' => 'Hector Medrano',
                'email' => 'hector.medrano@example.com',
                'telefono' => '5551234568',
                'password' => Hash::make('12345678'),
                'rol' => 'Root',
                'status' => 1,
            ],
        );

        $hector->assignRole('root');
    }
}
