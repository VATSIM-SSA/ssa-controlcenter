<?php

namespace Tests\Unit;

use App\Support\Calendar\IcsCalendar;
use App\Support\Calendar\IcsEvent;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IcsCalendarTest extends TestCase
{
    private function event(array $overrides = []): IcsEvent
    {
        return new IcsEvent(...[
            'uid' => 'booking-1@cc.test',
            'summary' => 'FAOR_TWR',
            'startsAt' => Carbon::parse('2026-12-01 18:00:00', 'UTC'),
            'endsAt' => Carbon::parse('2026-12-01 20:00:00', 'UTC'),
            ...$overrides,
        ]);
    }

    #[Test]
    public function it_renders_a_valid_calendar_envelope(): void
    {
        $ics = IcsCalendar::render('Bookings', [$this->event()]);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("X-WR-CALNAME:Bookings\r\n", $ics);
        $this->assertStringContainsString("DTSTART:20261201T180000Z\r\n", $ics);
        $this->assertStringContainsString("DTEND:20261201T200000Z\r\n", $ics);
    }

    #[Test]
    public function times_are_converted_to_utc(): void
    {
        $ics = IcsCalendar::render('Bookings', [$this->event([
            'startsAt' => Carbon::parse('2026-12-01 20:00:00', 'Africa/Johannesburg'),
        ])]);

        $this->assertStringContainsString("DTSTART:20261201T180000Z\r\n", $ics);
    }

    #[Test]
    public function text_values_are_escaped(): void
    {
        $ics = IcsCalendar::render('Bookings', [$this->event([
            'description' => "Line one; with, commas\nLine two \\ done",
        ])]);

        $this->assertStringContainsString('DESCRIPTION:Line one\\; with\\, commas\\nLine two \\\\ done', $ics);
    }

    #[Test]
    public function long_lines_fold_at_75_octets_without_splitting_characters(): void
    {
        $description = str_repeat('Ëë sweatbox ', 30);
        $ics = IcsCalendar::render('Bookings', [$this->event(['description' => $description])]);

        foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        }

        $unfolded = str_replace("\r\n ", '', $ics);
        $this->assertStringContainsString('DESCRIPTION:' . $description, $unfolded);
    }

    #[Test]
    public function no_value_can_break_out_of_its_line(): void
    {
        $ics = IcsCalendar::render("Name\r\nX-EVIL:1", [$this->event([
            'summary' => "Title\nBEGIN:VEVENT",
            'location' => "Here\r\nEND:VCALENDAR",
            'url' => "https://example.org/\r\nBEGIN:VEVENT",
        ])]);

        $this->assertSame(1, substr_count($ics, "\r\nBEGIN:VEVENT\r\n"));
        $this->assertSame(1, substr_count($ics, "\r\nEND:VCALENDAR\r\n"));
        $this->assertStringNotContainsString("\r\nX-EVIL", $ics);
    }
}
