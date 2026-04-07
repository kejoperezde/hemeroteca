<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

class HemerotecaController extends Controller
{
    public function openBackup(int $sourceId): BaseResponse
    {
        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo', 'url', 'titulo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $absolutePath = $this->resolveLocalBackupAbsolutePath((string) $source->ruta_archivo);
        abort_unless(File::exists($absolutePath), 404);

        if (Str::endsWith(strtolower($absolutePath), '.html')) {
            return response(File::get($absolutePath), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

                if (Str::endsWith(strtolower($absolutePath), '.wacz')) {
                    $sourceName = trim((string) ($source->titulo ?? ''));
                    if ($sourceName === '') {
                        $sourceName = (string) (parse_url((string) $source->url, PHP_URL_HOST) ?: 'Sin nombre');
                    }

                        $downloadUrl = route('hemeroteca.sources.backup.download', ['sourceId' => $sourceId]);
                        $uiAssetUrl = route('hemeroteca.sources.replay.asset', [
                            'sourceId' => $sourceId,
                            'asset' => 'ui.js',
                        ]);

                        $viewerHtml = <<<'HTML'
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Respaldo __SOURCE_ID__</title>
    <style>
        html, body {
            margin: 0;
            height: 100%;
            background: #dfd4c5;
            color: #e5e7eb;
            font-family: ui-sans-serif, -apple-system, Segoe UI, sans-serif;
        }
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 16px;
            border-bottom: 1px solid #7f6a4f;
            background: linear-gradient(90deg, #8b775e 0%, #9a8465 100%);
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.08) inset;
        }
        .toolbar-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .toolbar-logo {
            width: 30px;
            height: 30px;
            display: block;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.15);
            padding: 3px;
            box-sizing: border-box;
        }
        .toolbar-title {
            color: #ffffff;
            font-weight: 700;
            letter-spacing: 0.2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .toolbar-meta {
            color: #f8f6f1;
            font-size: 12px;
            line-height: 1.1;
            opacity: 0.95;
        }
        .toolbar-action {
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
            background: rgba(0, 0, 0, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 8px;
            padding: 8px 12px;
            line-height: 1;
        }
        .toolbar-action:hover {
            background: rgba(0, 0, 0, 0.28);
            text-decoration: none;
        }
        replay-web-page {
            display: block;
            width: 100%;
            height: calc(100% - 52px);
        }
    </style>
    <script src="__UI_ASSET_URL__" type="module"></script>
</head>
<body>
    <div class="toolbar">
        <div class="toolbar-brand">
            <img class="toolbar-logo" src="/favicon.svg" alt="Logo Fiscalía" />
            <div>
                <strong class="toolbar-title">Respaldo __SOURCE_ID__</strong>
                <div class="toolbar-meta">Nombre: __SOURCE_NAME__</div>
            </div>
        </div>
        <a class="toolbar-action" href="__DOWNLOAD_URL__">Descargar .wacz</a>
    </div>
    <replay-web-page source="__DOWNLOAD_URL__" url="__ORIGINAL_URL__"></replay-web-page>
</body>
</html>
HTML;

                        $viewerHtml = str_replace(
                            ['__SOURCE_ID__', '__SOURCE_NAME__', '__DOWNLOAD_URL__', '__ORIGINAL_URL__', '__UI_ASSET_URL__'],
                                [
                                        (string) $sourceId,
                                        e($sourceName),
                                        e($downloadUrl),
                                        e((string) $source->url),
                                e($uiAssetUrl),
                                ],
                                $viewerHtml,
                        );

                        return response($viewerHtml, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
                }

        $mimeType = File::mimeType($absolutePath) ?: 'application/octet-stream';

        return response()->file($absolutePath, ['Content-Type' => $mimeType]);
    }

    public function replayAsset(int $sourceId, string $asset): BaseResponse
    {
        abort_unless($sourceId > 0, 404);

        $normalizedAsset = trim($asset, '/');
        if ($normalizedAsset === '' || str_contains($normalizedAsset, '..')) {
            abort(404);
        }

        if (preg_match('/^[A-Za-z0-9_\-\.\/]+$/', $normalizedAsset) !== 1) {
            abort(404);
        }

        if (in_array($normalizedAsset, ['ui.js', 'ui.min.js'], true)) {
            $localUiPath = public_path('js/ui.js');
            abort_unless(File::exists($localUiPath), 404);

            return response(File::get($localUiPath), 200, [
                'Content-Type' => 'application/javascript; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        $upstreamUrl = "https://cdn.jsdelivr.net/npm/replaywebpage/{$normalizedAsset}";
        $upstream = Http::timeout(30)->get($upstreamUrl);

        abort_unless($upstream->successful(), 404);

        $contentType = $upstream->header('content-type') ?: 'application/octet-stream';

        return response($upstream->body(), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function downloadBackup(int $sourceId): BinaryFileResponse
    {
        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $absolutePath = $this->resolveLocalBackupAbsolutePath((string) $source->ruta_archivo);
        abort_unless(File::exists($absolutePath), 404);

        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);
        $downloadName = $extension
            ? "respaldo_fuente_{$sourceId}.{$extension}"
            : "respaldo_fuente_{$sourceId}";

        return response()->download($absolutePath, $downloadName);
    }

    public function thumbnailBackup(int $sourceId): BinaryFileResponse
    {
        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $thumbnailAbsolutePath = $this->resolveLocalThumbnailAbsolutePath((string) $source->ruta_archivo);

        abort_unless(is_string($thumbnailAbsolutePath) && File::exists($thumbnailAbsolutePath), 404);

        return response()->file($thumbnailAbsolutePath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $result = $this->persistSourceWithWacz($request);

        return redirect()
            ->route('hemeroteca')
            ->with('status', $result['ok'] ? 'success' : 'error')
            ->with('message', $result['message']);
    }

    public function uploadDraftApi(Request $request): JsonResponse
    {
        if ($request->hasFile('wacz') && !$request->hasFile('waczFile')) {
            $request->files->set('waczFile', $request->file('wacz'));
        }

        if ($request->hasFile('thumbnail') && !$request->hasFile('thumbnailFile')) {
            $request->files->set('thumbnailFile', $request->file('thumbnail'));
        }

        if ($request->hasFile('screenshot') && !$request->hasFile('thumbnailFile')) {
            $request->files->set('thumbnailFile', $request->file('screenshot'));
        }

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'text' => ['required', 'string'],
            'waczFile' => [
                'required',
                'file',
                'max:307200',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!$value instanceof UploadedFile) {
                        $fail('Debes enviar un archivo WACZ valido.');

                        return;
                    }

                    $originalName = strtolower($value->getClientOriginalName());
                    if (!Str::endsWith($originalName, ['.wacz', '.wacz.zip'])) {
                        $fail('El archivo debe tener extension .wacz o .wacz.zip.');
                    }
                },
            ],
            'thumbnailFile' => ['required', 'file', 'max:10240', 'mimes:png'],
        ]);

        /** @var UploadedFile $uploadedWacz */
        $uploadedWacz = $validated['waczFile'];
        /** @var UploadedFile $uploadedThumbnail */
        $uploadedThumbnail = $validated['thumbnailFile'];

        $token = (string) Str::uuid();
        $waczFileName = Str::endsWith(strtolower($uploadedWacz->getClientOriginalName()), '.wacz.zip')
            ? "{$token}.wacz.zip"
            : "{$token}.wacz";

        $waczDraftPath = $uploadedWacz->storeAs('capturas/drafts', $waczFileName, ['disk' => 'local']);
        $thumbnailDraftPath = $uploadedThumbnail->storeAs('capturas/drafts', "{$token}_preview.png", ['disk' => 'local']);

        if (!is_string($waczDraftPath) || $waczDraftPath === '' || !is_string($thumbnailDraftPath) || $thumbnailDraftPath === '') {
            return response()->json([
                'message' => 'No se pudo guardar el borrador temporal.',
            ], 500);
        }

        Cache::put($this->buildDraftCacheKey($token), [
            'token' => $token,
            'user_id' => (int) $request->user()->id,
            'url' => (string) $validated['url'],
            'text' => trim((string) $validated['text']),
            'wacz_original_name' => $uploadedWacz->getClientOriginalName(),
            'stored_path' => str_replace('\\', '/', $waczDraftPath),
            'thumbnail_path' => str_replace('\\', '/', $thumbnailDraftPath),
        ], now()->addMinutes(60));

        return response()->json([
            'message' => 'Borrador recibido. Abre la URL para completar el formulario.',
            'draftToken' => $token,
            'openUrl' => route('hemeroteca.register', ['draftToken' => $token]),
        ], 201);
    }

    public function discardDraftApi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draftToken' => ['required', 'string', 'max:120'],
        ]);

        $draftToken = trim((string) $validated['draftToken']);
        $draftPayload = $this->getDraftPayloadForUser($draftToken, (int) $request->user()->id);

        $this->forgetDraftPayload($draftToken, $draftPayload);

        return response()->json([
            'message' => 'Borrador eliminado.',
        ], 200);
    }

    public function thumbnailDraft(string $draftToken): BinaryFileResponse
    {
        if (!request()->user()) {
            abort(401);
        }

        $draftPayload = $this->getDraftPayloadForUser($draftToken, (int) request()->user()->id);
        if ($draftPayload === null || empty($draftPayload['thumbnail_path'])) {
            abort(404);
        }

        $thumbnailPath = (string) $draftPayload['thumbnail_path'];
        $absolutePath = Storage::disk('local')->path($thumbnailPath);
        abort_unless(File::exists($absolutePath), 404);

        return response()->file($absolutePath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }


    public function showRegisterForm(): Response
    {
        $prefillDraft = null;
        $draftToken = trim((string) request()->query('draftToken', ''));
        $suggestedTags = DB::table('etiquetas')
            ->select('nombre')
            ->orderBy('nombre')
            ->pluck('nombre')
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values();

        if ($draftToken !== '' && request()->user()) {
            $draftPayload = $this->getDraftPayloadForUser($draftToken, (int) request()->user()->id);

            if (is_array($draftPayload)) {
                $prefillDraft = [
                    'draftToken' => $draftToken,
                    'url' => (string) ($draftPayload['url'] ?? ''),
                    'waczFileName' => (string) ($draftPayload['wacz_original_name'] ?? 'archivo.wacz'),
                    'screenshotUrl' => route('hemeroteca.sources.draft.thumbnail', ['draftToken' => $draftToken]),
                    'previewText' => (string) ($draftPayload['text'] ?? ''),
                ];
            }
        }

        return Inertia::render('register-source', [
            'prefillDraft' => $prefillDraft,
            'suggestedTags' => $suggestedTags,
        ]);
    }

    private function persistSourceWithWacz(Request $request): array
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:120'],
            'draftToken' => ['required', 'string', 'max:120'],
        ]);

