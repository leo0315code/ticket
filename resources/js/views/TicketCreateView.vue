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
            <el-form-item label="分类" required>
                <el-select v-model="form.category_id" placeholder="请选择分类" clearable :style="{ width: isMobile ? '100%' : '240px' }">
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
            <el-form-item :label="`附件（可选，最多 ${maxMb}MB/个）`" class="attach-item">
                <div class="attach-box" :class="{ mobile: isMobile }">
                    <el-upload
                        :show-file-list="false"
                        :auto-upload="false"
                        multiple
                        accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip"
                        :on-change="addFile"
                    >
                        <el-button :size="isMobile ? 'large' : 'default'">
                            📎 选择文件
                        </el-button>
                    </el-upload>
                    <div v-for="(f, i) in files" :key="f.name + i" class="file-chip">
                        <span class="file-name">{{ f.name }}</span>
                        <span class="file-size">{{ formatSize(f.size) }}</span>
                        <el-icon class="file-del" @click="files.splice(i, 1)"><CircleCloseFilled /></el-icon>
                    </div>
                </div>
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
    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import http from '../api';
import { useAuthStore } from '../stores/auth';
import { adminPath, ticketPath } from '../paths';
import { loadMeta, meta } from '../meta';

const isMobile = ref(window.matchMedia('(max-width: 768px)').matches);
const maxMb = ref(meta().attach_max_mb);
const files = ref([]);

function addFile(file) {
    if (file.size > maxMb.value * 1024 * 1024) {
        ElMessage.warning(`「${file.name}」超过 ${maxMb.value}MB，未添加`);
        return;
    }
    if (files.value.length >= 5) {
        ElMessage.warning('最多添加 5 个附件');
        return;
    }
    files.value.push({ name: file.name, size: file.size, raw: file.raw });
}

function formatSize(bytes) {
    if (bytes > 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}
window.matchMedia('(max-width: 768px)').addEventListener('change', (e) => { isMobile.value = e.matches; });
import { PRIORITY_MAP } from '../constants';

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();
const loading = ref(false);
const categories = ref([]);
const form = reactive({ title: '', content: '', priority: 'medium', category_id: '' });

onMounted(async () => {
    const { data } = await http.get('/categories');
    categories.value = data.data;
    if (! form.category_id && data.data.length > 0) form.category_id = data.data[0].id;
});

async function submit() {
    if (!form.title || !form.content || !form.category_id) {
        notifyError('请填写标题、内容并选择分类');
        return;
    }
    loading.value = true;
    try {
        const { data } = await http.post('/tickets', {
            ...form,
            category_id: form.category_id || null,
        });
        // 工单创建成功后上传附件
        if (files.value.length > 0) {
            ElMessage.info(`正在上传 ${files.value.length} 个附件…`);
            let ok = 0;
            for (const f of files.value) {
                try {
                    const fd = new FormData();
                    fd.append('file', f.raw);
                    await http.post(`/tickets/${data.id}/attachments`, fd);
                    ok++;
                } catch (e) {
                    notifyError(`附件「${f.name}」上传失败，可稍后在工单详情中补传`);
                }
            }
            if (ok > 0) ElMessage.success(`${ok} 个附件已上传`);
        }
        ElMessage.success(`工单 ${data.no} 创建成功`);
        router.push(auth.isStaff && !route.path.startsWith('/m') ? ticketPath(data.id) : `/m/tickets/${data.id}`);
    } catch (e) {
        notifyError(e.response?.data?.message || '提交失败');
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
    /* 分类下拉与输入框同高（Element Plus 新版结构 .el-select__wrapper） */
    .create-form.mobile :deep(.el-select) {
        --el-component-size-large: 46px;
    }
    .create-form.mobile :deep(.el-select__wrapper),
    .create-form.mobile :deep(.el-select .el-input__wrapper) {
        height: 46px;
        min-height: 46px;
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
    /* 附件区 */
    .attach-box {
        width: 100%;
    }
    .attach-box :deep(.el-upload .el-button) {
        height: 46px;
        font-size: 15px;
        border-radius: 10px;
    }
    .attach-box .file-chip {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
        padding: 10px 12px;
        background: var(--el-fill-color-light);
        border-radius: 10px;
        font-size: 14px;
    }
    .file-chip .file-name {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .file-chip .file-size {
        color: var(--el-text-color-secondary);
        font-size: 12px;
    }
    .file-chip .file-del {
        color: var(--el-text-color-secondary);
        cursor: pointer;
    }
    /* 提示条紧凑 */
    :deep(.el-alert) {
        border-radius: 10px;
        margin-top: 4px;
    }
}
</style>