<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Geographic scope enforcement service.
 * Applies scope predicates to DB queries based on the user's scope level.
 * - county: sees everything
 * - township: sees all villages within their township
 * - village: sees only their village
 */
class ScopeService
{
    /**
     * Get the list of geo_area IDs visible to the given user scope.
     * Returns array of geo_area IDs that the user can access.
     */
    public static function getVisibleAreaIds(string $scopeLevel, int $scopeId): array
    {
        switch ($scopeLevel) {
            case 'county':
                // County sees everything — return all area IDs
                return Db::table('geo_areas')->column('id');

            case 'township':
                // Township sees itself + all child villages
                $ids = [$scopeId];
                $childIds = Db::table('geo_areas')
                    ->where('parent_id', $scopeId)
                    ->column('id');
                return array_merge($ids, $childIds);

            case 'village':
                // Village sees only itself
                return [$scopeId];

            default:
                return [];
        }
    }

    /**
     * Apply scope filtering to a query builder on a table with
     * geo_scope_level and geo_scope_id columns.
     * Returns the modified query.
     */
    public static function applyScope($query, array $user)
    {
        $scopeLevel = $user['geo_scope_level'];
        $scopeId = $user['geo_scope_id'];

        if ($scopeLevel === 'county') {
            // No filter needed — county sees all
            return $query;
        }

        $visibleIds = self::getVisibleAreaIds($scopeLevel, $scopeId);
        return $query->whereIn('geo_scope_id', $visibleIds);
    }

    /**
     * Check if a user has access to a specific entity by its geo scope.
     */
    public static function canAccess(array $user, string $entityScopeLevel, int $entityScopeId): bool
    {
        if ($user['geo_scope_level'] === 'county') {
            return true;
        }

        $visibleIds = self::getVisibleAreaIds($user['geo_scope_level'], $user['geo_scope_id']);
        return in_array($entityScopeId, $visibleIds, true);
    }
}
