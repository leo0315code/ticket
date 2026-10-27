<?php

namespace App\Support;

/**
 * 权限目录：后台「角色管理」按分组渲染，代码里用 key 做校验。
 * 新增权限只需在这里登记，角色页与校验会自动生效。
 */
class Permissions
{
    public const LABELS = [
        'panel.access' => '进入后台',
        'ticket.view_all' => '查看全部工单',
        'ticket.assign' => '指派 / 转交工单',
        'ticket.close' => '关闭工单',
        'ticket.delete' => '删除工单',
        'ticket.export' => '导出工单',
        'ticket.internal_note' => '写内部备注',
        'faq.manage' => '知识库管理',
        'category.manage' => '分类管理',
        'canned.manage' => '快捷回复管理',
        'stats.view' => '统计看板',
        'log.view' => '操作审计',
        'user.manage' => '用户管理',
        'role.manage' => '角色与权限',
        'settings.manage' => '系统设置',
    ];

    public const GROUPS = [
        '工单' => [
            'panel.access',
            'ticket.view_all',
            'ticket.assign',
            'ticket.close',
            'ticket.delete',
            'ticket.export',
            'ticket.internal_note',
        ],
        '内容' => ['faq.manage', 'category.manage', 'canned.manage'],
        '数据' => ['stats.view', 'log.view'],
        '系统' => ['user.manage', 'role.manage', 'settings.manage'],
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /** 分组后的目录，供前端直接渲染 checkbox */
    public static function catalog(): array
    {
        $catalog = [];
        foreach (self::GROUPS as $group => $keys) {
            $catalog[] = [
                'group' => $group,
                'permissions' => array_map(
                    fn (string $key) => ['key' => $key, 'label' => self::LABELS[$key]],
                    $keys,
                ),
            ];
        }

        return $catalog;
    }
}
