<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Services\BackupPathResolver;
use App\Services\BrowsertrixCaptureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

class HemerotecaController extends Controller
{
    public function __construct(
        private readonly BrowsertrixCaptureService $captureService,
        private readonly BackupPathResolver $pathResolver,
    ) {
    }

    public function openBackup(int $sourceId): BaseResponse
    {
        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo', 'url')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $absolutePath = $this->pathResolver->resolveBackupAbsolutePath((string) $source->ruta_archivo);
        abort_unless(File::exists($absolutePath), 404);

        if (Str::endsWith(strtolower($absolutePath), '.html')) {
            return response(File::get($absolutePath), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

                if (Str::endsWith(strtolower($absolutePath), '.wacz')) {
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
            background: #0b1020;
            color: #e5e7eb;
            font-family: ui-sans-serif, -apple-system, Segoe UI, sans-serif;
        }
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 14px;
            border-bottom: 1px solid #1f2937;
            background: #927e61;
        }
        .toolbar a {
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
        }
        .toolbar a:hover { text-decoration: underline; }
        replay-web-page {
            display: block;
            width: 100%;
            height: calc(100% - 48px);
        }
    </style>
    <script src="__UI_ASSET_URL__" type="module"></script>
</head>
<body>
    <div class="toolbar">
        <strong>Respaldo __SOURCE_ID__</strong>
        <a href="__DOWNLOAD_URL__">Descargar .wacz</a>
    </div>
    <replay-web-page source="__DOWNLOAD_URL__" url="__ORIGINAL_URL__"></replay-web-page>
</body>
</html>
HTML;

                        $viewerHtml = str_replace(
                            ['__SOURCE_ID__', '__DOWNLOAD_URL__', '__ORIGINAL_URL__', '__UI_ASSET_URL__'],
                                [
                                        (string) $sourceId,
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

        if (in_array($normalizedAsset, ['sw.js', 'sw.min.js'], true)) {
            $localSwPath = public_path('js/sw.min.js');
            abort_unless(File::exists($localSwPath), 404);

            return response(File::get($localSwPath), 200, [
                'Content-Type' => 'application/javascript; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ]);
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

        $absolutePath = $this->pathResolver->resolveBackupAbsolutePath((string) $source->ruta_archivo);
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

        $thumbnailAbsolutePath = $this->pathResolver->resolveGeneratedThumbnailPath($sourceId);

        abort_unless(is_string($thumbnailAbsolutePath) && File::exists($thumbnailAbsolutePath), 404);

        return response()->file($thumbnailAbsolutePath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'isRequestLetter' => ['nullable', 'boolean'],
            'oficioNumber' => ['nullable', 'string', 'max:255', 'required_if:isRequestLetter,true'],
        ]);

        $sourceId = $this->createSource($validated, (int) $request->user()->id);

        Log::info('Fuente registrada. Iniciando proceso de captura de respaldo.', [
            'source_id' => $sourceId,
            'url' => $validated['url'],
            'has_description' => filled($validated['description'] ?? null),
            'tags_count' => count($validated['tags'] ?? []),
            'is_request_letter' => !empty($validated['isRequestLetter']),
        ]);

        $captureSucceeded = false;

        try {
            $storedBackupPath = $this->captureService->capture($sourceId, $validated['url']);

            DB::table('fuentes')
                ->where('id', $sourceId)
                ->update([
                    'ruta_archivo' => $storedBackupPath,
                    'estado_captura' => 'capturada',
                    'capturado_en' => now(),
                    'updated_at' => now(),
                ]);

            $captureSucceeded = true;
        } catch (\Throwable $exception) {
            $this->markCaptureAsFailed($sourceId);

            Log::error('Fallo la captura de respaldo.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('hemeroteca')
            ->with('status', $captureSucceeded ? 'success' : 'error')
            ->with(
                'message',
                $captureSucceeded
                    ? 'Respaldo capturado y guardado correctamente.'
                    : 'La fuente se guardo, pero fallo la captura del respaldo.',
            );
    }

    private function createSource(array $validated, int $userId): int
    {
        return DB::transaction(function () use ($validated, $userId): int {
            $createdSourceId = DB::table('fuentes')->insertGetId([
                'url' => $validated['url'],
                'titulo' => $validated['name'],
                'descripcion' => $validated['description'] ?? null,
                'estado_captura' => 'pendiente',
                'capturado_en' => null,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $tags = collect($validated['tags'] ?? [])
                ->map(fn (string $tag): string => trim($tag))
                ->filter()
                ->unique()
                ->values();

            foreach ($tags as $tag) {
                $tagId = DB::table('etiquetas')->where('nombre', $tag)->value('id');

                if (!$tagId) {
                    $tagId = DB::table('etiquetas')->insertGetId([
                        'nombre' => $tag,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('etiqueta_fuente')->insertOrIgnore([
                    'fuente_id' => $createdSourceId,
                    'etiqueta_id' => $tagId,
                ]);
            }

            if (!empty($validated['isRequestLetter'])) {
                $oficioId = DB::table('libro_oficios')->insertGetId([
                    'oficio_peticion' => trim((string) ($validated['oficioNumber'] ?? '1')),
                    'fecha_oficio' => now()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('fuente_oficio')->insertOrIgnore([
                    'fuente_id' => $createdSourceId,
                    'oficio_id' => $oficioId,
                ]);
            }

            return $createdSourceId;
        });
    }

    private function markCaptureAsFailed(int $sourceId): void
    {
        DB::table('fuentes')
            ->where('id', $sourceId)
            ->update([
                'ruta_archivo' => null,
                'estado_captura' => 'error',
                'capturado_en' => null,
                'updated_at' => now(),
            ]);
    }

    private function formatSource(object $source, string $tagSeparator): array
    {
        $tagList = $source->tags ? explode($tagSeparator, (string) $source->tags) : [];
        $capturedAt = $source->capturado_en ? Carbon::parse($source->capturado_en) : null;
        $capturedAtLabel = $capturedAt
            ? $capturedAt->locale('es')->translatedFormat('j M Y')
            : 'Sin captura';

        return [
            'id' => (int) $source->id,
            'name' => $source->titulo ?: parse_url((string) $source->url, PHP_URL_HOST) ?: 'Sin titulo',
            'description' => $source->descripcion ?: 'Sin descripcion.',
            'url' => (string) $source->url,
            'backupPath' => $source->ruta_archivo ?: null,
            'tags' => array_values(array_filter($tagList)),
            'date' => $capturedAt ? $capturedAt->locale('es')->translatedFormat('d/m/Y H:i') : $capturedAtLabel,
            'capturedAt' => $capturedAt?->format('Y-m-d'),
            'capturedBy' => $source->captured_by ?: 'Sin usuario',
            'oficioNumber' => $source->oficio_number ? (string) $source->oficio_number : null,
        ];
    }

    public function __invoke(): Response
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $tagSeparator = $isSqlite ? ',' : '||';
        $tagAggregateSql = $isSqlite
            ? 'GROUP_CONCAT(DISTINCT etiquetas.nombre) AS tags'
            : "GROUP_CONCAT(DISTINCT etiquetas.nombre ORDER BY etiquetas.nombre SEPARATOR '{$tagSeparator}') AS tags";

        $sources = DB::table('fuentes')
            ->leftJoin('users', 'fuentes.user_id', '=', 'users.id')
            ->leftJoin('etiqueta_fuente', 'fuentes.id', '=', 'etiqueta_fuente.fuente_id')
            ->leftJoin('etiquetas', 'etiqueta_fuente.etiqueta_id', '=', 'etiquetas.id')
            ->leftJoin('fuente_oficio', 'fuentes.id', '=', 'fuente_oficio.fuente_id')
            ->leftJoin('libro_oficios', 'fuente_oficio.oficio_id', '=', 'libro_oficios.id')
            ->select(
                'fuentes.id',
                'fuentes.url',
                'fuentes.titulo',
                'fuentes.descripcion',
                'fuentes.ruta_archivo',
                'fuentes.capturado_en',
                'users.name as captured_by',
                DB::raw($tagAggregateSql),
                DB::raw('MAX(libro_oficios.oficio_peticion) AS oficio_number'),
            )
            ->groupBy(
                'fuentes.id',
                'fuentes.url',
                'fuentes.titulo',
                'fuentes.descripcion',
                'fuentes.ruta_archivo',
                'fuentes.capturado_en',
                'users.name',
            )
            ->orderByDesc('fuentes.capturado_en')
            ->orderByDesc('fuentes.id')
            ->get()
            ->map(fn (object $source): array => $this->formatSource($source, $tagSeparator))
            ->values();

        $suggestedTags = DB::table('etiquetas')
            ->orderBy('nombre')
            ->pluck('nombre')
            ->map(fn (string $tag): string => mb_convert_case($tag, MB_CASE_TITLE, 'UTF-8'))
            ->values();

        return Inertia::render('hemeroteca', [
            'sources' => $sources,
            'suggestedTags' => $suggestedTags,
        ]);
    }
}
