<template>
    <el-card header="附件" style="margin-top: 16px">
        <div v-if="ticket.attachments?.length">
            <div v-for="a in ticket.attachments" :key="a.id" class="attach-row">
                <el-icon><Document /></el-icon>
                <el-link type="primary" @click="download(a)">{{ a.original_name }}</el-link>
                <span class="attach-meta">{{ formatSize(a.size) }} · {{ a.user?.name }}</span>
            </div>
        </div>
        <p v-else style="color: #909399; margin: 0 0 8px">暂无附件</p>
        <el-upload :show-file-list="false" :http-request="upload" multiple>
            <el-button size="small" :loading="uploading">上传附件（≤10MB）</el-button>
        </el-upload>
    </el-card>
</template>

<script setup>
import { ref } from 'vue';
import { ElMessage } from 'element-plus';
import { Document } from '@element-plus/icons-vue';
import http from '../../api';

const props = defineProps({ ticket: { type: Object, required: true } });
const emit = defineEmits(['change']);

const uploading = ref(false);

function formatSize(bytes) {
    if (bytes > 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

async function download(a) {
    const res = await http.get(`/tickets/${props.ticket.id}/attachments/${a.id}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(res.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = a.original_name;
    link.click();
    URL.revokeObjectURL(url);
}

async function upload({ file }) {
    uploading.value = true;
    try {
        const fd = new FormData();
        fd.append('file', file);
        await http.post(`/tickets/${props.ticket.id}/attachments`, fd);
        ElMessage.success('附件已上传');
        emit('change');
    } catch (e) {
        ElMessage.error(e.response?.data?.message || '上传失败');
    } finally {
        uploading.value = false;
    }
}
</script>

<style scoped>
.attach-row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 0;
}
.attach-meta {
    color: #909399;
    font-size: 12px;
}
</style>
