<template>
    <el-container class="layout">
        <el-header class="header">
            <div class="brand" @click="$router.push(adminPath('tickets'))">🎫 工单系统</div>
            <!-- 桌面端横向菜单 -->
            <el-menu v-if="!isMobile" mode="horizontal" :default-active="active" router :ellipsis="false" class="menu">
                <el-menu-item :index="adminPath('tickets')">我的工单</el-menu-item>
                <el-menu-item :index="adminPath('tickets/new')">新建工单</el-menu-item>
                <el-menu-item :index="adminPath('faqs')">知识库</el-menu-item>
                <el-menu-item v-if="auth.isStaff" :index="adminPath('dashboard')">统计看板</el-menu-item>
            <el-menu-item v-if="auth.isStaff" :index="adminPath('logs')">操作审计</el-menu-item>
                <el-menu-item v-if="auth.isAdmin" :index="adminPath('categories')">分类管理</el-menu-item>
                <el-menu-item v-if="auth.isAdmin" :index="adminPath('users')">用户管理</el-menu-item>
            </el-menu>
            <!-- 移动端：菜单收进底部 Tab（见底部 tabbar） -->
            <el-tooltip :content="dark ? '切换亮色模式' : '切换暗黑模式'" placement="bottom">
                <el-icon :size="20" class="theme-toggle" @click="dark = toggleTheme()">
                    <Moon v-if="!dark" />
                    <Sunny v-else />
                </el-icon>
            </el-tooltip>
            <NotificationBell />
            <el-dropdown @command="onCommand">
                <span class="user">
                    <el-avatar :size="30" :style="{ background: avatarColor(auth.user?.name) }">
                        {{ auth.user?.name?.[0] }}
                    </el-avatar>
                    <span class="user-name">{{ auth.user?.name }}（{{ ROLE_MAP[auth.user?.role] }}）</span>
                    <el-icon><ArrowDown /></el-icon>
                </span>
                <template #dropdown>
                    <el-dropdown-menu>
                        <el-dropdown-item command="logout">退出登录</el-dropdown-item>
                    </el-dropdown-menu>
                </template>
            </el-dropdown>
        </el-header>
        <el-main>
            <router-view />
        </el-main>

        <!-- 移动端底部 Tab 导航 -->
        <nav v-if="isMobile" class="p-tabbar">
            <div
                v-for="t in mobileTabs"
                :key="t.path"
                class="p-tab"
                :class="{ active: isActive(t.path) }"
                @click="$router.push(t.path)"
            >
                <el-icon :size="22"><component :is="t.icon" /></el-icon>
                <span>{{ t.label }}</span>
            </div>
        </nav>
    </el-container>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { ArrowDown, Moon, Sunny, Tickets, EditPen, Collection, DataLine, Files, User, List } from '@element-plus/icons-vue';
import NotificationBell from './NotificationBell.vue';
import { useAuthStore } from '../stores/auth';
import { toggleTheme } from '../theme';
import { avatarColor } from '../utils/avatar';
import { adminPath } from '../paths';
import { ROLE_MAP } from '../constants';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const active = computed(() => route.path);
const dark = ref(document.documentElement.classList.contains('dark'));
const isMobile = ref(window.matchMedia('(max-width: 768px)').matches);
window.matchMedia('(max-width: 768px)').addEventListener('change', (e) => { isMobile.value = e.matches; });

const mobileTabs = computed(() => [
    { path: adminPath('tickets'), label: '工单', icon: Tickets },
    { path: adminPath('tickets/new'), label: '新建', icon: EditPen },
    { path: adminPath('faqs'), label: '知识库', icon: Collection },
    ...(auth.isStaff ? [{ path: adminPath('dashboard'), label: '看板', icon: DataLine }, { path: adminPath('logs'), label: '审计', icon: List }] : []),
    ...(auth.isAdmin ? [{ path: adminPath('categories'), label: '分类', icon: Files }, { path: adminPath('users'), label: '用户', icon: User }] : []),
]);

function isActive(path) {
    if (path.endsWith('tickets')) return route.path === path;
    return route.path === path || route.path.startsWith(`${path}/`);
}

async function onCommand(cmd) {
    if (cmd === 'logout') {
        await auth.logout();
        ElMessage.success('已退出登录');
        router.push('/login');
    }
}
</script>

<style scoped>
.header {
    display: flex;
    align-items: center;
    background: #fff;
    border-bottom: 1px solid #e4e7ed;
    position: sticky;
    top: 0;
    z-index: 100;
}
.brand {
    font-size: 18px;
    font-weight: 600;
    margin-right: 32px;
    cursor: pointer;
    white-space: nowrap;
}
.menu {
    flex: 1;
    border-bottom: none;
}
.theme-toggle {
    cursor: pointer;
    margin-right: 20px;
    color: var(--el-text-color-primary);
    display: flex;
    align-items: center;
}
.user {
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}
.user-name {
    color: var(--el-text-color-primary);
}
.p-tabbar {
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
.p-tab {
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
.p-tab.active {
    color: var(--el-color-primary);
}
@media (max-width: 768px) {
    .user-name {
        display: none;
    }
    .layout {
        padding-bottom: calc(58px + env(safe-area-inset-bottom));
    }
    .el-main {
        padding-bottom: calc(70px + env(safe-area-inset-bottom));
    }
}
</style>