        $tagNames = $this->normalizeTags($validated['tags'] ?? []);

        $draftToken = trim((string) $validated['draftToken']);
        $draftPayload = $this->getDraftPayloadForUser($draftToken, (int) $request->user()->id);

        if ($draftPayload === null) {
            return [
                'ok' => false,
                'sourceId' => null,
                'backupPath' => null,
                'message' => 'El borrador no existe, expiro o no pertenece al usuario autenticado.',
            ];
        }

        $sourceId = $this->createSource($validated, (int) $request->user()->id, $tagNames);

        Log::info('Fuente registrada. Iniciando carga de archivo WACZ.', [
            'source_id' => $sourceId,
            'url' => $validated['url'],
            'has_description' => filled($validated['description'] ?? null),
            'uploaded_wacz_name' => $draftPayload['wacz_original_name'] ?? null,
        ]);

        $uploadSucceeded = false;
        $storedBackupPath = null;

        try {
            $storedBackupPath = $this->storeWaczDraft($sourceId, $draftPayload);
            $this->storeThumbnailDraft($sourceId, $draftPayload);

            $absolutePath = $this->resolveLocalBackupAbsolutePath($storedBackupPath);
            $contentHash = File::exists($absolutePath) ? hash_file('sha256', $absolutePath) : null;

            DB::table('fuentes')
                ->where('id', $sourceId)
                ->update([
                    'ruta_archivo' => $storedBackupPath,
                    'estado_captura' => 'cargada',
                    'capturado_en' => now(),
                    'hash_contenido' => $contentHash,
                    'texto' => filled($draftPayload['text'] ?? null) ? (string) $draftPayload['text'] : null,
                    'updated_at' => now(),
                ]);

            $this->forgetDraftPayload($draftToken, $draftPayload);

            $uploadSucceeded = true;
        } catch (\Throwable $exception) {
            $this->markUploadAsFailed($sourceId);

            Log::error('Fallo la carga del archivo WACZ.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return [
            'ok' => $uploadSucceeded,
            'sourceId' => $sourceId,
            'backupPath' => $storedBackupPath,
            'message' => $uploadSucceeded
                ? 'Archivo WACZ cargado y guardado correctamente.'
                : 'La fuente se guardo, pero fallo la carga del archivo WACZ.',
        ];
    }

