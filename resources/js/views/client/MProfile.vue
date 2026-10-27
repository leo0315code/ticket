<template>
    <div class="profile">
        <div class="head">
            <el-avatar :size="64" :style="{ background: avatarColor(auth.user?.name) }">{{ auth.user?.name?.[0] }}</el-avatar>
            <div>
                <div class="name">{{ auth.user?.name }}</div>
                <div class="sub">@{{ auth.user?.username }}</div>
                <div class="sub">{{ auth.user?.email || '未绑定邮箱' }}</div>
            </div>
        </div>

        <div v-if="!auth.user?.email" class="bind-tip">
            邮箱选填，不绑定也能正常收站内通知；绑定后可同时收邮件提醒。
        </div>

        <div class="stats">
            <div class="stat" v-for="c in stats" :key="c.label">
                <b>{{ c.value }}</b>
                <span>{{ c.label }}</span>
            </div>
        </div>

        <el-button style="width: 100%; margin-top: 18px" @click="openSettings">账号设置</el-button>
        <el-button type="danger" plain style="width: 100%; margin-top: 10px" @click="logout">退出登录</el-button>

        <el-dialog v-model="settingsVisible" title="账号设置" width="90%" style="max-width: 420px">
            <el-form label-position="top">
                <el-form-item label="昵称">
                    <el-input v-model="profileForm.name" maxlength="50" placeholder="展示给客服的称呼" />
                </el-form-item>
                <el-form-item label="邮箱（选填）">
                    <el-input v-model="profileForm.email" placeholder="用于接收工单邮件通知" />
                </el-form-item>
            </el-form>
            <div class="dialog-tip">不填邮箱不影响使用，所有通知都会通过站内消息送达。</div>
            <template #footer>
                <el-button @click="settingsVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="saveSettings">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import http from '../../api';
import { useAuthStore } from '../../stores/auth';
import { avatarColor } from '../../utils/avatar';
import { STATUS_MAP } from '../../constants';

const auth = useAuthStore();
const router = useRouter();
const stats = ref([{ label: '全部', value: '–' }]);
const settingsVisible = ref(false);
const saving = ref(false);
const profileForm = reactive({ name: '', email: '' });

async function load() {
    const { data } = await http.get('/stats/mine');
    stats.value = [
        { label: '全部', value: data.total },
        ...Object.entries(data.by_status).map(([k, v]) => ({ label: STATUS_MAP[k].label, value: v })),
    ];
}

function openSettings() {
    profileForm.name = auth.user?.name || '';
    profileForm.email = auth.user?.email || '';
    settingsVisible.value = true;
}

async function saveSettings() {
    if (!profileForm.name.trim()) {
        ElMessage.warning('昵称不能为空');
        return;
    }
    saving.value = true;
    try {
        await auth.updateProfile({ name: profileForm.name.trim(), email: profileForm.email.trim() || null });
        settingsVisible.value = false;
        ElMessage.success('资料已更新');
    } catch (e) {
        const errors = e.response?.data?.errors;
        ElMessage.error(e.response?.data?.message || (errors ? Object.values(errors).flat().join('；') : '保存失败'));
    } finally {
        saving.value = false;
    }
}

async function logout() {
    await auth.logout();
    ElMessage.success('已退出登录');
    router.push('/login');
}

onMounted(load);
</script>

<style scoped>
.profile {
    max-width: 480px;
    margin: 0 auto;
}
.head {
    display: flex;
    align-items: center;
    gap: 16px;
    background: var(--el-bg-color);
    border-radius: 14px;
    padding: 18px 16px;
    border: 1px solid var(--el-border-color-lighter);
}
.bind-tip {
    margin-top: 10px;
    padding: 8px 12px;
    border-radius: 10px;
    font-size: 12px;
    line-height: 1.6;
    color: var(--el-text-color-secondary);
    background: var(--el-fill-color-light);
}
.dialog-tip {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    line-height: 1.6;
}
.name {
    font-size: 18px;
    font-weight: 700;
}
.sub {
    color: var(--el-text-color-secondary);
    font-size: 13px;
    margin-top: 2px;
}
.stats {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 6px;
}
.stat {
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 10px;
    padding: 10px 4px;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.stat b {
    font-size: 17px;
}
.stat span {
    font-size: 11px;
    color: var(--el-text-color-secondary);
}
</style>
