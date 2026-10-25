<template>
    <div class="admin-login">
        <el-icon :size="22" class="theme-toggle" @click="dark = toggleTheme()">
            <Moon v-if="!dark" />
            <Sunny v-else />
        </el-icon>

        <el-card class="card">
            <div class="head">
                <div class="logo">🛡️</div>
                <h2>工单管理后台</h2>
                <p class="sub">仅限客服 / 管理员</p>
            </div>

            <el-form @submit.prevent="doLogin">
                <el-form-item>
                    <el-input v-model="form.account" placeholder="账号（用户名 / 邮箱）" size="large" clearable>
                        <template #prefix><el-icon><User /></el-icon></template>
                    </el-input>
                </el-form-item>
                <el-form-item>
                    <el-input v-model="form.password" type="password" placeholder="密码" show-password size="large" @keyup.enter="doLogin">
                        <template #prefix><el-icon><Lock /></el-icon></template>
                    </el-input>
                </el-form-item>
                <el-button type="primary" size="large" class="submit" :loading="loading" @click="doLogin">登录后台</el-button>
            </el-form>

            <el-alert v-if="error" :title="error" :type="errorType" :closable="false" style="margin-top: 14px" />
        </el-card>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { User, Lock, Moon, Sunny } from '@element-plus/icons-vue';
import { useAuthStore } from '../stores/auth';
import { toggleTheme } from '../theme';
import { adminPath } from '../paths';

const dark = ref(document.documentElement.classList.contains('dark'));
const loading = ref(false);
const error = ref('');
const errorType = ref('error');
const form = reactive({ account: '', password: '' });
const auth = useAuthStore();
const router = useRouter();

async function doLogin() {
    loading.value = true;
    error.value = '';
    try {
        await auth.login(form.account, form.password);
        if (! auth.isStaff) {
            // 普通用户不能从后台入口登录
            await auth.logout();
            errorType.value = 'warning';
            error.value = '该账号是普通用户，请使用用户端登录（客户端入口）';
            return;
        }
        router.push(adminPath('tickets'));
    } catch (e) {
        errorType.value = 'error';
        error.value = e.response?.data?.message || '登录失败';
    } finally {
        loading.value = false;
    }
}
</script>

<style scoped>
.admin-login {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(160deg, #1f2d45 0%, #2b3a55 60%, #22304a 100%);
    position: relative;
}
.theme-toggle {
    position: absolute;
    top: 24px;
    right: 28px;
    cursor: pointer;
    color: #cfd8e8;
}
.card {
    width: 380px;
    max-width: 92vw;
    padding: 8px 4px;
}
.head {
    text-align: center;
    margin-bottom: 18px;
}
.logo {
    font-size: 38px;
}
.head h2 {
    margin: 8px 0 4px;
}
.sub {
    color: var(--el-text-color-secondary);
    font-size: 13px;
    margin: 0;
}
.submit {
    width: 100%;
    margin-top: 6px;
}
</style>
