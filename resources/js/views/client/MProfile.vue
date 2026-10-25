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

        <div class="stats">
            <div class="stat" v-for="c in stats" :key="c.label">
                <b>{{ c.value }}</b>
                <span>{{ c.label }}</span>
            </div>
        </div>

        <el-button type="danger" plain style="width: 100%; margin-top: 18px" @click="logout">退出登录</el-button>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import http from '../../api';
import { useAuthStore } from '../../stores/auth';
import { avatarColor } from '../../utils/avatar';
import { STATUS_MAP } from '../../constants';

const auth = useAuthStore();
const router = useRouter();
const stats = ref([{ label: '全部', value: '–' }]);

async function load() {
    const { data } = await http.get('/stats/mine');
    stats.value = [
        { label: '全部', value: data.total },
        ...Object.entries(data.by_status).map(([k, v]) => ({ label: STATUS_MAP[k].label, value: v })),
    ];
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
