<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\FileUpload;
use App\Application\Models\User;
use App\Application\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

class FileController extends Controller
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    public function __construct(private AuditService $audit)
    {
    }

    public function upload(Request $request): Response
    {
        $body = $request->getParsedBody();
        $data = $this->validateData(is_array($body) ? $body : [], [
            'category' => ['sometimes', 'in:avatar,document,attachment'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        return $this->storeFile(
            $request,
            $data['category'] ?? 'attachment',
            false,
        );
    }

    public function uploadAvatar(Request $request): Response
    {
        return $this->storeFile($request, 'avatar', true);
    }

    public function list(Request $request): Response
    {
        return $this->fileList($request, false);
    }

    public function adminList(Request $request): Response
    {
        return $this->fileList($request, true);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $file = $this->findAccessibleFile($request, (int) $args['id']);
        if (!$file) {
            return $this->respondWithError('File not found', 404);
        }

        return $this->respondWithData(['file' => $this->format($file)]);
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $file = $this->findAccessibleFile($request, (int) $args['id']);
        if (!$file) {
            return $this->respondWithError('File not found', 404);
        }

        $absolutePath = $this->resolveStoredPath($file->stored_path);
        if ($absolutePath === null) {
            return $this->respondWithError('Stored file is unavailable', 404);
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            return $this->respondWithError('Unable to read stored file', 500);
        }

        $downloadName = str_replace(["\r", "\n", '"'], '_', $file->original_name);
        $download = new SlimResponse();
        $download->getBody()->write($contents);
        $this->audit->record(
            $request,
            'file.downloaded',
            FileUpload::class,
            $file->id,
            null,
            ['original_name' => $file->original_name, 'size' => $file->size],
        );

        return $download
            ->withHeader('Content-Type', $file->mime_type)
            ->withHeader('Content-Length', (string) $file->size)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $file = $this->findAccessibleFile($request, (int) $args['id']);
        if (!$file) {
            return $this->respondWithError('File not found', 404);
        }

        $oldValues = $this->format($file);
        $absolutePath = $this->resolveStoredPath($file->stored_path);
        if ($absolutePath !== null) {
            unlink($absolutePath);
        }

        $file->delete();
        $this->audit->record(
            $request,
            'file.deleted',
            FileUpload::class,
            $file->id,
            $oldValues,
            null,
        );

        /** @var User $user */
        $user = $request->getAttribute('user');
        if ($file->user_id === $user->id && $user->profile_picture === $this->downloadUrl($file)) {
            $user->profile_picture = null;
            $user->save();
        }

        return $this->respondWithMessage('File deleted successfully');
    }

    private function storeFile(Request $request, string $category, bool $imageOnly): Response
    {
        $uploadedFile = $request->getUploadedFiles()['file'] ?? null;
        if (!$uploadedFile instanceof UploadedFileInterface) {
            return $this->respondWithValidationError([
                'file' => ['A file upload is required.'],
            ]);
        }

        $validationError = $this->validateUpload($uploadedFile, $imageOnly);
        if ($validationError !== null) {
            return $this->respondWithValidationError(['file' => [$validationError]]);
        }

        $mimeType = $this->detectMimeType($uploadedFile);
        if ($mimeType === null) {
            return $this->respondWithValidationError([
                'file' => ['The uploaded file type could not be verified.'],
            ]);
        }

        $extension = self::MIME_EXTENSIONS[$mimeType];
        $relativeDirectory = gmdate('Y/m');
        $targetDirectory = $this->storageRoot() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            return $this->respondWithError('Unable to initialize file storage', 500);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativePath = $relativeDirectory . '/' . $storedName;
        $absolutePath = $targetDirectory . DIRECTORY_SEPARATOR . $storedName;
        $uploadedFile->moveTo($absolutePath);

        /** @var User $user */
        $user = $request->getAttribute('user');

        try {
            $file = new FileUpload();
            $file->user_id = $user->id;
            $file->original_name = basename($uploadedFile->getClientFilename() ?: $storedName);
            $file->stored_path = $relativePath;
            $file->mime_type = $mimeType;
            $file->size = (int) $uploadedFile->getSize();
            $file->category = $category;
            $file->save();

            if ($category === 'avatar') {
                $user->profile_picture = $this->downloadUrl($file);
                $user->save();
            }
            $this->audit->record(
                $request,
                'file.uploaded',
                FileUpload::class,
                $file->id,
                null,
                $this->format($file),
            );
        } catch (\Throwable $exception) {
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }

            return $this->respondWithError('Unable to save file metadata', 500);
        }

        return $this->respondWithData([
            'message' => 'File uploaded successfully',
            'file' => $this->format($file),
        ], 201);
    }

    private function fileList(Request $request, bool $admin): Response
    {
        $filters = $this->validateData($request->getQueryParams(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'category' => ['sometimes', 'in:avatar,document,attachment'],
            'search' => ['sometimes', 'string', 'max:100'],
            'user_id' => [$admin ? 'sometimes' : 'prohibited', 'integer', 'min:1'],
        ]);

        if ($filters instanceof Response) {
            return $filters;
        }

        /** @var User $user */
        $user = $request->getAttribute('user');
        $query = FileUpload::query()->orderByDesc('id');
        if (!$admin) {
            $query->where('user_id', $user->id);
        } elseif (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (isset($filters['search'])) {
            $query->where('original_name', 'like', '%' . addcslashes(trim($filters['search']), '%_\\') . '%');
        }

        $files = $query->paginate(
            (int) ($filters['per_page'] ?? 15),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );

        $files->through(fn (FileUpload $file): array => $this->format($file));

        return $this->respondWithData(['files' => $files]);
    }

    private function validateUpload(UploadedFileInterface $file, bool $imageOnly): ?string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return 'The file upload failed.';
        }

        $size = $file->getSize();
        $maxBytes = $this->uploadMaxMb() * 1024 * 1024;
        if ($size === null || $size < 1 || $size > $maxBytes) {
            return "The file must be between 1 byte and {$this->uploadMaxMb()} MB.";
        }

        $mimeType = $this->detectMimeType($file);
        $allowed = $this->allowedMimeTypes();
        if ($mimeType === null || !in_array($mimeType, $allowed, true)) {
            return 'The file type is not allowed.';
        }

        if ($imageOnly && !str_starts_with($mimeType, 'image/')) {
            return 'The avatar must be a JPEG, PNG, or WebP image.';
        }

        return null;
    }

    private function detectMimeType(UploadedFileInterface $file): ?string
    {
        $path = $file->getStream()->getMetadata('uri');
        if (!is_string($path) || !is_file($path)) {
            return null;
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($fileInfo === false) {
            return null;
        }

        $mimeType = finfo_file($fileInfo, $path);
        finfo_close($fileInfo);

        return is_string($mimeType) ? $mimeType : null;
    }

    /** @return list<string> */
    private function allowedMimeTypes(): array
    {
        $configured = array_map('trim', explode(',', (string) env(
            'UPLOAD_ALLOWED_MIME_TYPES',
            implode(',', array_keys(self::MIME_EXTENSIONS)),
        )));

        return array_values(array_intersect($configured, array_keys(self::MIME_EXTENSIONS)));
    }

    private function uploadMaxMb(): int
    {
        return max(1, min(50, (int) env('UPLOAD_MAX_SIZE_MB', 10)));
    }

    private function findAccessibleFile(Request $request, int $fileId): ?FileUpload
    {
        $file = FileUpload::find($fileId);
        if (!$file) {
            return null;
        }

        /** @var User $user */
        $user = $request->getAttribute('user');

        return $user->role === 'admin' || $file->user_id === $user->id ? $file : null;
    }

    private function resolveStoredPath(string $relativePath): ?string
    {
        $root = realpath($this->storageRoot());
        $path = realpath($this->storageRoot() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    private function storageRoot(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'uploads';
    }

    /** @return array<string, mixed> */
    private function format(FileUpload $file): array
    {
        return [
            'id' => $file->id,
            'user_id' => $file->user_id,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'category' => $file->category,
            'download_url' => $this->downloadUrl($file),
            'created_at' => $file->created_at,
        ];
    }

    private function downloadUrl(FileUpload $file): string
    {
        return '/api/files/' . $file->id . '/download';
    }
}
