import { ElMessage } from 'element-plus';

let current = null;

/**
 * 失败提示统一出口：toast 样式，4 秒后自动消失，可手动关闭；同一时间只留一条。
 * 只用于“操作失败/校验不通过”，成功提示用 ElMessage.success。
 */
export function notifyError(message) {
    if (current) current.close();
    current = ElMessage({
        type: 'error',
        message: String(message || '操作失败，请稍后再试'),
        duration: 4000,
        showClose: true,
    });
}

/** 路由切换时清掉还在显示的提示 */
export function clearErrors() {
    if (current) {
        current.close();
        current = null;
    }
}
