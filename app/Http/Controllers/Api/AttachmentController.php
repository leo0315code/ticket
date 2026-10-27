<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        // 上限由后台「系统设置 → 附件大小」控制（KB）
        $maxKb = max(1, (int) Settings::get('attach_max_mb', 10)) * 1024;
        $data = $request->validate([
            'file' => ['required', 'file', "max:{$maxKb}", 'mimes:jpg,jpeg,png,gif,webp,bmp,pdf,doc,docx,xls,xlsx,csv,txt,zip,rar,7z'],
        ]);

        $file = $data['file'];
        $path = $file->store("attachments/{$ticket->id}");

        $attachment = Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
        ]);

        $ticket->log('attachment_uploaded', $request->user()->id, $attachment->original_name);

        return response()->json([
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'size' => $attachment->size,
            'mime' => $attachment->mime,
            'created_at' => $attachment->created_at->format('Y-m-d H:i'),
        ], 201);
    }

    public function download(Ticket $ticket, Attachment $attachment)
    {
        $this->authorize('view', $ticket);
        abort_unless($attachment->ticket_id === $ticket->id, 404);

        return Storage::download($attachment->path, $attachment->original_name);
    }
}
