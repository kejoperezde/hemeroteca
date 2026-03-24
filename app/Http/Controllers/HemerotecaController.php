<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Inertia\Inertia;
use Inertia\Response;

class HemerotecaController extends Controller
{
    private function truncateLogValue(string $value, int $limit = 3000): string
    {
        return mb_strlen($value) > $limit
            ? mb_substr($value, 0, $limit).'... [truncated]'
            : $value;
    }

    public function openBackup(int $sourceId): HttpResponse|BinaryFileResponse
    {
        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $absolutePath = $this->resolveStoredCaptureAbsolutePath((string) $source->ruta_archivo);
        abort_unless(File::exists($absolutePath), 404);

        if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'html') {
            return response(File::get($absolutePath), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return response()->file($absolutePath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function downloadBackup(int $sourceId): BinaryFileResponse
    {
        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $absolutePath = $this->resolveStoredCaptureAbsolutePath((string) $source->ruta_archivo);
        abort_unless(File::exists($absolutePath), 404);

        if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'html') {
            return response()->download($absolutePath, "respaldo_fuente_{$sourceId}.html");
        }

        return response()->download($absolutePath, "captura_fuente_{$sourceId}.png");
    }

    public function thumbnailBackup(int $sourceId): BinaryFileResponse
    {
        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $thumbnailAbsolutePath = $this->resolveScreenshotAbsolutePath((string) $source->ruta_archivo);
        abort_unless(File::exists($thumbnailAbsolutePath), 404);

        return response()->file($thumbnailAbsolutePath, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function backupOcr(int $sourceId): JsonResponse
    {
        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $ocrAbsolutePath = $this->resolveOcrAbsolutePath((string) $source->ruta_archivo);
        abort_unless(File::exists($ocrAbsolutePath), 404);

        return response()->json([
            'text' => File::get($ocrAbsolutePath),
        ]);
    }

    private function resolveStoredCaptureAbsolutePath(string $storedPath): string
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

    private function resolveScreenshotAbsolutePath(string $storedPath): string
    {
        $storedAbsolutePath = $this->resolveStoredCaptureAbsolutePath($storedPath);

        if (strtolower(pathinfo($storedAbsolutePath, PATHINFO_EXTENSION)) === 'html') {
            return dirname($storedAbsolutePath).DIRECTORY_SEPARATOR.'page.png';
        }

        return $storedAbsolutePath;
    }

    private function resolveOcrAbsolutePath(string $storedPath): string
    {
        $screenshotAbsolutePath = $this->resolveScreenshotAbsolutePath($storedPath);

        return dirname($screenshotAbsolutePath).DIRECTORY_SEPARATOR.'ocr.txt';
    }

    private function readOcrTextFromStoredPath(?string $storedPath): ?string
    {
        if (!$storedPath) {
            return null;
        }

        $ocrAbsolutePath = $this->resolveOcrAbsolutePath($storedPath);

        if (!File::exists($ocrAbsolutePath)) {
            return null;
        }

        return trim(File::get($ocrAbsolutePath));
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

        $relativeCaptureDir = "capturas/fuente_{$sourceId}";
        $absoluteCaptureDir = storage_path("app/private/{$relativeCaptureDir}");
        $tmpDir = storage_path('app/tmp');

        File::ensureDirectoryExists($absoluteCaptureDir);
        File::ensureDirectoryExists($tmpDir);
        $captureSucceeded = false;

        try {
            $nodeBinary = env('NODE_BINARY', 'node');
            $process = new Process([
                $nodeBinary,
                base_path('scripts/capture-page.mjs'),
                $validated['url'],
                $absoluteCaptureDir,
            ], base_path(), [
                'SYSTEMROOT' => env('SYSTEMROOT', 'C:\\Windows'),
                'WINDIR' => env('WINDIR', 'C:\\Windows'),
                'TEMP' => env('TEMP', $tmpDir),
                'TMP' => env('TMP', $tmpDir),
                'PATH' => env('PATH', getenv('PATH') ?: ''),
            ]);
            $process->setTimeout(180);

            Log::info('Iniciando captura con OCR usando Puppeteer.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'node_binary' => $nodeBinary,
                'capture_dir' => $absoluteCaptureDir,
                'tmp_dir' => $tmpDir,
                'command' => $process->getCommandLine(),
            ]);

            $process->mustRun();

            $captureOutput = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            DB::table('fuentes')
                ->where('id', $sourceId)
                ->update([
                    'ruta_archivo' => "{$relativeCaptureDir}/page.png",
                    'estado_captura' => 'capturada',
                    'capturado_en' => now(),
                    'updated_at' => now(),
                ]);

            Log::info('Captura y OCR finalizados correctamente.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'ocr_exists' => File::exists("{$absoluteCaptureDir}/ocr.txt"),
                'png_exists' => File::exists("{$absoluteCaptureDir}/page.png"),
                'metadata_exists' => File::exists("{$absoluteCaptureDir}/metadata.json"),
                'capture_output' => $captureOutput,
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

            Log::error('No se pudo capturar la fuente con Puppeteer.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'message' => $exception->getMessage(),
                'command' => $failedProcess->getCommandLine(),
                'exit_code' => $failedProcess->getExitCode(),
                'exit_text' => $failedProcess->getExitCodeText(),
                'stdout' => $this->truncateLogValue($failedProcess->getOutput()),
                'stderr' => $this->truncateLogValue($failedProcess->getErrorOutput()),
                'ocr_exists' => File::exists("{$absoluteCaptureDir}/ocr.txt"),
                'png_exists' => File::exists("{$absoluteCaptureDir}/page.png"),
                'metadata_exists' => File::exists("{$absoluteCaptureDir}/metadata.json"),
            ]);
        } catch (JsonException $exception) {
            DB::table('fuentes')
                ->where('id', $sourceId)
                ->update([
                    'ruta_archivo' => null,
                    'estado_captura' => 'error',
                    'capturado_en' => null,
                    'updated_at' => now(),
                ]);

            Log::error('La salida de Puppeteer no se pudo parsear como JSON.', [
                'source_id' => $sourceId,
                'url' => $validated['url'],
                'message' => $exception->getMessage(),
                'ocr_exists' => File::exists("{$absoluteCaptureDir}/ocr.txt"),
                'png_exists' => File::exists("{$absoluteCaptureDir}/page.png"),
                'metadata_exists' => File::exists("{$absoluteCaptureDir}/metadata.json"),
            ]);
        }

        return redirect()
            ->route('hemeroteca')
            ->with('status', $captureSucceeded ? 'success' : 'error')
            ->with(
                'message',
                $captureSucceeded
                    ? 'Captura y OCR guardados correctamente.'
                    : 'La fuente se guardo, pero fallo la captura con OCR.',
            );
    }

    public function __invoke(): Response
    {
        $driver = DB::connection()->getDriverName();
        $tagSeparator = $driver === 'sqlite' ? ',' : '||';
        $tagsAggregate = $driver === 'sqlite'
            ? 'GROUP_CONCAT(DISTINCT etiquetas.nombre) AS tags'
            : "GROUP_CONCAT(DISTINCT etiquetas.nombre ORDER BY etiquetas.nombre SEPARATOR '||') AS tags";

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
                DB::raw($tagsAggregate),
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
                    'ocrText' => $this->readOcrTextFromStoredPath($source->ruta_archivo),
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
