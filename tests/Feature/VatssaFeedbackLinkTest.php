<?php

namespace Tests\Feature;

use anlutro\LaravelSettings\Facade as Setting;
use App\Models\Area;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VatssaFeedbackLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('feedbackEnabled', true);
        $this->user = User::factory()->create();
        Position::factory()->create(['callsign' => 'FALE_TWR', 'area_id' => Area::factory()->create()->id]);
    }

    #[Test]
    public function the_link_fills_in_the_controller_and_position(): void
    {
        $this->actingAs($this->user)->get(route('feedback', ['controller' => '1234567', 'position' => 'FALE_TWR']))->assertOk()
            ->assertSeeInOrder(['name="controller"', 'value="1234567"'], false)
            ->assertSeeInOrder(['name="position"', 'list="positionsList"', 'multiple="multiple"', 'value="FALE_TWR"'], false);
    }

    #[Test]
    public function a_bad_link_leaves_the_fields_empty(): void
    {
        $this->actingAs($this->user)->get('/feedback?controller=%22%3E%3Cb%3Eprobe&position=FAKE_TWR')->assertOk()
            ->assertDontSee('probe', false)->assertDontSee('value="FAKE_TWR"', false);
        $this->actingAs($this->user)->get('/feedback?controller[]=1&position[]=FALE_TWR')->assertOk();
    }

    #[Test]
    public function the_form_opens_empty_without_a_link(): void
    {
        $this->actingAs($this->user)->get(route('feedback'))->assertOk()
            ->assertSeeInOrder(['name="controller"', 'multiple="multiple"', 'value=""'], false);
    }
}
