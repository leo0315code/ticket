<template>
    <el-card style="max-width: 960px">
        <template #header>
            <div class="card-header">
                <span>用户管理</span>
                <el-button type="primary" @click="openCreate">新增账号</el-button>
            </div>
        </template>

        <el-table :data="users" v-loading="loading" stripe>
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column prop="name" label="姓名" width="120" />
            <el-table-column prop="username" label="账号" width="120" />
            <el-table-column prop="email" label="邮箱" min-width="180" />
            <el-table-column prop="phone" label="手机号" width="130" />
            <el-table-column label="角色" width="150">
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
            <el-table-column prop="tickets_count" label="提交" width="70" align="center" />
            <el-table-column prop="assigned_tickets_count" label="受理" width="70" align="center" />
            <el-table-column label="操作" width="180" fixed="right">
                <template #default="{ row }">
                    <el-button link type="primary" @click="doReset(row)">重置密码</el-button>
                </template>
            </el-table-column>
        </el-table>

        <!-- 新增账号 -->
        <el-dialog v-model="createDialog" title="新增账号" width="460px">
            <el-form label-width="80px">
                <el-form-item label="姓名" required>
                    <el-input v-model="createForm.name" maxlength="50" placeholder="如：客服小王" />
                </el-form-item>
                <el-form-item label="账号" required>
                    <el-input v-model="createForm.username" maxlength="30" placeholder="登录用，2-30 位中文/字母/数字/下划线" />
                </el-form-item>
                <el-form-item label="角色" required>
                    <el-radio-group v-model="createForm.role">
                        <el-radio-button value="agent">客服</el-radio-button>
                        <el-radio-button value="admin">管理员</el-radio-button>
                        <el-radio-button value="user">普通用户</el-radio-button>
                    </el-radio-group>
                </el-form-item>
                <el-form-item label="邮箱">
                    <el-input v-model="createForm.email" placeholder="选填，用于邮件通知" />
                </el-form-item>
                <el-form-item label="初始密码" required>
                    <el-input v-model="createForm.password" placeholder="至少 8 位" show-password />
                    <el-button link size="small" @click="createForm.password = randomPassword()">随机生成</el-button>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="createDialog = false">取消</el-button>
                <el-button type="primary" :loading="creating" @click="create">创建</el-button>
            </template>
        </el-dialog>

        <!-- 重置密码结果（一次性展示） -->
        <el-dialog v-model="resetDialog" title="密码已重置" width="420px">
            <p style="margin: 0 0 8px">新密码（仅显示这一次，请立即转告本人）：</p>
            <el-input :model-value="resetPasswordValue" readonly>
                <template #append>
                    <el-button @click="copyReset">复制</el-button>
                </template>
            </el-input>
            <p style="color: var(--el-text-color-secondary); font-size: 12px; margin-top: 10px">
                该用户所有登录已失效，需用新密码重新登录。
            </p>
        </el-dialog>
    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import http from '../api';
import { useAuthStore } from '../stores/auth';
import { ROLE_MAP } from '../constants';

const auth = useAuthStore();
const users = ref([]);
const loading = ref(true);

const createDialog = ref(false);
const creating = ref(false);
const createForm = reactive({ name: '', username: '', email: '', password: '', role: 'agent' });

const resetDialog = ref(false);
const resetPasswordValue = ref('');

function randomPassword() {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    return Array.from({ length: 12 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
}

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/users');
        users.value = data.data;
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    Object.assign(createForm, { name: '', username: '', email: '', password: randomPassword(), role: 'agent' });
    createDialog.value = true;
}

async function create() {
    if (! createForm.name || ! createForm.username || ! createForm.password) {
        ElMessage.warning('请填写姓名、账号和初始密码');
        return;
    }
    creating.value = true;
    try {
        await http.post('/users', { ...createForm, email: createForm.email || null });
        createDialog.value = false;
        ElMessage.success(`账号 ${createForm.username} 已创建`);
        await load();
    } catch (e) {
        const errors = e.response?.data?.errors;
        ElMessage.error(errors ? Object.values(errors).flat()[0] : (e.response?.data?.message || '创建失败'));
    } finally {
        creating.value = false;
    }
}

async function doReset(row) {
    await ElMessageBox.confirm(`确认重置「${row.name}」的密码？其所有登录将失效。`, '重置密码', { type: 'warning' });
    const { data } = await http.put(`/users/${row.id}/reset-password`);
    resetPasswordValue.value = data.password;
    resetDialog.value = true;
}

function copyReset() {
    navigator.clipboard?.writeText(resetPasswordValue.value);
    ElMessage.success('已复制');
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

<style scoped>
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
</style>
