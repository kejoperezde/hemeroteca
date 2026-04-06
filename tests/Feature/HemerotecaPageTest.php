<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

it('stores a source with an uploaded wacz file', function (): void {
    Storage::fake('local');

    $user = User::factory()->create();
    $waczFile = UploadedFile::fake()->create('evidencia.wacz', 512, 'application/octet-stream');

    $response = $this->actingAs($user)
        ->post(route('hemeroteca.sources.store'), [
            'url' => 'https://example.com/nota',
            'name' => 'Nota archivada',
            'description' => 'Descripcion de prueba',
            'tags' => ['archivo', 'hemeroteca'],
            'waczFile' => $waczFile,
        ]);

    $response
        ->assertRedirect(route('hemeroteca'))
        ->assertSessionHas('status', 'success');

    $source = DB::table('fuentes')
        ->where('url', 'https://example.com/nota')
        ->first();

    expect($source)->not->toBeNull();
    expect($source->estado_captura)->toBe('cargada');
    expect($source->ruta_archivo)->toBe("capturas/fuente_{$source->id}/fuente_{$source->id}.wacz");

    Storage::disk('local')->assertExists($source->ruta_archivo);
});

it('stores a source through the authenticated upload api', function (): void {
    Storage::fake('local');

    $user = User::factory()->create();
    $waczFile = UploadedFile::fake()->create('capture.wacz.zip', 256, 'application/zip');

    $response = $this->actingAs($user)
        ->post(route('hemeroteca.sources.store-api'), [
            'url' => 'https://example.com/reporte',
            'description' => 'Carga por API',
            'waczFile' => $waczFile,
        ]);

    $response
        ->assertCreated()
        ->assertJsonStructure([
            'message',
            'sourceId',
            'backupPath',
        ]);

    $backupPath = (string) $response->json('backupPath');
    $sourceId = (int) $response->json('sourceId');

    Storage::disk('local')->assertExists($backupPath);

    $source = DB::table('fuentes')->where('id', $sourceId)->first();

    expect($source)->not->toBeNull();
    expect($source->titulo)->toBe('example.com');
});

it('uploads wacz draft and returns browser open url', function (): void {
    Storage::fake('local');
    Cache::flush();

    $user = User::factory()->create();
    $waczFile = UploadedFile::fake()->create('draft.wacz', 128, 'application/octet-stream');

    $response = $this->actingAs($user)
        ->post(route('hemeroteca.sources.upload-draft-api'), [
            'url' => 'https://example.com/desde-extension',
            'waczFile' => $waczFile,
        ]);

    $response
        ->assertCreated()
        ->assertJsonStructure([
            'message',
            'draftToken',
            'openUrl',
        ]);

    $draftToken = (string) $response->json('draftToken');

    expect($draftToken)->not->toBe('');

    $payload = Cache::get('hemeroteca:draft:'.$draftToken);

    expect($payload)->toBeArray();
    expect($payload['url'])->toBe('https://example.com/desde-extension');

    Storage::disk('local')->assertExists((string) $payload['stored_path']);
});

it('stores source with draft token without reuploading file', function (): void {
    Storage::fake('local');
    Cache::flush();

    $user = User::factory()->create();
    $waczFile = UploadedFile::fake()->create('draft.wacz.zip', 128, 'application/zip');

    $draftResponse = $this->actingAs($user)
        ->post(route('hemeroteca.sources.upload-draft-api'), [
            'url' => 'https://example.com/final',
            'waczFile' => $waczFile,
        ]);

    $draftToken = (string) $draftResponse->json('draftToken');

    $storeResponse = $this->actingAs($user)
        ->post(route('hemeroteca.sources.store'), [
            'url' => 'https://example.com/final',
            'name' => 'Registro final desde extension',
            'draftToken' => $draftToken,
        ]);

    $storeResponse
        ->assertRedirect(route('hemeroteca'))
        ->assertSessionHas('status', 'success');

    $source = DB::table('fuentes')
        ->where('url', 'https://example.com/final')
        ->first();

    expect($source)->not->toBeNull();
    expect($source->estado_captura)->toBe('cargada');

    Storage::disk('local')->assertExists($source->ruta_archivo);
    expect(Cache::get('hemeroteca:draft:'.$draftToken))->toBeNull();
});
