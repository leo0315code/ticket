import { defineConfig } from '@playwright/test';

// 前端冒烟测试：复用已运行的本地服务与演示库（幂等用例，随机标题）
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30000,
    retries: 1,
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8088',
        headless: true,
        viewport: { width: 390, height: 844 }, // 移动客户端优先
        locale: 'zh-CN',
    },
    projects: [
        { name: 'mobile', use: { viewport: { width: 390, height: 844 } } },
        { name: 'desktop', use: { viewport: { width: 1440, height: 900 } } },
    ],
});
