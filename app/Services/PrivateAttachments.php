<?php
namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateAttachments
{
    public static function respond(Request $request, string $path, string $name): StreamedResponse
    {
        $request->validate(['preview' => ['nullable', 'boolean']]);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $name))) ?: 'attachment';
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
        if (!$request->boolean('preview')) return $disk->download($path, $name, $headers);
        // Determine the type from stored bytes, never from the supplied filename.
        $type = $disk->mimeType($path);
        abort_unless(in_array($type, ['application/pdf', 'image/jpeg', 'image/png'], true), 415, 'This file type cannot be previewed. Use Download to open it separately.');
        $request->attributes->set('security_attachment_preview', true);
        return $disk->response($path, $name, $headers + [
            'Content-Type' => $type, 'Content-Security-Policy' => "frame-ancestors 'self'", 'X-Frame-Options' => 'SAMEORIGIN',
        ], 'inline');
    }
}
