<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatsExportTest extends ApiTestCase
{
    public function test_guest_and_user_cannot_export_stats(): void
    {
        $this->getJson('/api/stats/export')->assertStatus(401);
        $this->actingAsApi($this->makeUser())->getJson('/api/stats/export')->assertStatus(403);
    }

    public function test_admin_can_export_csv(): void
    {
        $admin = $this->makeUser('admin');
        Ticket::factory()->count(3)->create(['user_id' => $this->makeUser()->id, 'created_at' => now()]);

        $response = $this->actingAsApi($admin)->get('/api/stats/export?days=7');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $content = $this->streamContent($response->baseResponse);
        $this->assertStringContainsString('按天明细', $content);
        $this->assertStringContainsString('坐席绩效', $content);
        $this->assertStringContainsString(now()->format('Y-m-d'), $content);
    }

    public function test_invalid_days_falls_back_to_30(): void
    {
        $response = $this->actingAsApi($this->makeUser('admin'))->get('/api/stats/export?days=999');
        $response->assertOk();
        // 中文文件名经 utf-8'' 编码，回退文件名部分含天数
        $this->assertStringContainsString('-30-', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("utf-8''", $response->headers->get('Content-Disposition'));
    }

    private function streamContent(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }
}
