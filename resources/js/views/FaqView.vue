<template>
    <el-card style="max-width: 860px">
        <template #header>
            <div class="card-header">
                <span>知识库 / 常见问题</span>
                <el-button v-if="auth.isAdmin" type="primary" @click="openDialog()">新建 FAQ</el-button>
            </div>
        </template>

        <el-empty v-if="!loading && faqs.length === 0" description="暂无 FAQ" />

        <el-collapse v-else>
            <el-collapse-item v-for="f in faqs" :key="f.id" :name="f.id">
                <template #title>
                    <span class="q">{{ f.question }}</span>
                    <span v-if="auth.isAdmin" class="ops">
                        <el-button link type="primary" @click.stop="openDialog(f)">编辑</el-button>
                        <el-popconfirm title="确认删除？" @confirm="remove(f)">
                            <template #reference>
                                <el-button link type="danger" @click.stop>删除</el-button>
                            </template>
                        </el-popconfirm>
                    </span>
                </template>
                <div class="a">{{ f.answer }}</div>
            </el-collapse-item>
        </el-collapse>

        <el-dialog v-model="dialog" :title="editing ? '编辑 FAQ' : '新建 FAQ'" width="520px">
            <el-form label-width="60px">
                <el-form-item label="问题" required>
                    <el-input v-model="form.question" maxlength="200" />
                </el-form-item>
                <el-form-item label="答案" required>
                    <el-input v-model="form.answer" type="textarea" :rows="5" maxlength="5000" />
                </el-form-item>
                <el-form-item label="排序">
                    <el-input-number v-model="form.sort" :min="0" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialog = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="save">保存</el-button>
            </template>
        </el-dialog>
    </el-card>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import http from '../api';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const faqs = ref([]);
const loading = ref(true);
const dialog = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = reactive({ question: '', answer: '', sort: 0 });

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/faqs');
        faqs.value = data.data;
    } finally {
        loading.value = false;
    }
}

function openDialog(row = null) {
    editing.value = row;
    form.question = row?.question || '';
    form.answer = row?.answer || '';
    form.sort = row?.sort ?? 0;
    dialog.value = true;
}

async function save() {
    if (!form.question.trim() || !form.answer.trim()) {
        ElMessage.warning('请填写问题和答案');
        return;
    }
    saving.value = true;
    try {
        if (editing.value) {
            await http.put(`/faqs/${editing.value.id}`, { ...form });
        } else {
            await http.post('/faqs', { ...form });
        }
        dialog.value = false;
        ElMessage.success('已保存');
        await load();
    } catch (e) {
        ElMessage.error(e.response?.data?.message || '保存失败');
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    await http.delete(`/faqs/${row.id}`);
    ElMessage.success('已删除');
    await load();
}

onMounted(load);
</script>

<style scoped>
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.q {
    font-weight: 600;
}
.ops {
    margin-left: 12px;
}
.a {
    white-space: pre-wrap;
    line-height: 1.8;
    color: #606266;
}
</style>
