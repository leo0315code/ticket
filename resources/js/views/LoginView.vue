<template>
    <div class="login-page">
        <el-icon :size="20" class="theme-toggle" @click="dark = toggleTheme()">
            <Moon v-if="!dark" />
            <Sunny v-else />
        </el-icon>

        <div class="card">
            <h1 class="title">登录{{ site.site_name }}</h1>

            <div class="tabs">
                <button v-if="site.phone_login_enabled" :class="{ active: mode === 'phone' }" @click="mode = 'phone'">手机登录</button>
                <button :class="{ active: mode === 'password' }" @click="mode = 'password'">账号密码登录</button>
                <button v-if="site.wechat_login_enabled" :class="{ active: mode === 'wechat' }" @click="mode = 'wechat'">微信扫码</button>
            </div>

            <!-- 手机号验证码 -->
            <div v-if="mode === 'phone'" class="form">
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
            <div v-else-if="mode === 'password'" class="form">
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
            <div v-else class="form wechat">
                <template v-if="useQr">
                    <img v-if="qrImage" :src="qrImage" class="qr" alt="微信扫码登录" />
                    <p v-if="qrExpired" class="terms">
                        二维码已过期，<el-button link type="primary" @click="startQr">刷新一下</el-button>
                    </p>
                    <p v-else class="terms">用微信扫一扫，在手机里确认授权后，电脑会自动登录</p>
                    <p class="terms muted">需先在手机或电脑登录，并在「账号设置」里绑定微信</p>
                </template>
                <template v-else>
                    <el-button class="submit wechat-btn" :loading="wechatLoading" @click="doWechatLogin">微信扫码登录</el-button>
                    <p class="terms">跳转微信授权页，扫码确认后自动回到这里；微信需先在「账号设置」中绑定</p>
                </template>
            </div>

            <el-alert v-if="error" :title="error" type="error" :closable="false" class="error" />

            <div class="foot">
                <a @click="$router.push('/help')">帮助中心</a>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { Moon, Sunny } from '@element-plus/icons-vue';
import http from '../api';
import { useAuthStore } from '../stores/auth';
import { toggleTheme } from '../theme';
import { homePath } from '../paths';
import { loadMeta, meta } from '../meta';
import QRCode from 'qrcode';

const dark = ref(document.documentElement.classList.contains('dark'));
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const redirectTo = typeof route.query.redirect === 'string' ? route.query.redirect : '';

const site = reactive({ ...meta() });
// 登录方式：password / phone / wechat；默认账号密码登录
const mode = ref('password');
const loading = ref(false);
const error = ref('');

const form = reactive({ account: '', password: '' });
const phone = reactive({ phone: '', code: '' });
const phoneSending = ref(false);
const countdown = ref(0);
let countdownTimer = null;
const wechatLoading = ref(false);
// 公众号扫码：PC 端展示二维码，手机微信授权，电脑轮询拿登录态
const useQr = computed(() => site.wechat_mode === 'mp' && !/MicroMessenger/i.test(navigator.userAgent));
const qrImage = ref('');
const qrScene = ref('');
const qrExpired = ref(false);
let qrTimer = null;

async function startQr() {
    qrExpired.value = false;
    qrImage.value = '';
    qrScene.value = '';
    error.value = '';
    try {
        const { data } = await http.post('/auth/wechat/qr');
        qrScene.value = data.scene;
        qrImage.value = await QRCode.toDataURL(data.url, { width: 220, margin: 1 });
        clearInterval(qrTimer);
        qrTimer = setInterval(pollQr, 2000);
    } catch (e) {
        error.value = e.response?.data?.message || '生成二维码失败';
    }
}

async function pollQr() {
    if (!qrScene.value) return;
    try {
        const { data } = await http.get(`/auth/wechat/qr/${qrScene.value}`);
        if (data.status === 'ok') {
            stopQr();
            auth.applySession(data.token, data.user);
            afterLogin();
        } else if (data.status === 'expired') {
            stopQr();
            qrExpired.value = true;
        }
    } catch {
        // 网络抖动继续轮询
    }
}

function stopQr() {
    clearInterval(qrTimer);
    qrTimer = null;
    qrScene.value = '';
}

watch(mode, (value) => {
    if (value === 'wechat' && useQr.value) {
        startQr();
    } else {
        stopQr();
    }
});

onUnmounted(stopQr);

onMounted(async () => {
    Object.assign(site, await loadMeta());
    if (mode.value === 'wechat' && useQr.value) startQr();
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
.qr {
    width: 220px;
    height: 220px;
    display: block;
    margin: 6px auto 10px;
    border-radius: 6px;
    background: #fff;
    padding: 6px;
    border: 1px solid var(--el-border-color-lighter);
}
.terms.muted {
    color: var(--el-text-color-placeholder);
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
