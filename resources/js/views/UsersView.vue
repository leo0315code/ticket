<template>
    <el-card style="max-width: 860px">
        <template #header><span>用户管理</span></template>
        <el-table :data="users" v-loading="loading" stripe>
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column prop="name" label="姓名" width="120" />
            <el-table-column prop="email" label="邮箱" min-width="180" />
            <el-table-column label="手机号" width="130">
                <template #default="{ row }">{{ row.phone || '-' }}</template>
            </el-table-column>
            <el-table-column label="角色" width="160">
                <template #default="{ row }">
                    <el-select
                        :model-value="row.role"
                        size="small"
                        :disabled="row.id === auth.user?.id"
                        @change="(role) => changeRole(row, role)"
                    >
                        <el-option v-for="(v, k) in ROLE_MAP" :key="k" :label="v" :value="k" />
                    </el-select>
                </template>
            </el-table-column>
            <el-table-column prop="tickets_count" label="提交工单" width="90" align="center" />
            <el-table-column prop="assigned_tickets_count" label="受理工单" width="90" align="center" />
        </el-table>
    </el-card>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import http from '../api';
import { useAuthStore } from '../stores/auth';
import { ROLE_MAP } from '../constants';

const auth = useAuthStore();
const users = ref([]);
const loading = ref(true);

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/users');
        users.value = data.data;
    } finally {
        loading.value = false;
    }
}

async function changeRole(row, role) {
    try {
        await http.put(`/users/${row.id}`, { role });
        ElMessage.success(`已将 ${row.name} 的角色改为${ROLE_MAP[role]}`);
        await load();
    } catch (e) {
        ElMessage.error(e.response?.data?.message || '修改失败');
        await load();
    }
}

onMounted(load);
</script>
