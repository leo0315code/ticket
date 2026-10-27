<?php

namespace Tests\Feature\Api;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Support\Settings;
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

    public function test_attachment_count_limit_is_enforced(): void
    {
        Storage::fake('local');
        Settings::put(['attach_max_count' => 1]);

        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'a'),
        ])->assertCreated();

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('b.txt', 'b'),
        ])->assertStatus(422)->assertJsonPath('message', '每张工单最多上传 1 个附件');
    }

    public function test_attachment_quota_is_enforced(): void
    {
        Storage::fake('local');
        Settings::put(['attach_quota_mb' => 1]);

        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->create('big1.bin', 600, 'text/plain'),
        ])->assertCreated();

        $this->actingAsApi($owner)->postJson("/api/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->create('big2.bin', 600, 'text/plain'),
        ])->assertStatus(422);
    }

    public function test_prune_removes_missing_rows_and_orphan_files(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $ticket = Ticket::factory()->create(['user_id' => $owner->id]);

        // 记录存在、文件已丢
        $missing = Attachment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $owner->id,
            'original_name' => 'gone.txt',
            'path' => 'attachments/'.$ticket->id.'/gone.txt',
            'size' => 10,
            'mime' => 'text/plain',
        ]);

        // 文件存在、没有任何记录引用，且已过保留窗口
        $orphan = 'attachments/'.$ticket->id.'/orphan.txt';
        Storage::disk('local')->put($orphan, 'x');
        touch(Storage::disk('local')->path($orphan), now()->subHours(48)->getTimestamp());

        $this->artisan('attachments:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('attachments', ['id' => $missing->id]);
        $this->assertFalse(Storage::disk('local')->exists($orphan));
    }
}
