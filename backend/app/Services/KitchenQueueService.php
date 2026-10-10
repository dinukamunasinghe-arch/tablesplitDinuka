<?php

namespace App\Services;

use App\Support\Decimal;

class KitchenQueueService
{
    public function schedule(array $input): array
    {
        $now = Decimal::of($input['now']);
        $pending = [];

        foreach ($input['tickets'] as $ticket) {
            $pending[] = $this->normalize($ticket, $now);
        }

        $selections = [];
        $step = 1;

        while ($pending !== []) {
            usort($pending, fn (array $left, array $right): int => $this->compare($left, $right));
            $chosen = array_shift($pending);
            $selections[] = [
                'step' => $step,
                'id' => $chosen['id'],
                'table' => $chosen['table'],
                'priority' => $chosen['priority'],
                'created' => $chosen['created_number'],
                'prep' => $chosen['prep_number'],
                'waiting' => $chosen['waiting_number'],
                'reason' => $this->reason($chosen, $pending),
                'remaining' => array_column($pending, 'id'),
            ];
            $step++;
        }

        $next = $selections[0];

        return [
            'now' => $this->number($now),
            'policy' => config('tablesplit.kitchen.policy'),
            'next' => $this->summary($next),
            'remaining' => array_map(fn (array $selection): array => $this->summary($selection), array_slice($selections, 1)),
            'order' => array_column($selections, 'id'),
            'selections' => $selections,
        ];
    }

    private function normalize(array $ticket, string $now): array
    {
        $priority = $ticket['priority'] ?? null;
        $created = Decimal::of($ticket['created']);
        $prep = Decimal::of($ticket['prep']);
        $waiting = Decimal::sub($now, $created);

        return [
            'id' => (string) $ticket['id'],
            'table' => (int) $ticket['table'],
            'priority' => $priority,
            'rank' => $this->rank($priority),
            'created' => $created,
            'prep' => $prep,
            'waiting' => $waiting,
            'created_number' => $this->number($created),
            'prep_number' => $this->number($prep),
            'waiting_number' => $this->number($waiting),
        ];
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

    private function reason(array $chosen, array $others): string
    {
        if ($others === []) {
            return 'Only ticket left in the queue.';
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

            return 'Higher priority ('.$this->label($chosen).') than '.implode(' and ', $behind).'.';
        }

        $ids = implode(', ', array_column($sameRank, 'id'));
        $label = $this->label($chosen);

        if ($this->isStrictlyEarlier($chosen, $sameRank)) {
            return "Same {$label} priority as {$ids}; created earlier.";
        }

        if ($this->isStrictlyShorter($chosen, $sameRank)) {
            return "Same {$label} priority as {$ids}; shorter preparation time.";
        }

        return "Same {$label} priority as {$ids}; ticket id comes first.";
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
