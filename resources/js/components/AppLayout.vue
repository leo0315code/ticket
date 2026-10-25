<template>
    <el-container class="layout">
        <el-header class="header">
            <div class="brand" @click="$router.push('/')">🎫 工单系统</div>
            <!-- 桌面端横向菜单 -->
            <el-menu v-if="!isMobile" mode="horizontal" :default-active="active" router :ellipsis="false" class="menu">
                <el-menu-item index="/">我的工单</el-menu-item>
                <el-menu-item index="/tickets/new">新建工单</el-menu-item>
                <el-menu-item index="/faqs">知识库</el-menu-item>
                <el-menu-item v-if="auth.isStaff" index="/dashboard">统计看板</el-menu-item>
                <el-menu-item v-if="auth.isAdmin" index="/categories">分类管理</el-menu-item>
                <el-menu-item v-if="auth.isAdmin" index="/users">用户管理</el-menu-item>
            </el-menu>
            <!-- 移动端抽屉菜单 -->
            <el-icon v-else :size="22" class="mobile-menu-btn" @click="mobileMenu = true"><Menu /></el-icon>
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

        <el-drawer v-model="mobileMenu" direction="rtl" size="60%" :with-header="false">
            <el-menu :default-active="active" router @select="mobileMenu = false">
                <el-menu-item index="/">我的工单</el-menu-item>
                <el-menu-item index="/tickets/new">新建工单</el-menu-item>
                <el-menu-item index="/faqs">知识库</el-menu-item>
                <el-menu-item v-if="auth.isStaff" index="/dashboard">统计看板</el-menu-item>
                <el-menu-item v-if="auth.isAdmin" index="/categories">分类管理</el-menu-item>
                <el-menu-item v-if="auth.isAdmin" index="/users">用户管理</el-menu-item>
            </el-menu>
        </el-drawer>
    </el-container>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { ArrowDown, Menu, Moon, Sunny } from '@element-plus/icons-vue';
import NotificationBell from './NotificationBell.vue';
import { useAuthStore } from '../stores/auth';
import { toggleTheme } from '../theme';
import { avatarColor } from '../utils/avatar';
import { ROLE_MAP } from '../constants';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const active = computed(() => route.path);
const dark = ref(document.documentElement.classList.contains('dark'));
const mobileMenu = ref(false);
const isMobile = ref(window.matchMedia('(max-width: 768px)').matches);
window.matchMedia('(max-width: 768px)').addEventListener('change', (e) => { isMobile.value = e.matches; });

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
.mobile-menu-btn {
    cursor: pointer;
    margin-right: 8px;
    flex: 1;
    color: var(--el-text-color-primary);
}
@media (max-width: 768px) {
    .user-name {
        display: none;
    }
}
</style>
