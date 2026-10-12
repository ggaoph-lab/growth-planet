<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Exceptions\ApiException;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Support\BiMsg;
use App\Modules\Core\Support\Clock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores uploaded images after re-encoding (EXIF stripped) and compressing to ≤ 1000 KB.
 */
class ImageAttachmentService
{
    public const MAX_BYTES = 1000 * 1024;

    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private readonly Clock $clock) {}

    public function storeImage(
        UploadedFile $file,
        int $familyId,
        ?int $uploadedBy,
        string $ownerType,
        ?int $ownerId = null,
        string $subdir = 'attachments',
    ): Attachment {
        $this->assertValidUpload($file);

        $jpeg = $this->compressToLimit($file->getRealPath() ?: $file->getPathname());
        $relative = trim($subdir, '/').'/'.Str::uuid()->toString().'.jpg';
        $disk = 'local';

        Storage::disk($disk)->put($relative, $jpeg);

        return Attachment::query()->create([
            'family_id' => $familyId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'disk' => $disk,
            'path' => $relative,
            'thumb_path' => null,
            'mime' => 'image/jpeg',
            'size_bytes' => strlen($jpeg),
            'sha256' => hash('sha256', $jpeg),
            'uploaded_by' => $uploadedBy,
            'created_at' => $this->clock->now(),
        ]);
    }

    public function absolutePath(Attachment $attachment): ?string
    {
        if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
            return null;
        }

        return Storage::disk($attachment->disk)->path($attachment->path);
    }

    public function delete(Attachment $attachment): void
    {
        $disk = Storage::disk($attachment->disk);
        if ($disk->exists($attachment->path)) {
            $disk->delete($attachment->path);
        }
        if ($attachment->thumb_path && $disk->exists($attachment->thumb_path)) {
            $disk->delete($attachment->thumb_path);
        }
        $attachment->delete();
    }

    private function assertValidUpload(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('图片上传失败', 'Image upload failed'), 422);
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new ApiException(
                'VALIDATION_FAILED',
                BiMsg::of('图片过大，请选择 10MB 以内的图片', 'Image is too large (max 10MB)'),
                422,
            );
        }

        $mime = (string) ($file->getMimeType() ?: '');
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new ApiException(
                'VALIDATION_FAILED',
                BiMsg::of('仅支持 JPG / PNG / WEBP / GIF 图片', 'Only JPG, PNG, WEBP, or GIF images are allowed'),
                422,
            );
        }
    }

    private function compressToLimit(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('无法读取图片', 'Unable to read image'), 422);
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('图片格式无效', 'Invalid image format'), 422);
        }

        $width = imagesx($src);
        $height = imagesy($src);
        if ($width < 1 || $height < 1) {
            imagedestroy($src);
            throw new ApiException('VALIDATION_FAILED', BiMsg::of('图片格式无效', 'Invalid image format'), 422);
        }

        $maxEdges = [1600, 1280, 1024, 800, 640, 480];
        $qualities = [85, 75, 65, 55, 45, 35];

        foreach ($maxEdges as $maxEdge) {
            $scale = max($width, $height) > $maxEdge ? $maxEdge / max($width, $height) : 1.0;
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $dst = $this->resize($src, $width, $height, $targetWidth, $targetHeight);
            if ($dst === null) {
                continue;
            }

            foreach ($qualities as $quality) {
                ob_start();
                imagejpeg($dst, null, $quality);
                $jpeg = ob_get_clean();
                if (! is_string($jpeg) || $jpeg === '') {
                    continue;
                }
                if (strlen($jpeg) <= self::MAX_BYTES) {
                    imagedestroy($dst);
                    imagedestroy($src);

                    return $jpeg;
                }
            }
            imagedestroy($dst);
        }

        imagedestroy($src);
        throw new ApiException(
            'VALIDATION_FAILED',
            BiMsg::of('图片压缩后仍超过 1000KB，请换一张更小的图', 'Image still exceeds 1000KB after compression'),
            422,
        );
    }

    /**
     * @return \GdImage|null
     */
    private function resize(\GdImage $src, int $width, int $height, int $targetWidth, int $targetHeight): mixed
    {
        if ($targetWidth === $width && $targetHeight === $height) {
            $clone = imagecreatetruecolor($width, $height);
            if ($clone === false) {
                return null;
            }
            imagecopy($clone, $src, 0, 0, 0, 0, $width, $height);

            return $clone;
        }

        $dst = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($dst === false) {
            return null;
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $dst;
    }
}
