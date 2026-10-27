<template>
    <div class="callback-page">
        <el-card class="box">
            <el-icon v-if="loading" :size="28" class="spinner"><Loading /></el-icon>
            <el-icon v-else-if="!error" :size="28" color="var(--el-color-success)"><CircleCheck /></el-icon>
            <el-icon v-else :size="28" color="var(--el-color-danger)"><CircleClose /></el-icon>

            <h3>{{ loading ? '正在完成微信授权…' : error ? '授权未完成' : qrConfirmed ? '登录成功' : '授权成功' }}</h3>
            <p v-if="error" class="msg">{{ error }}</p>
            <p v-else-if="qrConfirmed" class="msg">已确认登录，请回到电脑端继续，这个页面可以关掉了</p>
            <p v-else-if="!loading" class="msg">正在跳转，请稍候</p>

            <div class="actions">
                <el-button v-if="error" type="primary" @click="$router.replace('/login')">返回登录</el-button>
                <el-button v-if="qrConfirmed" type="primary" @click="closeOrHome">我知道了</el-button>
                <el-button v-if="error" @click="$router.replace('/help')">去帮助中心</el-button>
            </div>
        </el-card>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CircleCheck, CircleClose, Loading } from '@element-plus/icons-vue';
import http from '../api';
import { useAuthStore } from '../stores/auth';
import { homePath } from '../paths';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const loading = ref(true);
const error = ref('');
const qrConfirmed = ref(false);

function closeOrHome() {
    // 手机微信里关不掉页面，退到帮助中心即可
    router.replace('/help');
}

onMounted(async () => {
    const code = typeof route.query.code === 'string' ? route.query.code : '';
    const state = typeof route.query.state === 'string' ? route.query.state : sessionStorage.getItem('wechat_state') || '';

    if (!code) {
        loading.value = false;
        error.value = '你取消了微信授权，或微信返回的参数不完整';
        return;
    }

    // PC 扫码登录：state 以 qr_ 开头，scene 交给后端确认
    if (state.startsWith('qr_')) {
        try {
            await http.post('/auth/wechat/qr/confirm', { scene: state.slice(3), code });
            loading.value = false;
            qrConfirmed.value = true;
        } catch (e) {
            loading.value = false;
            error.value = e.response?.data?.message || '确认登录失败，请回到电脑端刷新二维码';
        }
        return;
    }

    const binding = sessionStorage.getItem('wechat_bind') === '1';
    const redirectTo = sessionStorage.getItem('wechat_redirect') || '';

    try {
        if (binding) {
            await auth.bindWechat(code, state);
            sessionStorage.removeItem('wechat_bind');
            sessionStorage.removeItem('wechat_state');
            sessionStorage.removeItem('wechat_redirect');
            router.replace('/m/profile');
            return;
        }
        await auth.loginWithWechat(code, state);
        sessionStorage.removeItem('wechat_state');
        sessionStorage.removeItem('wechat_redirect');
        router.replace(redirectTo || homePath());
    } catch (e) {
        loading.value = false;
        error.value = e.response?.data?.message || '微信授权失败，请重试';
    }
});
</script>

<style scoped>
.callback-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--el-bg-color-page);
}
.box {
    width: 360px;
    text-align: center;
    padding: 18px 6px;
}
.spinner {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
.msg {
    color: var(--el-text-color-secondary);
    font-size: 13px;
}
.actions {
    margin-top: 8px;
}
</style>
