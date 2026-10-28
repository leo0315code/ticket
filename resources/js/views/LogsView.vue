<template>
    <el-card>
        <el-tabs v-model="tab" @tab-change="onTabChange">
            <el-tab-pane label="工单日志" name="ticket">

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
            <el-table-column label="时间" width="120">
                    <template #default="{ row }">
                        <el-tooltip :content="row.created_at" placement="top">
                            <span>{{ timeAgo(row.created_at) }}</span>
                        </el-tooltip>
                    </template>
                </el-table-column>
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
            </el-tab-pane>

            <el-tab-pane label="系统审计" name="system">
                <el-form inline style="margin-bottom: 8px">
                    <el-form-item label="关键字">
                        <el-input v-model="auditFilters.keyword" placeholder="操作人 / 对象" clearable style="width: 200px" @keyup.enter="loadAudit(1)" />
                    </el-form-item>
                    <el-form-item label="动作">
                        <el-select v-model="auditFilters.action" placeholder="全部" clearable style="width: 160px">
                            <el-option v-for="(label, value) in AUDIT_LABELS" :key="value" :label="label" :value="value" />
                        </el-select>
                    </el-form-item>
                    <el-form-item>
                        <el-button type="primary" @click="loadAudit(1)">查询</el-button>
                    </el-form-item>
                </el-form>

                <el-table :data="auditLogs" v-loading="auditLoading" stripe>
                    <el-table-column label="时间" width="120">
                    <template #default="{ row }">
                        <el-tooltip :content="row.created_at" placement="top">
                            <span>{{ timeAgo(row.created_at) }}</span>
                        </el-tooltip>
                    </template>
                </el-table-column>
                    <el-table-column label="操作人" width="120" prop="actor_name" />
                    <el-table-column label="动作" width="150">
                        <template #default="{ row }">
                            <el-tag size="small" type="warning">{{ AUDIT_LABELS[row.action] || row.action }}</el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="对象" min-width="180">
                        <template #default="{ row }">
                            <span v-if="row.target_label">{{ row.target_type }} · {{ row.target_label }}</span>
                            <span v-else>{{ row.target_type || '系统设置' }}</span>
                        </template>
                    </el-table-column>
                    <el-table-column label="变更" min-width="260">
                        <template #default="{ row }">{{ auditSummary(row) }}</template>
                    </el-table-column>
                    <el-table-column label="IP" width="130" prop="ip" />
                </el-table>

                <el-pagination
                    v-model:current-page="auditPage"
                    :page-size="20"
                    :total="auditTotal"
                    layout="total, prev, pager, next"
                    style="margin-top: 14px; justify-content: flex-end"
                    @current-change="loadAudit()"
                />
            </el-tab-pane>
        </el-tabs>
    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { timeAgo } from '../time';
import http from '../api';
import { notifyError } from '../notify';
import { ticketPath } from '../paths';

const ACTION_LABELS = {
    created: '创建工单', replied: '回复', status_changed: '状态变更', assigned: '指派',
    priority_changed: '优先级调整', category_changed: '分类调整', rated: '满意度评价',
    attachment_uploaded: '上传附件', sla_breach: 'SLA 超时',
};

const AUDIT_LABELS = {
    role_created: '创建角色', role_updated: '修改角色权限', role_deleted: '删除角色',
    user_created: '创建账号', user_role_changed: '变更用户角色', user_enabled: '启用账号',
    user_disabled: '停用账号', user_updated: '修改账号资料', user_password_reset: '重置密码',
    settings_updated: '修改系统设置',
};

const tab = ref('ticket');
const logs = ref([]);
const auditLogs = ref([]);
const auditTotal = ref(0);
const auditPage = ref(1);
const auditLoading = ref(false);
const auditFilters = reactive({ keyword: '', action: '' });
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

function onTabChange(name) {
    if (name === 'system' && auditLogs.value.length === 0) loadAudit(1);
}

async function loadAudit(p = auditPage.value) {
    auditLoading.value = true;
    auditPage.value = p;
    try {
        const { data } = await http.get('/audit-logs', {
            params: {
                page: p,
                keyword: auditFilters.keyword || undefined,
                action: auditFilters.action || undefined,
            },
        });
        auditLogs.value = data.data;
        auditTotal.value = data.total;
    } catch (e) {
        notifyError(e.response?.data?.message || '加载审计日志失败');
    } finally {
        auditLoading.value = false;
    }
}

function auditSummary(row) {
    const meta = row.meta || {};
    if (row.action === 'user_role_changed') return `${meta.before} → ${meta.after}`;
    if (row.action === 'settings_updated') {
        const keys = [...(meta.keys || []), ...(meta.secrets_changed || []).map((k) => k + '（密钥）')];
        return keys.join('、') || '无变更';
    }
    if (meta.keys) return meta.keys.join('、');
    return '';
}

onMounted(() => load(1));
</script>
