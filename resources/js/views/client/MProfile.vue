<template>
    <div class="profile">
        <!-- 用户卡 -->
        <section class="hero">
            <div class="hero-main">
                <el-avatar :size="56" :style="{ background: avatarColor(auth.user?.name) }">{{ auth.user?.name?.[0] }}</el-avatar>
                <div class="who">
                    <div class="name">
                        {{ auth.user?.name }}
                        <span class="role">{{ auth.user?.role_label || '普通用户' }}</span>
                    </div>
                    <div class="sub">@{{ auth.user?.username }}</div>
                </div>
                <el-icon class="gear" :size="18" @click="openSettings"><Setting /></el-icon>
            </div>

            <div class="chips">
                <button class="chip" :class="{ on: !!auth.user?.phone }" @click="openSettings">
                    <el-icon><Iphone /></el-icon>{{ auth.user?.phone || '绑定手机号' }}
                </button>
                <button class="chip" :class="{ on: !!auth.user?.email }" @click="openSettings">
                    <el-icon><Message /></el-icon>{{ auth.user?.email || '绑定邮箱' }}
                </button>
                <button class="chip" :class="{ on: !!auth.user?.has_wechat }" @click="openSettings">
                    <el-icon><ChatDotRound /></el-icon>{{ auth.user?.has_wechat ? '已绑定微信' : '绑定微信' }}
                </button>
            </div>
        </section>

        <!-- 工单概览 -->
        <section class="card">
            <header class="card-head">
                <h3>我的工单</h3>
                <a @click="router.push('/m/tickets')">全部工单 ›</a>
            </header>
            <div class="stats">
                <button v-for="c in stats" :key="c.label" class="stat" @click="router.push('/m/tickets')">
                    <b>{{ c.value }}</b>
                    <span>{{ c.label }}</span>
                </button>
            </div>
        </section>

        <!-- 常用入口 -->
        <section class="card menu">
            <button class="row" @click="router.push('/m/new')">
                <span class="row-ico"><el-icon><EditPen /></el-icon></span>
                <span class="row-txt">提交新工单</span>
                <span class="row-arrow">›</span>
            </button>
            <button class="row" @click="router.push('/m/tickets')">
                <span class="row-ico"><el-icon><Tickets /></el-icon></span>
                <span class="row-txt">我的工单</span>
                <span class="row-arrow">›</span>
            </button>
            <button class="row" @click="openSettings">
                <span class="row-ico"><el-icon><User /></el-icon></span>
                <span class="row-txt">账号设置</span>
                <span class="row-arrow">{{ auth.user?.phone && auth.user?.email ? '已完善' : '待完善' }} ›</span>
            </button>
            <button class="row" @click="router.push('/help')">
                <span class="row-ico"><el-icon><QuestionFilled /></el-icon></span>
                <span class="row-txt">帮助中心</span>
                <span class="row-arrow">›</span>
            </button>
        </section>

        <button class="logout" @click="logout">退出登录</button>
        <p class="ver">{{ meta().site_name || '工单支持中心' }}</p>

        <el-dialog v-model="settingsVisible" title="账号设置" width="92%" style="max-width: 440px">
            <div class="dlg-label">基本资料</div>
            <el-form label-position="top">
                <el-form-item label="昵称">
                    <el-input v-model="profileForm.name" maxlength="50" placeholder="展示给客服的称呼" />
                </el-form-item>
                <el-form-item label="邮箱（选填）">
                    <el-input v-model="profileForm.email" placeholder="用于接收工单邮件通知" />
                </el-form-item>
            </el-form>

            <div class="dlg-label">登录与通知</div>
            <el-form label-position="top">
                <el-form-item label="手机号">
                    <div class="bind-value">
                        {{ auth.user?.phone || '未绑定' }}
                        <span v-if="!auth.user?.phone" class="bind-hint">绑定后可用验证码登录</span>
                    </div>
                    <div class="code-row">
                        <el-input v-model="phoneForm.phone" maxlength="11" placeholder="输入手机号" />
                        <el-button :disabled="countdown > 0 || sendingCode" :loading="sendingCode" @click="sendCode">
                            {{ countdown > 0 ? countdown + 's' : '获取验证码' }}
                        </el-button>
                    </div>
                    <div class="code-row" style="margin-top: 8px">
                        <el-input v-model="phoneForm.code" maxlength="6" placeholder="6 位验证码" />
                        <el-button type="primary" :loading="bindingPhone" @click="bindPhone">
                            {{ auth.user?.phone ? '更换' : '绑定' }}
                        </el-button>
                    </div>
                </el-form-item>

                <el-form-item label="微信">
                    <div v-if="auth.user?.has_wechat" class="bind-value">
                        已绑定 {{ auth.user?.wechat_nickname || '' }}
                        <el-button link type="danger" @click="unbindWechat">解绑</el-button>
                    </div>
                    <el-button v-else plain @click="bindWechat">
                        <el-icon style="margin-right: 4px"><ChatDotRound /></el-icon>微信扫码绑定
                    </el-button>
                </el-form-item>
            </el-form>

            <p class="dialog-tip">绑定手机号/微信后，可用验证码、扫码登录；不填邮箱不影响使用，通知仍会通过站内消息送达。</p>
            <template #footer>
                <el-button @click="settingsVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="saveSettings">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { ChatDotRound, EditPen, Iphone, Message, QuestionFilled, Setting, Tickets, User } from '@element-plus/icons-vue';
