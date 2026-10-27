<template>
    <div class="list-page">
        <!-- 状态筛选：固定 5 格，不横向截断 -->
        <div class="chips">
            <button
                v-for="c in chips"
                :key="c.key"
                class="chip"
                :class="{ active: status === (c.key === 'total' ? '' : c.key) }"
                @click="pick(c.key)"
            >
                <b>{{ c.value }}</b>
                <span>{{ c.label }}</span>
            </button>
        </div>

        <!-- 骨架屏 -->
        <div v-if="loading && !tickets.length" class="cards">
            <div v-for="i in 3" :key="i" class="t-card skeleton">
                <div class="sk-line short" />
                <div class="sk-line" />
                <div class="sk-line tiny" />
            </div>
        </div>

        <!-- 列表 -->
        <div v-else-if="tickets.length" class="cards">
            <article v-for="t in tickets" :key="t.id" class="t-card" @click="$router.push(`/m/tickets/${t.id}`)">
                <i class="accent" :class="t.status" />
                <header class="head">
                    <el-tag :type="STATUS_MAP[t.status].type" size="small" effect="light">{{ STATUS_MAP[t.status].label }}</el-tag>
                    <el-tag v-if="t.overdue" type="danger" size="small" effect="dark">SLA 超时</el-tag>
                    <span class="no">{{ t.no }}</span>
                </header>

                <h3 class="title">{{ t.title }}</h3>

                <footer class="foot">
                    <span class="prio" :class="t.priority">
                        <i />{{ PRIORITY_MAP[t.priority].label }}
                    </span>
                    <span v-if="t.category?.name" class="meta">{{ t.category.name }}</span>
                    <span class="meta">{{ t.updated_at }}</span>
                    <span v-if="t.replies_count" class="replies">
                        <el-icon><ChatDotRound /></el-icon>{{ t.replies_count }}
                    </span>
                </footer>
            </article>

            <button v-if="page < lastPage" class="more" :disabled="loading" @click="load(page + 1)">
                {{ loading ? '加载中…' : '加载更多' }}
            </button>
            <p v-else class="end">没有更多了</p>
        </div>

        <!-- 空状态 -->
        <div v-else class="empty">
            <div class="empty-emoji">🗂️</div>
            <p class="empty-title">{{ status ? '这个状态下还没有工单' : '还没有提交过工单' }}</p>
            <p class="empty-sub">{{ status ? '换个状态看看，或提交新的问题' : '遇到问题随时提工单，客服会尽快回复' }}</p>
            <el-button type="primary" round @click="$router.push('/m/new')">提交工单</el-button>
        </div>

        <button class="fab" @click="$router.push('/m/new')">
            <el-icon :size="22"><Plus /></el-icon>
        </button>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { ChatDotRound, Plus } from '@element-plus/icons-vue';
import http from '../../api';
import { STATUS_MAP, PRIORITY_MAP } from '../../constants';

const tickets = ref([]);
const status = ref('');
const mine = ref(null);
const page = ref(1);
const lastPage = ref(1);
const loading = ref(false);

const chips = computed(() => {
    const s = mine.value?.by_status || {};
    return [
        { key: 'total', label: '全部', value: mine.value?.total ?? '–' },
        ...Object.entries(s).map(([k, v]) => ({ key: k, label: STATUS_MAP[k].label, value: v })),
    ];
});

function pick(key) {
    status.value = key === 'total' ? '' : key;
    load(1);
}

async function load(p = 1) {
    loading.value = true;
    try {
        const { data } = await http.get('/tickets', {
            params: { page: p, status: status.value || undefined, per_page: 10 },
        });
        tickets.value = p === 1 ? data.data : [...tickets.value, ...data.data];
        page.value = data.meta.current_page;
        lastPage.value = data.meta.last_page;
    } finally {
        loading.value = false;
    }
    const m = await http.get('/stats/mine');
    mine.value = m.data;
}

onMounted(() => load(1));
</script>

<style scoped>
.list-page {
    padding-bottom: 24px;
}

/* 筛选 */
.chips {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 6px;
}
.chip {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
    padding: 8px 2px;
    border-radius: 12px;
    border: 1px solid var(--el-border-color-lighter);
    background: var(--el-bg-color);
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
}
.chip.active {
    border-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
}
.chip b {
    font-size: 17px;
    line-height: 1.2;
    color: var(--el-text-color-primary);
    font-variant-numeric: tabular-nums;
}
.chip span {
    font-size: 10px;
    color: var(--el-text-color-secondary);
    white-space: nowrap;
}
.chip.active b,
.chip.active span {
    color: var(--el-color-primary);
}

/* 卡片 */
.cards {
    margin-top: 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.t-card {
    position: relative;
    overflow: hidden;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 14px;
    padding: 12px 14px 10px;
    cursor: pointer;
    transition: transform 0.06s;
}
.t-card:active {
    transform: scale(0.99);
}
.accent {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--el-color-primary);
}
.accent.closed { background: var(--el-text-color-placeholder); }
.accent.pending_resolved { background: var(--el-color-success); }
.accent.open { background: var(--el-color-warning); }

.head {
    display: flex;
    align-items: center;
    gap: 6px;
}
.no {
    margin-left: auto;
    font-size: 11px;
    color: var(--el-text-color-placeholder);
    font-variant-numeric: tabular-nums;
}
.title {
    margin: 8px 0 8px;
    font-size: 15px;
    font-weight: 600;
    line-height: 1.4;
    color: var(--el-text-color-primary);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    word-break: break-word;
}
.foot {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 11px;
    color: var(--el-text-color-secondary);
}
.prio {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.prio i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--el-text-color-placeholder);
}
.prio.urgent i { background: #f56c6c; }
.prio.high i { background: #e6a23c; }
.prio.medium i { background: #409eff; }
.prio.low i { background: #909399; }
.meta {
    padding-left: 10px;
    border-left: 1px solid var(--el-border-color-lighter);
}
.replies {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 2px;
}

.more {
    width: 100%;
    padding: 10px;
    border: 0;
    border-radius: 12px;
    background: var(--el-fill-color-light);
    color: var(--el-text-color-regular);
    font-size: 13px;
    cursor: pointer;
}
.end {
    text-align: center;
    font-size: 11px;
    color: var(--el-text-color-placeholder);
    margin: 6px 0;
}

/* 空状态 */
.empty {
    margin-top: 56px;
    text-align: center;
}
.empty-emoji {
    font-size: 54px;
    line-height: 1;
    filter: grayscale(0.2);
}
.empty-title {
    margin: 14px 0 4px;
    font-size: 15px;
    font-weight: 600;
    color: var(--el-text-color-primary);
}
.empty-sub {
    margin: 0 0 18px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
}

/* 悬浮提工单 */
.fab {
    position: fixed;
    right: 16px;
    bottom: calc(70px + env(safe-area-inset-bottom));
    width: 50px;
    height: 50px;
    border-radius: 50%;
    border: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    background: linear-gradient(135deg, #2f6bff, #5b8dff);
    box-shadow: 0 8px 20px rgba(47, 107, 255, 0.35);
    cursor: pointer;
    z-index: 150;
}

/* 骨架 */
.skeleton {
    pointer-events: none;
}
.sk-line {
    height: 12px;
    border-radius: 6px;
    background: var(--el-fill-color);
    margin: 8px 0;
    animation: pulse 1.2s infinite;
}
.sk-line.short { width: 30%; }
.sk-line.tiny { width: 55%; height: 10px; }
@keyframes pulse {
    0%, 100% { opacity: 0.7; }
    50% { opacity: 0.35; }
}
</style>
