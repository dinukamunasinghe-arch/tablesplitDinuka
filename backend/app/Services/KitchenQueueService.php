<?php

namespace App\Services;

use App\Support\Decimal;

class KitchenQueueService
{
    public function schedule(array $input): array
    {
        $clock = Decimal::of($input['now']);
        $pending = [];

        foreach ($input['tickets'] as $ticket) {
            $pending[] = $this->normalize($ticket);
        }

        $selections = [];
        $step = 1;

        while ($pending !== []) {
            $waiting = $this->waitingAt($pending, $clock);
            $waited = false;

            if ($waiting === []) {
                $clock = $this->earliestCreated($pending);
                $waiting = $this->waitingAt($pending, $clock);
                $waited = true;
            }

            usort($waiting, fn (array $left, array $right): int => $this->compare($left, $right));
            $chosen = $waiting[0];
            $pending = array_values(array_filter(
                $pending,
                fn (array $ticket): bool => $ticket['id'] !== $chosen['id'],
            ));
            $stillWaiting = array_values(array_filter(
                $waiting,
                fn (array $ticket): bool => $ticket['id'] !== $chosen['id'],
            ));

            $selections[] = [
                'step' => $step,
                'id' => $chosen['id'],
                'table' => $chosen['table'],
                'priority' => $chosen['priority'],
                'created' => $chosen['created_number'],
                'prep' => $chosen['prep_number'],
                'started' => $this->number($clock),
                'waiting' => $this->number(Decimal::sub($clock, $chosen['created'])),
                'reason' => $this->reason($chosen, $stillWaiting, $clock, $waited),
                'remaining' => array_column($pending, 'id'),
            ];

            $clock = Decimal::add($clock, $chosen['prep']);
            $step++;
        }

        $next = $selections[0];

        return [
            'now' => $this->number(Decimal::of($input['now'])),
            'policy' => config('tablesplit.kitchen.policy'),
            'next' => $this->summary($next),
            'remaining' => array_map(fn (array $selection): array => $this->summary($selection), array_slice($selections, 1)),
            'order' => array_column($selections, 'id'),
            'selections' => $selections,
        ];
    }

    private function normalize(array $ticket): array
    {
        $priority = $ticket['priority'] ?? null;
        $created = Decimal::of($ticket['created']);
        $prep = Decimal::of($ticket['prep']);

        return [
            'id' => (string) $ticket['id'],
            'table' => (int) $ticket['table'],
            'priority' => $priority,
            'rank' => $this->rank($priority),
            'created' => $created,
            'prep' => $prep,
            'created_number' => $this->number($created),
            'prep_number' => $this->number($prep),
        ];
    }

    private function waitingAt(array $pending, string $clock): array
    {
        return array_values(array_filter(
            $pending,
            fn (array $ticket): bool => Decimal::cmp($ticket['created'], $clock) <= 0,
        ));
    }

    private function earliestCreated(array $pending): string
    {
        $earliest = $pending[0]['created'];

        foreach ($pending as $ticket) {
            if (Decimal::cmp($ticket['created'], $earliest) < 0) {
                $earliest = $ticket['created'];
            }
        }

        return $earliest;
    }

    private function rank(?string $priority): int
    {
        if ($priority === null || $priority === '') {
            return (int) config('tablesplit.kitchen.default_rank');
        }

        return (int) config("tablesplit.kitchen.priorities.{$priority}");
    }

    private function compare(array $left, array $right): int
    {
        if ($left['rank'] !== $right['rank']) {
            return $right['rank'] <=> $left['rank'];
        }

        $byCreated = Decimal::cmp($left['created'], $right['created']);

        if ($byCreated !== 0) {
            return $byCreated;
        }

        $byPrep = Decimal::cmp($left['prep'], $right['prep']);

        if ($byPrep !== 0) {
            return $byPrep;
        }

        return strcmp($left['id'], $right['id']);
    }

    private function reason(array $chosen, array $others, string $clock, bool $waited): string
    {
        $minute = $this->number($clock);
        $choice = $this->choiceReason($chosen, $others);

        if ($waited) {
            return "No ticket was waiting yet, so the cook waited until minute {$minute}. {$choice}";
        }

        return "At minute {$minute}, {$choice}";
    }

    private function choiceReason(array $chosen, array $others): string
    {
        if ($others === []) {
            return 'only this ticket was waiting.';
        }

        $sameRank = array_values(array_filter(
            $others,
            fn (array $ticket): bool => $ticket['rank'] === $chosen['rank'],
        ));

        if ($sameRank === []) {
            $behind = array_map(
                fn (array $ticket): string => $ticket['id'].' ('.$this->label($ticket).')',
                $others,
            );

            return 'higher priority ('.$this->label($chosen).') than '.implode(' and ', $behind).'.';
        }

        $ids = implode(', ', array_column($sameRank, 'id'));
        $label = $this->label($chosen);

        if ($this->isStrictlyEarlier($chosen, $sameRank)) {
            return "same {$label} priority as {$ids}; created earlier.";
        }

        if ($this->isStrictlyShorter($chosen, $sameRank)) {
            return "same {$label} priority as {$ids}; shorter preparation time.";
        }

        return "same {$label} priority as {$ids}; ticket id comes first.";
    }

    private function isStrictlyEarlier(array $chosen, array $sameRank): bool
    {
        $earlierThanOne = false;

        foreach ($sameRank as $other) {
            $comparison = Decimal::cmp($chosen['created'], $other['created']);

            if ($comparison > 0) {
                return false;
            }

            if ($comparison < 0) {
                $earlierThanOne = true;
            }
        }

        return $earlierThanOne;
    }

    private function isStrictlyShorter(array $chosen, array $sameRank): bool
    {
        $shorterThanOne = false;

        foreach ($sameRank as $other) {
            if (Decimal::cmp($chosen['created'], $other['created']) !== 0) {
                continue;
            }

            $comparison = Decimal::cmp($chosen['prep'], $other['prep']);

            if ($comparison > 0) {
                return false;
            }

            if ($comparison < 0) {
                $shorterThanOne = true;
            }
        }

        return $shorterThanOne;
    }

    private function label(array $ticket): string
    {
        return $ticket['priority'] ?? 'normal';
    }

    private function summary(array $selection): array
    {
        return [
            'id' => $selection['id'],
            'table' => $selection['table'],
            'priority' => $selection['priority'],
            'created' => $selection['created'],
            'prep' => $selection['prep'],
            'started' => $selection['started'],
            'waiting' => $selection['waiting'],
            'reason' => $selection['reason'],
        ];
    }

    private function number(string $amount): int|float
    {
        $whole = bcdiv(Decimal::of($amount), '1', 0);

        if (Decimal::cmp($amount, $whole) === 0) {
            return (int) $whole;
        }

        return (float) Decimal::format($amount);
    }
}
