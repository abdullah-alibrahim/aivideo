<?php

namespace App\Services;

class BackgroundSequenceSelector
{
    /**
     * @param  list<array<string, mixed>>  $backgrounds
     * @return list<string>
     */
    public function select(array $backgrounds, ?string $selectedId, float $duration): array
    {
        $available = array_values(array_filter(
            $backgrounds,
            static fn (array $background): bool => is_string($background['path'] ?? null)
                && is_file($background['path']),
        ));
        if ($available === []) {
            return [];
        }

        $selectedIndex = array_search($selectedId, array_column($available, 'id'), true);
        $selected = $available[$selectedIndex === false ? 0 : $selectedIndex];
        $sequence = [$selected['path']];
        $targetCount = max(1, min(4, (int) ceil(max(3.0, $duration) / 15)));
        if ($targetCount === 1) {
            return $sequence;
        }

        $motionBackgrounds = array_values(array_filter(
            $available,
            static fn (array $background): bool => ($background['media_type'] ?? null) === 'video'
                && ($background['id'] ?? null) !== ($selected['id'] ?? null)
                && ! in_array($background['path'], $sequence, true),
        ));
        usort($motionBackgrounds, static function (array $left, array $right) use ($selected): int {
            $selectedCategory = $selected['category_id'] ?? null;
            $leftMatches = ($left['category_id'] ?? null) === $selectedCategory;
            $rightMatches = ($right['category_id'] ?? null) === $selectedCategory;

            return (int) $rightMatches <=> (int) $leftMatches;
        });

        foreach ($motionBackgrounds as $background) {
            if (count($sequence) >= $targetCount) {
                break;
            }
            $sequence[] = $background['path'];
        }

        return $sequence;
    }
}
