<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AttachmentTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_participant_can_upload_and_download_attachment(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);

        $res = $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('日志.txt', '报错信息'),
        ])->assertCreated();

        $this->assertSame('日志.txt', $res->json('original_name'));
        Storage::disk('local')->assertExists($res->json('path') ?? '');
        $this->assertDatabaseHas('attachments', ['ticket_id' => $ticket->id]);
        $this->assertDatabaseHas('ticket_logs', ['ticket_id' => $ticket->id, 'action' => 'attachment_uploaded']);

        $download = $this->actingAsApi($owner)
            ->getJson("/api/tickets/{$ticket->id}/attachments/{$res->json('id')}/download");
        $download->assertOk();
        $this->assertSame('报错信息', $download->streamedContent());
    }

    public function test_non_participant_cannot_upload(): void
    {
        Storage::fake('local');
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $this->actingAsApi($this->makeUser())
            ->postJson("/api/tickets/{$ticket->id}/attachments", [
                'file' => UploadedFile::fake()->create('x.txt'),
            ])->assertStatus(403);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->create('big.zip', 1024 * 11), // 11MB > 10MB
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        // 类型白名单
        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->create('evil.php', 10),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }
}
