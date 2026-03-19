<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EtiquetasSeeder extends Seeder
{
    public function run(): void
    {
        $etiquetas = [
            'política',
            'economía',
            'nota roja',
            'deportes',
            'tecnología',
            'sociedad',
            'internacional',
        ];

        foreach ($etiquetas as $nombre) {
            DB::table('etiquetas')->insert([
                'nombre'     => $nombre,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
