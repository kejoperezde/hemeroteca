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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\Process\Process;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Str;

class HemerotecaController extends Controller
{
    public function openReplay(int $sourceId): BaseResponse
    {
        return $this->replayAsset($sourceId, 'replay');
    }

    public function openBackup(int $sourceId): BaseResponse
    {
        abort_unless(auth()->user()?->can('abs_hemeroteca'), 403);

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
            $integrityUrl = route('hemeroteca.sources.backup.integrity', ['sourceId' => $sourceId]);
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
        .toolbar-actions {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .toolbar-status {
            min-height: 18px;
            font-size: 12px;
            color: #f8f6f1;
            opacity: 0.95;
        }
        .toolbar-status.is-success {
            color: #ecfccb;
        }
        .toolbar-status.is-error {
            color: #fee2e2;
        }
        button.toolbar-action {
            cursor: pointer;
            font: inherit;
        }
        button.toolbar-action[disabled] {
            opacity: 0.7;
            cursor: wait;
        }
        .integrity-modal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            background: rgba(16, 12, 8, 0.6);
        }
        .integrity-modal[hidden] {
            display: none;
        }
        .integrity-dialog {
            width: min(680px, 96vw);
            max-height: calc(100vh - 40px);
            overflow: auto;
            background: #f6f2ea;
            color: #2d2218;
            border: 1px solid #b49b79;
            border-radius: 12px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
        }
        .integrity-header {
            padding: 14px 16px;
            border-bottom: 1px solid #d7c8b3;
            background: linear-gradient(90deg, #ede2d2 0%, #f6f2ea 100%);
            font-weight: 700;
            font-size: 16px;
        }
        .integrity-body {
            padding: 14px 16px;
            display: grid;
            gap: 10px;
        }
        .integrity-message {
            margin: 0;
            color: #493728;
            line-height: 1.45;
        }
        .integrity-message.is-success {
            color: #3f6212;
        }
        .integrity-message.is-error {
            color: #7f1d1d;
        }
        .integrity-details {
            margin: 0;
            display: grid;
            gap: 8px;
        }
        .integrity-row {
            display: grid;
            grid-template-columns: 140px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
        }
        .integrity-row dt {
            font-weight: 600;
            color: #5a4633;
        }
        .integrity-row dd {
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            word-break: break-all;
            color: #2d2218;
        }
        .integrity-footer {
            padding: 12px 16px 16px;
            display: flex;
            justify-content: flex-end;
        }
        .integrity-close {
            border: 1px solid #b49b79;
            border-radius: 8px;
            background: #fff;
            color: #3c2f24;
            padding: 8px 12px;
            font: inherit;
            cursor: pointer;
        }
        .integrity-close:hover {
            background: #f2ece1;
        }
        replay-web-page {
            display: block;
            width: 100%;
            height: calc(100% - 52px);
        }
    </style>
    <script>
        if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
            window.location.replace('https://' + window.location.host + window.location.pathname + window.location.search + window.location.hash);
        }
    </script>
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
        <div class="toolbar-actions">
            <span class="toolbar-status" id="integrity-status" aria-live="polite"></span>
            <button class="toolbar-action" id="verify-integrity-button" type="button">Verificar integridad</button>
            <a class="toolbar-action" href="__DOWNLOAD_URL__">Descargar Respaldo</a>
        </div>
    </div>
    <replay-web-page source="__DOWNLOAD_URL__" url="__ORIGINAL_URL__" coll="fuente-__SOURCE_ID__"></replay-web-page>
    <div class="integrity-modal" id="integrity-modal" hidden>
        <div class="integrity-dialog" role="dialog" aria-modal="true" aria-labelledby="integrity-modal-title">
            <div class="integrity-header" id="integrity-modal-title">Resultado de integridad</div>
            <div class="integrity-body">
                <p class="integrity-message" id="integrity-modal-message"></p>
                <dl class="integrity-details" id="integrity-modal-details" hidden>
                    <div class="integrity-row">
                        <dt>Algoritmo</dt>
                        <dd id="integrity-algorithm">-</dd>
                    </div>
                    <div class="integrity-row">
                        <dt>Hash en DB</dt>
                        <dd id="integrity-stored-hash">-</dd>
                    </div>
                    <div class="integrity-row">
                        <dt>Hash actual</dt>
                        <dd id="integrity-current-hash">-</dd>
                    </div>
                    <div class="integrity-row">
                        <dt>Fecha verificacion</dt>
                        <dd id="integrity-checked-at">-</dd>
                    </div>
                </dl>
            </div>
            <div class="integrity-footer">
                <button class="integrity-close" id="integrity-close-button" type="button">Cerrar</button>
            </div>
        </div>
    </div>
    <script>
        const verifyButton = document.getElementById('verify-integrity-button');
        const integrityStatus = document.getElementById('integrity-status');
        const integrityUrl = '__INTEGRITY_URL__';
        const integrityModal = document.getElementById('integrity-modal');
        const integrityModalTitle = document.getElementById('integrity-modal-title');
        const integrityModalMessage = document.getElementById('integrity-modal-message');
        const integrityModalDetails = document.getElementById('integrity-modal-details');
        const integrityAlgorithm = document.getElementById('integrity-algorithm');
        const integrityStoredHash = document.getElementById('integrity-stored-hash');
        const integrityCurrentHash = document.getElementById('integrity-current-hash');
        const integrityCheckedAt = document.getElementById('integrity-checked-at');
        const integrityCloseButton = document.getElementById('integrity-close-button');

        const setIntegrityStatus = (message, tone) => {
            if (!integrityStatus) {
                return;
            }

            integrityStatus.textContent = message;
            integrityStatus.classList.remove('is-success', 'is-error');

            if (tone === 'success') {
                integrityStatus.classList.add('is-success');
            }

            if (tone === 'error') {
                integrityStatus.classList.add('is-error');
            }
        };

        const openIntegrityModal = ({ title, message, tone, details }) => {
            if (!integrityModal || !integrityModalTitle || !integrityModalMessage || !integrityModalDetails) {
                return;
            }

            integrityModalTitle.textContent = title;
            integrityModalMessage.textContent = message;
            integrityModalMessage.classList.remove('is-success', 'is-error');

            if (tone === 'success') {
                integrityModalMessage.classList.add('is-success');
            }

            if (tone === 'error') {
                integrityModalMessage.classList.add('is-error');
            }

            const hasDetails = details
                && (details.storedHash || details.currentHash || details.algorithm || details.checkedAt);

            integrityModalDetails.hidden = !hasDetails;

            if (hasDetails) {
                if (integrityAlgorithm) {
                    integrityAlgorithm.textContent = details.algorithm || '-';
                }

                if (integrityStoredHash) {
                    integrityStoredHash.textContent = details.storedHash || '-';
                }

                if (integrityCurrentHash) {
                    integrityCurrentHash.textContent = details.currentHash || '-';
                }

                if (integrityCheckedAt) {
                    integrityCheckedAt.textContent = details.checkedAt || '-';
                }
            }

            integrityModal.hidden = false;
        };

        const closeIntegrityModal = () => {
            if (integrityModal) {
                integrityModal.hidden = true;
            }
        };

        if (integrityCloseButton) {
            integrityCloseButton.addEventListener('click', closeIntegrityModal);
        }

        if (integrityModal) {
            integrityModal.addEventListener('click', (event) => {
                if (event.target === integrityModal) {
                    closeIntegrityModal();
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && integrityModal && !integrityModal.hidden) {
                closeIntegrityModal();
            }
        });

        if (verifyButton) {
            verifyButton.addEventListener('click', async () => {
                verifyButton.disabled = true;
                setIntegrityStatus('Verificando hash...', null);

                try {
                    const response = await fetch(integrityUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });

                    const payload = await response.json();

                    if (!response.ok) {
                        throw new Error(payload.message || 'No se pudo verificar la integridad.');
                    }

                    if (payload.matches) {
                        setIntegrityStatus('Integridad verificada.', 'success');
                        openIntegrityModal({
                            title: 'Integridad verificada',
                            message: 'El hash actual coincide con el hash almacenado en la base de datos.',
                            tone: 'success',
                            details: payload,
                        });
                    } else {
                        setIntegrityStatus('Integridad no coincide.', 'error');
                        openIntegrityModal({
                            title: 'Integridad no coincide',
                            message: 'El hash actual NO coincide con el hash almacenado en la base de datos.',
                            tone: 'error',
                            details: payload,
                        });
                    }
                } catch (error) {
                    const message = error.message || 'No se pudo verificar la integridad.';
                    setIntegrityStatus(message, 'error');
                    openIntegrityModal({
                        title: 'No se pudo verificar',
                        message,
                        tone: 'error',
                        details: null,
                    });
                } finally {
                    verifyButton.disabled = false;
                }
            });
        }
    </script>
</body>
</html>
HTML;

            $viewerHtml = str_replace(
                ['__SOURCE_ID__', '__SOURCE_NAME__', '__DOWNLOAD_URL__', '__ORIGINAL_URL__', '__UI_ASSET_URL__', '__INTEGRITY_URL__'],
                [
                    (string) $sourceId,
                    e($sourceName),
                    e($downloadUrl),
                    e((string) $source->url),
                    e($uiAssetUrl),
                    e($integrityUrl),
                ],
                $viewerHtml,
            );

            return response($viewerHtml, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $mimeType = File::mimeType($absolutePath) ?: 'application/octet-stream';

        return $this->respondWithAuthorizedFile($absolutePath, [
            'Content-Type' => $mimeType,
        ]);
    }

    public function verifyBackupIntegrity(int $sourceId): JsonResponse
    {
        abort_unless(auth()->user()?->can('abs_hemeroteca'), 403);

        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo', 'hash_contenido')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $storedHash = trim((string) ($source->hash_contenido ?? ''));
        if ($storedHash === '') {
            return response()->json([
                'message' => 'La fuente no tiene un hash almacenado para verificar la integridad.',
            ], 409);
        }

        $absolutePath = $this->resolveLocalBackupAbsolutePath((string) $source->ruta_archivo);
        abort_unless(File::exists($absolutePath), 404);

        $currentHash = hash_file('sha256', $absolutePath);
        if (!is_string($currentHash) || $currentHash === '') {
            return response()->json([
                'message' => 'No se pudo calcular el hash del archivo respaldado.',
            ], 500);
        }

        return response()->json([
            'matches' => hash_equals(strtolower($storedHash), strtolower($currentHash)),
            'storedHash' => $storedHash,
            'currentHash' => $currentHash,
            'algorithm' => 'sha256',
            'checkedAt' => now()->toIso8601String(),
            'message' => 'Verificacion completada.',
        ]);
    }

    public function replayAsset(int $sourceId, string $asset): BaseResponse
    {
        abort_unless(auth()->user()?->can('abs_hemeroteca'), 403);
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

        if ($normalizedAsset === 'replay') {
            $bootstrapHtml = <<<'HTML'
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Replay</title>
    <script src="./ui.js" type="module"></script>
</head>
<body>
    <replay-app-main></replay-app-main>
</body>
</html>
HTML;

            return response($bootstrapHtml, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        $upstreamUrl = "https://cdn.jsdelivr.net/npm/replaywebpage/{$normalizedAsset}";
        $cacheKey = 'hemeroteca:replay-asset:'.md5($normalizedAsset);

        /** @var array{0: string, 1: string} $cached */
        $cached = Cache::remember($cacheKey, 3600, function () use ($upstreamUrl): array {
            $upstream = Http::timeout(30)->get($upstreamUrl);
            abort_unless($upstream->successful(), 404);

            return [$upstream->body(), $upstream->header('content-type') ?: 'application/octet-stream'];
        });

        return response($cached[0], 200, [
            'Content-Type' => $cached[1],
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function downloadBackup(int $sourceId): BaseResponse
    {
        abort_unless(auth()->user()?->can('abs_hemeroteca'), 403);

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

        return $this->respondWithAuthorizedFileDownload($absolutePath, $downloadName);
    }

    public function thumbnailBackup(int $sourceId): BinaryFileResponse
    {
        abort_unless(auth()->user()?->can('abs_hemeroteca'), 403);

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

    public function backupImages(int $sourceId): JsonResponse
    {
        abort_unless(auth()->user()?->can('abs_hemeroteca'), 403);

        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $imageAbsolutePaths = $this->resolveBackupImageAbsolutePaths((string) $source->ruta_archivo);

        $images = array_map(
            fn (string $absolutePath, int $index): array => [
                'index' => $index,
                'name' => basename($absolutePath),
                'url' => route('hemeroteca.sources.backup.image', ['sourceId' => $sourceId, 'imageIndex' => $index]),
            ],
            $imageAbsolutePaths,
            array_keys($imageAbsolutePaths),
        );

        return response()->json([
            'images' => array_values($images),
        ]);
    }

    public function backupImage(int $sourceId, int $imageIndex): BaseResponse
    {
        abort_unless(auth()->user()?->can('abs_hemeroteca'), 403);
        abort_unless($imageIndex >= 0, 404);

        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $imageAbsolutePaths = $this->resolveBackupImageAbsolutePaths((string) $source->ruta_archivo);
        abort_unless(isset($imageAbsolutePaths[$imageIndex]), 404);

        $targetPath = $imageAbsolutePaths[$imageIndex];
        $mimeType = File::mimeType($targetPath) ?: 'application/octet-stream';

        return response()->file($targetPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function backupAttachments(int $sourceId): JsonResponse
    {
        abort_unless(auth()->user()?->can('abs_hemeroteca'), 403);

        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $attachmentAbsolutePaths = $this->resolveBackupAttachmentAbsolutePaths((string) $source->ruta_archivo);

        $attachments = array_map(
            function (string $absolutePath, int $index, int $sourceId): array {
                $mimeType = File::mimeType($absolutePath) ?: 'application/octet-stream';
                $sizeBytes = File::size($absolutePath) ?: 0;
                $isVideo = str_starts_with(strtolower($mimeType), 'video/');

                return [
                    'index' => $index,
                    'name' => basename($absolutePath),
                    'url' => route('hemeroteca.sources.backup.attachment', [
                        'sourceId' => $sourceId,
                        'attachmentIndex' => $index,
                    ]),
                    'mimeType' => $mimeType,
                    'sizeBytes' => $sizeBytes,
                    'kind' => $isVideo ? 'video' : 'document',
                ];
            },
            $attachmentAbsolutePaths,
            array_keys($attachmentAbsolutePaths),
            array_fill(0, count($attachmentAbsolutePaths), $sourceId),
        );

        return response()->json([
            'attachments' => array_values($attachments),
        ]);
    }

    public function backupAttachment(int $sourceId, int $attachmentIndex): BaseResponse
    {
        abort_unless(auth()->user()?->can('abs_hemeroteca'), 403);
        abort_unless($attachmentIndex >= 0, 404);

        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source && $source->ruta_archivo, 404);

        $attachmentAbsolutePaths = $this->resolveBackupAttachmentAbsolutePaths((string) $source->ruta_archivo);
        abort_unless(isset($attachmentAbsolutePaths[$attachmentIndex]), 404);

        $targetPath = $attachmentAbsolutePaths[$attachmentIndex];
        $mimeType = File::mimeType($targetPath) ?: 'application/octet-stream';

        return response()->file($targetPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function storeManual(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('abs_hemeroteca_edit'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'text' => ['nullable', 'string', 'max:1000000'],
            'isRequestLetter' => ['nullable', 'boolean'],
            'oficioNumber' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['required', 'string', 'max:100'],
            'images' => ['required', 'array', 'min:1', 'max:20'],
            'images.*' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['required', 'file', 'max:51200', 'mimes:mp4,mov,avi,mkv,webm,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv'],
        ]);

        $validated['url'] = (string) $request->input('url', '');

        $tagNames = $this->normalizeTags($validated['tags'] ?? []);
        $sourceId = $this->createSource($validated, (int) $request->user()->id, $tagNames);

        $directory = "capturas/fuente_{$sourceId}";
        Storage::disk('local')->makeDirectory($directory);

        /** @var array<int, UploadedFile> $uploadedImages */
        $uploadedImages = $validated['images'];
        /** @var array<int, UploadedFile> $uploadedAttachments */
        $uploadedAttachments = $validated['attachments'] ?? [];

        $storedRelativePaths = [];
        foreach ($uploadedImages as $index => $uploadedImage) {
            $extension = strtolower($uploadedImage->getClientOriginalExtension() ?: 'png');
            $fileName = 'imagen_'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT).'.'.$extension;
            $storedPath = $uploadedImage->storeAs($directory, $fileName, ['disk' => 'local']);

            if (!is_string($storedPath) || $storedPath === '') {
                throw new \RuntimeException('No se pudo guardar una de las imagenes del respaldo manual.');
            }

            $storedRelativePaths[] = str_replace('\\', '/', $storedPath);
        }

        $storedAttachmentPaths = $this->storeSourceAttachments($directory, $uploadedAttachments);

        $absoluteImagePaths = array_map(
            fn (string $relativePath): string => Storage::disk('local')->path($relativePath),
            $storedRelativePaths,
        );

        $providedText = trim((string) ($validated['text'] ?? ''));
        $ocrText = $providedText !== '' ? $providedText : $this->extractOcrTextFromImages($absoluteImagePaths);
        $hashSourcePath = $absoluteImagePaths[0] ?? null;
        $contentHash = is_string($hashSourcePath) && File::exists($hashSourcePath)
            ? hash_file('sha256', $hashSourcePath)
            : null;

        DB::table('fuentes')
            ->where('id', $sourceId)
            ->update([
                'ruta_archivo' => $storedRelativePaths[0] ?? null,
                'estado_captura' => 'captura_manual',
                'capturado_en' => now(),
                'hash_contenido' => $contentHash,
                'texto' => $ocrText !== '' ? $ocrText : null,
                'updated_at' => now(),
            ]);

        $isRequestLetter = filter_var($validated['isRequestLetter'] ?? false, FILTER_VALIDATE_BOOL);
        $oficioNumber = trim((string) ($validated['oficioNumber'] ?? ''));

        if ($isRequestLetter && $oficioNumber !== '') {
            $oficioId = DB::table('libro_oficios')->insertGetId([
                'oficio_peticion' => $oficioNumber,
                'fecha_oficio' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('fuente_oficio')->insertOrIgnore([
                'fuente_id' => $sourceId,
                'oficio_id' => (int) $oficioId,
            ]);
        }

        return response()->json([
            'message' => 'Fuente manual registrada correctamente.',
            'sourceId' => $sourceId,
            'imagesCount' => count($storedRelativePaths),
            'attachmentsCount' => count($storedAttachmentPaths),
            'ocrTextLength' => mb_strlen($ocrText, 'UTF-8'),
        ], 201);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('abs_hemeroteca_edit'), 403);

        $result = $this->persistSourceWithWacz($request);

        return redirect()
            ->route('hemeroteca')
            ->with('status', $result['ok'] ? 'success' : 'error')
            ->with('message', $result['message']);
    }

    public function uploadDraftApi(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('abs_hemeroteca_edit'), 403);

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
            'text' => ['nullable', 'string', 'max:100000'],
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
            'thumbnailFile' => ['nullable', 'file', 'max:10240', 'mimes:png'],
        ]);

        /** @var UploadedFile $uploadedWacz */
        $uploadedWacz = $validated['waczFile'];
        /** @var UploadedFile|null $uploadedThumbnail */
        $uploadedThumbnail = $validated['thumbnailFile'] ?? null;

        $token = (string) Str::uuid();
        $waczFileName = Str::endsWith(strtolower($uploadedWacz->getClientOriginalName()), '.wacz.zip')
            ? "{$token}.wacz.zip"
            : "{$token}.wacz";

        $waczDraftPath = $uploadedWacz->storeAs('capturas/drafts', $waczFileName, ['disk' => 'local']);
        $thumbnailDraftPath = $uploadedThumbnail
            ? $uploadedThumbnail->storeAs('capturas/drafts', "{$token}_preview.png", ['disk' => 'local'])
            : null;

        if (!is_string($waczDraftPath) || $waczDraftPath === '') {
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
            'thumbnail_path' => $thumbnailDraftPath ? str_replace('\\', '/', $thumbnailDraftPath) : null,
        ], now()->addMinutes(60));

        return response()->json([
            'message' => 'Borrador recibido. Abre la URL para completar el formulario.',
            'draftToken' => $token,
            'openUrl' => route('hemeroteca.register', ['draftToken' => $token]),
        ], 201);
    }

    public function discardDraftApi(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('abs_hemeroteca_edit'), 403);

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
        abort_unless(request()->user()?->can('abs_hemeroteca_edit'), 403);

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
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['required', 'file', 'max:51200', 'mimes:mp4,mov,avi,mkv,webm,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv'],
            'draftToken' => ['required', 'string', 'max:120'],
        ]);

        $tagNames = $this->normalizeTags($validated['tags'] ?? []);

        $draftToken = trim((string) $validated['draftToken']);

        // Acquire an atomic lock to prevent a duplicate form submission with the
        // same draft token from creating two fuentes records simultaneously.
        // The lock must cover the full operation (read draft + create record + move files)
        // to prevent two concurrent submissions from creating duplicate fuentes rows.
        $lock = Cache::lock('hemeroteca:draft-redeem:'.$draftToken, 60);
        if (!$lock->get()) {
            return [
                'ok' => false,
                'sourceId' => null,
                'backupPath' => null,
                'message' => 'Este borrador ya está siendo procesado. Espera un momento.',
            ];
        }

        $uploadSucceeded = false;
        $storedBackupPath = null;
        $sourceId = null;

        try {
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

            try {
                $storedBackupPath = $this->storeWaczDraft($sourceId, $draftPayload);
                $this->storeThumbnailDraft($sourceId, $draftPayload);
                /** @var array<int, UploadedFile> $uploadedAttachments */
                $uploadedAttachments = $validated['attachments'] ?? [];
                $this->storeSourceAttachments("capturas/fuente_{$sourceId}", $uploadedAttachments);

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
        } finally {
            $lock->release();
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
        if ($thumbnailPath !== '' && !Storage::disk('local')->exists($thumbnailPath)) {
            // Thumbnail was recorded in the payload but the file is missing — treat as invalid.
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

    private function respondWithAuthorizedFile(string $absolutePath, array $headers = []): BaseResponse
    {
        if (!$this->shouldUseApacheXSendfile()) {
            return response()->file($absolutePath, $headers);
        }

        $sendfileHeader = (string) config('hemeroteca.x_sendfile_header', 'X-Sendfile');

        return response('', 200, array_merge($headers, [
            $sendfileHeader => $absolutePath,
        ]));
    }

    private function respondWithAuthorizedFileDownload(string $absolutePath, string $downloadName): BaseResponse
    {
        if (!$this->shouldUseApacheXSendfile()) {
            return response()->download($absolutePath, $downloadName);
        }

        $mimeType = File::mimeType($absolutePath) ?: 'application/octet-stream';
        $sendfileHeader = (string) config('hemeroteca.x_sendfile_header', 'X-Sendfile');

        return response('', 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $downloadName,
            ),
            $sendfileHeader => $absolutePath,
        ]);
    }

    private function shouldUseApacheXSendfile(): bool
    {
        return (bool) config('hemeroteca.use_x_sendfile', false);
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

    /**
     * @return array<int, string>
     */
    private function resolveBackupImageAbsolutePaths(string $backupPath): array
    {
        $backupAbsolutePath = $this->resolveLocalBackupAbsolutePath($backupPath);
        $backupDirectory = dirname($backupAbsolutePath);

        if (!File::isDirectory($backupDirectory)) {
            return [];
        }

        $imagePaths = [];
        foreach (['*.png', '*.jpg', '*.jpeg', '*.webp'] as $pattern) {
            foreach (File::glob($backupDirectory.DIRECTORY_SEPARATOR.$pattern) ?: [] as $absolutePath) {
                if (File::exists($absolutePath)) {
                    $imagePaths[] = $absolutePath;
                }
            }
        }

        usort($imagePaths, static fn (string $left, string $right): int => strnatcasecmp(basename($left), basename($right)));

        return array_values(array_unique($imagePaths));
    }

    /**
     * @return array<int, string>
     */
    private function resolveBackupAttachmentAbsolutePaths(string $backupPath): array
    {
        $backupAbsolutePath = $this->resolveLocalBackupAbsolutePath($backupPath);
        $backupDirectory = dirname($backupAbsolutePath);

        if (!File::isDirectory($backupDirectory)) {
            return [];
        }

        $attachmentPaths = [];
        foreach (['*.mp4', '*.mov', '*.avi', '*.mkv', '*.webm', '*.pdf', '*.doc', '*.docx', '*.xls', '*.xlsx', '*.ppt', '*.pptx', '*.txt', '*.csv'] as $pattern) {
            $matches = File::glob($backupDirectory.DIRECTORY_SEPARATOR.$pattern) ?: [];
            $attachmentPaths = [...$attachmentPaths, ...$matches];
        }

        usort($attachmentPaths, static fn (string $left, string $right): int => strnatcasecmp(basename($left), basename($right)));

        return array_values(array_unique($attachmentPaths));
    }

    /**
     * @param  array<int, UploadedFile>  $uploadedAttachments
     * @return array<int, string>
     */
    private function storeSourceAttachments(string $directory, array $uploadedAttachments, int $startIndex = 0): array
    {
        $storedAttachmentPaths = [];

        foreach ($uploadedAttachments as $index => $uploadedAttachment) {
            $extension = strtolower($uploadedAttachment->getClientOriginalExtension() ?: 'bin');
            $fileNumber = $startIndex + $index + 1;
            $fileName = 'adjunto_'.str_pad((string) $fileNumber, 3, '0', STR_PAD_LEFT).'.'.$extension;
            $storedPath = $uploadedAttachment->storeAs($directory, $fileName, ['disk' => 'local']);

            if (!is_string($storedPath) || $storedPath === '') {
                throw new \RuntimeException('No se pudo guardar uno de los adjuntos del respaldo.');
            }

            $storedAttachmentPaths[] = str_replace('\\', '/', $storedPath);
        }

        return $storedAttachmentPaths;
    }

    /**
     * @param  array<int, string>  $absoluteImagePaths
     */
    private function extractOcrTextFromImages(array $absoluteImagePaths): string
    {
        $chunks = [];

        foreach ($absoluteImagePaths as $absoluteImagePath) {
            $ocrChunk = $this->extractOcrTextFromSingleImage($absoluteImagePath);
            if ($ocrChunk === '') {
                continue;
            }

            $chunks[] = $ocrChunk;
        }

        return trim(implode("\n\n", $chunks));
    }

    private function extractOcrTextFromSingleImage(string $absoluteImagePath): string
    {
        if (!File::exists($absoluteImagePath)) {
            return '';
        }

        $nodeBinary = trim((string) env('OCR_NODE_BIN', 'node'));
        $ocrScriptPath = base_path('scripts/ocr-image.mjs');

        if (!File::exists($ocrScriptPath)) {
            Log::warning('OCR script no encontrado para tesseract.js.', [
                'script_path' => $ocrScriptPath,
            ]);

            return '';
        }

        try {
            $process = new Process([
                $nodeBinary,
                $ocrScriptPath,
                $absoluteImagePath,
                'spa+eng',
            ]);
            $process->setWorkingDirectory(base_path());
            $process->setTimeout(180);
            $process->run();

            if (!$process->isSuccessful()) {
                Log::warning('OCR no disponible o fallo al ejecutar tesseract.js via Node.', [
                    'path' => $absoluteImagePath,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => trim($process->getErrorOutput()),
                    'stdout' => trim($process->getOutput()),
                ]);

                return '';
            }

            $output = trim($process->getOutput());

            return preg_replace('/[ \t]+\n/u', "\n", $output) ?? '';
        } catch (\Throwable $exception) {
            Log::warning('Error al intentar OCR de imagen.', [
                'path' => $absoluteImagePath,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return '';
        }
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
            'contentSnippet' => isset($source->content_snippet) && is_string($source->content_snippet)
                ? trim($source->content_snippet)
                : null,
            'url' => (string) $source->url,
            'backupPath' => $source->ruta_archivo ?: null,
            'tags' => $tags,
            'date' => $capturedAt ? $capturedAt->locale('es')->translatedFormat('d/m/Y H:i') : $capturedAtLabel,
            'capturedAt' => $capturedAt?->toIso8601String(),
            'capturedBy' => $source->captured_by ?: 'Sin usuario',
                'oficioNumber' => isset($source->oficio_number) && is_string($source->oficio_number) && trim($source->oficio_number) !== ''
                    ? trim($source->oficio_number)
                    : null,
            'hash' => $source->hash_contenido ?: null,
        ];
    }

    public function update(Request $request, int $sourceId): RedirectResponse
    {
        abort_unless($request->user()->can('abs_hemeroteca_edit'), 403);

        $source = DB::table('fuentes')->where('id', $sourceId)->first();
        abort_unless($source, 404);

        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'url'         => ['required', 'url', 'max:2048'],
            'oficioNumber' => ['nullable', 'string', 'max:120'],
            'tags'        => ['nullable', 'array', 'max:30'],
            'tags.*'      => ['required', 'string', 'max:100'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['required', 'file', 'max:51200', 'mimes:mp4,mov,avi,mkv,webm,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv'],
        ]);

        $tagNames = $this->normalizeTags($validated['tags'] ?? []);
        $oficioNumber = trim((string) ($validated['oficioNumber'] ?? ''));
        /** @var array<int, UploadedFile> $uploadedAttachments */
        $uploadedAttachments = $validated['attachments'] ?? [];

        $backupPath = trim((string) ($source->ruta_archivo ?? ''));
        if ($uploadedAttachments !== [] && $backupPath !== '' && !str_contains($backupPath, '..')) {
            $normalizedPath = ltrim(str_replace('\\', '/', $backupPath), '/');
            $directory = dirname($normalizedPath);

            if ($directory !== '.' && $directory !== '/') {
                Storage::disk('local')->makeDirectory($directory);
                $existingAttachmentCount = count($this->resolveBackupAttachmentAbsolutePaths($normalizedPath));
                $this->storeSourceAttachments($directory, $uploadedAttachments, $existingAttachmentCount);
            }
        }

        DB::transaction(function () use ($sourceId, $validated, $tagNames, $oficioNumber): void {
            DB::table('fuentes')->where('id', $sourceId)->update([
                'titulo'      => trim($validated['title']),
                'descripcion' => isset($validated['description']) ? trim($validated['description']) : null,
                'url'         => $validated['url'],
                'updated_at'  => now(),
            ]);

            DB::table('etiqueta_fuente')->where('fuente_id', $sourceId)->delete();

            if ($tagNames !== []) {
                $this->syncSourceTags($sourceId, $tagNames);
            }

            DB::table('fuente_oficio')->where('fuente_id', $sourceId)->delete();

            if ($oficioNumber !== '') {
                $oficioId = DB::table('libro_oficios')->insertGetId([
                    'oficio_peticion' => $oficioNumber,
                    'fecha_oficio' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('fuente_oficio')->insertOrIgnore([
                    'fuente_id' => $sourceId,
                    'oficio_id' => (int) $oficioId,
                ]);
            }
        });

        return redirect()->back();
    }

    public function destroy(Request $request, int $sourceId): RedirectResponse
    {
        abort_unless($request->user()->hasRole('root'), 403);

        $source = DB::table('fuentes')
            ->select('id', 'ruta_archivo')
            ->where('id', $sourceId)
            ->first();

        abort_unless($source, 404);

        DB::transaction(function () use ($source): void {
            DB::table('fuente_oficio')->where('fuente_id', $source->id)->delete();
            DB::table('etiqueta_fuente')->where('fuente_id', $source->id)->delete();
            DB::table('fuentes')->where('id', $source->id)->delete();
        });

        $backupPath = trim((string) ($source->ruta_archivo ?? ''));

        if ($backupPath !== '' && !str_contains($backupPath, '..')) {
            $normalizedPath = ltrim(str_replace('\\', '/', $backupPath), '/');

            if (Storage::disk('local')->exists($normalizedPath)) {
                Storage::disk('local')->delete($normalizedPath);
            }

            $directory = dirname($normalizedPath);

            if ($directory !== '.' && $directory !== '/' && Storage::disk('local')->exists($directory)) {
                Storage::disk('local')->deleteDirectory($directory);
            }
        }

        return redirect()->back()->with('status', 'success')->with('message', 'Fuente eliminada.');
    }

    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->can('abs_hemeroteca'), 403);

        $allowedSorts = ['name', 'description', 'capturedBy', 'date'];
        $sort      = in_array($request->input('sort'), $allowedSorts, true) ? $request->input('sort') : 'date';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $search    = trim((string) $request->input('search', ''));
        $from      = trim((string) $request->input('from', ''));
        $to        = trim((string) $request->input('to', ''));
        // Discard malformed date strings silently to prevent SQL errors
        if ($from !== '' && strtotime($from) === false) {
            $from = '';
        }
        if ($to !== '' && strtotime($to) === false) {
            $to = '';
        }
        $tags      = array_values(array_filter(array_map('trim', (array) $request->input('tags', []))));
        $view      = $request->input('view') === 'list' ? 'list' : 'grid';
        $perPage   = $view === 'grid' ? 6 : 8;
        $page      = max(1, (int) $request->input('page', 1));
        $normalizedSearch = mb_strtolower($search, 'UTF-8');

        $searchTokens = array_values(array_filter(
            preg_split('/\s+/u', preg_replace('/[^\pL\pN\s]+/u', ' ', $normalizedSearch) ?? '') ?: [],
            static fn (string $token): bool => mb_strlen($token, 'UTF-8') >= 2,
        ));

        $booleanTokens = array_map(
            static fn (string $token): string => '+'.$token.'*',
            $searchTokens,
        );

        $booleanQuery = implode(' ', $booleanTokens);
        $tagCount = count($tags);
        $foldedSearch = $this->foldSearchTerm($search);
        $foldedLike = '%'.$foldedSearch.'%';

        $foldedTitleExpr = $this->foldSqlExpression('fuentes.titulo');
        $foldedDescriptionExpr = $this->foldSqlExpression('fuentes.descripcion');
        $foldedTextExpr = $this->foldSqlExpression('fuentes.texto');
        $foldedUserNameExpr = $this->foldSqlExpression('users.name');
        $foldedTagNameExpr = $this->foldSqlExpression('etiquetas.nombre');
        $foldedOficioExpr = $this->foldSqlExpression('libro_oficios.oficio_peticion');

        // Build the filtered ID subquery for counting and as a base for the main query.
        $filteredIds = DB::table('fuentes')->select('id');

        if ($search !== '') {
            $filteredIds->where(function ($q) use (
                $booleanQuery,
                $foldedLike,
                $foldedTitleExpr,
                $foldedDescriptionExpr,
                $foldedTextExpr,
                $foldedUserNameExpr,
                $foldedTagNameExpr,
                $foldedOficioExpr,
            ): void {
                if ($booleanQuery !== '') {
                    $q->whereRaw('MATCH(titulo, descripcion, texto) AGAINST (? IN BOOLEAN MODE)', [$booleanQuery]);
                }

                $q->orWhereRaw("{$foldedTitleExpr} LIKE ?", [$foldedLike])
                    ->orWhereRaw("{$foldedDescriptionExpr} LIKE ?", [$foldedLike])
                    ->orWhereRaw("{$foldedTextExpr} LIKE ?", [$foldedLike])
                    ->orWhereExists(function ($sub) use ($foldedLike, $foldedUserNameExpr): void {
                        $sub->selectRaw('1')
                            ->from('users')
                            ->whereColumn('users.id', 'fuentes.user_id')
                            ->whereRaw("{$foldedUserNameExpr} LIKE ?", [$foldedLike]);
                    })
                    ->orWhereExists(function ($sub) use ($foldedLike, $foldedTagNameExpr): void {
                        $sub->selectRaw('1')
                            ->from('etiqueta_fuente')
                            ->join('etiquetas', 'etiqueta_fuente.etiqueta_id', '=', 'etiquetas.id')
                            ->whereColumn('etiqueta_fuente.fuente_id', 'fuentes.id')
                            ->whereRaw("{$foldedTagNameExpr} LIKE ?", [$foldedLike]);
                    })
                    ->orWhereExists(function ($sub) use ($foldedLike, $foldedOficioExpr): void {
                        $sub->selectRaw('1')
                            ->from('fuente_oficio')
                            ->join('libro_oficios', 'fuente_oficio.oficio_id', '=', 'libro_oficios.id')
                            ->whereColumn('fuente_oficio.fuente_id', 'fuentes.id')
                            ->whereRaw("{$foldedOficioExpr} LIKE ?", [$foldedLike]);
                    });
            });
        }

        if ($from !== '') {
            $filteredIds->whereDate('capturado_en', '>=', $from);
        }

        if ($to !== '') {
            $filteredIds->whereDate('capturado_en', '<=', $to);
        }

        if ($tagCount > 0) {
            $normalizedTagNames = array_values(array_unique(array_map(
                static fn (string $tagName): string => mb_strtolower($tagName, 'UTF-8'),
                $tags,
            )));

            $requiredTagCount = count($normalizedTagNames);

            $filteredIds->whereIn('id', static function ($sub) use ($normalizedTagNames, $requiredTagCount): void {
                $sub->select('etiqueta_fuente.fuente_id')
                    ->from('etiqueta_fuente')
                    ->join('etiquetas', 'etiqueta_fuente.etiqueta_id', '=', 'etiquetas.id')
                    ->whereIn(DB::raw('LOWER(etiquetas.nombre)'), $normalizedTagNames)
                    ->groupBy('etiqueta_fuente.fuente_id')
                    ->havingRaw('COUNT(DISTINCT LOWER(etiquetas.nombre)) = ?', [$requiredTagCount]);
            });
        }

        $total     = (clone $filteredIds)->count();
        $lastPage  = max(1, (int) ceil($total / $perPage));
        $page      = min($page, $lastPage);

        $sortColumn = match ($sort) {
            'name'        => 'fuentes.titulo',
            'description' => 'fuentes.descripcion',
            'capturedBy'  => 'users.name',
            default       => 'fuentes.capturado_en',
        };

        $sourcesQuery = DB::table('fuentes')
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
                'fuentes.hash_contenido',
                'users.name as captured_by',
                DB::raw("GROUP_CONCAT(etiquetas.nombre ORDER BY etiquetas.nombre SEPARATOR '||') as tags_concat"),
                DB::raw('MAX(libro_oficios.oficio_peticion) as oficio_number'),
            )
            ->whereIn('fuentes.id', $filteredIds)
            ->groupBy(
                'fuentes.id',
                'fuentes.url',
                'fuentes.titulo',
                'fuentes.descripcion',
                'fuentes.ruta_archivo',
                'fuentes.capturado_en',
                'fuentes.hash_contenido',
                'users.name',
            );

        if ($search !== '') {
            $snippetNeedle = $this->foldSearchTerm($searchTokens[0] ?? $normalizedSearch);

            if ($booleanQuery !== '') {
                $sourcesQuery->selectRaw(
                    'MATCH(fuentes.titulo, fuentes.descripcion, fuentes.texto) AGAINST (? IN BOOLEAN MODE) as relevance_score',
                    [$booleanQuery],
                );
            } else {
                $sourcesQuery->selectRaw('0 as relevance_score');
            }

            if ($snippetNeedle !== '') {
                $sourcesQuery->selectRaw(
                    "CASE
                        WHEN fuentes.texto IS NULL OR fuentes.texto = '' THEN NULL
                        WHEN LOCATE(?, {$foldedTextExpr}) > 0
                            THEN TRIM(SUBSTRING(
                                fuentes.texto,
                                GREATEST(1, LOCATE(?, {$foldedTextExpr}) - 70),
                                280
                            ))
                        ELSE NULL
                    END as content_snippet",
                    [$snippetNeedle, $snippetNeedle],
                );
            }

            $sourcesQuery->selectRaw(
                "MAX(CASE WHEN {$foldedTagNameExpr} LIKE ? THEN 1 ELSE 0 END) as tag_match_score",
                [$foldedLike],
            )->selectRaw(
                "MAX(CASE WHEN {$foldedUserNameExpr} LIKE ? THEN 1 ELSE 0 END) as user_match_score",
                [$foldedLike],
            )->selectRaw(
                "MAX(CASE WHEN {$foldedOficioExpr} LIKE ? THEN 1 ELSE 0 END) as oficio_match_score",
                [$foldedLike],
            );

            $sourcesQuery
                ->orderByDesc('relevance_score')
                ->orderByDesc('oficio_match_score')
                ->orderByDesc('tag_match_score')
                ->orderByDesc('user_match_score');
        }

        $sources = $sourcesQuery
            ->orderBy($sortColumn, $direction)
            ->orderBy('fuentes.id', $direction)
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
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
            'sources'     => $sources,
            'suggestedTags' => $suggestedTags,
            'total'       => $total,
            'perPage'     => $perPage,
            'currentPage' => $page,
            'lastPage'    => $lastPage,
            'canEdit'     => $request->user()->can('abs_hemeroteca_edit'),
            'canDelete'   => $request->user()->hasRole('root'),
            'filters'     => [
                'search'    => $search,
                'from'      => $from,
                'to'        => $to,
                'tags'      => $tags,
                'sort'      => $sort,
                'direction' => $direction,
                'view'      => $view,
            ],
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

        // Build a normalized-key → original-name map
        $normalizedMap = [];
        foreach ($tagNames as $tagName) {
            $normalizedMap[mb_strtolower($tagName, 'UTF-8')] = $tagName;
        }

        $normalizedKeys = array_keys($normalizedMap);

        // Fetch all already-existing tags in a single query instead of N queries
        $existingRows = DB::table('etiquetas')
            ->whereRaw(
                'LOWER(nombre) IN ('.implode(',', array_fill(0, count($normalizedKeys), '?')).')',
                $normalizedKeys,
            )
            ->select('id', DB::raw('LOWER(nombre) as lower_nombre'))
            ->get();

        $existingIds = [];
        foreach ($existingRows as $row) {
            $existingIds[$row->lower_nombre] = (int) $row->id;
        }

        // Only call resolveTagId (which may INSERT) for tags that don't exist yet
        $pivotRows = [];
        foreach ($normalizedMap as $normalizedKey => $originalName) {
            $tagId = $existingIds[$normalizedKey] ?? $this->resolveTagId($originalName);
            $pivotRows[] = ['fuente_id' => $sourceId, 'etiqueta_id' => $tagId];
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

    private function foldSearchTerm(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), [
            'á' => 'a',
            'à' => 'a',
            'ä' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ë' => 'e',
            'ê' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'ï' => 'i',
            'î' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ö' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'ü' => 'u',
            'û' => 'u',
        ]);
    }

    private function foldSqlExpression(string $columnExpression): string
    {
        $expr = "LOWER(COALESCE({$columnExpression}, ''))";

        foreach ([
            ['á', 'a'], ['à', 'a'], ['ä', 'a'], ['â', 'a'], ['ã', 'a'],
            ['é', 'e'], ['è', 'e'], ['ë', 'e'], ['ê', 'e'],
            ['í', 'i'], ['ì', 'i'], ['ï', 'i'], ['î', 'i'],
            ['ó', 'o'], ['ò', 'o'], ['ö', 'o'], ['ô', 'o'], ['õ', 'o'],
            ['ú', 'u'], ['ù', 'u'], ['ü', 'u'], ['û', 'u'],
        ] as [$from, $to]) {
            $expr = "REPLACE({$expr}, '{$from}', '{$to}')";
        }

        return $expr;
    }
}
