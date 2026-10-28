<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // CI 的 test 任务不构建前端，没有 Vite manifest；跳过 @vite 资产标签
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
