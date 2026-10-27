<template>
    <div class="pw">
        <el-card class="pw-card">
            <template #header>
                <span class="pw-head">设置新密码</span>
            </template>

            <el-result v-if="done" icon="success" title="密码已重置" sub-title="请用新密码登录">
                <template #extra>
                    <el-button type="primary" @click="$router.push('/login')">去登录</el-button>
                </template>
            </el-result>

            <template v-else>
                <el-alert v-if="missing" type="warning" :closable="false" show-icon
                    title="链接不完整或已失效，请重新申请找回密码" />
                <template v-else>
                    <el-form label-position="top">
                        <el-form-item label="邮箱">
                            <el-input v-model="email" disabled />
                        </el-form-item>
                        <el-form-item label="新密码（至少 8 位）">
                            <el-input v-model="password" type="password" show-password autofocus />
                        </el-form-item>
                        <el-form-item label="确认新密码">
                            <el-input v-model="confirm" type="password" show-password @keyup.enter="submit" />
                        </el-form-item>
                        <el-button type="primary" :loading="loading" style="width: 100%" @click="submit">保存新密码</el-button>
                    </el-form>
                    <p class="hint">重置后所有设备都需要重新登录。</p>
                </template>
                <div class="links">
                    <el-link type="primary" underline="never" @click="$router.push('/forgot-password')">重新申请</el-link>
                </div>
            </template>
        </el-card>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { notifyError } from '../notify';
import http from '../api';

const route = useRoute();
const router = useRouter();

const token = ref('');
const email = ref('');
const password = ref('');
const confirm = ref('');
const loading = ref(false);
const done = ref(false);
const missing = ref(false);

onMounted(() => {
    token.value = String(route.query.token || '');
    email.value = String(route.query.email || '');
    missing.value = ! token.value || ! email.value;
});

async function submit() {
    if (password.value.length < 8) return notifyError('密码至少 8 位');
    if (password.value !== confirm.value) return notifyError('两次输入的密码不一致');

    loading.value = true;
    try {
        await http.post('/password/reset', {
            token: token.value,
            email: email.value,
            password: password.value,
            password_confirmation: confirm.value,
        });
        done.value = true;
        setTimeout(() => router.push('/login'), 1800);
    } catch (e) {
        notifyError(e.response?.data?.message || '重置失败，链接可能已过期');
    } finally {
        loading.value = false;
    }
}
</script>

<style scoped>
.pw {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--el-bg-color-page);
    padding: 24px;
}
.pw-card {
    width: min(420px, 100%);
}
.pw-head {
    font-weight: 600;
}
.hint {
    color: var(--el-text-color-secondary);
    font-size: 12px;
    margin-top: 12px;
}
.links {
    margin-top: 12px;
    text-align: center;
}
</style>
