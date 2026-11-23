<?php

namespace App\Versions;

use App\Enums\ChoiceList;
use App\Enums\Software;
use App\Models\Choice;
use InvalidArgumentException;

/**
 * Finds the `os` list entry for a server's OS reading (SPEC §11.5): the
 * first entry whose label names both the distribution `ID` and `VERSION_ID`,
 * so `ubuntu` 24.04 is "Ubuntu 24.04 LTS" and `debian` 12 is "Debian 12".
 *
 * SPEC: the spec has only admins adding list entries. When nothing matches,
 * the entry is added here ("Ubuntu 28.04 LTS" from "Ubuntu 28.04.1 LTS"), so a
 * reporting server always has its OS in the list.
 */
class OsChoiceMatcher
{
    public function forReading(SoftwareReading $reading): Choice
    {
        if ($reading->software !== Software::Os) {
            throw new InvalidArgumentException("Not an OS reading: {$reading->software->value}");
        }

        $id = is_string($reading->details['id'] ?? null) ? mb_strtolower($reading->details['id']) : null;
        $versionId = $id !== null && $reading->version !== $id ? mb_strtolower($reading->version) : null;
        $label = $this->labelFor($reading, $id);
        $choices = Choice::ofList(ChoiceList::Os)->get();

        $match = $id !== null && $versionId !== null
            ? $choices->first(function (Choice $choice) use ($id, $versionId): bool {
                $tokens = preg_split('/[\s\/]+/', mb_strtolower($choice->label), flags: PREG_SPLIT_NO_EMPTY) ?: [];

                return in_array($id, $tokens, true) && in_array($versionId, $tokens, true);
            })
            : $choices->first(fn (Choice $choice): bool => mb_strtolower($choice->label) === mb_strtolower($label));

        return $match ?? Choice::query()->firstOrCreate(
            ['list' => ChoiceList::Os, 'label' => $label],
            ['position' => (int) Choice::query()->where('list', ChoiceList::Os)->max('position') + 1],
        );
    }

    /**
     * PRETTY_NAME without the point release or codename: "Ubuntu 28.04.1 LTS"
     * is "Ubuntu 28.04 LTS", "Rocky Linux 9.4 (Blue Onyx)" is "Rocky Linux 9.4".
     */
    private function labelFor(SoftwareReading $reading, ?string $id): string
    {
        $prettyName = is_string($reading->details['pretty_name'] ?? null) ? $reading->details['pretty_name'] : null;

        if ($prettyName === null) {
            $name = $id === null ? $reading->version : ucfirst($id);

            return $reading->version === $name || $reading->version === $id ? $name : "{$name} {$reading->version}";
        }

        $version = preg_quote($reading->version, '/');
        $label = (string) preg_replace("/(?<![\\w.]){$version}(?:\\.\\d+)+(?![\\w.])/", $reading->version, $prettyName);
        $label = (string) preg_replace('/\s*\([^)]*\)\s*$/', '', $label);

        return mb_strimwidth(trim($label) !== '' ? trim($label) : $prettyName, 0, 100);
    }
}
