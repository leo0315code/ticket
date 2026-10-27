<template>
    <el-container class="layout">
        <!-- 桌面端左侧边栏 -->
        <el-aside v-if="!isMobile" width="200px" class="aside">
            <div class="brand" @click="$router.push(adminPath('tickets'))">🎫 工单系统</div>
            <el-menu :default-active="active" router class="side-menu">
                <el-menu-item :index="adminPath('tickets')">
                    <el-icon><Tickets /></el-icon><span>我的工单</span>
                </el-menu-item>
                <el-menu-item :index="adminPath('tickets/new')">
                    <el-icon><EditPen /></el-icon><span>新建工单</span>
                </el-menu-item>
                <el-menu-item :index="adminPath('faqs')">
                    <el-icon><Collection /></el-icon><span>知识库</span>
                </el-menu-item>
                <el-menu-item v-if="auth.can('stats.view')" :index="adminPath('dashboard')">
                    <el-icon><DataLine /></el-icon><span>统计看板</span>
                </el-menu-item>
                <el-menu-item v-if="auth.can('log.view')" :index="adminPath('logs')">
                    <el-icon><List /></el-icon><span>操作审计</span>
                </el-menu-item>
                <el-menu-item v-if="auth.can('category.manage')" :index="adminPath('categories')">
                    <el-icon><Files /></el-icon><span>分类管理</span>
                </el-menu-item>
                <el-menu-item v-if="auth.can('user.manage')" :index="adminPath('users')">
                    <el-icon><User /></el-icon><span>用户管理</span>
                </el-menu-item>
                <el-menu-item v-if="auth.can('role.manage')" :index="adminPath('roles')">
                    <el-icon><Lock /></el-icon><span>角色权限</span>
                </el-menu-item>
                <el-menu-item v-if="auth.can('settings.manage')" :index="adminPath('settings')">
                    <el-icon><Setting /></el-icon><span>系统设置</span>
                </el-menu-item>
            </el-menu>
        </el-aside>

        <el-container class="main-wrap">
            <el-header class="header">
                <div class="header-left">
                    <span class="page-crumb">{{ currentLabel }}</span>
                </div>
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
                        <span class="user-name">{{ auth.user?.name }}（{{ auth.user?.role_label || ROLE_MAP[auth.user?.role] || auth.user?.role }}）</span>
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
        </el-container>

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
import { ArrowDown, Moon, Sunny, Tickets, EditPen, Collection, DataLine, Files, User, List, Setting, Lock } from '@element-plus/icons-vue';
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

const LABELS = [
    [adminPath('tickets/new'), '新建工单'],
    [adminPath('tickets'), '工单管理'],
    [adminPath('faqs'), '知识库'],
    [adminPath('dashboard'), '统计看板'],
    [adminPath('logs'), '操作审计'],
    [adminPath('categories'), '分类管理'],
    [adminPath('users'), '用户管理'],
    [adminPath('roles'), '角色权限'],
    [adminPath('settings'), '系统设置'],
];
const currentLabel = computed(() => {
    const hit = LABELS.find(([p]) => isActive(p));
    return hit ? hit[1] : '工单系统';
});
const dark = ref(document.documentElement.classList.contains('dark'));
const isMobile = ref(window.matchMedia('(max-width: 768px)').matches);
window.matchMedia('(max-width: 768px)').addEventListener('change', (e) => { isMobile.value = e.matches; });

const mobileTabs = computed(() => [
    { path: adminPath('tickets'), label: '工单', icon: Tickets },
    { path: adminPath('tickets/new'), label: '新建', icon: EditPen },
    { path: adminPath('faqs'), label: '知识库', icon: Collection },
    ...(auth.can('stats.view') ? [{ path: adminPath('dashboard'), label: '看板', icon: DataLine }] : []),
    ...(auth.can('log.view') ? [{ path: adminPath('logs'), label: '审计', icon: List }] : []),
    ...(auth.can('category.manage') ? [{ path: adminPath('categories'), label: '分类', icon: Files }] : []),
    ...(auth.can('user.manage') ? [{ path: adminPath('users'), label: '用户', icon: User }] : []),
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
.layout {
    min-height: 100vh;
}
.aside {
    background: var(--el-bg-color);
    border-right: 1px solid var(--el-border-color-lighter);
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
}
.brand {
    font-size: 17px;
    font-weight: 700;
    padding: 18px 16px 12px;
    cursor: pointer;
    white-space: nowrap;
}
.side-menu {
    border-right: none;
}
.side-menu .el-menu-item {
    height: 46px;
    line-height: 46px;
}
.side-menu .el-menu-item.is-active {
    background: var(--el-color-primary-light-9);
    border-right: 2px solid var(--el-color-primary);
}
.main-wrap {
    background: var(--el-fill-color-lighter);
    min-height: 100vh;
}
.header {
    display: flex;
    align-items: center;
    gap: 16px;
    background: var(--el-bg-color);
    border-bottom: 1px solid var(--el-border-color-lighter);
    height: 56px;
}
.header-left {
    margin-right: auto;
}
.page-crumb {
    font-size: 15px;
    font-weight: 600;
}
.theme-toggle {
    cursor: pointer;
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
/* 移动端 */
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
    .header {
        padding: 0 12px;
    }
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
