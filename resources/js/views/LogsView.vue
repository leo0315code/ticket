<template>
    <el-card>
        <template #header><span>操作审计</span></template>

        <el-form inline style="margin-bottom: 8px">
            <el-form-item label="工单">
                <el-input v-model="filters.keyword" placeholder="工单号 / 标题" clearable style="width: 180px" @keyup.enter="load(1)" />
            </el-form-item>
            <el-form-item label="动作">
                <el-select v-model="filters.action" placeholder="全部" clearable style="width: 140px">
                    <el-option v-for="a in actions" :key="a" :label="ACTION_LABELS[a] || a" :value="a" />
                </el-select>
            </el-form-item>
            <el-form-item>
                <el-button type="primary" @click="load(1)">查询</el-button>
            </el-form-item>
        </el-form>

        <el-table :data="logs" v-loading="loading" stripe>
            <el-table-column label="时间" width="150" prop="created_at" />
            <el-table-column label="操作人" width="110">
                <template #default="{ row }">{{ row.user?.name || '—' }}</template>
            </el-table-column>
            <el-table-column label="动作" width="110">
                <template #default="{ row }">
                    <el-tag size="small" :type="actionType(row.action)">{{ ACTION_LABELS[row.action] || row.action }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="工单" min-width="200">
                <template #default="{ row }">
                    <el-link v-if="row.ticket" type="primary" @click="$router.push(ticketPath(row.ticket.id))">
                        {{ row.ticket.no }} · {{ row.ticket.title }}
                    </el-link>
                    <span v-else>—</span>
                </template>
            </el-table-column>
            <el-table-column prop="detail" label="详情" min-width="220" show-overflow-tooltip />
        </el-table>

        <el-pagination
            v-model:current-page="page"
            :page-size="20"
            :total="total"
            layout="total, prev, pager, next"
            style="margin-top: 14px; justify-content: flex-end"
            @current-change="load()"
        />
    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import http from '../api';
import { ticketPath } from '../paths';

const ACTION_LABELS = {
    created: '创建工单', replied: '回复', status_changed: '状态变更', assigned: '指派',
    priority_changed: '优先级调整', category_changed: '分类调整', rated: '满意度评价',
    attachment_uploaded: '上传附件', sla_breach: 'SLA 超时',
};

const logs = ref([]);
const actions = ref([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);
const filters = reactive({ keyword: '', action: '' });

function actionType(action) {
    return {
        status_changed: 'primary', assigned: 'warning', sla_breach: 'danger',
        attachment_uploaded: 'info', rated: 'success',
    }[action] || 'info';
}

async function load(p = page.value) {
    loading.value = true;
    page.value = p;
    try {
        const { data } = await http.get('/logs', {
            params: {
                page: p,
                keyword: filters.keyword || undefined,
                action: filters.action || undefined,
            },
        });
        logs.value = data.data;
        total.value = data.meta.total;
        actions.value = data['meta.actions'] || Object.keys(ACTION_LABELS);
    } finally {
        loading.value = false;
    }
}

onMounted(() => load(1));
</script>
