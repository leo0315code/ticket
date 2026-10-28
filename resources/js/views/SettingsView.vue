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

                    <el-tabs v-model="activeTab">
            <el-tab-pane label="基本信息" name="basic">
            <h4 class="section">基本信息</h4>

        <el-form label-width="150px" style="max-width: 660px">
            <el-form-item label="站点名称">
                <el-input v-model="form.site_name" maxlength="50" />
            </el-form-item>
            <el-form-item label="手机号自动建号">
                <el-switch v-model="form.allow_register" />
                <span class="hint">关闭后未注册的手机号验证通过也无法登录，账号只能由管理员在用户管理中创建</span>
            </el-form-item>
            <el-form-item label="邮件通知">
                <el-switch v-model="form.notify_mail" />
                <span class="hint">关闭后只发站内信，不再发邮件</span>
            </el-form-item>
            <el-form-item label="单个附件上限">
                <el-input-number v-model="form.attach_max_mb" :min="1" :max="100" />
                <span class="hint">MB</span>
            </el-form-item>
            <el-form-item label="每单附件数量">
                <el-input-number v-model="form.attach_max_count" :min="1" :max="50" />
                <span class="hint">个</span>
            </el-form-item>
            <el-form-item label="每单附件总容量">
                <el-input-number v-model="form.attach_quota_mb" :min="0" :max="10240" :step="100" />
                <span class="hint">MB，0 = 不限</span>
            </el-form-item>
        </el-form>

                    </el-tab-pane>
            <el-tab-pane label="登录方式" name="login">
            <h4 class="section">登录方式</h4>

        <el-form label-width="150px" style="max-width: 660px">
            <el-form-item label="手机号验证码登录">
                <el-switch v-model="form.phone_login_enabled" />
                <span class="hint">开启后登录页出现「手机号验证码」入口；未注册的手机号验证通过后自动建号（受「开放注册」开关控制）</span>
            </el-form-item>
            <el-form-item label="短信平台">
                <el-select v-model="form.sms_provider" style="width: 220px">
                    <el-option label="写入日志（联调，不发真短信）" value="log" />
                    <el-option label="阿里云短信" value="aliyun" />
                    <el-option label="腾讯云短信" value="tencent" />
                    <el-option label="云片短信" value="yunpian" />
                    <el-option label="自定义 HTTP 网关" value="http" />
                </el-select>
                <span class="hint">验证码短信走哪个平台；日志模式只写到 storage/logs/laravel.log</span>
            </el-form-item>

            <template v-if="form.sms_provider === 'aliyun'">
                <el-form-item label="AccessKey ID">
                    <el-input v-model="form.sms_aliyun_key" style="width: 320px" />
                </el-form-item>
                <el-form-item label="AccessKey Secret">
                    <el-input v-model="form.sms_aliyun_secret" show-password placeholder="留空表示不修改" style="width: 320px" />
                    <span v-if="form.sms_aliyun_secret_set" class="hint">已设置</span>
                </el-form-item>
                <el-form-item label="短信签名">
                    <el-input v-model="form.sms_aliyun_sign" maxlength="30" style="width: 200px" />
                </el-form-item>
                <el-form-item label="模板 CODE">
                    <el-input v-model="form.sms_aliyun_template" placeholder="如 SMS_123456789" style="width: 240px" />
                    <span class="hint">模板变量用 ${code}</span>
                </el-form-item>
            </template>

            <template v-else-if="form.sms_provider === 'tencent'">
                <el-form-item label="SecretId">
                    <el-input v-model="form.sms_tencent_secret_id" style="width: 320px" />
                </el-form-item>
                <el-form-item label="SecretKey">
                    <el-input v-model="form.sms_tencent_secret_key" show-password placeholder="留空表示不修改" style="width: 320px" />
                    <span v-if="form.sms_tencent_secret_key_set" class="hint">已设置</span>
                </el-form-item>
                <el-form-item label="短信应用 SdkAppId">
                    <el-input v-model="form.sms_tencent_app_id" placeholder="1400xxxxxx" style="width: 200px" />
                </el-form-item>
                <el-form-item label="短信签名">
                    <el-input v-model="form.sms_tencent_sign" maxlength="30" style="width: 200px" />
                </el-form-item>
                <el-form-item label="模板 ID">
                    <el-input v-model="form.sms_tencent_template" placeholder="如 1234567" style="width: 200px" />
                </el-form-item>
                <el-form-item label="地域">
                    <el-input v-model="form.sms_tencent_region" placeholder="ap-guangzhou" style="width: 200px" />
                </el-form-item>
            </template>

            <template v-else-if="form.sms_provider === 'yunpian'">
                <el-form-item label="APIKEY">
                    <el-input v-model="form.sms_yunpian_key" show-password placeholder="留空表示不修改" style="width: 320px" />
                    <span v-if="form.sms_yunpian_key_set" class="hint">已设置</span>
                </el-form-item>
            </template>

            <el-form-item v-if="form.sms_provider === 'http'" label="短信网关地址">
                <el-input v-model="form.sms_http_url" placeholder="https://sms.example.com/send" style="width: 360px" />
            </el-form-item>

            <el-form-item v-if="['http', 'yunpian'].includes(form.sms_provider)" label="短信签名">
                <el-input v-model="form.sms_sign" maxlength="30" style="width: 200px" />
            </el-form-item>

            <el-form-item label="验证码有效期">
                <el-input-number v-model="form.sms_code_ttl" :min="60" :max="1800" :step="30" />
                <span class="hint">秒</span>
            </el-form-item>

            <el-form-item label="测试发送">
                <el-input v-model="testPhone" maxlength="11" placeholder="接收测试短信的手机号" style="width: 200px" />
                <el-button :loading="testing" style="margin-left: 10px" @click="sendTest">发送测试短信</el-button>
                <span class="hint">用当前平台发一条「123456」，保存后再测</span>
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
            <el-form-item label=" " v-if="form.wechat_mode === 'mp'">
                <span class="hint">公众号模式：电脑上显示二维码，手机微信扫码授权后电脑自动登录；微信内打开则直接跳授权。AppID/AppSecret 填公众号的，并在公众号后台配置「网页授权域名」</span>
            </el-form-item>
            <el-form-item label=" " v-else>
                <span class="hint">开放平台模式：电脑端用开放平台网站应用的二维码扫码；AppID/AppSecret 填网站应用的，并在开放平台配置授权回调域</span>
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

            <el-divider content-position="left">模板消息通知</el-divider>
            <el-form-item label="启用模板消息">
                <el-switch v-model="form.wechat_notify_enabled" />
                <span class="hint">客服回复 / 工单状态变更时，给已绑定公众号的客户推微信提醒（需已认证服务号）</span>
            </el-form-item>
            <el-form-item label="新回复模板 ID" v-if="form.wechat_notify_enabled">
                <el-input v-model="form.wechat_reply_template_id" maxlength="64" style="width: 320px" placeholder="公众号后台「模板消息」里添加" />
            </el-form-item>
            <el-form-item label="状态变更模板 ID" v-if="form.wechat_notify_enabled">
                <el-input v-model="form.wechat_status_template_id" maxlength="64" style="width: 320px" placeholder="留空则状态变更不推送" />
            </el-form-item>
            <el-form-item label=" " v-if="form.wechat_notify_enabled">
                <span class="hint">模板字段顺序需为：first / keyword1（工单号）/ keyword2（工单标题）/ keyword3（时间）/ remark</span>
            </el-form-item>
        </el-form>

                    </el-tab-pane>
            <el-tab-pane label="SLA 时限" name="sla">
            <h4 class="section">响应时限（SLA）</h4>

        <el-form label-width="150px" style="max-width: 660px">
            <el-form-item v-for="p in priorities" :key="p.key" :label="`${p.label}工单`">
                <el-input-number v-model="form.sla_hours[p.key]" :min="1" :max="168" />
                <span class="hint">小时内首次响应，超时会在列表标红并提醒客服</span>
            </el-form-item>
        </el-form>

                    <h4 class="section">解决时限（SLA）</h4>

        <el-form label-width="150px" style="max-width: 660px">
            <el-form-item v-for="p in priorities" :key="`r-${p.key}`" :label="`${p.label}工单`">
                <el-input-number v-model="form.resolution_hours[p.key]" :min="1" :max="720" />
                <span class="hint">营业小时内解决，仅用于达标率统计与超期提醒</span>
            </el-form-item>
            <el-form-item label="按营业时间计算">
                <el-switch v-model="form.business_hours_enabled" />
                <span class="hint">开启后非工作时间不计入解决时限</span>
            </el-form-item>
            <template v-if="form.business_hours_enabled">
                <el-form-item label="工作时间">
                    <el-time-select v-model="form.business_hours_start" start="00:00" step="00:30" end="23:30" style="width: 130px" />
                    <span style="margin: 0 8px">至</span>
                    <el-time-select v-model="form.business_hours_end" start="00:30" step="00:30" end="23:59" style="width: 130px" />
                </el-form-item>
                <el-form-item label="工作日">
                    <el-checkbox-group v-model="form.business_hours_days">
                        <el-checkbox v-for="(label, idx) in weekdays" :key="idx" :value="idx + 1">{{ label }}</el-checkbox>
                    </el-checkbox-group>
                </el-form-item>
                <el-form-item label="时区">
                    <el-select v-model="form.business_timezone" filterable style="width: 240px">
                        <el-option label="Asia/Shanghai（北京时间）" value="Asia/Shanghai" />
                        <el-option label="Asia/Hong_Kong" value="Asia/Hong_Kong" />
                        <el-option label="UTC" value="UTC" />
                    </el-select>
                </el-form-item>
            </template>
        </el-form>

                    </el-tab-pane>
            <el-tab-pane label="生命周期" name="lifecycle">
            <h4 class="section">工单生命周期</h4>

        <el-form-item label="新工单自动分配">
            <el-switch v-model="form.auto_assign" />
            <span class="hint">开启后优先指派给分类的默认客服，否则给未结工单最少的客服</span>
        </el-form-item>
        <el-form-item label="无客服在线时禁提单">
            <el-switch v-model="form.require_online_agent" />
            <span class="hint">开启后，普通用户在无客服在线（5 分钟内无活动）时不能提交工单</span>
        </el-form-item>
        <el-form-item label="紧急联系电话">
            <el-input v-model="form.support_phone" maxlength="30" placeholder="如 400-800-1234，留空不显示" style="width: 240px" />
            <span class="hint">显示在帮助中心顶部；非服务时间或无客服在线时的拦截提示里也会带上</span>
        </el-form-item>
        <el-form-item label="服务时间">
            <span class="hint">复用上方「按营业时间计算」的工作时间与工作日：开启后非服务时段同样拦截提单</span>
        </el-form-item>
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
        </el-form>            </el-tab-pane>
            </el-tabs>

    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { notifyError } from '../notify';
