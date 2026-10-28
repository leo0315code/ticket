<template>
    <div v-loading="loading">
        <div class="toolbar">
            <span class="toolbar-label">统计区间</span>
            <el-radio-group v-model="days" size="small" @change="load">
                <el-radio-button :value="7">近 7 天</el-radio-button>
                <el-radio-button :value="30">近 30 天</el-radio-button>
                <el-radio-button :value="90">近 90 天</el-radio-button>
            </el-radio-group>
            <el-button size="small" :loading="exporting" @click="exportCsv">导出 CSV</el-button>
            <span v-if="stats?.range" class="toolbar-tip">
                新增 {{ stats.range.new }} · 关闭 {{ stats.range.closed }} · 首次响应合规
                <b>{{ stats.range.sla_rate !== null ? stats.range.sla_rate + '%' : '—' }}</b>
                <template v-if="stats.range.responded">（{{ stats.range.on_time }}/{{ stats.range.responded }}）</template>
                · 解决合规
                <b>{{ stats.range.resolution_rate !== null ? stats.range.resolution_rate + '%' : '—' }}</b>
                <template v-if="stats.range.resolved">（{{ stats.range.resolved_on_time }}/{{ stats.range.resolved }}）</template>
                <template v-if="stats.range.resolution_overdue">· 超期 {{ stats.range.resolution_overdue }}</template>
            </span>
        </div>

        <el-row :gutter="16">
            <el-col :xs="12" :sm="6" v-for="c in cards" :key="c.label">
                <el-card>
                    <div class="stat-num" :style="{ color: c.color }">{{ c.value }}</div>
                    <div class="stat-label">{{ c.label }}</div>
                </el-card>
            </el-col>
        </el-row>

        <el-row :gutter="16" style="margin-top: 16px">
            <el-col :xs="24">
                <el-card header="客服绩效">
                    <el-table :data="stats?.by_agent || []" size="small" stripe>
                        <el-table-column prop="name" label="客服" width="120" />
                        <el-table-column prop="assigned" label="受理工单" align="center" />
                        <el-table-column prop="closed" label="已关闭" align="center" />
                        <el-table-column label="平均首次响应" align="center">
                            <template #default="{ row }">{{ row.avg_response_min !== null ? row.avg_response_min + ' 分钟' : '—' }}</template>
                        </el-table-column>
                        <el-table-column label="满意度" align="center">
                            <template #default="{ row }">
                                <el-link v-if="row.avg_rating !== null" type="primary" @click="showRatings(row)">{{ row.avg_rating }} ★</el-link>
                                <span v-else>—</span>
                            </template>
                        </el-table-column>
                    </el-table>
                </el-card>
            </el-col>
        </el-row>

        <!-- 评价明细 -->
        <el-dialog v-model="ratingsDialog" :title="`${ratingsAgent} 的评价明细`" width="640px">
            <el-table :data="ratingRows" size="small" stripe v-loading="ratingsLoading">
                <el-table-column label="评分" width="140">
                    <template #default="{ row }">
                        <el-rate :model-value="row.rating" disabled size="small" />
                    </template>
                </el-table-column>
                <el-table-column prop="user" label="评价人" width="100" />
                <el-table-column label="工单" min-width="180">
                    <template #default="{ row }">
                        <el-link type="primary" @click="$router.push(ticketPath(row.ticket_id))">{{ row.no }}</el-link>
                    </template>
                </el-table-column>
                <el-table-column prop="comment" label="评语" min-width="140" show-overflow-tooltip />
                <el-table-column prop="created_at" label="时间" width="100" />
            </el-table>
        </el-dialog>

        <el-row :gutter="16" style="margin-top: 16px">
            <el-col :xs="24" :sm="12">
                <el-card header="近 7 天新增工单">
                    <div ref="trendRef" class="chart"></div>
                </el-card>
            </el-col>
            <el-col :xs="24" :sm="6">
                <el-card header="状态分布">
                    <div ref="statusRef" class="chart"></div>
                </el-card>
            </el-col>
            <el-col :xs="24" :sm="6">
                <el-card header="优先级分布">
                    <div ref="priorityRef" class="chart"></div>
                </el-card>
            </el-col>
        </el-row>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import * as echarts from 'echarts';
import http from '../api';
import { ticketPath } from '../paths';
import { STATUS_MAP, PRIORITY_MAP } from '../constants';
import { ElMessage } from 'element-plus';
import { notifyError } from '../notify';

const stats = ref(null);
const ratingsDialog = ref(false);
const ratingsAgent = ref('');
const ratingRows = ref([]);
const ratingsLoading = ref(false);

