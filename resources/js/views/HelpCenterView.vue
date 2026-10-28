<template>
    <div class="help">
        <header class="help-header">
            <div class="brand" @click="$router.push('/help')">
                <span class="logo">?</span>
                <span class="name">{{ site.site_name || '帮助中心' }}</span>
            </div>
            <div class="actions">
                <el-tooltip :content="dark ? '切换亮色模式' : '切换暗黑模式'" placement="bottom">
                    <el-icon :size="18" class="theme-toggle" @click="dark = toggleTheme()">
                        <Sunny v-if="dark" />
                        <Moon v-else />
                    </el-icon>
                </el-tooltip>
                <template v-if="auth.isLoggedIn">
                    <el-button size="small" @click="goApp">{{ auth.isStaff ? '进入工作台' : '我的工单' }}</el-button>
                </template>
                <template v-else>
                    <el-button size="small" @click="$router.push('/login')">登录</el-button>
                </template>
            </div>
        </header>

        <main class="help-main">
            <el-alert
                v-if="site.maintenance_mode"
                type="warning"
                :title="site.maintenance_message"
                :closable="false"
                class="maintenance-banner"
            />
            <section class="hero">
                <div class="hero-badge">帮助中心</div>
                <h1>我们能帮你做什么？</h1>
                <p class="hero-sub">先搜一下，多数问题这里就有答案。没找到也可以直接提交工单。</p>
                <el-input
                    v-model="keyword"
                    size="large"
                    class="hero-search"
                    placeholder="搜索问题，如：登录、退款、发票"
                    clearable
                    :prefix-icon="Search"
                    @input="onSearch"
                />
                <div v-if="categories.length" class="chips">
                    <button :class="['chip', categoryFilter === '' && 'active']" @click="categoryFilter = ''">全部</button>
                    <button
                        v-for="c in categories"
                        :key="c"
                        :class="['chip', categoryFilter === c && 'active']"
                        @click="categoryFilter = c"
                    >{{ c }}</button>
                </div>
            </section>

            <el-skeleton v-if="loading" :rows="6" animated class="skeleton" />

            <el-empty v-else-if="groups.length === 0" :description="keyword ? '没有匹配的条目，换个关键词试试' : '暂时还没有内容'" />

            <section v-for="g in groups" :key="g.name" class="group">
                <h3 class="group-title">
                    {{ g.name }}
                    <span class="group-count">{{ g.items.length }}</span>
                </h3>
                <el-collapse v-model="expanded" class="faq-list" @change="onExpand">
                    <el-collapse-item v-for="f in g.items" :key="f.id" :name="String(f.id)">
                        <template #title>
                            <span class="q">{{ f.question }}</span>
                        </template>
                        <div class="a">{{ f.answer }}</div>
                        <div class="feedback">
                            <span class="fq">这条内容有帮助吗？</span>
                            <el-button size="small" :disabled="voted.has(f.id)" @click="vote(f, true)">👍 有帮助 {{ f.helpful_yes }}</el-button>
                            <el-button size="small" :disabled="voted.has(f.id)" @click="vote(f, false)">👎 没帮助 {{ f.helpful_no }}</el-button>
                        </div>
                    </el-collapse-item>
                </el-collapse>
            </section>

            <section class="cta">
                <div class="cta-emoji">💬</div>
                <div class="cta-title">没找到答案？</div>
                <div class="cta-sub">提交工单，客服会在工作时间内尽快回复</div>
                <el-button type="primary" size="large" round @click="goNewTicket">
                    {{ auth.isLoggedIn ? '提交工单' : '登录后提交工单' }}
                </el-button>
            </section>
        </main>

        <footer class="help-footer">
            © {{ new Date().getFullYear() }} {{ site.site_name || '工单支持中心' }} · 帮助中心
        </footer>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, reactive } from 'vue';
import { useRouter } from 'vue-router';
import { Moon, Search, Sunny } from '@element-plus/icons-vue';
import http from '../api';
import { loadMeta, meta } from '../meta';
import { useAuthStore } from '../stores/auth';
import { adminPath } from '../paths';
import { isDark, toggleTheme } from '../theme';

const router = useRouter();
const auth = useAuthStore();
const site = reactive(meta());

const dark = ref(isDark());
const faqs = ref([]);
const loading = ref(true);
const keyword = ref('');
const categoryFilter = ref('');
const expanded = ref([]);
const voted = ref(new Set());
let searchTimer = null;

const categories = computed(() => [...new Set(faqs.value.map((f) => f.category).filter(Boolean))]);

const groups = computed(() => {
    const map = new Map();
    for (const f of faqs.value) {
        if (categoryFilter.value && f.category !== categoryFilter.value) continue;
        const name = f.category || '其他';
        if (! map.has(name)) map.set(name, []);
        map.get(name).push(f);
    }
    return [...map.entries()].map(([name, items]) => ({ name, items }));
});

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/faqs', { params: keyword.value ? { q: keyword.value } : {} });
        faqs.value = data.data;
    } finally {
        loading.value = false;
    }
}

function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 250);
}

function onExpand(ids) {
    const opened = ids.map(String);
    for (const id of opened) {
        const f = faqs.value.find((x) => String(x.id) === id);
        if (f) http.post(`/faqs/${f.id}/view`).catch(() => {});
    }
}

