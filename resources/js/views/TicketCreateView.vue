<template>
    <el-card style="max-width: 720px">
        <template #header><span>新建工单</span></template>
        <el-form :model="form" label-width="80px">
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
            <el-form-item>
                <el-button type="primary" :loading="loading" @click="submit">提交工单</el-button>
                <el-button @click="$router.back()">取消</el-button>
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
