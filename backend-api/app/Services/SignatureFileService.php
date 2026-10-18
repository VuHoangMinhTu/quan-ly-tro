<?php

namespace App\Services;

use App\Models\ContractSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SignatureFileService
{
    public function store(UploadedFile $file): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['signature' => 'Máy chủ chưa được cấu hình xử lý ảnh chữ ký. Vui lòng liên hệ quản trị viên.']);
        }
        $bytes = file_get_contents($file->getRealPath());
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[2] !== IMAGETYPE_PNG || $size[0] < 80 || $size[1] < 30 || $size[0] > 2000 || $size[1] > 1000 || strlen($bytes) > 524288) {
            throw ValidationException::withMessages(['signature' => 'Chữ ký phải là ảnh PNG hợp lệ, tối đa 512 KB và kích thước từ 80×30 đến 2000×1000.']);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['signature' => 'Không đọc được ảnh chữ ký.']);
        }
        $ink = 0;
        for ($y = 0; $y < $size[1] && $ink < 20; $y++) {
            for ($x = 0; $x < $size[0] && $ink < 20; $x++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($color['alpha'] < 100 && min($color['red'], $color['green'], $color['blue']) < 200) {
                    $ink++;
                }
            }
        }
        if ($ink < 20) {
            imagedestroy($image);
            throw ValidationException::withMessages(['signature' => 'Vui lòng vẽ chữ ký trước khi xác nhận.']);
        }
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);
        $clean = ob_get_clean();
        imagedestroy($image);
        $path = bin2hex(random_bytes(24)).'.png';
        try {
            Storage::disk('contract_signatures')->put($path, $clean);
        } catch (\Throwable $exception) {
            Storage::disk('contract_signatures')->delete($path);
            throw $exception;
        }

        return ['path' => $path, 'hash' => hash('sha256', $clean)];
    }

    public function read(ContractSignature $signature): string
    {
        $bytes = Storage::disk('contract_signatures')->get($signature->signature_file_path);
        if (! hash_equals($signature->signature_file_hash, hash('sha256', $bytes))) {
            throw ValidationException::withMessages(['signature' => 'Ảnh chữ ký không khớp dữ liệu đã lưu.']);
        }

        return $bytes;
    }
}
