<template>
    <el-card class="create-card" :style="isMobile ? '' : 'max-width: 720px'">
        <template #header>
            <span class="page-title">{{ isMobile ? '提交新工单' : '新建工单' }}</span>
        </template>
        <el-form
            :model="form"
            :label-position="isMobile ? 'top' : 'right'"
            :label-width="isMobile ? 'auto' : '80px'"
            class="create-form"
            :class="{ mobile: isMobile }"
        >
            <el-form-item label="标题" required>
                <el-input v-model="form.title" maxlength="100" show-word-limit placeholder="一句话描述问题" />
            </el-form-item>
            <el-form-item label="分类">
                <el-select v-model="form.category_id" placeholder="选择分类（可选）" clearable style="width: 240px">
                    <el-option v-for="c in categories" :key="c.id" :label="c.name" :value="c.id" />
                </el-select>
            </el-form-item>
            <el-form-item label="优先级" required>
                <el-radio-group v-model="form.priority">
                    <el-radio-button v-for="(v, k) in PRIORITY_MAP" :key="k" :value="k">{{ v.label }}</el-radio-button>
                </el-radio-group>
            </el-form-item>
            <el-form-item label="内容" required>
                <el-input v-model="form.content" type="textarea" :rows="8" maxlength="5000" show-word-limit placeholder="详细描述问题现象、出现时间、已尝试的操作等" />
            </el-form-item>
            <el-form-item class="actions">
                <el-button v-if="!isMobile" @click="$router.back()">取消</el-button>
                <el-button type="primary" :loading="loading" class="submit-btn" @click="submit">
                    {{ isMobile ? '提 交 工 单' : '提交工单' }}
                </el-button>
            </el-form-item>
        </el-form>
        <el-alert type="info" :closable="false">
            <template #title>
                提交前建议先查
                <el-link type="primary" @click="$router.push(route.path.startsWith('/m') ? '/m/faqs' : adminPath('faqs'))">知识库</el-link>
                ，常见问题可立即解决；回复中输入
                <b>@用户名</b> 可提醒相关同事。
            </template>
        </el-alert>
        <el-alert v-if="error" :title="error" type="error" :closable="false" style="margin-top: 12px" />
    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import http from '../api';
import { useAuthStore } from '../stores/auth';
import { adminPath, ticketPath } from '../paths';

const isMobile = ref(window.matchMedia('(max-width: 768px)').matches);
window.matchMedia('(max-width: 768px)').addEventListener('change', (e) => { isMobile.value = e.matches; });
import { PRIORITY_MAP } from '../constants';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const loading = ref(false);
const error = ref('');
const categories = ref([]);
const form = reactive({ title: '', content: '', priority: 'medium', category_id: '' });

onMounted(async () => {
    const { data } = await http.get('/categories');
    categories.value = data.data;
});

async function submit() {
    if (!form.title || !form.content) {
        error.value = '请填写标题和内容';
        return;
    }
    loading.value = true;
    error.value = '';
    try {
        const { data } = await http.post('/tickets', {
            ...form,
            category_id: form.category_id || null,
        });
        ElMessage.success(`工单 ${data.no} 创建成功`);
        router.push(auth.isStaff && !route.path.startsWith('/m') ? ticketPath(data.id) : `/m/tickets/${data.id}`);
    } catch (e) {
        error.value = e.response?.data?.message || '提交失败';
    } finally {
        loading.value = false;
    }
}
</script>

<style scoped>
.page-title {
    font-weight: 700;
}

/* 移动端精修表单 */
@media (max-width: 768px) {
    .create-card {
        border-radius: 14px;
        border: none;
    }
    .create-card :deep(.el-card__header) {
        padding: 16px 16px 8px;
        border-bottom: none;
        font-size: 18px;
    }
    .create-card :deep(.el-card__body) {
        padding: 8px 16px 16px;
    }
    .create-form.mobile :deep(.el-form-item) {
        margin-bottom: 18px;
    }
    .create-form.mobile :deep(.el-form-item__label) {
        font-size: 14px;
        font-weight: 600;
        color: var(--el-text-color-primary);
        margin-bottom: 8px;
        line-height: 1.4;
    }
    .create-form.mobile :deep(.el-input__wrapper),
    .create-form.mobile :deep(.el-select .el-input__wrapper),
    .create-form.mobile :deep(.el-textarea__inner) {
        border-radius: 10px;
        box-shadow: 0 0 0 1px var(--el-border-color) inset;
        background: var(--el-bg-color);
    }
    .create-form.mobile :deep(.el-input__inner) {
        height: 46px;
        font-size: 16px;
    }
    .create-form.mobile :deep(.el-textarea__inner) {
        min-height: 132px;
        font-size: 16px;
        line-height: 1.7;
        padding: 12px 14px;
    }
    .create-form.mobile :deep(.el-textarea__inner::placeholder),
    .create-form.mobile :deep(.el-input__inner::placeholder) {
        color: var(--el-text-color-secondary);
    }
    /* 优先级四等分 */
    .create-form.mobile :deep(.el-radio-group) {
        display: flex;
        width: 100%;
    }
    .create-form.mobile :deep(.el-radio-button) {
        flex: 1;
    }
    .create-form.mobile :deep(.el-radio-button__inner) {
        width: 100%;
        padding: 13px 0;
        font-size: 15px;
        border-radius: 0;
    }
    .create-form.mobile :deep(.el-radio-button:first-child .el-radio-button__inner),
    .create-form.mobile :deep(.el-radio-button:last-child .el-radio-button__inner) {
        border-radius: 0;
    }
    .create-form.mobile :deep(.el-radio-group) {
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 0 0 1px var(--el-border-color) inset;
    }
    .create-form.mobile :deep(.el-radio-button__original-radio:checked + .el-radio-button__inner) {
        font-weight: 700;
    }
    /* 主按钮全宽 */
    .actions :deep(.el-form-item__content) {
        display: block;
    }
    .submit-btn {
        width: 100%;
        height: 50px;
        font-size: 16px;
        border-radius: 12px;
        letter-spacing: 1px;
    }
    /* 提示条紧凑 */
    :deep(.el-alert) {
        border-radius: 10px;
        margin-top: 4px;
    }
}
</style>