<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects guest users from hemeroteca', function (): void {
    $this->get(route('hemeroteca'))
        ->assertRedirect(route('login'));
});

it('renders hemeroteca data including oficio number', function (): void {
    $user = User::factory()->create();

    $sourceId = DB::table('fuentes')->insertGetId([
        'url' => 'https://example.com/noticia',
        'titulo' => 'Noticia de prueba',
        'descripcion' => 'Descripcion de prueba',
        'estado_captura' => 'capturada',
        'ruta_archivo' => 'capturas/fuente_1/page.html',
        'capturado_en' => now(),
        'hash_contenido' => null,
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tagId = DB::table('etiquetas')->insertGetId([
        'nombre' => 'archivo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('etiqueta_fuente')->insert([
        'fuente_id' => $sourceId,
        'etiqueta_id' => $tagId,
    ]);

    $oficioId = DB::table('libro_oficios')->insertGetId([
        'oficio_peticion' => 'OF-123',
        'fecha_oficio' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('fuente_oficio')->insert([
        'fuente_id' => $sourceId,
        'oficio_id' => $oficioId,
    ]);

    $this->actingAs($user)
        ->get(route('hemeroteca'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hemeroteca')
            ->has('sources', 1)
            ->where('sources.0.id', $sourceId)
            ->where('sources.0.name', 'Noticia de prueba')
            ->where('sources.0.oficioNumber', 'OF-123')
            ->where('sources.0.capturedBy', $user->name)
            ->where('sources.0.tags.0', 'archivo')
            ->has('suggestedTags')
        );
});
