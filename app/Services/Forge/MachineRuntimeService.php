<?php

namespace App\Services\Forge;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MachineRuntimeService
{
    /**
     * @param  Collection<int, object>  $runs
     * @param  Collection<int, object>  $downtimes
     * @return array{scheduled_seconds: int, stopped_seconds: int, working_seconds: int}
     */
    public function summarize(Collection $runs, Collection $downtimes, CarbonInterface $from, CarbonInterface $to): array
    {
        $runWindows = $this->merge($this->clip($runs, $from, $to));
        $stopWindows = $this->merge($this->clip($downtimes, $from, $to));
        $overlap = [];

        foreach ($runWindows as [$runStart, $runEnd]) {
            foreach ($stopWindows as [$stopStart, $stopEnd]) {
                $start = max($runStart, $stopStart);
                $end = min($runEnd, $stopEnd);
                if ($start < $end) {
                    $overlap[] = [$start, $end];
                }
            }
        }

        $scheduled = $this->seconds($runWindows);
        $stopped = $this->seconds($this->merge($overlap));

        return [
            'scheduled_seconds' => $scheduled,
            'stopped_seconds' => $stopped,
            'working_seconds' => max(0, $scheduled - $stopped),
        ];
    }

    private function clip(Collection $events, CarbonInterface $from, CarbonInterface $to): array
    {
        $windows = [];
        foreach ($events as $event) {
            $start = max($from->getTimestamp(), Carbon::parse($event->started_at)->getTimestamp());
            $end = min($to->getTimestamp(), $event->ended_at ? Carbon::parse($event->ended_at)->getTimestamp() : $to->getTimestamp());
            if ($start < $end) {
                $windows[] = [$start, $end];
            }
        }

        return $windows;
    }

    private function merge(array $windows): array
    {
        usort($windows, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($windows as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    private function seconds(array $windows): int
    {
        return array_sum(array_map(fn (array $window) => $window[1] - $window[0], $windows));
    }
}
