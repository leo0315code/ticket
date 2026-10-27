<template>
    <div class="login-page">
        <el-icon :size="20" class="theme-toggle" @click="dark = toggleTheme()">
            <Moon v-if="!dark" />
            <Sunny v-else />
        </el-icon>

        <div class="card">
            <h1 class="title">{{ pane === 'register' ? '注册' + site.site_name : '登录' + site.site_name }}</h1>

            <div v-if="pane === 'login'" class="tabs">
                <button v-if="site.phone_login_enabled" :class="{ active: mode === 'phone' }" @click="mode = 'phone'">手机登录</button>
                <button :class="{ active: mode === 'password' }" @click="mode = 'password'">账号密码登录</button>
                <button v-if="site.wechat_login_enabled" :class="{ active: mode === 'wechat' }" @click="mode = 'wechat'">微信扫码</button>
            </div>

            <!-- 手机号验证码 -->
            <div v-if="pane === 'login' && mode === 'phone'" class="form">
                <label>手机号</label>
                <el-input v-model="phone.phone" maxlength="11" size="large" placeholder="请输入你的手机号" clearable />

                <label>验证码</label>
                <div class="code-row">
                    <el-input v-model="phone.code" maxlength="6" size="large" placeholder="请输入 6 位数字" />
                    <el-button class="code-btn" :disabled="countdown > 0 || phoneSending" :loading="phoneSending" @click="sendPhoneCode">
                        {{ countdown > 0 ? countdown + 's' : '发送验证码' }}
                    </el-button>
                </div>

                <el-button class="submit" :loading="loading" @click="doPhoneLogin">登 录</el-button>
                <p class="terms">手机验证后自动注册登录，登录即代表同意<a @click="$router.push('/help')">帮助中心</a>相关条款</p>
            </div>

            <!-- 账号密码 -->
            <div v-else-if="pane === 'login' && mode === 'password'" class="form">
                <label>账号</label>
                <el-input v-model="form.account" size="large" placeholder="用户名 / 邮箱" clearable />

                <label>密码</label>
                <el-input v-model="form.password" type="password" size="large" placeholder="请输入密码" show-password @keyup.enter="doLogin" />

                <el-button class="submit" :loading="loading" @click="doLogin">登 录</el-button>
                <p class="terms">
                    <a @click="$router.push('/forgot-password')">忘记密码？</a>
                    <a @click="$router.push('/help')">帮助中心</a>
                </p>
            </div>

            <!-- 微信扫码 -->
            <div v-else-if="pane === 'login'" class="form wechat">
                <el-button class="submit wechat-btn" :loading="wechatLoading" @click="doWechatLogin">微信扫码登录</el-button>
                <p class="terms">跳转微信授权页，扫码确认后自动回到这里；微信需先在「账号设置」中绑定</p>
            </div>

            <!-- 注册 -->
            <div v-else class="form">
                <label>昵称</label>
                <el-input v-model="reg.name" size="large" placeholder="展示给客服的称呼" />

                <label>账号</label>
                <el-input v-model="reg.username" size="large" placeholder="2-30 位中文 / 字母 / 数字 / 下划线" />

                <label>密码</label>
                <el-input v-model="reg.password" type="password" size="large" placeholder="至少 8 位" show-password @keyup.enter="doRegister" />

                <el-button class="submit" :loading="loading" @click="doRegister">注 册</el-button>
                <p class="terms">邮箱选填，注册后可在「我的 → 账号设置」绑定</p>
            </div>

            <el-alert v-if="error" :title="error" type="error" :closable="false" class="error" />

            <div class="foot">
                <template v-if="pane === 'register'">
                    <a @click="pane = 'login'">已有账号，去登录</a>
                </template>
                <template v-else>
                    <a v-if="site.allow_register" @click="pane = 'register'">注册账号</a>
                    <a @click="$router.push('/help')">帮助中心</a>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { Moon, Sunny } from '@element-plus/icons-vue';
import http from '../api';
import { useAuthStore } from '../stores/auth';
import { toggleTheme } from '../theme';
import { homePath } from '../paths';
import { loadMeta, meta } from '../meta';

const dark = ref(document.documentElement.classList.contains('dark'));
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const redirectTo = typeof route.query.redirect === 'string' ? route.query.redirect : '';

const site = reactive({ ...meta() });
const pane = ref('login');
// 登录方式：phone / password / wechat
const mode = ref(site.phone_login_enabled ? 'phone' : 'password');
const loading = ref(false);
const error = ref('');

const form = reactive({ account: '', password: '' });
const phone = reactive({ phone: '', code: '' });
const phoneSending = ref(false);
const countdown = ref(0);
let countdownTimer = null;
const wechatLoading = ref(false);
const reg = reactive({ name: '', username: '', password: '' });

onMounted(async () => {
    Object.assign(site, await loadMeta());
    // 开了手机号登录就默认用它（更省事），否则用账号密码
    if (site.phone_login_enabled && mode.value === 'password') mode.value = 'phone';
});

function afterLogin() {
    router.push(redirectTo || homePath());
}

async function doLogin() {
    loading.value = true;
    error.value = '';
    try {
        await auth.login(form.account, form.password);
        if (auth.isStaff && !redirectTo) {
            await auth.logout();
            error.value = '该账号为客服/管理员，请从后台入口登录';
            return;
        }
        afterLogin();
    } catch (e) {
        error.value = e.response?.data?.message || '登录失败';
    } finally {
        loading.value = false;
    }
}

