<template>
    <el-card header="附件" style="margin-top: 16px" :class="{ 'drag-over': dragOver }"
        @drop.prevent="onDrop" @dragover.prevent="dragOver = true" @dragleave="dragOver = false">
        <!-- 图片缩略图（点击大图预览） -->
        <div v-if="images.length" class="img-wall">
            <el-image
                v-for="img in images"
                :key="img.id"
                :src="blobUrls[img.id]"
                :preview-src-list="images.map((i) => blobUrls[i.id]).filter(Boolean)"
                :initial-index="images.indexOf(img)"
                fit="cover"
                class="img-thumb"
                :preview-teleported="true"
            />
        </div>

        <div v-if="files.length">
            <div v-for="a in files" :key="a.id" class="attach-row">
                <el-icon><Document /></el-icon>
                <el-link type="primary" @click="download(a)">{{ a.original_name }}</el-link>
                <span class="attach-meta">{{ formatSize(a.size) }} · {{ a.user?.name }}</span>
            </div>
        </div>
        <p v-if="ticket.attachments?.length === 0" style="color: var(--el-text-color-secondary); margin: 0 0 8px">暂无附件</p>
        <el-upload :show-file-list="false" :http-request="upload" multiple :disabled="reachedLimit">
            <el-button size="small" :loading="uploading" :disabled="reachedLimit">
                上传附件（≤{{ maxMb }}MB）
            </el-button>
        </el-upload>
        <p v-if="reachedLimit" class="hint">已达每单 {{ maxCount }} 个附件上限</p>
    </el-card>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { notifyError } from '../../notify';
import { Document } from '@element-plus/icons-vue';
import http from '../../api';
import { loadMeta, meta } from '../../meta';

const props = defineProps({ ticket: { type: Object, required: true } });
const emit = defineEmits(['change']);

const uploading = ref(false);
const dragOver = ref(false);

function onDrop(e) {
    dragOver.value = false;
    if (reachedLimit.value) {
        ElMessage.warning(`已达每单 ${maxCount.value} 个附件上限`);
        return;
    }
    const dropped = [...(e.dataTransfer?.files || [])];
    for (const f of dropped) {
        if (f.size > maxMb.value * 1024 * 1024) {
            ElMessage.warning(`「${f.name}」超过 ${maxMb.value}MB，未上传`);
            continue;
        }
        upload({ file: f });
    }
}
const maxMb = ref(meta().attach_max_mb);
const maxCount = ref(meta().attach_max_count);

onMounted(async () => {
    const m = await loadMeta();
    maxMb.value = m.attach_max_mb;
    maxCount.value = m.attach_max_count;
});

const reachedLimit = computed(() => (props.ticket.attachments?.length || 0) >= maxCount.value);

// 图片附件（缩略图墙）；其余文件走列表
const images = computed(() => (props.ticket.attachments || []).filter((a) => (a.mime || '').startsWith('image/')));
const files = computed(() => (props.ticket.attachments || []).filter((a) => !(a.mime || '').startsWith('image/')));
const blobUrls = ref({});

// 下载接口需要 Authorization，el-image 直接 src 不带 token：预取为 blob URL
async function loadBlobUrls() {
    for (const img of images.value) {
        if (blobUrls.value[img.id]) continue;
        try {
            const res = await http.get(`/tickets/${props.ticket.id}/attachments/${img.id}/download`, { responseType: 'blob' });
            blobUrls.value = { ...blobUrls.value, [img.id]: URL.createObjectURL(res.data) };
        } catch { /* 单图失败忽略 */ }
    }
}

watch(() => props.ticket.attachments, loadBlobUrls, { immediate: true });

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
        notifyError(e.response?.data?.message || '上传失败');
    } finally {
        uploading.value = false;
    }
}
</script>

<style scoped>
.drag-over {
    box-shadow: 0 0 0 2px var(--el-color-primary) inset;
}
.img-wall {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
}
.img-thumb {
    width: 72px;
    height: 72px;
    border-radius: 8px;
    cursor: zoom-in;
}
.attach-row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 0;
}
.attach-meta {
    color: var(--el-text-color-secondary);
    font-size: 12px;
}

.hint {
    margin: 6px 0 0;
    font-size: 12px;
    color: var(--el-text-color-secondary);
}
</style>