    private function storeWaczDraft(int $sourceId, array $draftPayload): string
    {
        $draftPath = (string) ($draftPayload['stored_path'] ?? '');
        if ($draftPath === '' || !Storage::disk('local')->exists($draftPath)) {
            throw new \RuntimeException('El archivo WACZ temporal no esta disponible.');
        }

        $directory = "capturas/fuente_{$sourceId}";
        Storage::disk('local')->makeDirectory($directory);

        $originalName = strtolower((string) ($draftPayload['wacz_original_name'] ?? ''));
        $suffix = Str::endsWith($originalName, '.wacz.zip') ? '.wacz.zip' : '.wacz';
        $targetPath = "{$directory}/fuente_{$sourceId}{$suffix}";

        if (!Storage::disk('local')->copy($draftPath, $targetPath)) {
            throw new \RuntimeException('No se pudo mover el WACZ temporal al respaldo final.');
        }

        return str_replace('\\', '/', $targetPath);
    }

    private function storeThumbnailDraft(int $sourceId, array $draftPayload): ?string
    {
        $thumbnailPath = (string) ($draftPayload['thumbnail_path'] ?? '');
        if ($thumbnailPath === '' || !Storage::disk('local')->exists($thumbnailPath)) {
            return null;
        }

        $directory = "capturas/fuente_{$sourceId}";
        Storage::disk('local')->makeDirectory($directory);

        $targetPath = "{$directory}/preview.png";
        if (!Storage::disk('local')->copy($thumbnailPath, $targetPath)) {
            throw new \RuntimeException('No se pudo mover el thumbnail temporal al respaldo final.');
        }

        return str_replace('\\', '/', $targetPath);
    }

