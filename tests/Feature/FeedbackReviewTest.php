<?php

namespace Tests\Feature;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\FeedbackActivityType;
use App\Helpers\FeedbackDelivery;
use App\Helpers\FeedbackSentiment;
use App\Helpers\FeedbackStatus;
use App\Helpers\VatsimRating;
use App\Livewire\FeedbackForm;
use App\Livewire\FeedbackTable;
use App\Models\Acknowledgement;
use App\Models\Area;
use App\Models\AtcActivity;
use App\Models\Endorsement;
use App\Models\Feedback;
use App\Models\Position;
use App\Models\User;
use App\Notifications\FeedbackSentNotification;
use App\Services\FeedbackDeliveryService;
use App\Services\StatisticsService;
use App\Services\VatsimOnlineControllers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesRoleAssignedUsers;
use Tests\TestCase;

class FeedbackReviewTest extends TestCase
{
    use CreatesRoleAssignedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Setting::set('feedbackEnabled', true);
        Setting::set('feedbackForwardEmail', false);
        Setting::set('feedbackDiscordWebhook', '');
        Setting::set('feedbackStaffDiscordWebhook', '');
        Http::fake(['api.vatsim.net/*' => Http::response([], 503), 'data.vatsim.net/*' => Http::response(['controllers' => []])]);
    }

    private function feedbackIn(Area $area, array $attributes = []): Feedback
    {
        return Feedback::factory()->create([
            'reference_position_id' => Position::factory()->create(['area_id' => $area->id])->id,
            ...$attributes,
        ]);
    }

    private function onRosterOf(Area $area): User
    {
        $controller = User::factory()->create(['rating' => VatsimRating::S2->value]);
        AtcActivity::create(['user_id' => $controller->id, 'area_id' => $area->id, 'hours' => 50, 'atc_active' => true]);

        return $controller;
    }

    private function visitorIn(Area $area): User
    {
        $visitor = User::factory()->create(['rating' => VatsimRating::S3->value]);

        $endorsement = new Endorsement;
        $endorsement->user_id = $visitor->id;
        $endorsement->type = 'VISITING';
        $endorsement->valid_from = now()->subDay();
        $endorsement->issued_by = $visitor->id;
        $endorsement->save();
        $endorsement->areas()->sync([$area->id]);

        return $visitor;
    }

    // Submitting

    #[Test]
    public function with_several_areas_the_form_asks_for_the_area_first(): void
    {
        Area::factory()->count(2)->create();

        $this->actingAs(User::factory()->create())->get(route('feedback'))
            ->assertOk()
            ->assertSee('Which area is your feedback about?');
    }

    #[Test]
    public function the_controller_and_the_text_are_required(): void
    {
        $area = Area::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('feedback.store'), ['area' => $area->id])
            ->assertSessionHasErrors(['controller', 'feedback']);
    }

    #[Test]
    public function the_roster_includes_visitors_and_controllers_of_every_area(): void
    {
        [$area, $otherArea] = Area::factory()->count(2)->create();
        $visitor = $this->visitorIn($area);
        $local = $this->onRosterOf($area);
        $elsewhere = $this->onRosterOf($otherArea);
        $notOnRoster = User::factory()->create();

        $ids = User::getFeedbackControllers()->pluck('id');

        $this->assertTrue($ids->contains($visitor->id));
        $this->assertTrue($ids->contains($local->id));
        $this->assertTrue($ids->contains($elsewhere->id));
        $this->assertFalse($ids->contains($notOnRoster->id));
    }

    #[Test]
    public function the_form_lists_online_controllers_first(): void
    {
        $area = Area::factory()->create();
        Position::factory()->create(['area_id' => $area->id, 'callsign' => 'ZZZA_APP']);
        $online = $this->onRosterOf($area);
        $elsewhere = $this->onRosterOf(Area::factory()->create());
        $this->mock(VatsimOnlineControllers::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('callsignsByCid')
            ->andReturn(collect([$online->id => 'ZZZA_APP', $elsewhere->id => 'ZZZB_TWR'])));

        Livewire::actingAs(User::factory()->create())
            ->test(FeedbackForm::class, ['areaId' => $area->id])
            ->assertSee('Online now')
            ->assertSee('ZZZA_APP')
            ->assertDontSee('ZZZB_TWR');
    }

    #[Test]
    public function picking_a_controller_offers_their_recent_sessions_and_fills_the_position(): void
    {
        $area = Area::factory()->create();
        Position::factory()->create(['area_id' => $area->id, 'callsign' => 'ZZZC_TWR']);
        $controller = $this->onRosterOf($area);

        $this->mock(StatisticsService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('getRecentSessionsSummary')
            ->andReturn([['callsign' => 'ZZZC_TWR', 'start' => '2026-11-08T19:40:00+00:00', 'end' => '2026-11-08T20:38:00+00:00', 'duration' => '58m']]));

        Livewire::actingAs(User::factory()->create())
            ->test(FeedbackForm::class, ['areaId' => $area->id])
            ->call('selectController', $controller->id)
            ->assertSet('controller', $controller->id)
            ->assertSee('ZZZC_TWR · 8 Nov, 1940–2038z')
            ->set('session', '0')
            ->assertSet('position', 'ZZZC_TWR')
            ->assertSee('name="session_callsign" value="ZZZC_TWR"', false);
    }

    #[Test]
    public function someone_off_the_rosters_cannot_be_picked(): void
    {
        $outsider = User::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(FeedbackForm::class)
            ->call('selectController', $outsider->id)
            ->assertSet('controller', null);
    }

    #[Test]
    public function a_submission_stores_the_session_and_resolves_its_position(): void
    {
        [$area] = Area::factory()->count(2)->create();
        $position = Position::factory()->create(['area_id' => $area->id, 'callsign' => 'ZZZC_TWR']);
        $controller = $this->onRosterOf($area);
        $pilot = User::factory()->create();

        $this->actingAs($pilot)->post(route('feedback.store'), [
            'area' => $area->id,
            'controller' => $controller->id,
            'session_callsign' => 'ZZZC_TWR',
            'session_started_at' => '2026-11-08T19:40:00+00:00',
            'session_ended_at' => '2026-11-08T20:38:00+00:00',
            'feedback' => 'Smooth handling.',
        ])->assertRedirect(route('dashboard'));

        $feedback = Feedback::sole();
        $this->assertSame($position->id, $feedback->reference_position_id);
        $this->assertNull($feedback->area_id);
        $this->assertSame('ZZZC_TWR · 8 Nov, 1940–2038z', $feedback->sessionLabel());
        $this->assertSame(FeedbackStatus::OPEN, $feedback->status);
    }

    #[Test]
    public function the_submitter_cannot_open_their_feedback_again(): void
    {
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);

        $this->actingAs($feedback->submitter)->get(route('feedback.show', $feedback))->assertForbidden();
    }

    // Reviewing

    #[Test]
    public function staff_outside_the_area_cannot_open_or_review_it(): void
    {
        $feedback = $this->feedbackIn(Area::factory()->create());
        $moderator = $this->moderatorFor(Area::factory()->create());

        $this->actingAs($moderator)->get(route('feedback.show', $feedback))->assertForbidden();
        $this->actingAs($moderator)->post(route('feedback.comment', $feedback), ['comment' => 'Hi'])->assertForbidden();
        $this->actingAs($moderator)->post(route('feedback.close', $feedback))->assertForbidden();
        $this->actingAs($moderator)->post(route('feedback.withdraw', $feedback))->assertForbidden();
    }

    #[Test]
    public function the_tone_can_be_set_changed_and_cleared_with_each_change_on_the_timeline(): void
    {
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);

        $this->actingAs($moderator)->post(route('feedback.sentiment', $feedback), ['sentiment' => 'positive']);
        $this->assertSame(FeedbackSentiment::POSITIVE, $feedback->fresh()->sentiment);

        $this->actingAs($moderator)->post(route('feedback.sentiment', $feedback), ['sentiment' => '']);
        $this->assertNull($feedback->fresh()->sentiment);

        $this->assertSame(2, $feedback->activities()->where('type', FeedbackActivityType::SENTIMENT)->count());
    }

    #[Test]
    public function several_staff_can_comment_also_after_it_is_closed(): void
    {
        $area = Area::factory()->create();
        $first = $this->moderatorFor($area);
        $second = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);

        $this->actingAs($first)->post(route('feedback.comment', $feedback), ['comment' => 'Looking into it.']);
        $feedback->close($first);
        $this->actingAs($second)->post(route('feedback.comment', $feedback), ['comment' => 'Spoke to them on Discord.'])
            ->assertRedirect(route('feedback.show', $feedback));

        $this->actingAs($first)->get(route('feedback.show', $feedback))
            ->assertSeeInOrder([$second->name, 'Spoke to them on Discord.', $first->name, 'Looking into it.']);
    }

    #[Test]
    public function closing_without_sending_keeps_it_internal(): void
    {
        Notification::fake();
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);

        $this->actingAs($this->moderatorFor($area))
            ->post(route('feedback.close', $feedback), ['discord' => '1'])
            ->assertRedirect(route('feedback.show', $feedback));

        $feedback->refresh();
        $this->assertSame(FeedbackStatus::CLOSED, $feedback->status);
        $this->assertFalse($feedback->wasSent());
        $this->assertSame(0, Acknowledgement::count());
        Notification::assertNothingSent();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'discord'));
    }

    #[Test]
    public function sending_emails_the_controller_asks_them_to_acknowledge_and_leaves_the_review_open(): void
    {
        Notification::fake();
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);

        $this->actingAs($moderator)->post(route('feedback.send', $feedback), ['delivery' => 'original', 'comment' => 'Nice one.']);

        $feedback->refresh();
        $this->assertSame(FeedbackStatus::OPEN, $feedback->status);
        $this->assertSame(FeedbackDelivery::ORIGINAL, $feedback->sent_version);
        $this->assertSame($moderator->id, $feedback->sent_by_user_id);
        $this->assertSame($feedback->feedback, $feedback->sentText());
        Notification::assertSentTo($feedback->referenceUser, FeedbackSentNotification::class);
        $this->assertTrue(Acknowledgement::where('user_id', $feedback->reference_user_id)->pending()->exists());
        $this->assertSame(1, $feedback->activities()->where('type', FeedbackActivityType::SENT)->count());
        $this->assertSame(1, $feedback->activities()->where('type', FeedbackActivityType::COMMENT)->count());
    }

    #[Test]
    public function a_rewritten_version_is_sent_instead_of_the_original(): void
    {
        Notification::fake();
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area, ['feedback' => 'Rude original words']);

        $this->actingAs($this->moderatorFor($area))->post(route('feedback.send', $feedback), ['delivery' => 'rewritten'])
            ->assertSessionHasErrors('rewritten');

        $this->actingAs($this->moderatorFor($area))->post(route('feedback.send', $feedback), [
            'delivery' => 'rewritten',
            'rewritten' => 'A pilot found your instructions hard to follow.',
        ]);

        $feedback->refresh();
        $this->assertSame('Rude original words', $feedback->feedback);
        $this->assertSame('A pilot found your instructions hard to follow.', $feedback->sentText());

        $mail = (new FeedbackSentNotification($feedback))->toMail($feedback->referenceUser)->render();
        $this->assertStringContainsString('hard to follow', $mail);
        $this->assertStringNotContainsString('Rude original words', $mail);
        $this->assertStringNotContainsString($feedback->submitter->name, $mail);
    }

    #[Test]
    public function feedback_without_a_controller_can_only_be_kept_internal(): void
    {
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area, ['reference_user_id' => null]);

        $this->actingAs($this->moderatorFor($area))
            ->post(route('feedback.send', $feedback), ['delivery' => 'original'])
            ->assertSessionHasErrors('delivery');

        $this->assertTrue($feedback->fresh()->isOpen());
    }

    #[Test]
    public function closed_feedback_can_be_sent_later_without_reopening(): void
    {
        Notification::fake();
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);
        $feedback->close($moderator);

        $this->actingAs($moderator)->post(route('feedback.send', $feedback), ['delivery' => 'original', 'comment' => 'Decided to share it after all.'])
            ->assertRedirect(route('feedback.show', $feedback));

        $feedback->refresh();
        $this->assertSame(FeedbackStatus::CLOSED, $feedback->status);
        $this->assertTrue($feedback->wasSent());
        Notification::assertSentTo($feedback->referenceUser, FeedbackSentNotification::class);
    }

    #[Test]
    public function only_one_version_can_be_with_the_controller_at_a_time(): void
    {
        Notification::fake();
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);

        $this->actingAs($moderator)->post(route('feedback.send', $feedback), ['delivery' => 'original']);
        $this->actingAs($moderator)->post(route('feedback.send', $feedback), ['delivery' => 'rewritten', 'rewritten' => 'Another version'])
            ->assertSessionHasErrors('delivery');

        $this->assertSame(FeedbackDelivery::ORIGINAL, $feedback->fresh()->sent_version);
        $this->assertSame(1, Acknowledgement::count());
    }

    #[Test]
    public function withdrawing_takes_it_back_from_the_controller(): void
    {
        Notification::fake();
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area, ['feedback' => 'Shared by mistake']);
        $controller = $feedback->referenceUser;

        $this->actingAs($moderator)->post(route('feedback.send', $feedback), ['delivery' => 'original']);
        $this->actingAs($moderator)->post(route('feedback.withdraw', $feedback))->assertRedirect(route('feedback.show', $feedback));

        $feedback->refresh();
        $this->assertFalse($feedback->wasSent());
        $this->assertSame(0, Acknowledgement::pending()->count());
        $this->assertSame(1, $feedback->activities()->where('type', FeedbackActivityType::WITHDRAWN)->count());

        $this->actingAs($controller)->get(route('dashboard'))->assertDontSee('Shared by mistake');
        $this->actingAs($controller)->get(route('user.show', $controller))->assertDontSee('Shared by mistake');
    }

    #[Test]
    public function after_withdrawing_a_different_version_can_be_sent(): void
    {
        Notification::fake();
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);

        $this->actingAs($moderator)->post(route('feedback.send', $feedback), ['delivery' => 'original']);
        $this->actingAs($moderator)->post(route('feedback.withdraw', $feedback));
        $this->actingAs($moderator)->post(route('feedback.send', $feedback), ['delivery' => 'rewritten', 'rewritten' => 'Second version.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Second version.', $feedback->fresh()->sentText());
        $this->assertSame(1, Acknowledgement::pending()->count());
    }

    #[Test]
    public function reopening_returns_it_to_the_queue_without_touching_what_was_sent(): void
    {
        Notification::fake();
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);

        $this->actingAs($moderator)->post(route('feedback.send', $feedback), ['delivery' => 'original']);
        $this->actingAs($moderator)->post(route('feedback.close', $feedback));
        $this->actingAs($moderator)->post(route('feedback.reopen', $feedback));

        $this->assertTrue($feedback->fresh()->isOpen());
        $this->assertTrue($feedback->fresh()->wasSent());

        // Closing again leaves what was sent as it is.
        $this->actingAs($moderator)->post(route('feedback.close', $feedback))->assertSessionHasNoErrors();
        $this->assertSame(FeedbackStatus::CLOSED, $feedback->fresh()->status);
        $this->assertSame(1, $feedback->activities()->where('type', FeedbackActivityType::SENT)->count());
    }

    #[Test]
    public function sent_feedback_is_posted_to_discord_and_withdrawing_deletes_the_post(): void
    {
        Notification::fake();
        Setting::set('feedbackDiscordWebhook', 'https://discord.com/api/webhooks/1/abc');
        Http::fake([
            'discord.com/api/webhooks/1/abc/messages/*' => Http::response(null, 204),
            'discord.com/api/webhooks/1/abc*' => Http::response(['id' => '999888777']),
        ]);
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area, ['feedback' => 'Brilliant service']);

        $this->actingAs($moderator)
            ->post(route('feedback.send', $feedback), ['delivery' => 'original', 'discord' => '1'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'wait=true')
            && str_contains($request['embeds'][0]['description'], 'Brilliant service')
            && ! str_contains(json_encode($request->data()), $feedback->submitter->name));
        $this->assertSame('999888777', $feedback->fresh()->discord_message_id);
        $this->assertTrue($feedback->activities()->where('type', FeedbackActivityType::SENT)->sole()->postedToDiscord());

        $this->actingAs($moderator)->post(route('feedback.withdraw', $feedback))->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/messages/999888777'));
        $this->assertNull($feedback->fresh()->discord_posted_at);
        $this->assertTrue($feedback->activities()->where('type', FeedbackActivityType::WITHDRAWN)->sole()->removedFromDiscord());
    }

    #[Test]
    public function nothing_is_posted_to_discord_unless_asked(): void
    {
        Notification::fake();
        Setting::set('feedbackDiscordWebhook', 'https://discord.com/api/webhooks/1/abc');
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);

        $this->actingAs($this->moderatorFor($area))->post(route('feedback.send', $feedback), ['delivery' => 'original']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'discord'));
        $this->assertNull($feedback->fresh()->discord_posted_at);
    }

    #[Test]
    public function a_failed_discord_post_still_sends_the_feedback(): void
    {
        Notification::fake();
        Setting::set('feedbackDiscordWebhook', 'https://discord.com/api/webhooks/1/abc');
        Http::fake(['discord.com/*' => Http::response(null, 500)]);
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);

        $this->actingAs($this->moderatorFor($area))
            ->post(route('feedback.send', $feedback), ['delivery' => 'original', 'discord' => '1'])
            ->assertSessionHasErrors();

        $this->assertTrue($feedback->fresh()->wasSent());
        $this->assertNull($feedback->fresh()->discord_posted_at);
    }

    #[Test]
    public function an_open_entry_offers_sending_separately_and_the_close_dialog_says_nothing_is_sent(): void
    {
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);
        $name = $feedback->referenceUser->name;

        $this->actingAs($this->moderatorFor($area))->get(route('feedback.show', $feedback))
            ->assertSee('Send to ' . $name)
            ->assertSee('Nothing has been sent to ' . $name . '. Closing keeps it with staff')
            ->assertSee('Closing does not send anything');
    }

    #[Test]
    public function the_discord_option_is_shown_but_disabled_without_a_webhook(): void
    {
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);

        $this->actingAs($this->moderatorFor($area))->get(route('feedback.show', $feedback))
            ->assertSee('Also post it to Discord')
            ->assertSee('add a Discord webhook');
    }

    #[Test]
    public function every_detail_except_the_submitter_and_text_can_be_edited_and_is_on_the_timeline(): void
    {
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area, ['pilot_callsign' => 'OLD1', 'feedback' => 'Original text']);
        $newController = User::factory()->create();

        $this->actingAs($moderator)->patch(route('feedback.update', $feedback), [
            'controller' => $newController->id,
            'position' => $feedback->referencePosition->callsign,
            'session_callsign' => 'zzzc_twr',
            'session_started_at' => '2026-11-08T19:40',
            'session_ended_at' => '2026-11-08T20:38',
            'pilot_callsign' => 'new2',
            'event_date' => '2026-11-08',
            'departure_airport' => 'essa',
            'arrival_airport' => 'EKCH',
            'feedback' => 'Tampered text',
            'submitter_user_id' => $moderator->id,
        ])->assertRedirect(route('feedback.show', $feedback));

        $feedback->refresh();
        $this->assertSame('Original text', $feedback->feedback);
        $this->assertNotSame($moderator->id, $feedback->submitter_user_id);
        $this->assertSame('ZZZC_TWR · 8 Nov, 1940–2038z', $feedback->sessionLabel());
        $this->assertSame('NEW2 · ESSA → EKCH · 08/11/2026', $feedback->flightLabel());

        $labels = collect($feedback->activities()->where('type', FeedbackActivityType::EDITED)->sole()->changes())->pluck('label');
        $this->assertEqualsCanonicalizing(
            ['Controller', 'Session callsign', 'Session start', 'Session end', 'Pilot callsign', 'Date of event', 'Departure', 'Arrival'],
            $labels->all(),
        );
    }

    #[Test]
    public function the_controller_cannot_be_changed_while_they_hold_a_sent_version(): void
    {
        Notification::fake();
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);
        $original = $feedback->reference_user_id;
        $this->actingAs($moderator)->post(route('feedback.send', $feedback), ['delivery' => 'original']);

        $this->actingAs($moderator)->patch(route('feedback.update', $feedback), [
            'controller' => User::factory()->create()->id,
            'position' => $feedback->referencePosition->callsign,
        ])->assertSessionHasErrors('controller');

        $this->assertSame($original, $feedback->fresh()->reference_user_id);
    }

    // Acknowledging

    #[Test]
    public function sent_feedback_stays_on_the_dashboard_until_acknowledged(): void
    {
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area, ['feedback' => 'Thanks for the help!']);
        app(FeedbackDeliveryService::class)->send($feedback, $this->moderatorFor($area), FeedbackDelivery::ORIGINAL);
        $acknowledgement = Acknowledgement::request($feedback->referenceUser, $feedback);
        $controller = $feedback->referenceUser;

        $this->actingAs($controller)->get(route('dashboard'))
            ->assertSee('You have received feedback')
            ->assertSee('Thanks for the help!');

        $this->actingAs($controller)->post(route('acknowledgements.acknowledge', $acknowledgement))->assertRedirect();

        $this->assertFalse($acknowledgement->fresh()->isPending());
        $this->actingAs($controller)->get(route('dashboard'))->assertDontSee('Thanks for the help!');
        $this->assertSame(1, $feedback->activities()->where('type', FeedbackActivityType::ACKNOWLEDGED)->count());
    }

    #[Test]
    public function only_the_controller_can_acknowledge(): void
    {
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);
        app(FeedbackDeliveryService::class)->send($feedback, $this->moderatorFor($area), FeedbackDelivery::ORIGINAL);
        $acknowledgement = Acknowledgement::request($feedback->referenceUser, $feedback);

        $this->actingAs(User::factory()->create())->post(route('acknowledgements.acknowledge', $acknowledgement))->assertForbidden();
        $this->assertTrue($acknowledgement->fresh()->isPending());
    }

    #[Test]
    public function staff_see_whether_the_controller_acknowledged(): void
    {
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);
        app(FeedbackDeliveryService::class)->send($feedback, $moderator, FeedbackDelivery::ORIGINAL);
        $acknowledgement = Acknowledgement::request($feedback->referenceUser, $feedback);

        $this->actingAs($moderator)->get(route('feedback.show', $feedback))->assertSee('Not yet');

        $acknowledgement->acknowledge();

        $this->actingAs($moderator)->get(route('feedback.show', $feedback))
            ->assertDontSee('Not yet')
            ->assertSee('Acknowledged the feedback sent to them');
    }

    // Profiles

    #[Test]
    public function the_controller_sees_only_what_was_sent_to_them(): void
    {
        $area = Area::factory()->create();
        $controller = User::factory()->create();
        $submitter = User::factory()->create(['first_name' => 'Secret', 'last_name' => 'Pilotname']);
        $base = ['reference_user_id' => $controller->id, 'submitter_user_id' => $submitter->id];

        $this->feedbackIn($area, $base + ['feedback' => 'Original words'])->update([
            'status' => FeedbackStatus::CLOSED, 'sent_version' => FeedbackDelivery::REWRITTEN, 'sent_message' => 'Kind rewrite', 'sent_at' => now(),
        ]);
        $this->feedbackIn($area, $base + ['feedback' => 'Kept internal'])->update(['status' => FeedbackStatus::CLOSED]);
        $this->feedbackIn($area, $base + ['feedback' => 'Still open']);

        $this->actingAs($controller)->get(route('user.show', $controller))
            ->assertOk()
            ->assertSee('Feedback about you')
            ->assertSee('Kind rewrite')
            ->assertDontSee('Original words')
            ->assertDontSee('Kept internal')
            ->assertDontSee('Still open')
            ->assertDontSee('Pilotname');
    }

    #[Test]
    public function staff_see_all_feedback_a_member_received_including_on_their_own_profile(): void
    {
        $area = Area::factory()->create();
        $admin = $this->admin();
        $member = User::factory()->create();
        $this->feedbackIn($area, ['reference_user_id' => $member->id, 'feedback' => 'Unsent about member']);
        $this->feedbackIn($area, ['reference_user_id' => $admin->id, 'feedback' => 'Unsent about admin']);

        $this->actingAs($admin)->get(route('user.show', $member))
            ->assertSee('Feedback received')
            ->assertSee('Unsent about member');

        // Staff with feedback permissions can read this in the report anyway,
        // so their own profile shows it as Feedback received.
        $this->actingAs($admin)->get(route('user.show', $admin))
            ->assertSee('Feedback received')
            ->assertSee('Unsent about admin')
            ->assertSee('Feedback about you');
    }

    #[Test]
    public function the_migration_carries_over_the_legacy_forwarded_flag(): void
    {
        $migration = require database_path('migrations/2026_11_10_130000_add_review_workflow_to_feedback.php');
        $feedback = Feedback::factory()->create();

        $migration->down();
        \DB::table('feedback')->where('id', $feedback->id)->update(['forwarded' => true]);
        $migration->up();

        $feedback->refresh();
        $this->assertSame(FeedbackStatus::CLOSED, $feedback->status);
        $this->assertTrue($feedback->wasSent());
    }

    // Hardening

    #[Test]
    public function reopening_an_open_entry_is_refused_and_not_put_on_the_timeline(): void
    {
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);

        $this->actingAs($this->moderatorFor($area))->post(route('feedback.reopen', $feedback))->assertSessionHasErrors();

        $this->assertSame(0, $feedback->activities()->where('type', FeedbackActivityType::REOPENED)->count());
    }

    #[Test]
    public function nobody_can_give_feedback_about_themselves(): void
    {
        $area = Area::factory()->create();
        $controller = $this->onRosterOf($area);

        $this->actingAs($controller)->post(route('feedback.store'), [
            'area' => $area->id,
            'controller' => $controller->id,
            'feedback' => 'I was great.',
        ])->assertSessionHasErrors('controller');

        Livewire::actingAs($controller)
            ->test(FeedbackForm::class, ['areaId' => $area->id])
            ->call('selectController', $controller->id)
            ->assertSet('controller', null);
    }

    #[Test]
    public function a_second_send_from_a_stale_page_cannot_reach_the_controller_twice(): void
    {
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);
        $stale = Feedback::find($feedback->id);
        $delivery = app(FeedbackDeliveryService::class);

        $delivery->send($feedback, $moderator, FeedbackDelivery::ORIGINAL);

        $this->expectException(\LogicException::class);

        try {
            $delivery->send($stale, $moderator, FeedbackDelivery::REWRITTEN, 'Another version');
        } finally {
            Notification::assertSentToTimes($feedback->referenceUser, FeedbackSentNotification::class, 1);
            $this->assertSame(FeedbackDelivery::ORIGINAL, $feedback->fresh()->sent_version);
        }
    }

    #[Test]
    public function a_queued_email_is_not_sent_once_the_feedback_is_withdrawn(): void
    {
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $feedback = $this->feedbackIn($area);
        $delivery = app(FeedbackDeliveryService::class);
        $delivery->send($feedback, $moderator, FeedbackDelivery::ORIGINAL);

        $notification = new FeedbackSentNotification($feedback);
        $this->assertTrue($notification->shouldSend($feedback->referenceUser, 'mail'));

        $delivery->withdraw($feedback, $moderator);

        $this->assertFalse($notification->shouldSend($feedback->referenceUser, 'mail'));
    }

    #[Test]
    public function a_malformed_vatsim_feed_is_ignored_rather_than_breaking_the_form(): void
    {
        // Replace the empty feed faked in setUp(); the first matching fake wins.
        Http::swap(new Factory);
        Http::fake(['data.vatsim.net/*' => Http::response(['controllers' => [
            'not an object',
            ['cid' => 'abc', 'callsign' => 'X_TWR', 'facility' => 4],
            ['cid' => 1234567, 'facility' => 4],
            ['cid' => 1234568, 'callsign' => 'ZZZD_TWR', 'facility' => 4],
            ['cid' => 1234569, 'callsign' => 'ZZZD_ATIS', 'facility' => 4],
            ['cid' => 1234570, 'callsign' => 'ZZZD_OBS', 'facility' => 0],
        ]])]);

        $this->assertSame([1234568 => 'ZZZD_TWR'], app(VatsimOnlineControllers::class)->callsignsByCid()->all());
    }

    #[Test]
    public function discord_failures_never_log_the_webhook_url(): void
    {
        Setting::set('feedbackDiscordWebhook', 'https://discord.com/api/webhooks/1/secret-token');
        Http::fake(['discord.com/*' => fn () => throw new ConnectionException('cURL error for https://discord.com/api/webhooks/1/secret-token')]);
        Log::spy();
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);

        $this->actingAs($this->moderatorFor($area))->post(route('feedback.send', $feedback), ['delivery' => 'original', 'discord' => '1']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => ! str_contains(json_encode($context), 'secret-token'))
            ->once();
    }

    #[Test]
    public function the_withdraw_confirmation_survives_an_apostrophe_in_the_name(): void
    {
        $area = Area::factory()->create();
        $controller = User::factory()->create(['first_name' => 'Seán', 'last_name' => "O'Brien"]);
        $feedback = $this->feedbackIn($area, ['reference_user_id' => $controller->id]);
        $moderator = $this->moderatorFor($area);
        app(FeedbackDeliveryService::class)->send($feedback, $moderator, FeedbackDelivery::ORIGINAL);

        $html = $this->actingAs($moderator)->get(route('feedback.show', $feedback))->getContent();

        // Js::from escapes the apostrophe as ', so it cannot end the JavaScript string.
        $this->assertStringContainsString('O' . chr(92) . 'u0027Brien', $html);
        $this->assertStringNotContainsString("O'Brien? It leaves", $html);
        $this->assertStringNotContainsString('O&#039;Brien? It leaves', $html);
    }

    // Profiles: submitted feedback

    #[Test]
    public function staff_see_what_a_member_submitted_and_the_member_never_does(): void
    {
        $area = Area::factory()->create();
        $pilot = User::factory()->create();
        $this->feedbackIn($area, ['submitter_user_id' => $pilot->id, 'feedback' => 'Written by the pilot']);

        $this->actingAs($this->admin())->get(route('user.show', $pilot))
            ->assertSee('Feedback submitted')
            ->assertSee('Written by the pilot');

        $this->actingAs($pilot)->get(route('user.show', $pilot))
            ->assertDontSee('Feedback submitted')
            ->assertDontSee('Written by the pilot');
    }

    #[Test]
    public function members_without_feedback_permissions_see_nothing_on_other_profiles(): void
    {
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area, ['feedback' => 'Not for everyone']);
        $mentor = User::factory()->create();
        $mentor->roleAssignments()->create(['role' => 'mentor', 'area_id' => $area->id]);

        $page = $this->actingAs($mentor)->get(route('user.show', $feedback->referenceUser));

        if ($page->isOk()) {
            $page->assertDontSee('Not for everyone')->assertDontSee('Feedback received');
        } else {
            $page->assertForbidden();
        }
    }

    // Staff Discord channel

    private function staffChannel(int $editStatus = 200): void
    {
        Setting::set('feedbackStaffDiscordWebhook', 'https://discord.com/api/webhooks/2/staff');
        Http::swap(new Factory);
        Http::fake([
            'api.vatsim.net/*' => Http::response([], 503),
            'data.vatsim.net/*' => Http::response(['controllers' => []]),
            'discord.com/api/webhooks/2/staff/messages/*' => Http::response(['id' => '555'], $editStatus),
            'discord.com/api/webhooks/2/staff*' => Http::response(['id' => '777']),
        ]);
    }

    #[Test]
    public function a_new_submission_is_posted_to_the_staff_channel(): void
    {
        $this->staffChannel();
        [$area] = Area::factory()->count(2)->create();
        $controller = $this->onRosterOf($area);
        $pilot = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Pilot']);

        $this->actingAs($pilot)->post(route('feedback.store'), [
            'area' => $area->id,
            'controller' => $controller->id,
            'feedback' => 'Great handling.',
        ])->assertRedirect(route('dashboard'));

        $feedback = Feedback::sole();
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'webhooks/2/staff?wait=true')
            && str_contains(json_encode($request->data()), 'Pat Pilot')
            && $request['embeds'][0]['url'] === route('feedback.show', $feedback));
        // The feedback text itself never goes to Discord.
        Http::assertNotSent(fn ($request) => str_contains(json_encode($request->data()), 'Great handling'));
        $this->assertSame('777', $feedback->fresh()->staff_discord_message_id);
    }

    #[Test]
    public function the_staff_message_is_edited_once_per_request_as_the_review_changes(): void
    {
        $this->staffChannel();
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);
        Feedback::whereKey($feedback->id)->update(['staff_discord_message_id' => '555']);

        $this->actingAs($this->moderatorFor($area))->post(route('feedback.close', $feedback), ['comment' => 'Handled by phone.']);

        $edits = Http::recorded(fn ($request) => $request->method() === 'PATCH');
        $this->assertCount(1, $edits);
        [$request] = $edits->first();
        $this->assertStringEndsWith('/messages/555', $request->url());
        $this->assertStringContainsString('Closed by', json_encode($request->data()));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'staff'));
    }

    #[Test]
    public function sending_and_acknowledging_show_on_the_staff_message(): void
    {
        $this->staffChannel();
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);
        Feedback::whereKey($feedback->id)->update(['staff_discord_message_id' => '555']);

        $this->actingAs($this->moderatorFor($area))->post(route('feedback.send', $feedback), ['delivery' => 'original']);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_contains(json_encode($request->data()), 'Sent as written, not acknowledged yet'));

        $acknowledgement = Acknowledgement::where('user_id', $feedback->reference_user_id)->sole();
        $this->actingAs($feedback->referenceUser)->post(route('acknowledgements.acknowledge', $acknowledgement));
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_contains(json_encode($request->data()), 'Sent as written, acknowledged'));
    }

    #[Test]
    public function a_staff_message_that_has_gone_is_posted_again(): void
    {
        $this->staffChannel(404);
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area);
        Feedback::whereKey($feedback->id)->update(['staff_discord_message_id' => '555']);

        $this->actingAs($this->moderatorFor($area))->post(route('feedback.sentiment', $feedback), ['sentiment' => 'positive']);

        $this->assertSame('777', $feedback->fresh()->staff_discord_message_id);
    }

    #[Test]
    public function nothing_goes_to_discord_without_a_staff_webhook(): void
    {
        $area = Area::factory()->create();
        $controller = $this->onRosterOf($area);

        $this->actingAs(User::factory()->create())->post(route('feedback.store'), [
            'area' => $area->id,
            'controller' => $controller->id,
            'feedback' => 'Hello',
        ]);
        $this->actingAs($this->moderatorFor($area))->post(route('feedback.close', Feedback::sole()));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'discord'));
    }

    #[Test]
    public function the_staff_webhook_setting_only_accepts_discord_webhooks(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.settings.store'), ['feedbackStaffDiscordWebhook' => 'https://example.com/hook'])
            ->assertSessionHasErrors('feedbackStaffDiscordWebhook');
    }

    #[Test]
    public function long_feedback_is_shortened_in_tables_but_shown_in_full_elsewhere(): void
    {
        $area = Area::factory()->create();
        $moderator = $this->moderatorFor($area);
        $long = str_repeat('Very long feedback text. ', 20) . 'THE-END';
        $feedback = $this->feedbackIn($area, ['feedback' => $long]);

        Livewire::actingAs($moderator)->test(FeedbackTable::class)
            ->assertSee('Very long feedback text.')
            ->assertDontSee('THE-END');

        $this->actingAs($this->admin())->get(route('user.show', $feedback->referenceUser))->assertDontSee('THE-END');
        $this->actingAs($moderator)->get(route('feedback.show', $feedback))->assertSee('THE-END');

        app(FeedbackDeliveryService::class)->send($feedback, $moderator, FeedbackDelivery::ORIGINAL);
        $this->actingAs($feedback->referenceUser)->get(route('user.show', $feedback->referenceUser))->assertSee('THE-END');
    }

    #[Test]
    public function the_staff_message_leads_with_the_state_and_links_instead_of_quoting_the_text(): void
    {
        $this->staffChannel();
        $area = Area::factory()->create();
        $feedback = $this->feedbackIn($area, ['feedback' => 'Private words for staff only']);
        Feedback::whereKey($feedback->id)->update(['staff_discord_message_id' => '555']);

        $this->actingAs($this->moderatorFor($area))->post(route('feedback.close', $feedback));

        [$request] = Http::recorded(fn ($request) => $request->method() === 'PATCH')->first();
        $description = $request['embeds'][0]['description'];

        $this->assertSame('', $request['content']);
        $this->assertStringStartsWith('### ✅ Closed by ', $description);
        $this->assertStringContainsString(' · Untagged · Not sent', $description);
        $this->assertStringContainsString(route('feedback.show', $feedback), $description);
        $this->assertStringNotContainsString('Private words', json_encode($request->data()));
    }

    #[Test]
    public function staff_see_feedback_about_you_plus_received_and_submitted_on_their_own_profile(): void
    {
        $area = Area::factory()->create();
        $admin = $this->admin();
        $this->feedbackIn($area, ['submitter_user_id' => $admin->id, 'feedback' => 'Written by the admin']);
        $this->feedbackIn($area, ['reference_user_id' => $admin->id, 'feedback' => 'Unsent about the admin']);

        $this->actingAs($admin)->get(route('user.show', $admin))
            ->assertSee('Feedback submitted')
            ->assertSee('Written by the admin')
            ->assertSee('Feedback received')
            ->assertSee('Unsent about the admin')
            ->assertSee('Feedback about you')
            ->assertSee('No feedback has been sent to you.');
    }

    #[Test]
    public function profile_boxes_show_even_when_empty_but_only_to_the_right_people(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin())->get(route('user.show', $member))
            ->assertSee('No feedback received.')
            ->assertSee('No feedback submitted.');

        $this->actingAs($member)->get(route('user.show', $member))
            ->assertSee('No feedback has been sent to you.')
            ->assertDontSee('No feedback submitted.')
            ->assertDontSee('No feedback received.');
    }

    #[Test]
    public function members_without_permissions_still_get_only_what_was_sent_to_them(): void
    {
        $area = Area::factory()->create();
        $controller = User::factory()->create();
        $this->feedbackIn($area, ['reference_user_id' => $controller->id, 'feedback' => 'Never sent']);

        $this->actingAs($controller)->get(route('user.show', $controller))
            ->assertSee('Feedback about you')
            ->assertSee('No feedback has been sent to you.')
            ->assertDontSee('Never sent')
            ->assertDontSee('Feedback received');
    }

    #[Test]
    public function feedback_sent_from_outside_a_staff_members_scope_still_shows_as_about_you(): void
    {
        $ownArea = Area::factory()->create();
        $otherArea = Area::factory()->create();
        $moderator = $this->moderatorFor($ownArea);
        $feedback = $this->feedbackIn($otherArea, ['reference_user_id' => $moderator->id, 'feedback' => 'From the other area']);
        app(FeedbackDeliveryService::class)->send($feedback, $this->admin(), FeedbackDelivery::ORIGINAL);

        $page = $this->actingAs($moderator)->get(route('user.show', $moderator));
        $html = $page->getContent();

        $page->assertSee('From the other area');
        // Shown in "Feedback about you", not in the scoped "Feedback received" table.
        $this->assertStringContainsString('From the other area', substr($html, strpos($html, 'id="feedback-about-you"'), strpos($html, 'id="feedback-received"') - strpos($html, 'id="feedback-about-you"')));
    }
}
