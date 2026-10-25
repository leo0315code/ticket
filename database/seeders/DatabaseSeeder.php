<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Faq;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 演示账号，密码统一 password
        $admin = User::create(['name' => '管理员', 'username' => 'admin', 'email' => 'admin@example.com', 'password' => Hash::make('password'), 'role' => 'admin']);
        $agent1 = User::create(['name' => '客服小张', 'username' => 'zhang', 'email' => 'agent@example.com', 'password' => Hash::make('password'), 'role' => 'agent']);
        $agent2 = User::create(['name' => '客服小李', 'username' => 'li', 'email' => 'agent2@example.com', 'password' => Hash::make('password'), 'role' => 'agent']);
        $user1 = User::create(['name' => '用户甲', 'username' => 'xiaoming', 'email' => 'user@example.com', 'password' => Hash::make('password'), 'role' => 'user']);
        $user2 = User::create(['name' => '用户乙', 'username' => 'xiaohong', 'email' => null, 'password' => Hash::make('password'), 'role' => 'user']);

        $net = Category::create(['name' => '网络问题', 'description' => '无法连接、掉线等']);
        $account = Category::create(['name' => '账号问题', 'description' => '登录、密码、权限等']);
        $billing = Category::create(['name' => '账单问题', 'description' => '付款、发票、退款等']);

        Faq::create(['question' => '忘记密码怎么办？', 'answer' => '在登录页点击"忘记密码"，输入注册邮箱后按邮件指引重置；或联系客服解锁后自助重置。', 'sort' => 1]);
        Faq::create(['question' => 'VPN 连接超时的常见排查步骤', 'answer' => '1) 确认公司网络可达；2) 重装客户端；3) 检查系统时间是否准确；4) 仍无法连接请提交"网络问题"工单并附上报错截图。', 'sort' => 2]);
        Faq::create(['question' => '发票多久能收到？', 'answer' => '电子发票一般在付款后 3 个工作日内发送至下单邮箱；纸质发票 7-10 个工作日寄出。超期请提交"账单问题"工单。', 'sort' => 3]);

        $tickets = [
            ['title' => '电脑无法连接公司 VPN', 'content' => '从今天早上开始连接 VPN 一直超时，重装客户端也没用。', 'priority' => 'high', 'status' => 'in_progress', 'user' => $user1, 'agent' => $agent1, 'category' => $net],
            ['title' => '后台账号被锁定', 'content' => '输错三次密码后账号被锁，请帮忙解锁。', 'priority' => 'urgent', 'status' => 'open', 'user' => $user2, 'agent' => null, 'category' => $account],
            ['title' => '上个月发票还没收到', 'content' => '订单号 20260918-3342，发票一直没有寄出。', 'priority' => 'medium', 'status' => 'pending_resolved', 'user' => $user1, 'agent' => $agent2, 'category' => $billing],
            ['title' => '希望增加导出报表功能', 'content' => '目前的统计页面希望支持导出 Excel。', 'priority' => 'low', 'status' => 'open', 'user' => $user2, 'agent' => null, 'category' => null],
            ['title' => '邮箱收不到验证码', 'content' => '注册时验证码邮件收不到，垃圾箱也没有。', 'priority' => 'medium', 'status' => 'closed', 'user' => $user1, 'agent' => $agent1, 'category' => $account],
        ];

        foreach ($tickets as $i => $t) {
            $createdAt = now()->subDays(rand(0, 6));
            $ticket = Ticket::create([
                'no' => sprintf('TK-%s-%04d', $createdAt->format('Ymd'), $i + 1),
                'title' => $t['title'],
                'content' => $t['content'],
                'priority' => $t['priority'],
                'status' => $t['status'],
                'user_id' => $t['user']->id,
                'agent_id' => $t['agent']?->id,
                'category_id' => $t['category']?->id,
                'resolved_at' => in_array($t['status'], ['pending_resolved', 'closed']) ? $createdAt->copy()->addDay() : null,
                'closed_at' => $t['status'] === 'closed' ? now() : null,
                'created_at' => $createdAt,
            ]);

            if ($t['agent']) {
                TicketReply::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $t['agent']->id,
                    'content' => '您好，已收到您的工单，正在跟进处理，请稍候。',
                    'created_at' => $ticket->created_at->addHour(),
                ]);
            }
            TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $t['user']->id,
                'content' => '补充说明：问题出现前没有做过任何配置修改。',
                'created_at' => $ticket->created_at->addHours(2),
            ]);
        }
    }
}