async function vote(f, helpful) {
    voted.value = new Set([...voted.value, f.id]);
    try {
        await http.post(`/faqs/${f.id}/feedback`, { helpful });
        f.helpful_yes += helpful ? 1 : 0;
        f.helpful_no += helpful ? 0 : 1;
    } catch {
        voted.value = new Set([...voted.value].filter((id) => id !== f.id));
    }
}

function goNewTicket() {
    if (auth.isLoggedIn) {
        router.push('/m/new');
    } else {
        router.push({ path: '/login', query: { redirect: '/m/new' } });
    }
}

function goApp() {
    router.push(auth.isStaff ? adminPath('tickets') : '/m/tickets');
}

onMounted(async () => {
    Object.assign(site, await loadMeta());
    document.title = `${site.site_name || '帮助中心'} · 帮助中心`;
    await load();
});
</script>

<style scoped>
.help {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: var(--el-bg-color-page);
    color: var(--el-text-color-primary);
}
.help-header {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 24px;
    background: var(--el-bg-color);
    border-bottom: 1px solid var(--el-border-color-lighter);
}
.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    cursor: pointer;
}
.logo {
    width: 28px;
    height: 28px;
    border-radius: 9px;
    background: linear-gradient(135deg, #409eff, #7b8cff);
    color: #fff;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.theme-toggle {
    cursor: pointer;
    color: var(--el-text-color-secondary);
    margin-right: 4px;
}
.theme-toggle:hover {
    color: var(--el-color-primary);
}
.help-main {
    width: min(820px, 92vw);
    margin: 0 auto;
    padding: 28px 0 72px;
    flex: 1;
}
.hero {
    padding: 32px 28px 26px;
    border-radius: 18px;
    background:
        radial-gradient(120% 140% at 0% 0%, rgba(64, 158, 255, 0.16), transparent 60%),
        var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    text-align: center;
}
.hero-badge {
    display: inline-block;
    padding: 2px 10px;
    font-size: 12px;
    border-radius: 999px;
    color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
    margin-bottom: 12px;
}
.hero h1 {
    margin: 0 0 8px;
    font-size: 28px;
    letter-spacing: 0.5px;
}
.hero-sub {
    margin: 0 0 20px;
    color: var(--el-text-color-secondary);
    font-size: 14px;
}
.hero-search {
    max-width: 560px;
}
.hero-search :deep(.el-input__wrapper) {
    border-radius: 999px;
    padding: 4px 18px;
    box-shadow: 0 6px 20px rgba(31, 45, 61, 0.08), 0 0 0 1px var(--el-border-color) inset;
}
.chips {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px;
    margin-top: 18px;
}
.chip {
    padding: 5px 14px;
    border-radius: 999px;
    border: 1px solid var(--el-border-color);
    background: var(--el-bg-color);
    color: var(--el-text-color-regular);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.15s;
}
.chip:hover {
    border-color: var(--el-color-primary-light-5);
    color: var(--el-color-primary);
}
.chip.active {
    background: var(--el-color-primary);
    border-color: var(--el-color-primary);
    color: #fff;
}
.skeleton {
    margin-top: 24px;
    padding: 20px;
    background: var(--el-bg-color);
    border-radius: 14px;
}
.group {
    margin-top: 26px;
}
.group-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    margin: 0 0 10px;
    color: var(--el-text-color-regular);
}
.group-count {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    background: var(--el-fill-color-light);
    border-radius: 999px;
    padding: 1px 8px;
}
.faq-list {
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 14px;
    overflow: hidden;
    background: var(--el-bg-color);
}
.faq-list :deep(.el-collapse-item__header) {
    padding: 0 18px;
    font-size: 14px;
    border-bottom-color: var(--el-border-color-lighter);
}
.faq-list :deep(.el-collapse-item__wrap) {
    background: var(--el-bg-color);
}
.faq-list :deep(.el-collapse-item__content) {
    padding: 4px 18px 16px;
}
.q {
    font-weight: 500;
}
.a {
    white-space: pre-wrap;
    line-height: 1.75;
    color: var(--el-text-color-regular);
}
.feedback {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px dashed var(--el-border-color-lighter);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.fq {
    font-size: 12px;
    color: var(--el-text-color-secondary);
}
.cta {
    margin-top: 32px;
    padding: 28px;
    text-align: center;
    border-radius: 16px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
}
.cta-emoji {
    font-size: 26px;
}
.cta-title {
    font-size: 16px;
    font-weight: 600;
    margin: 8px 0 4px;
}
.cta-sub {
    font-size: 13px;
    color: var(--el-text-color-secondary);
    margin-bottom: 16px;
}
.help-footer {
    text-align: center;
    font-size: 12px;
    color: var(--el-text-color-secondary);
    padding: 18px;
    border-top: 1px solid var(--el-border-color-lighter);
}
.dot {
    margin: 0 6px;
}

.hero-phone {
    margin-top: 10px;
    font-size: 14px;
    color: var(--el-text-color-secondary);
}

.hero-phone a {
    color: var(--el-color-primary);
    font-weight: 600;
    text-decoration: none;
}

.hero-phone-time {
    color: var(--el-text-color-placeholder);
}
.maintenance-banner { max-width: 860px; margin: 12px auto 0; }
</style>
