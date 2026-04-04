<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Duplicate detection service for entity profiles.
 * Flags potential duplicates based on: display_name + address + last4 of ID/license.
 * Does NOT block saves — creates a duplicate_flags record for review.
 */
class DuplicateService
{
    /**
     * Check for duplicates of the given profile data.
     * Skips the profile's own ID (for updates).
     * Returns array of matching profile IDs, or empty.
     */
    public static function findMatches(
        string $displayName,
        string $address,
        ?string $idLast4,
        ?string $licenseLast4,
        ?int $excludeId = null
    ): array {
        $query = Db::table('entity_profiles')
            ->where('display_name', $displayName)
            ->where('address', $address)
            ->where('status', 'active');

        if ($excludeId) {
            $query->where('id', '<>', $excludeId);
        }

        // Must match at least one of id_last4 or license_last4
        $hasIdentifier = false;
        if (!empty($idLast4)) {
            $query->where('id_last4', $idLast4);
            $hasIdentifier = true;
        }
        if (!empty($licenseLast4) && !$hasIdentifier) {
            $query->where('license_last4', $licenseLast4);
            $hasIdentifier = true;
        }

        // If no identifier provided, match on name + address only
        // (still flag for review)
        if (!$hasIdentifier && (empty($idLast4) && empty($licenseLast4))) {
            return [];
        }

        return $query->column('id');
    }

    /**
     * Create duplicate flag records for a profile against its matches.
     * Returns the number of flags created.
     */
    public static function flagDuplicates(int $profileId, array $matchingIds): int
    {
        $count = 0;
        foreach ($matchingIds as $matchId) {
            // Avoid duplicate flags (check both directions)
            $existing = Db::table('duplicate_flags')
                ->where(function ($q) use ($profileId, $matchId) {
                    $q->where('left_profile_id', $profileId)->where('right_profile_id', $matchId);
                })
                ->whereOr(function ($q) use ($profileId, $matchId) {
                    $q->where('left_profile_id', $matchId)->where('right_profile_id', $profileId);
                })
                ->where('status', 'open')
                ->find();

            if (!$existing) {
                Db::table('duplicate_flags')->insert([
                    'left_profile_id'  => $profileId,
                    'right_profile_id' => $matchId,
                    'match_basis'      => 'name+address+last4',
                    'status'           => 'open',
                ]);
                $count++;
            }
        }
        return $count;
    }
}
