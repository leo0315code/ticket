<template>
    <el-card v-loading="loading" style="max-width: 760px">
        <template #header>
            <div class="card-header">
                <span>系统设置</span>
                <el-button type="primary" :loading="saving" @click="save">保存设置</el-button>
            </div>
        </template>

        <el-alert
            type="info"
            :closable="false"
            show-icon
            title="修改后立即生效：SLA 与重开规则作用于之后新建或更新的工单，不会追溯已有工单"
            style="margin-bottom: 18px"
        />

        <h4 class="section">基本信息</h4>
        <el-form label-width="150px" style="max-width: 660px">
            <el-form-item label="站点名称">
                <el-input v-model="form.site_name" maxlength="50" />
            </el-form-item>
            <el-form-item label="开放注册">
                <el-switch v-model="form.allow_register" />
                <span class="hint">关闭后新用户无法自助注册，只能由管理员创建账号</span>
            </el-form-item>
            <el-form-item label="邮件通知">
                <el-switch v-model="form.notify_mail" />
                <span class="hint">关闭后只发站内信，不再发邮件</span>
            </el-form-item>
            <el-form-item label="单个附件上限">
                <el-input-number v-model="form.attach_max_mb" :min="1" :max="100" />
                <span class="hint">MB</span>
            </el-form-item>
        </el-form>

        <h4 class="section">登录方式</h4>
        <el-form label-width="150px" style="max-width: 660px">
            <el-form-item label="手机号验证码登录">
                <el-switch v-model="form.phone_login_enabled" />
                <span class="hint">开启后登录页出现「手机号验证码」入口；未注册的手机号验证通过后自动建号（受「开放注册」开关控制）</span>
            </el-form-item>
            <el-form-item label="短信通道">
                <el-select v-model="form.sms_driver" style="width: 180px">
                    <el-option label="写入日志（联调）" value="log" />
                    <el-option label="HTTP 网关" value="http" />
                </el-select>
                <span class="hint">日志模式验证码写在 storage/logs/laravel.log</span>
            </el-form-item>
            <el-form-item v-if="form.sms_driver === 'http'" label="短信网关地址">
                <el-input v-model="form.sms_http_url" placeholder="https://sms.example.com/send" style="width: 360px" />
            </el-form-item>
            <el-form-item label="短信签名">
                <el-input v-model="form.sms_sign" maxlength="30" style="width: 200px" />
            </el-form-item>
            <el-form-item label="验证码有效期">
                <el-input-number v-model="form.sms_code_ttl" :min="60" :max="1800" :step="30" />
                <span class="hint">秒</span>
            </el-form-item>

            <el-form-item label="微信扫码登录">
                <el-switch v-model="form.wechat_login_enabled" />
                <span class="hint">需在微信后台把回调地址加入授权回调域</span>
            </el-form-item>
            <el-form-item label="微信类型">
                <el-radio-group v-model="form.wechat_mode">
                    <el-radio-button value="open">开放平台（网站应用）</el-radio-button>
                    <el-radio-button value="mp">公众号（网页授权）</el-radio-button>
                </el-radio-group>
            </el-form-item>
            <el-form-item label="AppID">
                <el-input v-model="form.wechat_app_id" maxlength="100" style="width: 320px" />
            </el-form-item>
            <el-form-item label="AppSecret">
                <el-input v-model="form.wechat_app_secret" placeholder="留空表示不修改" show-password style="width: 320px" />
                <span v-if="form.wechat_app_secret_set" class="hint">已设置</span>
            </el-form-item>
            <el-form-item label="授权回调地址">
                <el-input :model-value="wechatCallback" readonly />
            </el-form-item>
        </el-form>

        <h4 class="section">响应时限（SLA）</h4>
        <el-form label-width="150px" style="max-width: 660px">
            <el-form-item v-for="p in priorities" :key="p.key" :label="`${p.label}工单`">
                <el-input-number v-model="form.sla_hours[p.key]" :min="1" :max="168" />
                <span class="hint">小时内首次响应，超时会在列表标红并提醒客服</span>
            </el-form-item>
        </el-form>

        <h4 class="section">工单生命周期</h4>
        <el-form label-width="150px" style="max-width: 660px">
            <el-form-item label="待确认自动关闭">
                <el-input-number v-model="form.auto_close_days" :min="1" :max="90" />
                <span class="hint">天无活动后自动关闭，客户仍可重开</span>
            </el-form-item>
            <el-form-item label="允许重开窗口">
                <el-input-number v-model="form.reopen_days" :min="0" :max="365" />
                <span class="hint">关闭后多少天内可重开</span>
            </el-form-item>
            <el-form-item label="单工单重开上限">
                <el-input-number v-model="form.reopen_limit" :min="0" :max="20" />
                <span class="hint">次，超过后提示新建工单</span>
            </el-form-item>
        </el-form>
    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import http from '../api';
import { loadMeta } from '../meta';

const priorities = [
    { key: 'urgent', label: '紧急' },
    { key: 'high', label: '高优' },
    { key: 'medium', label: '普通' },
    { key: 'low', label: '低优' },
];

const loading = ref(true);
const saving = ref(false);
const form = reactive({
    site_name: '',
    allow_register: true,
    notify_mail: true,
    attach_max_mb: 10,
    sla_hours: { urgent: 4, high: 8, medium: 24, low: 72 },
    auto_close_days: 7,
    reopen_days: 14,
    reopen_limit: 3,
    phone_login_enabled: false,
    sms_driver: 'log',
    sms_http_url: '',
    sms_sign: '工单支持中心',
    sms_code_ttl: 300,
    wechat_login_enabled: false,
    wechat_mode: 'open',
    wechat_app_id: '',
    wechat_app_secret: '',
    wechat_app_secret_set: false,
});

const wechatCallback = `${window.location.origin}/wechat/callback`;

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/settings');
        Object.assign(form, data);
    } catch (e) {
        ElMessage.error(e.response?.data?.message || '加载设置失败');
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        const { data } = await http.put('/settings', {
            ...form,
            sla_hours: { ...form.sla_hours },
        });
        Object.assign(form, data);
        if (data.site_name) document.title = data.site_name;
        // 元信息缓存同步刷新，附件上限等立即生效
        await loadMeta();
        ElMessage.success('设置已保存');
    } catch (e) {
        ElMessage.error(e.response?.data?.message || '保存失败');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.section {
    margin: 22px 0 12px;
    padding-left: 8px;
    border-left: 3px solid var(--el-color-primary);
    font-size: 14px;
    color: #303133;
}

.section:first-of-type {
    margin-top: 0;
}

.hint {
    margin-left: 10px;
    color: #909399;
    font-size: 13px;
}
</style>
