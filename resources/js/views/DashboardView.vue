<template>
    <div v-loading="loading">
        <el-row :gutter="16">
            <el-col :span="6" v-for="c in cards" :key="c.label">
                <el-card>
                    <div class="stat-num" :style="{ color: c.color }">{{ c.value }}</div>
                    <div class="stat-label">{{ c.label }}</div>
                </el-card>
            </el-col>
        </el-row>

        <el-row :gutter="16" style="margin-top: 16px">
            <el-col :span="12">
                <el-card header="近 7 天新增工单">
                    <div ref="trendRef" class="chart"></div>
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card header="状态分布">
                    <div ref="statusRef" class="chart"></div>
                </el-card>
            </el-col>
            <el-col :span="6">
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
import { STATUS_MAP, PRIORITY_MAP } from '../constants';

const stats = ref(null);
const loading = ref(true);
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

onMounted(async () => {
    const { data } = await http.get('/stats');
    stats.value = data;
    loading.value = false;
    await nextTick();
    renderCharts();
    window.addEventListener('resize', onResize);
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
    charts.forEach((c) => c.dispose());
});
</script>

<style scoped>
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
