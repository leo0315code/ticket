<template>
    <div class="help">
        <header class="help-header">
            <div class="brand">
                <span class="logo">?</span>
                <span class="name">{{ site.site_name || '帮助中心' }}</span>
            </div>
            <div class="actions">
                <template v-if="auth.isLoggedIn">
                    <el-button text @click="goApp">{{ auth.isStaff ? '进入工作台' : '我的工单' }}</el-button>
                </template>
                <template v-else>
                    <el-button text @click="$router.push('/login')">登录 / 注册</el-button>
                    <el-button text @click="$router.push(adminLoginPath)">客服入口</el-button>
                </template>
            </div>
        </header>

        <main class="help-main">
            <h1 class="hero">我们能帮你做什么？</h1>
            <el-input
                v-model="keyword"
                size="large"
                placeholder="搜索问题，如：登录、退款、发票"
                clearable
                :prefix-icon="Search"
                @input="onSearch"
            />

            <div v-if="categories.length" class="chips">
                <span :class="['chip', categoryFilter === '' && 'active']" @click="categoryFilter = ''">全部</span>
                <span
                    v-for="c in categories"
                    :key="c"
                    :class="['chip', categoryFilter === c && 'active']"
                    @click="categoryFilter = c"
                >{{ c }}</span>
            </div>

            <el-skeleton v-if="loading" :rows="5" animated />

            <el-empty v-else-if="groups.length === 0" :description="keyword ? '没有匹配的条目，换个关键词试试' : '暂时还没有内容'" />

            <div v-for="g in groups" :key="g.name" class="group">
                <h3 class="group-title">{{ g.name }}</h3>
                <el-collapse v-model="expanded" @change="onExpand">
                    <el-collapse-item v-for="f in g.items" :key="f.id" :name="String(f.id)">
                        <template #title>
                            <span class="q">{{ f.question }}</span>
                        </template>
                        <div class="a">{{ f.answer }}</div>
                        <div class="feedback">
                            <span class="fq">这条内容有帮助吗？</span>
                            <el-button size="small" plain :disabled="voted.has(f.id)" @click="vote(f, true)">👍 有帮助 {{ f.helpful_yes }}</el-button>
                            <el-button size="small" plain :disabled="voted.has(f.id)" @click="vote(f, false)">👎 没帮助 {{ f.helpful_no }}</el-button>
                        </div>
                    </el-collapse-item>
                </el-collapse>
            </div>

            <div class="cta">
                <div class="cta-text">没找到答案？</div>
                <el-button type="primary" @click="goNewTicket">{{ auth.isLoggedIn ? '提交工单' : '登录后提交工单' }}</el-button>
                <div class="cta-tip">客服会在工作时间内尽快回复</div>
            </div>
        </main>

        <footer class="help-footer">© {{ new Date().getFullYear() }} {{ site.site_name || '工单支持中心' }}</footer>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { Search } from '@element-plus/icons-vue';
import http from '../api';
import { loadMeta, meta } from '../meta';
import { useAuthStore } from '../stores/auth';
import { adminPath } from '../paths';

const adminLoginPath = adminPath('login');

const router = useRouter();
const auth = useAuthStore();
const site = meta();

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
    // 展开即记一次阅读
    const opened = ids.map(String).filter((id) => expanded.value.includes(id));
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
    background: #f7f8fa;
    display: flex;
    flex-direction: column;
}
.help-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 24px;
    background: #fff;
    border-bottom: 1px solid #ebeef5;
}
.brand {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}
.logo {
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: #409eff;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.help-main {
    width: min(760px, 92vw);
    margin: 0 auto;
    padding: 40px 0 60px;
    flex: 1;
}
.hero {
    font-size: 26px;
    margin: 0 0 20px;
    text-align: center;
    color: #303133;
}
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 16px 0 8px;
}
.chip {
    padding: 4px 12px;
    border-radius: 999px;
    background: #fff;
    border: 1px solid #e4e7ed;
    font-size: 13px;
    cursor: pointer;
    color: #606266;
}
.chip.active {
    background: #ecf5ff;
    border-color: #409eff;
    color: #409eff;
}
.group {
    margin-top: 18px;
}
.group-title {
    font-size: 15px;
    color: #303133;
    margin: 0 0 8px;
}
.q {
    font-size: 14px;
}
.a {
    white-space: pre-wrap;
    line-height: 1.7;
    color: #606266;
}
.feedback {
    margin-top: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.fq {
    font-size: 12px;
    color: #909399;
}
.cta {
    margin-top: 36px;
    padding: 24px;
    background: #fff;
    border-radius: 10px;
    text-align: center;
}
.cta-text {
    font-size: 15px;
    margin-bottom: 12px;
}
.cta-tip {
    margin-top: 8px;
    font-size: 12px;
    color: #909399;
}
.help-footer {
    text-align: center;
    font-size: 12px;
    color: #909399;
    padding: 16px;
}
</style>