    private function getDraftPayloadForUser(string $draftToken, int $userId): ?array
    {
        $payload = Cache::get($this->buildDraftCacheKey($draftToken));
        if (!is_array($payload) || (int) ($payload['user_id'] ?? 0) !== $userId) {
            return null;
        }

        $storedPath = (string) ($payload['stored_path'] ?? '');
        if ($storedPath === '' || !Storage::disk('local')->exists($storedPath)) {
            return null;
        }

        $thumbnailPath = (string) ($payload['thumbnail_path'] ?? '');
        if ($thumbnailPath === '' || !Storage::disk('local')->exists($thumbnailPath)) {
            return null;
        }

        return $payload;
    }

    private function forgetDraftPayload(string $draftToken, ?array $draftPayload): void
    {
        if (is_array($draftPayload)) {
            $storedPath = (string) ($draftPayload['stored_path'] ?? '');
            if ($storedPath !== '' && Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            $thumbnailPath = (string) ($draftPayload['thumbnail_path'] ?? '');
            if ($thumbnailPath !== '' && Storage::disk('local')->exists($thumbnailPath)) {
                Storage::disk('local')->delete($thumbnailPath);
            }
        }

        Cache::forget($this->buildDraftCacheKey($draftToken));
    }

    private function buildDraftCacheKey(string $draftToken): string
    {
        return 'hemeroteca:draft:'.$draftToken;
    }

    private function resolveLocalBackupAbsolutePath(string $backupPath): string
    {
        $normalizedPath = str_replace('\\', '/', trim($backupPath));
        abort_unless($normalizedPath !== '' && !str_contains($normalizedPath, '..'), 404);

        return Storage::disk('local')->path(ltrim($normalizedPath, '/'));
    }

    private function resolveLocalThumbnailAbsolutePath(string $backupPath): ?string
    {
        $backupAbsolutePath = $this->resolveLocalBackupAbsolutePath($backupPath);
        $backupDirectory = dirname($backupAbsolutePath);

        if (!File::isDirectory($backupDirectory)) {
            return null;
        }

        $candidates = [
            $backupDirectory.DIRECTORY_SEPARATOR.'thumbnail.png',
            $backupDirectory.DIRECTORY_SEPARATOR.'thumb.png',
            $backupDirectory.DIRECTORY_SEPARATOR.'preview.png',
        ];

        foreach ($candidates as $candidatePath) {
            if (File::exists($candidatePath)) {
                return $candidatePath;
            }
        }

        $pngFiles = File::glob($backupDirectory.DIRECTORY_SEPARATOR.'*.png') ?: [];

        return $pngFiles[0] ?? null;
    }

    private function resolveSourceTitle(array $validated): string
    {
        $providedName = trim((string) ($validated['name'] ?? ''));
        if ($providedName !== '') {
            return $providedName;
        }

        $host = (string) parse_url((string) ($validated['url'] ?? ''), PHP_URL_HOST);

        return $host !== '' ? $host : 'Sin titulo';
    }

    private function markUploadAsFailed(int $sourceId): void
    {
        DB::table('fuentes')
            ->where('id', $sourceId)
            ->update([
                'ruta_archivo' => null,
                'estado_captura' => 'error_carga',
                'capturado_en' => null,
                'updated_at' => now(),
            ]);
    }

    private function formatSource(object $source): array
    {
        $capturedAt = $source->capturado_en ? Carbon::parse($source->capturado_en) : null;
        $capturedAtLabel = $capturedAt
            ? $capturedAt->locale('es')->translatedFormat('j M Y')
            : 'Sin captura';
        $tags = [];
        $hasBackupPath = is_string($source->ruta_archivo ?? null) && trim((string) $source->ruta_archivo) !== '';
        $storedHash = is_string($source->hash_contenido ?? null) ? trim((string) $source->hash_contenido) : null;
        $currentHash = null;
        $hashStatus = $hasBackupPath ? 'sin_verificar' : 'sin_respaldo';

        if ($hasBackupPath) {
            try {
                $absolutePath = $this->resolveLocalBackupAbsolutePath((string) $source->ruta_archivo);
                if (File::exists($absolutePath)) {
                    $currentHash = hash_file('sha256', $absolutePath) ?: null;

                    if ($storedHash && $currentHash) {
                        $hashStatus = hash_equals(strtolower($storedHash), strtolower($currentHash))
                            ? 'valido'
                            : 'invalido';
                    } elseif ($storedHash) {
                        $hashStatus = 'sin_verificar';
                    } else {
                        $hashStatus = 'sin_hash';
                    }
                } else {
                    $hashStatus = 'sin_respaldo';
                }
            } catch (\Throwable $exception) {
                $hashStatus = 'sin_verificar';
            }
        }

        if (isset($source->tags_concat) && is_string($source->tags_concat) && $source->tags_concat !== '') {
            $tags = array_values(array_filter(array_map(
                static fn (string $tag): string => trim($tag),
                explode('||', $source->tags_concat),
            )));
        }

        return [
            'id' => (int) $source->id,
            'name' => $source->titulo ?: parse_url((string) $source->url, PHP_URL_HOST) ?: 'Sin titulo',
            'description' => $source->descripcion ?: 'Sin descripcion.',
            'url' => (string) $source->url,
            'backupPath' => $source->ruta_archivo ?: null,
            'tags' => $tags,
            'date' => $capturedAt ? $capturedAt->locale('es')->translatedFormat('d/m/Y H:i') : $capturedAtLabel,
            'capturedAt' => $capturedAt?->format('Y-m-d'),
            'capturedBy' => $source->captured_by ?: 'Sin usuario',
            'oficioNumber' => null,
            'hash' => $storedHash,
            'currentHash' => $currentHash,
            'hashStatus' => $hashStatus,
        ];
    }

    public function __invoke(): Response
    {
        $sources = DB::table('fuentes')
            ->leftJoin('users', 'fuentes.user_id', '=', 'users.id')
            ->leftJoin('etiqueta_fuente', 'fuentes.id', '=', 'etiqueta_fuente.fuente_id')
            ->leftJoin('etiquetas', 'etiqueta_fuente.etiqueta_id', '=', 'etiquetas.id')
            ->select(
                'fuentes.id',
                'fuentes.url',
                'fuentes.titulo',
                'fuentes.descripcion',
                'fuentes.ruta_archivo',
                'fuentes.hash_contenido',
                'fuentes.capturado_en',
                'users.name as captured_by',
                DB::raw("GROUP_CONCAT(etiquetas.nombre ORDER BY etiquetas.nombre SEPARATOR '||') as tags_concat"),
            )
            ->groupBy(
                'fuentes.id',
                'fuentes.url',
                'fuentes.titulo',
                'fuentes.descripcion',
                'fuentes.ruta_archivo',
                'fuentes.hash_contenido',
                'fuentes.capturado_en',
                'users.name',
            )
            ->orderByDesc('fuentes.capturado_en')
            ->orderByDesc('fuentes.id')
            ->get()
            ->map(fn (object $source): array => $this->formatSource($source))
            ->values();

        $suggestedTags = DB::table('etiquetas')
            ->select('nombre')
            ->orderBy('nombre')
            ->pluck('nombre')
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values();

        return Inertia::render('hemeroteca', [
            'sources' => $sources,
            'suggestedTags' => $suggestedTags,
        ]);
    }

    private function createSource(array $validated, int $userId, array $tagNames = []): int
    {
        return DB::transaction(function () use ($validated, $userId, $tagNames): int {
            $createdSourceId = DB::table('fuentes')->insertGetId([
                'url' => $validated['url'],
                'titulo' => $this->resolveSourceTitle($validated),
                'descripcion' => $validated['description'] ?? null,
                'estado_captura' => 'pendiente',
                'capturado_en' => null,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncSourceTags($createdSourceId, $tagNames);

            return $createdSourceId;
        });
    }

    /**
     * @param  array<int, mixed>  $rawTags
     * @return array<int, string>
     */
    private function normalizeTags(array $rawTags): array
    {
        $normalizedTags = [];
        $seen = [];

        foreach ($rawTags as $rawTag) {
            $tag = preg_replace('/\s+/u', ' ', trim((string) $rawTag));

            if (!is_string($tag) || $tag === '') {
                continue;
            }

            $key = mb_strtolower($tag, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalizedTags[] = $tag;
        }

        return $normalizedTags;
    }

    /**
     * @param  array<int, string>  $tagNames
     */
    private function syncSourceTags(int $sourceId, array $tagNames): void
    {
        if ($tagNames === []) {
            return;
        }

        $pivotRows = [];

        foreach ($tagNames as $tagName) {
            $pivotRows[] = [
                'fuente_id' => $sourceId,
                'etiqueta_id' => $this->resolveTagId($tagName),
            ];
        }

        DB::table('etiqueta_fuente')->insertOrIgnore($pivotRows);
    }

    private function resolveTagId(string $tagName): int
    {
        $normalizedKey = mb_strtolower(trim($tagName), 'UTF-8');

        $existingTagId = DB::table('etiquetas')
            ->whereRaw('LOWER(nombre) = ?', [$normalizedKey])
            ->value('id');

        if (is_numeric($existingTagId)) {
            return (int) $existingTagId;
        }

        try {
            return (int) DB::table('etiquetas')->insertGetId([
                'nombre' => $tagName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $existingTagId = DB::table('etiquetas')
                ->whereRaw('LOWER(nombre) = ?', [$normalizedKey])
                ->value('id');

            if (is_numeric($existingTagId)) {
                return (int) $existingTagId;
            }

            throw $exception;
        }
    }
}
