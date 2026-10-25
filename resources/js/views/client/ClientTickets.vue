<template>
    <div>
        <!-- 状态 chips：横滑 -->
        <div class="chips">
            <div
                v-for="c in chips"
                :key="c.key"
                class="chip"
                :class="{ active: status === (c.key === 'total' ? '' : c.key) }"
                @click="pick(c.key)"
            >
                <b>{{ c.value }}</b>
                <span>{{ c.label }}</span>
            </div>
        </div>

        <!-- 卡片列表 -->
        <div v-if="tickets.length" class="cards">
            <div v-for="t in tickets" :key="t.id" class="t-card" @click="$router.push(`/m/tickets/${t.id}`)">
                <div class="row1">
                    <el-tag :type="STATUS_MAP[t.status].type" size="small">{{ STATUS_MAP[t.status].label }}</el-tag>
                    <el-tag v-if="t.overdue" type="danger" size="small" effect="dark">超时</el-tag>
                    <el-tag :type="PRIORITY_MAP[t.priority].type" effect="plain" size="small">{{ PRIORITY_MAP[t.priority].label }}</el-tag>
                    <span class="replies">💬 {{ t.replies_count }}</span>
                </div>
                <div class="title">{{ t.title }}</div>
                <div class="meta">
                    <span>{{ t.no }}</span>
                    <span>{{ t.created_at }}</span>
                </div>
            </div>
        </div>
        <el-empty v-else-if="!loading" description="暂无工单" />

        <div v-if="loading" class="loading"><el-icon class="is-loading"><Loading /></el-icon></div>

        <el-button
            v-if="!loading && page < lastPage"
            style="width: 100%; margin-top: 12px"
            @click="load(page + 1)"
        >加载更多</el-button>

        <el-fab v-if="false" />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Loading } from '@element-plus/icons-vue';
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
            params: { page: p, status: status.value || undefined, per_page: 8 },
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
.chips {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 4px;
    scrollbar-width: none;
}
.chips::-webkit-scrollbar {
    display: none;
}
.chip {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 64px;
    padding: 8px 12px;
    border-radius: 12px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    cursor: pointer;
}
.chip.active {
    border-color: var(--el-color-primary);
    color: var(--el-color-primary);
}
.chip b {
    font-size: 18px;
}
.chip span {
    font-size: 11px;
    color: var(--el-text-color-secondary);
}
.chip.active span {
    color: var(--el-color-primary);
}
.cards {
    margin-top: 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.t-card {
    background: var(--el-bg-color);
    border-radius: 12px;
    padding: 12px 14px;
    border: 1px solid var(--el-border-color-lighter);
    cursor: pointer;
}
.t-card:active {
    background: var(--el-fill-color-light);
}
.row1 {
    display: flex;
    align-items: center;
    gap: 6px;
}
.replies {
    margin-left: auto;
    color: var(--el-text-color-secondary);
    font-size: 12px;
}
.title {
    font-size: 15px;
    font-weight: 600;
    margin: 8px 0 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.meta {
    display: flex;
    justify-content: space-between;
    color: var(--el-text-color-secondary);
    font-size: 12px;
}
.loading {
    text-align: center;
    padding: 16px;
    color: var(--el-color-primary);
}
</style>
