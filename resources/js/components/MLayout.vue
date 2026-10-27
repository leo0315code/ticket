<template>
    <div class="m-shell">
        <header class="m-header">
            <div class="m-brand" @click="$router.push('/m/tickets')">
                <span class="m-logo">🎫</span>
                <span class="m-name">{{ site.site_name || '工单支持中心' }}</span>
            </div>
            <NotificationBell />
            <el-avatar :size="30" :style="{ background: avatarColor(auth.user?.name) }" @click="$router.push('/m/profile')">
                {{ auth.user?.name?.[0] }}
            </el-avatar>
        </header>

        <main class="m-body">
            <router-view />
        </main>

        <nav class="m-tabbar">
            <div
                v-for="t in tabs"
                :key="t.path"
                class="m-tab"
                :class="{ active: active.startsWith(t.path) }"
                @click="$router.push(t.path)"
            >
                <el-icon :size="22"><component :is="t.icon" /></el-icon>
                <span>{{ t.label }}</span>
            </div>
        </nav>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive } from 'vue';
import { useRoute } from 'vue-router';
import { Tickets, EditPen, Collection, User } from '@element-plus/icons-vue';
import NotificationBell from './NotificationBell.vue';
import { useAuthStore } from '../stores/auth';
import { avatarColor } from '../utils/avatar';
import { loadMeta, meta } from '../meta';

const site = reactive({ ...meta() });
onMounted(async () => Object.assign(site, await loadMeta()));

const auth = useAuthStore();
const route = useRoute();
const active = computed(() => route.path);

const tabs = [
    { path: '/m/tickets', label: '我的工单', icon: Tickets },
    { path: '/m/new', label: '提工单', icon: EditPen },
    { path: '/m/faqs', label: '知识库', icon: Collection },
    { path: '/m/profile', label: '我的', icon: User },
];
</script>

<style scoped>
.m-shell {
    min-height: 100vh;
    padding-bottom: calc(58px + env(safe-area-inset-bottom));
    background: var(--el-fill-color-lighter);
}
.m-header {
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 14px;
    padding-top: calc(10px + env(safe-area-inset-top));
    background: var(--el-bg-color);
    border-bottom: 1px solid var(--el-border-color-lighter);
}
.m-brand {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-right: auto;
    min-width: 0;
    cursor: pointer;
}
.m-logo {
    font-size: 17px;
}
.m-name {
    font-size: 16px;
    font-weight: 700;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.m-body {
    padding: 12px;
    max-width: 640px;
    margin: 0 auto;
}
.m-tabbar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 200;
    display: flex;
    background: var(--el-bg-color);
    border-top: 1px solid var(--el-border-color-lighter);
    padding-bottom: env(safe-area-inset-bottom);
}
.m-tab {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: 7px 0 5px;
    font-size: 10px;
    color: var(--el-text-color-secondary);
    cursor: pointer;
    transition: color 0.15s;
}
.m-tab .el-icon {
    padding: 3px 12px;
    border-radius: 999px;
    transition: background 0.15s;
}
.m-tab.active {
    color: var(--el-color-primary);
    font-weight: 600;
}
.m-tab.active .el-icon {
    background: var(--el-color-primary-light-9);
}
</style>