async function sendPhoneCode() {
    if (!/^1[3-9]\d{9}$/.test(phone.phone)) {
        ElMessage.warning('请输入正确的手机号');
        return;
    }
    phoneSending.value = true;
    error.value = '';
    try {
        await http.post('/auth/phone/code', { phone: phone.phone });
        ElMessage.success('验证码已发送');
        countdown.value = 60;
        clearInterval(countdownTimer);
        countdownTimer = setInterval(() => {
            countdown.value -= 1;
            if (countdown.value <= 0) clearInterval(countdownTimer);
        }, 1000);
    } catch (e) {
        error.value = e.response?.data?.message || '验证码发送失败';
    } finally {
        phoneSending.value = false;
    }
}

async function doPhoneLogin() {
    loading.value = true;
    error.value = '';
    try {
        await auth.loginWithPhone(phone.phone, phone.code);
        afterLogin();
    } catch (e) {
        error.value = e.response?.data?.message || '登录失败';
    } finally {
        loading.value = false;
    }
}

async function doWechatLogin() {
    wechatLoading.value = true;
    error.value = '';
    try {
        const { data } = await http.get('/auth/wechat/url');
        sessionStorage.setItem('wechat_state', data.state);
        sessionStorage.setItem('wechat_redirect', redirectTo || '');
        window.location.href = data.url;
    } catch (e) {
        error.value = e.response?.data?.message || '微信登录暂不可用';
        wechatLoading.value = false;
    }
}

async function doRegister() {
    loading.value = true;
    error.value = '';
    try {
        await auth.register(reg.name, reg.username, reg.password);
        ElMessage.success('注册成功，欢迎！');
        afterLogin();
    } catch (e) {
        const errors = e.response?.data?.errors;
        error.value = e.response?.data?.message || (errors ? Object.values(errors).flat().join('；') : '注册失败');
    } finally {
        loading.value = false;
    }
}
</script>

<style scoped>
.login-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f2f5f8;
    position: relative;
    padding: 24px;
    box-sizing: border-box;
}
.theme-toggle {
    position: absolute;
    top: 22px;
    right: 26px;
    cursor: pointer;
    color: var(--el-text-color-secondary);
}
.card {
    width: 420px;
    max-width: 100%;
    background: var(--el-bg-color);
    border-radius: 8px;
    padding: 40px 36px 28px;
    box-shadow: 0 2px 16px rgba(31, 45, 61, 0.07);
}
.title {
    margin: 0 0 26px;
    font-size: 26px;
    font-weight: 600;
    letter-spacing: 1px;
}
.tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--el-border-color);
    margin-bottom: 6px;
}
.tabs button {
    background: none;
    border: none;
    padding: 0 4px 12px;
    margin-right: 26px;
    font-size: 15px;
    color: var(--el-text-color-regular);
    cursor: pointer;
    position: relative;
}
.tabs button.active {
    color: var(--el-color-primary);
    font-weight: 500;
}
.tabs button.active::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: -1px;
    height: 2px;
    background: var(--el-color-primary);
    border-radius: 2px;
}
label {
    display: block;
    margin: 18px 0 8px;
    font-size: 14px;
    color: var(--el-text-color-regular);
}
.code-row {
    display: flex;
    gap: 10px;
}
.code-row .el-input {
    flex: 1;
}
.code-btn {
    width: 116px;
    height: 40px;
    color: #fff;
    background: var(--el-color-primary);
    border-color: var(--el-color-primary);
}
.code-btn:hover {
    color: #fff;
    background: var(--el-color-primary-light-3);
    border-color: var(--el-color-primary-light-3);
}
.code-btn.is-disabled {
    color: #fff;
    background: var(--el-color-primary-light-5);
    border-color: var(--el-color-primary-light-5);
}
.submit {
    width: 100%;
    height: 44px;
    margin-top: 26px;
    font-size: 15px;
    letter-spacing: 4px;
    color: #fff;
    background: #33415c;
    border-color: #33415c;
}
.submit:hover {
    color: #fff;
    background: #26334d;
    border-color: #26334d;
}
.wechat-btn {
    background: #2aae67;
    border-color: #2aae67;
}
.wechat-btn:hover {
    background: #23995a;
    border-color: #23995a;
}
.terms {
    margin: 14px 0 0;
    font-size: 12px;
    line-height: 1.9;
    color: var(--el-text-color-secondary);
}
.terms a,
.foot a {
    color: var(--el-color-primary);
    cursor: pointer;
    text-decoration: none;
}
.terms a {
    margin-right: 14px;
}
.terms a:last-child {
    margin-right: 0;
}
.error {
    margin-top: 16px;
}
.foot {
    margin-top: 22px;
    padding-top: 16px;
    border-top: 1px solid var(--el-border-color-lighter);
    display: flex;
    gap: 18px;
    justify-content: flex-end;
    font-size: 13px;
}
@media (max-width: 480px) {
    .card {
        padding: 30px 22px 22px;
    }
    .title {
        font-size: 22px;
    }
}
</style>

<style>
/* 暗色模式：选择器要挂在 html 根节点上，放在 scoped 里会被 Vue 编译坏 */
html.dark .login-page {
    background: #0f1014;
}
html.dark .login-page .card {
    box-shadow: 0 2px 16px rgba(0, 0, 0, 0.5);
    border: 1px solid var(--el-border-color-lighter);
}
html.dark .login-page .theme-toggle {
    color: var(--el-text-color-secondary);
}
</style>
