<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\SoftwareSetting;
use App\Application\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController extends Controller
{
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
}
