<?php

namespace Tests\Feature\Api;

use App\Models\Ticket;
use App\Notifications\TicketEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NotificationPrefTest extends ApiTestCase
{
    use RefreshDatabase;

    public function test_profile_can_disable_email_notifications(): void
    {
        $user = $this->makeUser();
        $user->update(['email' => 'pref@example.com']);

        $this->actingAsApi($user)->putJson('/api/profile', [
            'name' => $user->name,
            'email' => 'pref@example.com',
            'notify_email' => false,
        ])->assertOk()->assertJsonPath('user.notify_email', false);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'notify_email' => false]);
    }

    public function test_mail_channel_respects_user_preference(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->makeUser()->id]);

        $on = $this->makeUser();
        $on->update(['email' => 'on@example.com', 'notify_email' => true]);
        $this->assertContains('mail', (new TicketEventNotification($ticket, 'x', 'replied'))->via($on));

        $off = $this->makeUser();
        $off->update(['email' => 'off@example.com', 'notify_email' => false]);
        $this->assertNotContains('mail', (new TicketEventNotification($ticket, 'x', 'replied'))->via($off));
    }
}
