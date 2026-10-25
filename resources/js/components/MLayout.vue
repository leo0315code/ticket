<template>
    <div class="m-shell">
        <header class="m-header">
            <div class="m-brand" @click="$router.push('/m/tickets')">🎫 工单</div>
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
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { Tickets, EditPen, Collection, User } from '@element-plus/icons-vue';
import NotificationBell from './NotificationBell.vue';
import { useAuthStore } from '../stores/auth';
import { avatarColor } from '../utils/avatar';

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
    font-size: 17px;
    font-weight: 700;
    margin-right: auto;
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
    gap: 2px;
    padding: 8px 0 6px;
    font-size: 11px;
    color: var(--el-text-color-secondary);
    cursor: pointer;
}
.m-tab.active {
    color: var(--el-color-primary);
}
</style>