import { notifyError } from '../../notify';
import http from '../../api';
import { useAuthStore } from '../../stores/auth';
import { avatarColor } from '../../utils/avatar';
import { STATUS_MAP } from '../../constants';
import { meta } from '../../meta';

const auth = useAuthStore();
const router = useRouter();
const stats = ref([{ label: '全部', value: '–' }]);
const settingsVisible = ref(false);
const saving = ref(false);
const profileForm = reactive({ name: '', email: '' });
const phoneForm = reactive({ phone: '', code: '' });
const sendingCode = ref(false);
const bindingPhone = ref(false);
const countdown = ref(0);
let countdownTimer = null;

async function load() {
    const { data } = await http.get('/stats/mine');
    stats.value = [
        { label: '全部', value: data.total },
        ...Object.entries(data.by_status).map(([k, v]) => ({ label: STATUS_MAP[k].label, value: v })),
    ];
}

function openSettings() {
    profileForm.name = auth.user?.name || '';
    profileForm.email = auth.user?.email || '';
    settingsVisible.value = true;
}

async function sendCode() {
    if (!/^1[3-9]\d{9}$/.test(phoneForm.phone)) {
        notifyError('请输入正确的手机号');
        return;
    }
    sendingCode.value = true;
    try {
        await auth.sendBindPhoneCode(phoneForm.phone);
        ElMessage.success('验证码已发送');
        countdown.value = 60;
        clearInterval(countdownTimer);
        countdownTimer = setInterval(() => {
            countdown.value -= 1;
            if (countdown.value <= 0) clearInterval(countdownTimer);
        }, 1000);
    } catch (e) {
        notifyError(e.response?.data?.message || '验证码发送失败');
    } finally {
        sendingCode.value = false;
    }
}

async function bindPhone() {
    bindingPhone.value = true;
    try {
        await auth.bindPhone(phoneForm.phone, phoneForm.code);
        ElMessage.success('手机号已绑定');
        phoneForm.code = '';
    } catch (e) {
        notifyError(e.response?.data?.message || '绑定失败');
    } finally {
        bindingPhone.value = false;
    }
}

async function unbindWechat() {
    try {
        await auth.unbindWechat();
        ElMessage.success('微信已解绑');
    } catch (e) {
        notifyError(e.response?.data?.message || '解绑失败');
    }
}

async function bindWechat() {
    try {
        const { data } = await http.get('/auth/wechat/url');
        sessionStorage.setItem('wechat_state', data.state);
        sessionStorage.setItem('wechat_bind', '1');
        window.location.href = data.url;
    } catch (e) {
        notifyError(e.response?.data?.message || '微信登录未启用');
    }
}

async function saveSettings() {
    if (!profileForm.name.trim()) {
        notifyError('昵称不能为空');
        return;
    }
    saving.value = true;
    try {
        await auth.updateProfile({ name: profileForm.name.trim(), email: profileForm.email.trim() || null });
        settingsVisible.value = false;
        ElMessage.success('资料已更新');
    } catch (e) {
        const errors = e.response?.data?.errors;
        notifyError(e.response?.data?.message || (errors ? Object.values(errors).flat().join('；') : '保存失败'));
    } finally {
        saving.value = false;
    }
}

