<template>
    <div>
        <el-row :gutter="12" class="overview" v-if="mine">
            <el-col :xs="8" :sm="4" v-for="c in overviewCards" :key="c.key">
                <el-card
                    class="ov-card"
                    :class="{ active: filters.status === (c.key === 'total' ? '' : c.key) }"
                    shadow="hover"
                    @click="pickStatus(c.key)"
                >
                    <span class="ov-num" :style="{ color: c.color }">{{ c.value }}</span>
                    <span class="ov-label">{{ c.label }}</span>
                </el-card>
            </el-col>
        </el-row>

        <el-card>
        <template #header>
            <div class="card-header">
                <span>工单列表</span>
                <div>
                    <el-button v-if="auth.isStaff" :loading="exporting" @click="exportCsv">导出 CSV</el-button>
                    <el-button type="primary" @click="$router.push('/admin/tickets/new')">新建工单</el-button>
                </div>
            </div>
        </template>

        <div class="filter-bar">
            <el-button v-if="isMobile" size="small" @click="showFilters = ! showFilters">
                筛选{{ hasActiveFilters ? ' ●' : '' }}
            </el-button>
        </div>
        <el-form v-show="! isMobile || showFilters" inline class="filters" :class="{ expanded: isMobile }">
            <el-form-item label="关键字">
                <el-input v-model="filters.keyword" placeholder="标题 / 工单号" clearable @keyup.enter="load(1)" style="width: 180px" />
            </el-form-item>
            <el-form-item label="状态">
                <el-select v-model="filters.status" placeholder="全部" clearable style="width: 120px">
                    <el-option v-for="(v, k) in STATUS_MAP" :key="k" :label="v.label" :value="k" />
                </el-select>
            </el-form-item>
            <el-form-item label="优先级">
                <el-select v-model="filters.priority" placeholder="全部" clearable style="width: 120px">
                    <el-option v-for="(v, k) in PRIORITY_MAP" :key="k" :label="v.label" :value="k" />
                </el-select>
            </el-form-item>
            <el-form-item label="分类">
                <el-select v-model="filters.category_id" placeholder="全部" clearable style="width: 140px">
                    <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
                </el-select>
            </el-form-item>
            <el-form-item v-if="auth.isStaff">
                <el-checkbox v-model="filters.mine" label="只看我受理的" />
            </el-form-item>
            <el-form-item v-if="auth.isStaff">
                <el-checkbox v-model="filters.overdue" label="只看已超时" @change="load(1)" />
            </el-form-item>
            <el-form-item>
                <el-button type="primary" @click="load(1)">查询</el-button>
                <el-button @click="reset">重置</el-button>
            </el-form-item>
        </el-form>

        <el-table :data="tickets" v-loading="loading" stripe>
            <el-table-column prop="no" label="工单号" width="160" class-name="hide-md" />
            <el-table-column prop="title" label="标题" min-width="220" show-overflow-tooltip>
                <template #default="{ row }">
                    <el-link type="primary" @click="$router.push(`/tickets/${row.id}`)">{{ row.title }}</el-link>
                </template>
            </el-table-column>
            <el-table-column label="状态" width="140">
                <template #default="{ row }">
                    <el-tag :type="STATUS_MAP[row.status].type" size="small">{{ STATUS_MAP[row.status].label }}</el-tag>
                    <el-tag v-if="row.overdue" type="danger" size="small" effect="dark" style="margin-left: 4px">超时</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="优先级" width="90">
                <template #default="{ row }">
                    <el-tag :type="PRIORITY_MAP[row.priority].type" effect="plain" size="small">{{ PRIORITY_MAP[row.priority].label }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="分类" width="110" class-name="hide-md">
                <template #default="{ row }">{{ row.category?.name || '-' }}</template>
            </el-table-column>
            <el-table-column label="提交人" width="120">
                <template #default="{ row }">
                    <div class="person">
                        <el-avatar :size="24" :style="{ background: avatarColor(row.user?.name) }">{{ row.user?.name?.[0] }}</el-avatar>
                        {{ row.user?.name }}
                    </div>
                </template>
            </el-table-column>
            <el-table-column v-if="auth.isStaff" label="受理客服" width="120">
                <template #default="{ row }">
                    <div v-if="row.agent" class="person">
                        <el-avatar :size="24" :style="{ background: avatarColor(row.agent.name) }">{{ row.agent.name?.[0] }}</el-avatar>
                        {{ row.agent.name }}
                    </div>
                    <span v-else style="color: var(--el-text-color-secondary)">-</span>
                </template>
            </el-table-column>
            <el-table-column prop="replies_count" label="回复" width="70" align="center" class-name="hide-md" />
            <el-table-column prop="created_at" label="创建时间" width="150" class-name="hide-sm" />
        </el-table>

        <el-pagination
            v-model:current-page="page"
            :page-size="10"
            :total="total"
            layout="total, prev, pager, next"
            style="margin-top: 16px; justify-content: flex-end"
            @current-change="load()"
        />
    </el-card>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import http from '../api';
import { avatarColor } from '../utils/avatar';
import { useAuthStore } from '../stores/auth';
import { STATUS_MAP, PRIORITY_MAP } from '../constants';

const auth = useAuthStore();
const tickets = ref([]);
const categories = ref([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);
const exporting = ref(false);
const mine = ref(null);
const showFilters = ref(false);
const isMobile = ref(window.matchMedia('(max-width: 768px)').matches);
window.matchMedia('(max-width: 768px)').addEventListener('change', (e) => { isMobile.value = e.matches; });
const hasActiveFilters = computed(() => !!(filters.keyword || filters.status || filters.priority || filters.category_id || filters.mine || filters.overdue));
const filters = reactive({ keyword: '', status: '', priority: '', category_id: '', mine: false, overdue: false });

const overviewCards = computed(() => {
    const s = mine.value?.by_status || {};
    return [
        { key: 'total', label: '全部', value: mine.value?.total ?? '-', color: '#409eff' },
        ...Object.entries(s).map(([k, v]) => ({
            key: k,
            label: STATUS_MAP[k].label,
            value: v,
            color: { open: '#e6a23c', in_progress: '#409eff', pending_resolved: '#67c23a', closed: '#909399' }[k],
        })),
    ];
});

function pickStatus(key) {
    filters.status = key === 'total' ? '' : key;
    load(1);
}

async function loadMine() {
    const { data } = await http.get('/stats/mine');
    mine.value = data;
}

async function exportCsv() {
    exporting.value = true;
    try {
        const res = await http.get('/tickets/export', {
            params: {
                keyword: filters.keyword || undefined,
                status: filters.status || undefined,
                priority: filters.priority || undefined,
                category_id: filters.category_id || undefined,
                mine: filters.mine ? 1 : undefined,
                overdue: filters.overdue ? 1 : undefined,
            },
            responseType: 'blob',
        });
        const url = URL.createObjectURL(res.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `工单导出-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
        URL.revokeObjectURL(url);
        ElMessage.success('已导出');
    } finally {
        exporting.value = false;
    }
}

async function load(p = page.value) {
    loading.value = true;
    page.value = p;
    try {
        const params = {
            page: p,
            keyword: filters.keyword || undefined,
            status: filters.status || undefined,
            priority: filters.priority || undefined,
            category_id: filters.category_id || undefined,
            mine: filters.mine ? 1 : undefined,
            overdue: filters.overdue ? 1 : undefined,
        };
        const { data } = await http.get('/tickets', { params });
        tickets.value = data.data;
        total.value = data.meta.total;
        loadMine(); // 保持概览计数与列表一致
    } finally {
        loading.value = false;
    }
}

function reset() {
    Object.assign(filters, { keyword: '', status: '', priority: '', category_id: '', mine: false, overdue: false });
    load(1);
}

onMounted(async () => {
    load(1);
    loadMine();
    const { data } = await http.get('/categories');
    categories.value = data.data;
});
</script>

<style scoped>
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.filters {
    margin-bottom: 4px;
}
.person {
    display: flex;
    align-items: center;
    gap: 6px;
}
.overview {
    margin-bottom: 16px;
}
.ov-card {
    cursor: pointer;
    text-align: center;
    padding: 4px 0;
    border-radius: 10px;
}
.ov-card.active {
    border-color: var(--el-color-primary);
}
.ov-num {
    font-size: 26px;
    font-weight: 700;
    margin-right: 8px;
}
.ov-label {
    color: var(--el-text-color-secondary);
    font-size: 13px;
}
@media (max-width: 768px) {
    .overview :deep(.el-col) {
        margin-bottom: 8px;
    }
    .ov-num {
        font-size: 20px;
    }
    /* 移动端隐藏次要表格列 */
    :deep(.hide-md) {
        display: none;
    }
    /* 筛选展开后全宽对齐 */
    .filters.expanded :deep(.el-form-item) {
        display: block;
        margin: 0 0 10px;
    }
    .filters.expanded :deep(.el-input),
    .filters.expanded :deep(.el-select) {
        width: 100% !important;
    }
    /* 卡片内边距收紧、头尾对齐 */
    :deep(.el-card__body),
    :deep(.el-card__header) {
        padding: 12px;
    }
    :deep(.el-card__header) {
        padding: 10px 12px;
    }
    /* 表格行距收紧 */
    :deep(.el-table .cell) {
        padding: 0 6px;
    }
    :deep(.el-table td) {
        padding: 6px 0;
    }
    .card-header {
        flex-wrap: wrap;
        gap: 8px;
    }
    .card-header > div {
        display: flex;
        gap: 8px;
    }
}
@media (max-width: 480px) {
    :deep(.hide-sm) {
        display: none;
    }
}
</style>
