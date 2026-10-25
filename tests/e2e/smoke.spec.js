import { test, expect } from '@playwright/test';

/**
 * 前端主链路冒烟：登录分流、移动端建单、回复
 * 幂等设计：随机标题，不依赖既有数据顺序
 */

const USER = { account: 'xiaoming', password: 'password' };
const STAFF = { account: 'zhang', password: 'password' };

async function login(page, account, password) {
    await page.goto('/login');
    await page.getByPlaceholder('用户名 / 邮箱').fill(account);
    await page.getByPlaceholder('密码', { exact: true }).first().fill(password);
    await page.getByRole('button', { name: '登 录' }).click();
}

test('普通用户登录进入移动客户端并可见底部 Tab', async ({ page }) => {
    await login(page, USER.account, USER.password);
    await expect(page).toHaveURL(/\/m\/tickets$/);
    await expect(page.locator('.m-tab', { hasText: '我的工单' })).toBeVisible();
    await expect(page.locator('.m-tab', { hasText: '提工单' })).toBeVisible();
});

test('staff 账号在客户端登录被拒绝', async ({ page }) => {
    await login(page, STAFF.account, STAFF.password);
    await expect(page.getByText('该账号为客服/管理员，请从后台入口登录')).toBeVisible();
    await expect(page).toHaveURL(/\/login$/);
});

test('移动端建单主链路：提交→详情', async ({ page }) => {
    await login(page, USER.account, USER.password);
    const catLoaded = page.waitForResponse((r) => r.url().includes('/api/categories'));
    await page.locator('.m-tab', { hasText: '提工单' }).click();
    await expect(page).toHaveURL(/\/m\/new$/);
    await catLoaded; // 分类默认选中需等列表加载完成

    const title = `e2e-${Date.now()}`;
    await page.getByPlaceholder('一句话描述问题').fill(title);
    await page.getByPlaceholder(/详细描述/).fill('playwright 冒烟自动创建');
    await page.getByRole('button', { name: /提交工单|提 交 工 单/ }).click();

    // 跳转详情并能看到工单号与内容
    await expect(page).toHaveURL(/\/m\/tickets\/\d+$/, { timeout: 15000 });
    await expect(page.getByText(title).first()).toBeVisible();
});

test('工单详情回复发送', async ({ page }) => {
    await login(page, USER.account, USER.password);
    await page.locator('.t-card').first().click();
    await expect(page).toHaveURL(/\/m\/tickets\/\d+$/);

    const content = `e2e-reply-${Date.now()}`;
    await page.locator('textarea').last().fill(content);
    await page.getByRole('button', { name: '回复', exact: true }).click();

    await expect(page.getByText(content).first()).toBeVisible({ timeout: 10000 });
});

test('普通用户访问后台被弹回客户端', async ({ page }) => {
    await login(page, USER.account, USER.password);
    await page.goto('/panel/tickets');
    await expect(page).toHaveURL(/\/m\/tickets$/);
});

test.describe('desktop 后台（staff）', () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    async function loginAdmin(page) {
        await page.goto('/panel/login');
        await page.getByPlaceholder(/账号/).fill(STAFF.account);
        await page.getByPlaceholder('密码', { exact: true }).first().fill(STAFF.password);

        // 拖动滑块到最右（行为验证）：等 issue 下发 → 拖 → 等服务端核销
        const thumb = page.locator('.slider-captcha .thumb');
        const track = page.locator('.slider-captcha .track');
        await thumb.waitFor();
        await page.waitForResponse((r) => r.url().includes('/api/captcha/issue'));
        await page.waitForTimeout(200);
        const tb = await thumb.boundingBox();
        const tr = await track.boundingBox();
        await page.mouse.move(tb.x + tb.width / 2, tb.y + tb.height / 2);
        await page.mouse.down();
        await page.mouse.move(tr.x + tr.width - tb.width / 2 - 4, tb.y + tb.height / 2, { steps: 12 });
        await page.mouse.up();
        await expect(page.locator('.slider-captcha.done')).toBeVisible({ timeout: 8000 });

        await page.getByRole('button', { name: '登录后台' }).click();
    }

    test('后台登录进入工单列表并可打开详情回复', async ({ page }) => {
        await loginAdmin(page);
        await expect(page).toHaveURL(/\/panel\/tickets$/);
        await expect(page.locator('.el-table__body tr').first()).toBeVisible();

        // 打开第一张工单回复
        await page.locator('.el-table__body .el-link').first().click();
        await expect(page).toHaveURL(/\/panel\/tickets\/\d+$/);

        const content = `e2e-staff-${Date.now()}`;
        await page.locator('textarea').last().fill(content);
        await page.getByRole('button', { name: '回复', exact: true }).click();
        await expect(page.getByText(content).first()).toBeVisible({ timeout: 10000 });
    });

    test('后台登录页拒绝普通用户', async ({ page }) => {
        await page.goto('/panel/login');
        await page.getByPlaceholder(/账号/).fill(USER.account);
        await page.getByPlaceholder('密码', { exact: true }).first().fill(USER.password);

        const thumb = page.locator('.slider-captcha .thumb');
        const track = page.locator('.slider-captcha .track');
        await page.waitForResponse((r) => r.url().includes('/api/captcha/issue'));
        await page.waitForTimeout(200);
        const tb = await thumb.boundingBox();
        const tr = await track.boundingBox();
        await page.mouse.move(tb.x + tb.width / 2, tb.y + tb.height / 2);
        await page.mouse.down();
        await page.mouse.move(tr.x + tr.width - tb.width / 2 - 4, tb.y + tb.height / 2, { steps: 12 });
        await page.mouse.up();
        await expect(page.locator('.slider-captcha.done')).toBeVisible({ timeout: 8000 });

        await page.getByRole('button', { name: '登录后台' }).click();
        await expect(page.getByText('该账号是普通用户，请使用用户端登录')).toBeVisible();
    });
});
