<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\SoftwareSetting;
use App\Application\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

class SettingsController extends Controller
{
    private const BRANDING_ASSETS = [
        'logo' => ['setting' => 'branding.logo_path', 'max_bytes' => 5242880],
        'favicon' => ['setting' => 'branding.favicon_path', 'max_bytes' => 1048576],
    ];

    private const BRANDING_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private AuditService $audit)
    {
    }

    public function publicSettings(): Response
    {
        $settings = SoftwareSetting::query()
            ->where('is_public', true)
            ->orderBy('setting_group')
            ->orderBy('setting_key')
            ->get();

        $data = [];
        foreach ($settings as $setting) {
            /** @var SoftwareSetting $setting */
            $data[$setting->setting_key] = $setting->typedValue();
        }

        foreach (self::BRANDING_ASSETS as $asset => $configuration) {
            $path = SoftwareSetting::query()
                ->where('setting_key', $configuration['setting'])
                ->value('setting_value');
            if (is_string($path) && $this->resolveBrandingPath($path) !== null) {
                $data["branding.{$asset}_url"] = "/branding/{$asset}?v=" . rawurlencode(basename($path));
            }
        }

        return $this->respondWithData(['settings' => $data]);
    }

    public function list(Request $request): Response
    {
        $filters = $this->validateData($request->getQueryParams(), [
            'group' => ['sometimes', 'string', 'max:50'],
            'search' => ['sometimes', 'string', 'max:100'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        if ($filters instanceof Response) {
            return $filters;
        }

        $query = SoftwareSetting::query()->orderBy('setting_group')->orderBy('setting_key');
        if (isset($filters['group'])) {
            $query->where('setting_group', $filters['group']);
        }
        if (isset($filters['is_public'])) {
            $query->where('is_public', (bool) $filters['is_public']);
        }
        if (isset($filters['search'])) {
            $search = addcslashes(trim($filters['search']), '%_\\');
            $query->where(function ($builder) use ($search): void {
                $builder->where('setting_key', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $this->respondWithData([
            'settings' => $query->get()->map(fn (SoftwareSetting $setting): array => $this->format($setting)),
        ]);
    }

    public function upsert(Request $request, Response $response, array $args): Response
    {
        $data = $this->validateRequest($request, [
            'value' => ['present'],
            'type' => ['required', 'in:string,integer,boolean,json'],
            'group' => ['sometimes', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
            'is_public' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $valueError = $this->validateTypedValue($data['value'], $data['type']);
        if ($valueError !== null) {
            return $this->respondWithValidationError(['value' => [$valueError]]);
        }

        $setting = SoftwareSetting::query()->firstOrNew(['setting_key' => $args['key']]);
        $created = !$setting->exists;
        $oldValues = $created ? null : $this->format($setting);
        $setting->setTypedValue($data['value'], $data['type']);
        $setting->setting_group = $data['group'] ?? $setting->setting_group ?? 'general';
        $setting->is_public = (bool) ($data['is_public'] ?? $setting->is_public ?? false);
        $setting->description = $data['description'] ?? $setting->description;
        $setting->save();
        $this->audit->record(
            $request,
            $created ? 'setting.created' : 'setting.updated',
            SoftwareSetting::class,
            $setting->id,
            $oldValues,
            $this->format($setting),
        );

        return $this->respondWithData([
            'message' => $created ? 'Setting created successfully' : 'Setting updated successfully',
            'setting' => $this->format($setting),
        ], $created ? 201 : 200);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $setting = SoftwareSetting::query()->where('setting_key', $args['key'])->first();
        if (!$setting) {
            return $this->respondWithError('Setting not found', 404);
        }

        return $this->respondWithData(['setting' => $this->format($setting)]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $setting = SoftwareSetting::query()->where('setting_key', $args['key'])->first();
        if (!$setting) {
            return $this->respondWithError('Setting not found', 404);
        }

        $oldValues = $this->format($setting);
        $setting->delete();
        $this->audit->record(
            $request,
            'setting.deleted',
            SoftwareSetting::class,
            $setting->id,
            $oldValues,
            null,
        );

        return $this->respondWithMessage('Setting deleted successfully');
    }

    public function systemInfo(): Response
    {
        return $this->respondWithData([
            'php_version' => PHP_VERSION,
            'slim_version' => \Composer\InstalledVersions::getPrettyVersion('slim/slim'),
            'environment' => env('APP_ENV', 'production'),
            'upload_max_mb' => $this->uploadMaxMb(),
            'disk_free_bytes' => disk_free_space(dirname(__DIR__, 3)) ?: null,
            'server_time' => gmdate(DATE_ATOM),
        ]);
    }

    public function brandingAsset(Request $request, Response $response, array $args): Response
    {
        $asset = (string) ($args['asset'] ?? '');
        $configuration = self::BRANDING_ASSETS[$asset] ?? null;
        if ($configuration === null) {
            return $this->respondWithError('Branding asset not found', 404);
        }

        $relativePath = SoftwareSetting::query()
            ->where('setting_key', $configuration['setting'])
            ->value('setting_value');
        $absolutePath = is_string($relativePath) ? $this->resolveBrandingPath($relativePath) : null;
        if ($absolutePath === null) {
            return $this->respondWithError('Branding asset not configured', 404);
        }

        $contents = file_get_contents($absolutePath);
        $mimeType = mime_content_type($absolutePath);
        if ($contents === false || !is_string($mimeType)) {
            return $this->respondWithError('Branding asset is unavailable', 404);
        }

        $assetResponse = new SlimResponse();
        $assetResponse->getBody()->write($contents);

        return $assetResponse
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string) strlen($contents))
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'public, max-age=86400, immutable');
    }

    public function updateBranding(Request $request): Response
    {
        $body = $request->getParsedBody();
        $data = $this->validateData(is_array($body) ? $body : [], [
            'app_name' => ['sometimes', 'string', 'min:2', 'max:60'],
        ]);
        if ($data instanceof Response) {
            return $data;
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (!isset($data['app_name']) && !isset($uploadedFiles['logo']) && !isset($uploadedFiles['favicon'])) {
            return $this->respondWithValidationError([
                'branding' => ['Provide an application name, logo, or favicon.'],
            ]);
        }

        foreach (self::BRANDING_ASSETS as $asset => $configuration) {
            $upload = $uploadedFiles[$asset] ?? null;
            if (!$upload instanceof UploadedFileInterface) {
                continue;
            }
            $validationError = $this->validateBrandingUpload($upload, $configuration['max_bytes']);
            if ($validationError !== null) {
                return $this->respondWithValidationError([$asset => [$validationError]]);
            }
        }

        $oldValues = $this->brandingValues();
        $newValues = $oldValues;

        if (isset($data['app_name'])) {
            $name = trim((string) $data['app_name']);
            $this->saveStringSetting(
                'branding.app_name',
                $name,
                true,
                'Public application name shown in the client portal.',
            );
            $newValues['app_name'] = $name;
        }

        foreach (self::BRANDING_ASSETS as $asset => $configuration) {
            $upload = $uploadedFiles[$asset] ?? null;
            if (!$upload instanceof UploadedFileInterface) {
                continue;
            }

            $oldPath = SoftwareSetting::query()
                ->where('setting_key', $configuration['setting'])
                ->value('setting_value');
            $relativePath = $this->storeBrandingUpload($upload, $asset);
            $this->saveStringSetting(
                $configuration['setting'],
                $relativePath,
                false,
                "Stored path for the portal {$asset}.",
            );
            $newValues[$asset] = basename($relativePath);

            if (is_string($oldPath)) {
                $oldAbsolutePath = $this->resolveBrandingPath($oldPath);
                if ($oldAbsolutePath !== null && $oldAbsolutePath !== $this->resolveBrandingPath($relativePath)) {
                    unlink($oldAbsolutePath);
                }
            }
        }

        $this->audit->record(
            $request,
            'branding.updated',
            SoftwareSetting::class,
            'branding',
            $oldValues,
            $newValues,
        );

        return $this->respondWithData([
            'message' => 'Portal branding updated successfully',
            'settings' => $this->publicBrandingValues(),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(SoftwareSetting $setting): array
    {
        return [
            'key' => $setting->setting_key,
            'value' => $setting->typedValue(),
            'type' => $setting->type,
            'group' => $setting->setting_group,
            'is_public' => $setting->is_public,
            'description' => $setting->description,
        ];
    }

    private function validateTypedValue(mixed $value, string $type): ?string
    {
        return match ($type) {
            'integer' => filter_var($value, FILTER_VALIDATE_INT) === false
                ? 'The value must be an integer.' : null,
            'boolean' => !is_bool($value)
                ? 'The value must be a boolean.' : null,
            'json' => !is_array($value) && !is_object($value)
                ? 'The value must be a JSON object or array.' : null,
            'string' => !is_string($value) ? 'The value must be a string.' : null,
            default => 'The setting type is invalid.',
        };
    }

    private function uploadMaxMb(): int
    {
        return max(1, min(50, (int) env('UPLOAD_MAX_SIZE_MB', 10)));
    }

    /** @return array<string, mixed> */
    private function brandingValues(): array
    {
        return [
            'app_name' => SoftwareSetting::query()
                ->where('setting_key', 'branding.app_name')->value('setting_value'),
            'logo' => SoftwareSetting::query()
                ->where('setting_key', 'branding.logo_path')->value('setting_value'),
            'favicon' => SoftwareSetting::query()
                ->where('setting_key', 'branding.favicon_path')->value('setting_value'),
        ];
    }

    /** @return array<string, mixed> */
    private function publicBrandingValues(): array
    {
        $values = $this->brandingValues();

        return [
            'branding.app_name' => $values['app_name'] ?: 'Slimer',
            'branding.logo_url' => $values['logo']
                ? '/branding/logo?v=' . rawurlencode(basename((string) $values['logo']))
                : null,
            'branding.favicon_url' => $values['favicon']
                ? '/branding/favicon?v=' . rawurlencode(basename((string) $values['favicon']))
                : null,
        ];
    }

    private function saveStringSetting(string $key, string $value, bool $public, string $description): void
    {
        $setting = SoftwareSetting::query()->firstOrNew(['setting_key' => $key]);
        $setting->setTypedValue($value, 'string');
        $setting->setting_group = 'branding';
        $setting->is_public = $public;
        $setting->description = $description;
        $setting->save();
    }

    private function validateBrandingUpload(UploadedFileInterface $upload, int $maxBytes): ?string
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            return 'The image upload failed.';
        }

        $size = $upload->getSize();
        if ($size === null || $size < 1 || $size > $maxBytes) {
            return 'The image is empty or exceeds the allowed size.';
        }

        $path = $upload->getStream()->getMetadata('uri');
        $mimeType = is_string($path) && is_file($path) ? mime_content_type($path) : false;

        return is_string($mimeType) && isset(self::BRANDING_MIME_EXTENSIONS[$mimeType])
            ? null
            : 'Use a verified JPEG, PNG, or WebP image.';
    }

    private function storeBrandingUpload(UploadedFileInterface $upload, string $asset): string
    {
        $path = $upload->getStream()->getMetadata('uri');
        $mimeType = is_string($path) ? mime_content_type($path) : false;
        if (!is_string($mimeType) || !isset(self::BRANDING_MIME_EXTENSIONS[$mimeType])) {
            throw new \RuntimeException('Unable to determine branding image type.');
        }

        $directory = $this->brandingRoot();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to initialize branding storage.');
        }

        $filename = $asset . '-' . bin2hex(random_bytes(12)) . '.' . self::BRANDING_MIME_EXTENSIONS[$mimeType];
        $upload->moveTo($directory . DIRECTORY_SEPARATOR . $filename);

        return 'branding/' . $filename;
    }

    private function resolveBrandingPath(string $relativePath): ?string
    {
        if (!str_starts_with($relativePath, 'branding/')) {
            return null;
        }

        $root = realpath($this->brandingRoot());
        $path = realpath(dirname($this->brandingRoot()) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    private function brandingRoot(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'branding';
    }
}
