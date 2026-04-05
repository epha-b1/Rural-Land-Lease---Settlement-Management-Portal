<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Geographic scope enforcement service.
 * Applies scope predicates to DB queries based on the user's scope level,
 * PLUS any active, non-expired access delegations granted to the user.
 *
 * Base scope:
 *  - county    → sees everything
 *  - township  → sees own township + all child villages
 *  - village   → sees only own village
 *
 * Delegation augmentation (Issue #5 remediation):
 *  Active delegations where `grantee_id = user.id` AND `status = 'active'`
 *  AND `expires_at > NOW()` add the delegated scope's visible area IDs to
 *  the user's effective visibility set for the duration of the request.
 *  A county-level delegation effectively grants full access.
 */
class ScopeService
{
    /**
     * Resolve the effective visible geo_area IDs for the given user.
     *
     * Prefers the full user row (for delegation lookup). When only the
     * base scope is known (legacy callers passing string+int), delegations
     * are NOT applied — callers should use getEffectiveVisibleAreaIdsForUser().
     */
    public static function getVisibleAreaIds(string $scopeLevel, int $scopeId): array
    {
        switch ($scopeLevel) {
            case 'county':
                // County sees everything — return all area IDs
                return array_map('intval', Db::table('geo_areas')->column('id'));

            case 'township':
                // Township sees itself + all child villages
                $ids = [$scopeId];
                $childIds = Db::table('geo_areas')
                    ->where('parent_id', $scopeId)
                    ->column('id');
                return array_values(array_unique(array_map('intval', array_merge($ids, $childIds))));

            case 'village':
                // Village sees only itself
                return [$scopeId];

            default:
                return [];
        }
    }

    /**
     * Compute the effective visible area IDs for a user, including any
     * active, non-expired delegations. Returns deduplicated int array.
     * If the user or any active delegation is county-level, returns the
     * full set of all geo_areas.
     */
    public static function getEffectiveVisibleAreaIdsForUser(array $user): array
    {
        $scopeLevel = $user['geo_scope_level'] ?? '';
        $scopeId = (int)($user['geo_scope_id'] ?? 0);

        // Fast path: user is already county-level → everything
        if ($scopeLevel === 'county') {
            return array_map('intval', Db::table('geo_areas')->column('id'));
        }

        $baseIds = self::getVisibleAreaIds($scopeLevel, $scopeId);

        // Augment with active delegations
        $userId = (int)($user['id'] ?? 0);
        if ($userId > 0) {
            $now = date('Y-m-d H:i:s');
            $delegations = Db::table('access_delegations')
                ->where('grantee_id', $userId)
                ->where('status', 'active')
                ->where('expires_at', '>', $now)
                ->field('scope_level, scope_id')
                ->select()
                ->toArray();

            foreach ($delegations as $d) {
                $dLevel = $d['scope_level'];
                $dId = (int)$d['scope_id'];

                // County-level delegation → full access, short-circuit
                if ($dLevel === 'county') {
                    return array_map('intval', Db::table('geo_areas')->column('id'));
                }

                $delegatedIds = self::getVisibleAreaIds($dLevel, $dId);
                $baseIds = array_merge($baseIds, $delegatedIds);
            }
        }

        return array_values(array_unique(array_map('intval', $baseIds)));
    }

    /**
     * Apply scope filtering to a query builder.
     * Default column is `geo_scope_id` (used by entities, contracts, etc.).
     * The `conversations` table uses `scope_id` instead, so callers on that
     * table must pass 'scope_id' as $scopeIdColumn.
     *
     * Delegations are always considered.
     *
     * @param mixed $query        Query builder
     * @param array $user         Authenticated user context
     * @param string $scopeIdColumn  Column name holding the geo area ID
     * @return mixed Modified query
     */
    public static function applyScope($query, array $user, string $scopeIdColumn = 'geo_scope_id')
    {
        // If base or delegated scope is county-wide, no filter is needed.
        if (self::hasCountyReach($user)) {
            return $query;
        }

        $visibleIds = self::getEffectiveVisibleAreaIdsForUser($user);
        // Defensive: never leave whereIn empty — empty array => no matches,
        // which is the correct secure default.
        return $query->whereIn($scopeIdColumn, $visibleIds);
    }

    /**
     * Check if a user has access to a specific geo area.
     * Considers both base scope and active delegations.
     */
    public static function canAccess(array $user, string $entityScopeLevel, int $entityScopeId): bool
    {
        if (self::hasCountyReach($user)) {
            return true;
        }

        $visibleIds = self::getEffectiveVisibleAreaIdsForUser($user);
        return in_array($entityScopeId, $visibleIds, true);
    }

    /**
     * Does the user effectively have county-wide visibility (either via
     * their base scope or via an active county-level delegation)?
     */
    private static function hasCountyReach(array $user): bool
    {
        if (($user['geo_scope_level'] ?? '') === 'county') {
            return true;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $countyDelegation = Db::table('access_delegations')
            ->where('grantee_id', $userId)
            ->where('status', 'active')
            ->where('scope_level', 'county')
            ->where('expires_at', '>', $now)
            ->find();

        return $countyDelegation !== null;
    }
}
