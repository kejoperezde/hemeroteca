<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LibroOficiosSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('libro_oficios')->insert([
            [
                'oficio_peticion' => 'OF-2024-001',
                'fecha_oficio'    => '2024-01-15',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'oficio_peticion' => 'OF-2024-002',
                'fecha_oficio'    => '2024-03-10',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'oficio_peticion' => 'OF-2024-003',
                'fecha_oficio'    => '2024-06-22',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
        ]);
    }
}
