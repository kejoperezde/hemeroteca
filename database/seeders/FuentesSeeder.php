<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FuentesSeeder extends Seeder
{
    public function run(): void
    {
        $firstUser = User::firstOrCreate(
            ['email' => 'seed.user1@example.com'],
            [
                'name' => 'Seed User 1',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ],
        );

        $secondUser = User::firstOrCreate(
            ['email' => 'seed.user2@example.com'],
            [
                'name' => 'Seed User 2',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ],
        );

        $fuentes = [
            [
                'url'            => 'https://ejemplo.com/articulo-1',
                'titulo'         => 'Primer artículo de prueba',
                'descripcion'    => 'Descripción del primer artículo de la hemeroteca.',
                'estado_captura' => 'capturado',
                'ruta_archivo'   => 'archivos/articulo-1.pdf',
                'capturado_en'   => now(),
                'hash_contenido' => md5('articulo-1'),
                'user_id'        => $firstUser->id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'url'            => 'https://ejemplo.com/articulo-2',
                'titulo'         => 'Segundo artículo de prueba',
                'descripcion'    => 'Descripción del segundo artículo de la hemeroteca.',
                'estado_captura' => 'capturado',
                'ruta_archivo'   => 'archivos/articulo-2.pdf',
                'capturado_en'   => now(),
                'hash_contenido' => md5('articulo-2'),
                'user_id'        => $firstUser->id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'url'            => 'https://ejemplo.com/articulo-3',
                'titulo'         => 'Tercer artículo de prueba',
                'descripcion'    => null,
                'estado_captura' => 'pendiente',
                'ruta_archivo'   => null,
                'capturado_en'   => null,
                'hash_contenido' => null,
                'user_id'        => $secondUser->id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'url'            => 'https://ejemplo.com/articulo-4',
                'titulo'         => 'Cuarto artículo de prueba',
                'descripcion'    => 'Descripción del cuarto artículo.',
                'estado_captura' => 'capturado',
                'ruta_archivo'   => 'archivos/articulo-4.pdf',
                'capturado_en'   => now(),
                'hash_contenido' => md5('articulo-4'),
                'user_id'        => $secondUser->id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ];

        DB::table('fuentes')->insert($fuentes);

        // Pivot fuente_oficio
        DB::table('fuente_oficio')->insert([
            ['fuente_id' => 1, 'oficio_id' => 1],
            ['fuente_id' => 2, 'oficio_id' => 1],
            ['fuente_id' => 3, 'oficio_id' => 2],
            ['fuente_id' => 4, 'oficio_id' => 3],
        ]);

        // Pivot etiqueta_fuente
        DB::table('etiqueta_fuente')->insert([
            ['fuente_id' => 1, 'etiqueta_id' => 1],
            ['fuente_id' => 1, 'etiqueta_id' => 3],
            ['fuente_id' => 2, 'etiqueta_id' => 2],
            ['fuente_id' => 3, 'etiqueta_id' => 4],
            ['fuente_id' => 4, 'etiqueta_id' => 5],
            ['fuente_id' => 4, 'etiqueta_id' => 7],
        ]);
    }
}
