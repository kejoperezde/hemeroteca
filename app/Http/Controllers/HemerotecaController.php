<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

class HemerotecaController extends Controller
{
    private function truncateLogValue(string $value, int $limit = 3000): string
    {
        return mb_strlen($value) > $limit
            ? mb_substr($value, 0, $limit).'... [truncated]'
            : $value;
    }

    private function captureDirectorySnapshot(string $absoluteCaptureDir): array
    {
        if (!File::isDirectory($absoluteCaptureDir)) {
            return [
                'capture_dir_exists' => false,
                'capture_files' => [],
            ];
        }

        $normalizedRoot = str_replace('\\', '/', $absoluteCaptureDir);
        $files = collect(File::allFiles($absoluteCaptureDir))
            ->map(function (\SplFileInfo $file) use ($normalizedRoot): array {
                $filePath = str_replace('\\', '/', $file->getPathname());
                $relativePath = ltrim((string) Str::after($filePath, $normalizedRoot), '/');

                return [
                    'path' => $relativePath !== '' ? $relativePath : basename($filePath),
                    'size' => $file->getSize(),
                ];
            })
            ->values()
            ->all();

        return [
            'capture_dir_exists' => true,
            'capture_files' => $files,
        ];
    }

    private function resolveGeneratedWaczPath(string $captureRoot): ?string
    {
        if (!File::isDirectory($captureRoot)) {
            return null;
        }

        return collect(File::allFiles($captureRoot))
            ->filter(function (\SplFileInfo $file): bool {
                $path = str_replace('\\', '/', strtolower($file->getPathname()));

                return Str::endsWith($path, '.wacz')
                    && !str_contains($path, '/profile/');
            })
            ->sortByDesc(fn (\SplFileInfo $file): int => $file->getMTime())
            ->map(fn (\SplFileInfo $file): string => $file->getPathname())
            ->first();
    }

    private function resolveGeneratedThumbnailPath(int $sourceId): ?string
    {
        $captureRoot = storage_path("app/private/capturas/fuente_{$sourceId}");
        $preferredCandidates = [
            $captureRoot.DIRECTORY_SEPARATOR.'page.png',
            $captureRoot.DIRECTORY_SEPARATOR.'collections'.DIRECTORY_SEPARATOR."fuente_{$sourceId}".DIRECTORY_SEPARATOR.'page.png',
        ];

        foreach ($preferredCandidates as $candidatePath) {
            if (File::exists($candidatePath)) {
                return $candidatePath;
            }
        }

        if (!File::isDirectory($captureRoot)) {
            return null;
        }

        return collect(File::allFiles($captureRoot))
            ->filter(function (\SplFileInfo $file): bool {
                $path = str_replace('\\', '/', strtolower($file->getPathname()));

                if (!preg_match('/\.(png|jpe?g|webp)$/i', $path)) {
                    return false;
                }

                if (str_contains($path, '/profile/')) {
                    return false;
                }

                return str_contains($path, '/pages/') || str_contains($path, '/screenshots/');
            })
            ->sortByDesc(fn (\SplFileInfo $file): int => $file->getMTime())
            ->map(fn (\SplFileInfo $file): string => $file->getPathname())
            ->first();
    }

