<template>
    <el-card style="max-width: 720px">
        <template #header>
            <div class="card-header">
                <span>分类管理</span>
                <el-button type="primary" @click="openDialog()">新建分类</el-button>
            </div>
        </template>

        <el-table :data="categories" v-loading="loading" stripe>
            <el-table-column prop="id" label="ID" width="60" />
            <el-table-column prop="name" label="名称" width="160" />
            <el-table-column prop="description" label="描述" />
            <el-table-column label="操作" width="140">
                <template #default="{ row }">
                    <el-button link type="primary" @click="openDialog(row)">编辑</el-button>
                    <el-popconfirm title="确认删除该分类？" @confirm="remove(row)">
                        <template #reference>
                            <el-button link type="danger">删除</el-button>
                        </template>
                    </el-popconfirm>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="dialog" :title="editing ? '编辑分类' : '新建分类'" width="420px">
            <el-form label-width="60px">
                <el-form-item label="名称" required>
                    <el-input v-model="form.name" maxlength="50" />
                </el-form-item>
                <el-form-item label="描述">
                    <el-input v-model="form.description" maxlength="200" />
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
import { notifyError } from '../notify';
import http from '../api';

const categories = ref([]);
const loading = ref(true);
const dialog = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = reactive({ name: '', description: '' });

async function load() {
    loading.value = true;
    try {
        const { data } = await http.get('/categories');
        categories.value = data.data;
    } finally {
        loading.value = false;
    }
}

function openDialog(row = null) {
    editing.value = row;
    form.name = row?.name || '';
    form.description = row?.description || '';
    dialog.value = true;
}

async function save() {
    if (!form.name.trim()) {
        notifyError('请输入分类名称');
        return;
    }
    saving.value = true;
    try {
        if (editing.value) {
            await http.put(`/categories/${editing.value.id}`, { ...form });
        } else {
            await http.post('/categories', { ...form });
        }
        dialog.value = false;
        ElMessage.success('已保存');
        await load();
    } catch (e) {
        notifyError(e.response?.data?.message || '保存失败');
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    await http.delete(`/categories/${row.id}`);
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
</style>
