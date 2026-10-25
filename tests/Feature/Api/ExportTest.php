<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ExportTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_staff_can_export_csv_with_headers(): void
    {
        $agent = $this->makeUser('agent');
        Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'title' => '导出测试工单']);

        $res = $this->actingAsApi($agent)->get('/api/tickets/export');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));

        $content = $res->streamedContent();
        // UTF-8 BOM
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
        $this->assertStringContainsString('工单号,标题', $content);
        $this->assertStringContainsString('导出测试工单', $content);
    }

    public function test_export_respects_overdue_filter(): void
    {
        $agent = $this->makeUser('agent');
        Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'title' => '超时的', 'sla_due_at' => now()->subHours(2)]);
        Ticket::factory()->create(['user_id' => $this->makeUser()->id, 'title' => '没超时', 'sla_due_at' => now()->addDay()]);

        $content = $this->actingAsApi($agent)->get('/api/tickets/export?overdue=1')->streamedContent();
        $this->assertStringContainsString('超时的', $content);
        $this->assertStringNotContainsString('没超时', $content);
    }

    public function test_normal_user_cannot_export(): void
    {
        $this->actingAsApi($this->makeUser())
            ->get('/api/tickets/export')->assertStatus(403);
    }
}