    public function openBackup(int $sourceId): BaseResponse
    {
        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo', 'url')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $absolutePath = $this->resolveBackupAbsolutePath((string) $source->ruta_archivo);
        abort_unless(File::exists($absolutePath), 404);

        if (Str::endsWith(strtolower($absolutePath), '.html')) {
            return response(File::get($absolutePath), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

                if (Str::endsWith(strtolower($absolutePath), '.wacz')) {
                        $downloadUrl = route('hemeroteca.sources.backup.download', ['sourceId' => $sourceId]);
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
            background: #111827;
        }
        .toolbar a {
            color: #93c5fd;
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
    <script src="https://cdn.jsdelivr.net/npm/replaywebpage/ui.js" type="module"></script>
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
                                ['__SOURCE_ID__', '__DOWNLOAD_URL__', '__ORIGINAL_URL__'],
                                [
                                        (string) $sourceId,
                                        e($downloadUrl),
                                        e((string) $source->url),
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

        $absolutePath = $this->resolveBackupAbsolutePath((string) $source->ruta_archivo);
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

        $thumbnailAbsolutePath = $this->resolveGeneratedThumbnailPath($sourceId);

        abort_unless(is_string($thumbnailAbsolutePath) && File::exists($thumbnailAbsolutePath), 404);

        return response()->file($thumbnailAbsolutePath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function resolveBackupAbsolutePath(string $storedPath): string
    {
        $normalizedPath = str_replace('\\', '/', trim($storedPath));
        $privateRoot = str_replace('\\', '/', storage_path('app/private'));

        if (str_starts_with($normalizedPath, $privateRoot.'/')) {
            return str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        }

        if (preg_match('/^[A-Za-z]:\//', $normalizedPath) === 1) {
            return str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        }

        $relative = ltrim($normalizedPath, '/');
        return storage_path('app/private/'.$relative);
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

        $sourceId = DB::transaction(function () use ($request, $validated): int {
            $createdSourceId = DB::table('fuentes')->insertGetId([
                'url' => $validated['url'],
                'titulo' => $validated['name'],
                'descripcion' => $validated['description'] ?? null,
                'estado_captura' => 'pendiente',
                'capturado_en' => null,
                'user_id' => $request->user()->id,
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

        Log::info('Fuente registrada. Iniciando proceso de captura de respaldo.', [
            'source_id' => $sourceId,
            'url' => $validated['url'],
            'has_description' => filled($validated['description'] ?? null),
            'tags_count' => count($validated['tags'] ?? []),
            'is_request_letter' => !empty($validated['isRequestLetter']),
        ]);

        $relativeCaptureDir = "capturas/fuente_{$sourceId}";
        $absoluteCaptureDir = storage_path("app/private/{$relativeCaptureDir}");
        $tmpDir = storage_path('app/tmp');

        File::ensureDirectoryExists($absoluteCaptureDir);
        File::ensureDirectoryExists($tmpDir);
        $captureSucceeded = false;

        try {
            $dockerBinary = env('DOCKER_BINARY', 'docker');
            $browsertrixImage = env('BROWSERTRIX_IMAGE', 'webrecorder/browsertrix-crawler');
            $captureTimeout = (int) env('CAPTURE_PROCESS_TIMEOUT', 420);
            $collectionName = "fuente_{$sourceId}";
            $process = new Process([
                $dockerBinary,
                'run',
                '--rm',
                '-v',
                "{$absoluteCaptureDir}:/crawls",
                $browsertrixImage,
                'crawl',
                '--url',
                $validated['url'],
                '--generateWACZ',
                '--screenshot',
                '--headless',
                '--collection',
                $collectionName,
                '--timeout',
                '30',
                '--limit',
                '1',
                '--sizeLimit',
                '104857600',
                '--behaviorTimeout',
                '10',
            ], base_path(), [
                'SYSTEMROOT' => env('SYSTEMROOT', 'C:\\Windows'),
                'WINDIR' => env('WINDIR', 'C:\\Windows'),
                'TEMP' => env('TEMP', $tmpDir),
                'TMP' => env('TMP', $tmpDir),
                'PATH' => env('PATH', getenv('PATH') ?: ''),
            ]);
            $process->setTimeout($captureTimeout);

            Log::info('Iniciando captura de respaldo con Browsertrix.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'docker_binary' => $dockerBinary,
                'browsertrix_image' => $browsertrixImage,
                'collection' => $collectionName,
                'timeout_seconds' => $captureTimeout,
                'capture_dir' => $absoluteCaptureDir,
                'tmp_dir' => $tmpDir,
                'command' => $process->getCommandLine(),
            ]);

            $process->mustRun();

            $waczPath = $this->resolveGeneratedWaczPath($absoluteCaptureDir);

            if (!is_string($waczPath)) {
                throw new \RuntimeException('Browsertrix finalizo, pero no se encontro ningun archivo .wacz.');
            }

            $privateRoot = str_replace('\\', '/', storage_path('app/private'));
            $normalizedWaczPath = str_replace('\\', '/', $waczPath);
            $storedBackupPath = ltrim((string) Str::after($normalizedWaczPath, $privateRoot), '/');

            if ($storedBackupPath === '') {
                throw new \RuntimeException('No se pudo resolver la ruta relativa del archivo .wacz generado.');
            }

            DB::table('fuentes')
                ->where('id', $sourceId)
                ->update([
                    'ruta_archivo' => $storedBackupPath,
                    'estado_captura' => 'capturada',
                    'capturado_en' => now(),
                    'updated_at' => now(),
                ]);

            Log::info('Captura de respaldo finalizada correctamente.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'stored_backup_path' => $storedBackupPath,
                'wacz_exists' => File::exists($waczPath),
                'png_exists' => File::exists("{$absoluteCaptureDir}/page.png"),
                'metadata_exists' => File::exists("{$absoluteCaptureDir}/metadata.json"),
                ...$this->captureDirectorySnapshot($absoluteCaptureDir),
                'stdout' => $this->truncateLogValue($process->getOutput()),
                'stderr' => $this->truncateLogValue($process->getErrorOutput()),
            ]);

            $captureSucceeded = true;
        } catch (ProcessFailedException $exception) {
            DB::table('fuentes')
                ->where('id', $sourceId)
                ->update([
                    'ruta_archivo' => null,
                    'estado_captura' => 'error',
                    'capturado_en' => null,
                    'updated_at' => now(),
                ]);

            $failedProcess = $exception->getProcess();

            Log::error('No se pudo capturar la fuente con Browsertrix.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'message' => $exception->getMessage(),
                'command' => $failedProcess->getCommandLine(),
                'timeout_seconds' => $captureTimeout,
                'exit_code' => $failedProcess->getExitCode(),
                'exit_text' => $failedProcess->getExitCodeText(),
                'stdout' => $this->truncateLogValue($failedProcess->getOutput()),
                'stderr' => $this->truncateLogValue($failedProcess->getErrorOutput()),
                'wacz_exists' => collect(File::allFiles($absoluteCaptureDir))
                    ->contains(fn (\SplFileInfo $file): bool => Str::endsWith(strtolower($file->getPathname()), '.wacz')),
                'png_exists' => File::exists("{$absoluteCaptureDir}/page.png"),
                'metadata_exists' => File::exists("{$absoluteCaptureDir}/metadata.json"),
                ...$this->captureDirectorySnapshot($absoluteCaptureDir),
            ]);
        } catch (\RuntimeException $exception) {
            DB::table('fuentes')
                ->where('id', $sourceId)
                ->update([
                    'ruta_archivo' => null,
                    'estado_captura' => 'error',
                    'capturado_en' => null,
                    'updated_at' => now(),
                ]);

            Log::error('La captura de Browsertrix finalizo con salida invalida.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'message' => $exception->getMessage(),
                'wacz_exists' => collect(File::allFiles($absoluteCaptureDir))
                    ->contains(fn (\SplFileInfo $file): bool => Str::endsWith(strtolower($file->getPathname()), '.wacz')),
                'png_exists' => File::exists("{$absoluteCaptureDir}/page.png"),
                'metadata_exists' => File::exists("{$absoluteCaptureDir}/metadata.json"),
                ...$this->captureDirectorySnapshot($absoluteCaptureDir),
            ]);
        } catch (\Throwable $exception) {
            DB::table('fuentes')
                ->where('id', $sourceId)
                ->update([
                    'ruta_archivo' => null,
                    'estado_captura' => 'error',
                    'capturado_en' => null,
                    'updated_at' => now(),
                ]);

            Log::error('Error inesperado durante la captura de respaldo.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
                ...$this->captureDirectorySnapshot($absoluteCaptureDir),
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
            ->map(function (object $source) use ($tagSeparator): array {
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
            })
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
