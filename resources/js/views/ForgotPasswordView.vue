<template>
    <div class="pw">
        <el-card class="pw-card">
            <template #header>
                <div class="pw-head">
                    <span>找回密码</span>
                    <el-link type="primary" underline="never" @click="$router.push('/login')">返回登录</el-link>
                </div>
            </template>

            <el-alert v-if="sent" type="success" :closable="false" show-icon :title="message" />
            <template v-else>
                <p class="tip">
                    输入你的账号或邮箱。若账号已绑定邮箱，我们会发送一封重置密码的邮件。
                </p>
                <el-form label-position="top" @submit.prevent="submit">
                    <el-form-item label="账号 / 邮箱">
                        <el-input v-model="account" placeholder="用户名或邮箱" autofocus @keyup.enter="submit" />
                    </el-form-item>
                    <el-button type="primary" :loading="loading" style="width: 100%" @click="submit">发送重置链接</el-button>
                </el-form>
                <p class="hint">
                    没绑定邮箱？请联系管理员重置密码，或在「我的 → 账号设置」里先绑定邮箱。
                </p>
            </template>

            <div class="links">
                <el-link type="primary" underline="never" @click="$router.push('/help')">帮助中心</el-link>
            </div>
        </el-card>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import http from '../api';

const account = ref('');
const loading = ref(false);
const sent = ref(false);
const message = ref('');

async function submit() {
    if (! account.value.trim()) return;
    loading.value = true;
    try {
        const { data } = await http.post('/password/forgot', { account: account.value.trim() });
        message.value = data.message;
        sent.value = true;
    } catch (e) {
        message.value = e.response?.data?.message || '发送失败，请稍后再试';
        sent.value = true;
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
    background: #f7f8fa;
    padding: 24px;
}
.pw-card {
    width: min(420px, 100%);
}
.pw-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
}
.tip {
    color: #606266;
    font-size: 13px;
    line-height: 1.6;
    margin: 0 0 12px;
}
.hint {
    color: #909399;
    font-size: 12px;
    line-height: 1.6;
    margin-top: 12px;
}
.links {
    margin-top: 12px;
    text-align: center;
}
</style>