import http from '../api';
import { loadMeta } from '../meta';

const weekdays = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'];
const priorities = [
    { key: 'urgent', label: '紧急' },
    { key: 'high', label: '高优' },
    { key: 'medium', label: '普通' },
    { key: 'low', label: '低优' },
];

const loading = ref(true);
const saving = ref(false);
const activeTab = ref('basic');
const form = reactive({
    site_name: '',
    allow_register: true,
    notify_mail: true,
    attach_max_mb: 10,
    attach_max_count: 10,
    attach_quota_mb: 0,
    sla_hours: { urgent: 4, high: 8, medium: 24, low: 72 },
    resolution_hours: { urgent: 24, high: 48, medium: 72, low: 120 },
    business_hours_enabled: false,
    business_hours_start: '09:00',
    business_hours_end: '18:00',
    business_hours_days: [1, 2, 3, 4, 5],
    business_timezone: 'Asia/Shanghai',
    auto_assign: false,
    require_online_agent: false,
    support_phone: '',
    auto_close_days: 7,
    reopen_days: 14,
    reopen_limit: 3,
    phone_login_enabled: false,
    sms_provider: 'log',
    sms_aliyun_key: '',
    sms_aliyun_secret: '',
    sms_aliyun_sign: '',
    sms_aliyun_template: '',
    sms_aliyun_secret_set: false,
    sms_tencent_secret_id: '',
    sms_tencent_secret_key: '',
    sms_tencent_app_id: '',
    sms_tencent_sign: '',
    sms_tencent_template: '',
    sms_tencent_region: 'ap-guangzhou',
    sms_tencent_secret_key_set: false,
    sms_yunpian_key: '',
    sms_yunpian_key_set: false,
    sms_http_url: '',
    sms_sign: '工单支持中心',
    sms_code_ttl: 300,
    wechat_login_enabled: false,
    wechat_mode: 'open',
    wechat_notify_enabled: false,
    wechat_reply_template_id: '',
    wechat_status_template_id: '',
    wechat_app_id: '',
    wechat_app_secret: '',
    wechat_app_secret_set: false,
});

const wechatCallback = `${window.location.origin}/wechat/callback`;
const testPhone = ref('');
const testing = ref(false);

async function sendTest() {
    if (!/^1[3-9]\d{9}$/.test(testPhone.value)) {
        notifyError('请输入正确的手机号');
        return;
    }
    testing.value = true;
    try {
        const { data } = await http.post('/settings/sms-test', { phone: testPhone.value });
        ElMessage.success(data.message || '已发送');
    } catch (e) {
        notifyError(e.response?.data?.message || '发送失败');
    } finally {
        testing.value = false;
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/settings');
        Object.assign(form, data);
    } catch (e) {
        notifyError(e.response?.data?.message || '加载设置失败');
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
        notifyError(e.response?.data?.message || '保存失败');
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
    color: var(--el-text-color-primary);
}

.section:first-of-type {
    margin-top: 0;
}

.hint {
    margin-left: 10px;
    color: var(--el-text-color-secondary);
    font-size: 13px;
}
</style>