async function logout() {
    try {
        await ElMessageBox.confirm('退出后需要重新登录才能查看工单，确定退出吗？', '退出登录', {
            confirmButtonText: '退出',
            cancelButtonText: '取消',
            type: 'warning',
        });
    } catch {
        return;
    }
    await auth.logout();
    ElMessage.success('已退出登录');
    await router.replace('/login');
    if (router.currentRoute.value.path !== '/login') window.location.replace('/login');
}

onMounted(load);
</script>

<style scoped>
.profile {
    max-width: 520px;
    margin: 0 auto;
    padding: 12px 12px 24px;
}

/* 用户卡 */
.hero {
    border-radius: 18px;
    padding: 16px;
    background: linear-gradient(135deg, #2f6bff 0%, #5b8dff 55%, #7aa5ff 100%);
    color: #fff;
    box-shadow: 0 8px 24px rgba(47, 107, 255, 0.22);
}
.hero-main {
    display: flex;
    align-items: center;
    gap: 14px;
}
.who {
    flex: 1;
    min-width: 0;
}
.name {
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
.role {
    font-size: 11px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.2);
}
.sub {
    font-size: 13px;
    opacity: 0.85;
    margin-top: 3px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.gear {
    cursor: pointer;
    opacity: 0.85;
}
.gear:hover {
    opacity: 1;
}
.chips {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 14px;
}
.chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 0;
    cursor: pointer;
    font-size: 12px;
    padding: 5px 10px;
    border-radius: 999px;
    color: #fff;
    background: rgba(255, 255, 255, 0.16);
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.chip.on {
    background: rgba(255, 255, 255, 0.3);
}

/* 卡片 */
.card {
    margin-top: 12px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 16px;
    padding: 14px 14px 8px;
}
.card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.card-head h3 {
    margin: 0;
    font-size: 15px;
}
.card-head a {
    font-size: 12px;
    color: var(--el-color-primary);
    cursor: pointer;
}
.stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 4px;
    padding-bottom: 6px;
}
.stat {
    border: 0;
    background: none;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: 6px 0;
    border-radius: 10px;
}
.stat:hover {
    background: var(--el-fill-color-light);
}
.stat b {
    font-size: 18px;
    color: var(--el-text-color-primary);
}
.stat span {
    font-size: 11px;
    color: var(--el-text-color-secondary);
}

/* 菜单行 */
.menu {
    padding: 4px 6px;
    overflow: hidden;
}
.row {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    border: 0;
    background: none;
    cursor: pointer;
    padding: 13px 8px;
    text-align: left;
    color: var(--el-text-color-primary);
}
.row + .row {
    border-top: 1px solid var(--el-border-color-lighter);
}
.row:active {
    background: var(--el-fill-color-light);
}
.row-ico {
    width: 30px;
    height: 30px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--el-color-primary-light-9);
    color: var(--el-color-primary);
}
.row-txt {
    flex: 1;
    font-size: 14px;
}
.row-arrow {
    font-size: 12px;
    color: var(--el-text-color-placeholder);
}

.logout {
    margin-top: 16px;
    width: 100%;
    border: 1px solid var(--el-color-danger-light-5);
    background: var(--el-color-danger-light-9);
    color: var(--el-color-danger);
    border-radius: 14px;
    padding: 12px;
    font-size: 14px;
    cursor: pointer;
}
.ver {
    text-align: center;
    font-size: 11px;
    color: var(--el-text-color-placeholder);
    margin: 14px 0 0;
}

/* 弹窗 */
.dlg-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--el-text-color-secondary);
    margin: 6px 0 10px;
    padding-left: 8px;
    border-left: 3px solid var(--el-color-primary);
    line-height: 1;
}
.bind-value {
    font-size: 13px;
    color: var(--el-text-color-regular);
    margin-bottom: 6px;
}
.bind-hint {
    margin-left: 6px;
    color: var(--el-text-color-placeholder);
    font-size: 12px;
}
.code-row {
    display: flex;
    gap: 8px;
    width: 100%;
}
.code-row .el-input {
    flex: 1;
}
.dialog-tip {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    line-height: 1.7;
    margin: 4px 0 0;
}
</style>
