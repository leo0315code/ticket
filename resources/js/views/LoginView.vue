<template>
    <div class="login-page">
        <el-icon :size="22" class="theme-toggle" @click="dark = toggleTheme()">
            <Moon v-if="!dark" />
            <Sunny v-else />
        </el-icon>

        <div class="panel">
            <!-- 左侧品牌区 -->
            <div class="brand-side">
                <div class="brand-logo">🎫</div>
                <h1>{{ site.site_name }}</h1>
                <p class="slogan">问题提交 · 快速响应 · 全程可追踪</p>
                <ul class="features">
                    <li>⚡ SLA 时限保障，超时自动提醒客服</li>
                    <li>🔔 回复与状态变更实时通知</li>
                    <li>📎 支持附件，操作留痕可回溯</li>
                    <li>📚 知识库自助解决常见问题</li>
                </ul>
            </div>

            <!-- 右侧表单区 -->
            <div class="form-side">
                <el-tabs v-model="tab" stretch>
                    <el-tab-pane label="登录" name="login">
                        <el-form @submit.prevent="doLogin">
                            <el-form-item>
                                <el-input v-model="form.account" placeholder="用户名 / 邮箱" size="large" clearable>
                                    <template #prefix><el-icon><User /></el-icon></template>
                                </el-input>
                            </el-form-item>
                            <el-form-item>
                                <el-input v-model="form.password" type="password" placeholder="密码" show-password size="large" @keyup.enter="doLogin">
                                    <template #prefix><el-icon><Lock /></el-icon></template>
                                </el-input>
                            </el-form-item>
                            <el-button type="primary" size="large" class="submit" :loading="loading" @click="doLogin">登 录</el-button>
                        </el-form>
                        <div class="tips">
                            演示账号：xiaoming / xiaohong，密码 <b>password</b><br />
                            客服与管理员请从后台入口登录
                        </div>
                    </el-tab-pane>

                    <el-tab-pane v-if="site.allow_register" label="注册" name="register">
                        <el-form @submit.prevent="doRegister">
                            <el-form-item>
                                <el-input v-model="reg.name" placeholder="昵称" size="large">
                                    <template #prefix><el-icon><Postcard /></el-icon></template>
                                </el-input>
                            </el-form-item>
                            <el-form-item>
                                <el-input v-model="reg.username" placeholder="账号（2-30 位中文/字母/数字/下划线）" size="large">
                                    <template #prefix><el-icon><User /></el-icon></template>
                                </el-input>
                            </el-form-item>
                            <el-form-item>
                                <el-input v-model="reg.password" type="password" placeholder="密码（至少 8 位）" show-password size="large" @keyup.enter="doRegister">
                                    <template #prefix><el-icon><Lock /></el-icon></template>
                                </el-input>
                            </el-form-item>
                            <el-button type="primary" size="large" class="submit" :loading="loading" @click="doRegister">注 册</el-button>
                            <div class="tips">邮箱选填：注册后可在「我的 → 账号设置」绑定，用于接收邮件通知</div>
                        </el-form>
                    </el-tab-pane>
                </el-tabs>
                <el-alert v-if="error" :title="error" type="error" :closable="false" style="margin-top: 12px" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import { User, Lock, Postcard, Moon, Sunny } from '@element-plus/icons-vue';
import { useAuthStore } from '../stores/auth';
import { toggleTheme } from '../theme';
import { homePath } from '../paths';
import { loadMeta, meta } from '../meta';

const dark = ref(document.documentElement.classList.contains('dark'));
const tab = ref('login');
const loading = ref(false);
const error = ref('');
const form = reactive({ account: '', password: '' });
const reg = reactive({ name: '', username: '', password: '' });
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const redirectTo = typeof route.query.redirect === 'string' ? route.query.redirect : '';
// 站点信息（注册开关）决定是否展示注册入口
const site = reactive({ ...meta() });

onMounted(async () => Object.assign(site, await loadMeta()));

async function doLogin() {
    loading.value = true;
    error.value = '';
    try {
        await auth.login(form.account, form.password);
        if (auth.isStaff && ! redirectTo) {
            // 移动端仅普通用户：工作人员请走后台入口
            await auth.logout();
            error.value = '该账号为客服/管理员，请从后台入口登录';
            return;
        }
        // 有回跳目标（邮件/分享深链）时按目标走；客服会被路由再送到后台工单页
        router.push(redirectTo || '/m/tickets');
    } catch (e) {
        error.value = e.response?.data?.message || '登录失败';
    } finally {
        loading.value = false;
    }
}

async function doRegister() {
    loading.value = true;
    error.value = '';
    try {
        await auth.register(reg.name, reg.username, reg.password);
        ElMessage.success('注册成功，欢迎！');
        router.push('/m/tickets');
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
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    position: relative;
}
.theme-toggle {
    position: absolute;
    top: 24px;
    right: 28px;
    cursor: pointer;
    color: #fff;
}
.panel {
    display: flex;
    width: 860px;
    max-width: 94vw;
    min-height: 520px;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3);
}
.brand-side {
    flex: 1;
    background: linear-gradient(160deg, #5b6ee8, #8e54c9);
    color: #fff;
    padding: 56px 40px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.brand-logo {
    font-size: 44px;
}
.brand-side h1 {
    margin: 12px 0 8px;
    font-size: 28px;
}
.slogan {
    opacity: 0.9;
    margin-bottom: 28px;
}
.features {
    list-style: none;
    padding: 0;
    margin: 0;
    line-height: 2.2;
    font-size: 14px;
    opacity: 0.95;
}
.form-side {
    width: 400px;
    background: var(--el-bg-color);
    padding: 40px 36px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.submit {
    width: 100%;
    margin-top: 4px;
}
@media (max-width: 768px) {
    .brand-side {
        display: none;
    }
    .panel {
        width: 420px;
        padding: 28px 18px;
    }
    .login-page {
        background: linear-gradient(165deg, #5b6ee8 0%, #8e54c9 100%);
    }
    .form-side :deep(.el-tabs__nav) {
        width: 100%;
    }
    .form-side :deep(.el-tabs__item) {
        font-size: 16px;
        padding: 0 16px;
    }
    /* 输入放大（防 iOS 聚焦缩放） */
    .form-side :deep(.el-input__inner) {
        height: 46px;
        font-size: 16px;
    }
    .form-side :deep(.el-input__wrapper) {
        border-radius: 10px;
    }
    .form-side :deep(.el-input__inner::placeholder) {
        color: var(--el-text-color-secondary);
    }
    .submit {
        height: 48px;
        font-size: 16px;
        border-radius: 12px;
        letter-spacing: 2px;
        margin-top: 8px;
    }
    .tips {
        font-size: 12px;
        line-height: 1.9;
        border-radius: 10px;
        padding: 10px 14px;
    }
    .login-page :deep(.el-alert) {
        border-radius: 10px;
    }
}
.tips {
    margin-top: 18px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
    background: var(--el-fill-color-light);
    border-radius: 6px;
    padding: 8px 12px;
    line-height: 1.7;
}
</style>