async function exportCsv() {
    exporting.value = true;
    try {
        const { data } = await http.get('/stats/export', { params: { days: days.value }, responseType: 'blob' });
        const url = URL.createObjectURL(data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `工单统计-近${days.value}天.csv`;
        a.click();
        URL.revokeObjectURL(url);
        ElMessage.success('已导出 CSV');
    } catch {
        notifyError('导出失败');
    } finally {
        exporting.value = false;
    }
}

async function showRatings(row) {
    ratingsAgent.value = row.name;
    ratingsDialog.value = true;
    ratingsLoading.value = true;
    try {
        const { data } = await http.get('/stats/ratings', { params: { agent_id: row.id } });
        ratingRows.value = data.data;
    } finally {
        ratingsLoading.value = false;
    }
}
const loading = ref(true);
const days = ref(30);
const exporting = ref(false);
const trendRef = ref();
const statusRef = ref();
const priorityRef = ref();
let charts = [];

const cards = computed(() => [
    { label: '工单总数', value: stats.value?.total ?? '-', color: '#303133' },
    { label: '未处理（待处理 + 处理中）', value: stats.value?.unhandled ?? '-', color: '#e6a23c' },
    { label: 'SLA 已超时未响应', value: stats.value?.overdue ?? '-', color: '#f56c6c' },
    { label: '已关闭', value: stats.value?.by_status?.closed ?? '-', color: '#67c23a' },
]);

function renderCharts() {
    const isDark = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#a3a6ad' : '#606266';
    const lineColor = isDark ? '#333' : '#ebeef5';

    const trend = echarts.init(trendRef.value);
    trend.setOption({
        grid: { left: 32, right: 16, top: 24, bottom: 28 },
        tooltip: { trigger: 'axis' },
        xAxis: {
            type: 'category',
            data: stats.value.trend.map((t) => t.date),
            axisLine: { lineStyle: { color: lineColor } },
            axisLabel: { color: textColor },
        },
        yAxis: { type: 'value', minInterval: 1, axisLabel: { color: textColor }, splitLine: { lineStyle: { color: lineColor } } },
        series: [{
            type: 'line',
            data: stats.value.trend.map((t) => t.count),
            smooth: true,
            symbolSize: 8,
            itemStyle: { color: '#409eff' },
            areaStyle: { color: 'rgba(64,158,255,0.15)' },
        }],
    });

    const pie = (refEl, data) => {
        const chart = echarts.init(refEl.value);
        chart.setOption({
            tooltip: { trigger: 'item' },
            legend: { bottom: 0, textStyle: { color: textColor }, itemWidth: 12, itemHeight: 12 },
            series: [{
                type: 'pie',
                radius: ['42%', '68%'],
                center: ['50%', '42%'],
                data,
                label: { show: false },
                itemStyle: { borderRadius: 6, borderColor: isDark ? '#141414' : '#fff', borderWidth: 2 },
            }],
        });
        return chart;
    };

    charts = [
        trend,
        pie(statusRef, Object.entries(stats.value.by_status).map(([k, v]) => ({
            name: STATUS_MAP[k].label, value: v,
            itemStyle: { color: { open: '#e6a23c', in_progress: '#409eff', pending_resolved: '#67c23a', closed: '#909399' }[k] },
        }))),
        pie(priorityRef, Object.entries(stats.value.by_priority).map(([k, v]) => ({
            name: PRIORITY_MAP[k].label, value: v,
            itemStyle: { color: { low: '#909399', medium: '#409eff', high: '#e6a23c', urgent: '#f56c6c' }[k] },
        }))),
    ];
}

function onResize() {
    charts.forEach((c) => c.resize());
}

async function load() {
    loading.value = true;
    const { data } = await http.get('/stats', { params: { days: days.value } });
    stats.value = data;
    loading.value = false;
    charts.forEach((c) => c.dispose());
    charts = [];
    await nextTick();
    renderCharts();
}

onMounted(async () => {
    await load();
    window.addEventListener('resize', onResize);
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
    charts.forEach((c) => c.dispose());
});
</script>

<style scoped>
.toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.toolbar-label {
    color: var(--el-text-color-secondary);
    font-size: 13px;
}
.toolbar-tip {
    color: var(--el-text-color-secondary);
    font-size: 13px;
}
.toolbar-tip b {
    color: var(--el-color-success);
}
.stat-num {
    font-size: 32px;
    font-weight: 700;
}
.stat-label {
    color: var(--el-text-color-secondary);
    margin-top: 4px;
}
.chart {
    height: 280px;
}
</style>
