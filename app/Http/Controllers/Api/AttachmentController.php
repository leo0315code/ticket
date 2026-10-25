<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 最大 10MB
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
