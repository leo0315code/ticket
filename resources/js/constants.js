export const STATUS_MAP = {
    open: { label: '待处理', type: 'warning' },
    in_progress: { label: '处理中', type: 'primary' },
    pending_resolved: { label: '待确认', type: 'success' },
    closed: { label: '已关闭', type: 'info' },
};

export const PRIORITY_MAP = {
    low: { label: '低', type: 'info' },
    medium: { label: '中', type: 'primary' },
    high: { label: '高', type: 'warning' },
    urgent: { label: '紧急', type: 'danger' },
};

export const ROLE_MAP = {
    user: '普通用户',
    agent: '客服',
    admin: '管理员',
};
