<?php

namespace App\Services\Stats;

use App\Models\PlaytimeSnapshot;
use App\Models\User;
use App\Services\Library\LibraryQuery;
use Illuminate\Support\Facades\Date;

class BacklogStats
{
    /**
     * Pace window — recent enough to reflect current habits, long enough
     * to smooth a quiet week.
     */
    private const PACE_WINDOW_WEEKS = 4;

    public function __construct(private readonly LibraryQuery $library)
    {
    }

    /**
     * I.api: {unplayed_count, est_hours, burndown} — burn-down pace reads
     * playtime snapshots (V16), never live platform data.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        // Reuses the system collection so "unplayed" keeps V12 semantics:
        // known-zero or user-declared, null playtime excluded.
        $unplayed = $this->library->forUser($user, ['collection' => 'unplayed']);

        // §C: estimate only covers games with time-to-beat data.
        $estimableMinutes = array_sum(array_filter(array_column($unplayed, 'time_to_beat_minutes')));
        $estHours = intdiv($estimableMinutes, 60);

        $hoursPerWeek = $this->paceHoursPerWeek($user);

        return [
            'unplayed_count' => count($unplayed),
            'est_hours' => $estHours,
            'burndown' => [
                'avg_hours_per_week' => $hoursPerWeek,
                'est_years_to_clear' => $hoursPerWeek > 0.0
                    ? round($estHours / $hoursPerWeek / 52, 1)
                    : null,
            ],
        ];
    }

    /**
     * V16: pace = per-game playtime growth between the first and last
     * snapshot inside the window, summed across the library.
     */
    private function paceHoursPerWeek(User $user): float
    {
        $windowStart = Date::now()->subWeeks(self::PACE_WINDOW_WEEKS);

        $deltaMinutes = PlaytimeSnapshot::query()
            ->whereHas('ownedGame', fn ($query) => $query->where('user_id', $user->id))
            ->where('captured_at', '>=', $windowStart)
            ->get()
            ->groupBy('owned_game_id')
            ->sum(function ($snapshots) {
                $ordered = $snapshots->sortBy('captured_at');

                return max(0, $ordered->last()->playtime_minutes - $ordered->first()->playtime_minutes);
            });

        return round($deltaMinutes / 60 / self::PACE_WINDOW_WEEKS, 1);
    }
}
