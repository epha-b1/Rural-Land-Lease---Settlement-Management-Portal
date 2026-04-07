<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Entity profile CRUD service.
 * Manages farmer/enterprise/collective profiles with configurable extra fields,
 * geo scope enforcement, and duplicate detection.
 */
class EntityService
{
    private const VALID_TYPES = ['farmer', 'enterprise', 'collective'];

    /**
     * Issue #10 remediation: validate extra_fields against the
     * admin-configured `extra_field_definitions` for the given entity_type.
     *
     * Rules enforced:
     *  - Unknown keys are rejected (400).
     *  - text: must be a string.
     *  - number: must be numeric (int or float).
     *  - date: must match YYYY-MM-DD and parse to a real date.
     *  - select: value must appear in options_json.
     * Only definitions with active=1 are considered.
     *
     * @throws \think\exception\HttpException on any violation.
     */
    public static function validateExtraFields(string $entityType, ?array $extraFields): void
    {
        if (empty($extraFields)) {
            return;
        }

        $defs = Db::table('extra_field_definitions')
            ->where('entity_type', $entityType)
            ->where('active', 1)
            ->select()
            ->toArray();

        $byKey = [];
        foreach ($defs as $d) {
            $byKey[$d['field_key']] = $d;
        }

        foreach ($extraFields as $key => $value) {
            if (!isset($byKey[$key])) {
                throw new \think\exception\HttpException(400,
                    "unknown extra field '{$key}' for entity_type={$entityType}"
                );
            }
            $def = $byKey[$key];
            $type = $def['field_type'];

            switch ($type) {
                case 'text':
                    if (!is_string($value)) {
                        throw new \think\exception\HttpException(400,
                            "extra field '{$key}' must be a string"
                        );
                    }
                    break;
                case 'number':
                    if (!is_numeric($value)) {
                        throw new \think\exception\HttpException(400,
                            "extra field '{$key}' must be numeric"
                        );
                    }
                    break;
                case 'date':
                    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        throw new \think\exception\HttpException(400,
                            "extra field '{$key}' must be YYYY-MM-DD"
                        );
                    }
                    $parts = explode('-', $value);
                    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                        throw new \think\exception\HttpException(400,
                            "extra field '{$key}' is not a valid date"
                        );
                    }
                    break;
                case 'select':
                    $options = !empty($def['options_json']) ? json_decode($def['options_json'], true) : [];
                    if (!is_array($options) || !in_array($value, $options, true)) {
                        throw new \think\exception\HttpException(400,
                            "extra field '{$key}' must be one of: " . implode(', ', $options ?: [])
                        );
                    }
                    break;
                default:
                    throw new \think\exception\HttpException(400,
                        "extra field '{$key}' has unknown definition type"
                    );
            }
        }
    }

    /**
     * List entities with scope filtering and pagination.
     */
    public static function list(array $user, array $filters = []): array
    {
        $query = Db::table('entity_profiles')->where('status', 'active');

        // Apply geo scope
        $query = ScopeService::applyScope($query, $user);

        // Optional filters
        if (!empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }
        if (!empty($filters['keyword'])) {
            $query->whereLike('display_name', '%' . $filters['keyword'] . '%');
        }

        $page = max((int)($filters['page'] ?? 1), 1);
        $size = min(max((int)($filters['size'] ?? 20), 1), 100);
        $offset = ($page - 1) * $size;

        $total = $query->count();
        $items = $query->order('created_at', 'desc')
            ->limit($offset, $size)
            ->select()
            ->toArray();

        // Parse extra fields JSON
        foreach ($items as &$item) {
            $item['extra_fields'] = !empty($item['extra_fields_json'])
                ? json_decode($item['extra_fields_json'], true) : [];
            unset($item['extra_fields_json']);
        }

        return ['items' => $items, 'page' => $page, 'total' => $total];
    }

    /**
     * Get a single entity by ID (with scope check).
     */
    public static function getById(int $id, array $user): array
    {
        $entity = Db::table('entity_profiles')->where('id', $id)->find();
        if (!$entity) {
            throw new \think\exception\HttpException(404, 'Entity not found');
        }

        // Scope check
        if (!ScopeService::canAccess($user, $entity['geo_scope_level'], (int)$entity['geo_scope_id'])) {
            throw new \think\exception\HttpException(403, 'Access denied: entity outside your scope');
        }

        $entity['extra_fields'] = !empty($entity['extra_fields_json'])
            ? json_decode($entity['extra_fields_json'], true) : [];
        unset($entity['extra_fields_json']);

        // Load duplicate flags
        $duplicateFlags = Db::table('duplicate_flags')
            ->where(function ($q) use ($id) {
                $q->where('left_profile_id', $id)->whereOr('right_profile_id', $id);
            })
            ->where('status', 'open')
            ->select()
            ->toArray();

        // Load merge history
        $mergeHistory = Db::table('profile_merge_history')
            ->where(function ($q) use ($id) {
                $q->where('source_profile_id', $id)->whereOr('target_profile_id', $id);
            })
            ->order('created_at', 'desc')
            ->select()
            ->toArray();

        return [
            'profile'         => $entity,
            'duplicate_flags' => $duplicateFlags,
            'merge_history'   => $mergeHistory,
        ];
    }

    /**
     * Create a new entity profile.
     */
    public static function create(array $data, array $user, string $traceId = ''): array
    {
        $entityType = $data['entity_type'] ?? '';
        if (!in_array($entityType, self::VALID_TYPES, true)) {
            throw new \think\exception\HttpException(400, 'Invalid entity_type');
        }

        $displayName = trim($data['display_name'] ?? '');
        if (empty($displayName)) {
            throw new \think\exception\HttpException(400, 'display_name is required');
        }

        $address = trim($data['address'] ?? '');
        $idLast4 = $data['id_last4'] ?? null;
        $licenseLast4 = $data['license_last4'] ?? null;
        $extraFields = $data['extra_fields'] ?? null;

        // Issue #10 remediation: enforce extra field definition contract
        self::validateExtraFields($entityType, $extraFields);

        $profileId = Db::table('entity_profiles')->insertGetId([
            'entity_type'      => $entityType,
            'display_name'     => $displayName,
            'address'          => $address,
            'id_last4'         => $idLast4,
            'license_last4'    => $licenseLast4,
            'extra_fields_json'=> $extraFields ? json_encode($extraFields) : null,
            'geo_scope_level'  => $user['geo_scope_level'],
            'geo_scope_id'     => $user['geo_scope_id'],
            'created_by'       => $user['id'],
        ]);

        // Check for duplicates
        $duplicateFlag = null;
        $matches = DuplicateService::findMatches($displayName, $address, $idLast4, $licenseLast4, $profileId);
        if (!empty($matches)) {
            $flagCount = DuplicateService::flagDuplicates($profileId, $matches);
            if ($flagCount > 0) {
                $duplicateFlag = true;
            }
        }

        LogService::info('entity_created', [
            'profile_id'  => $profileId,
            'entity_type' => $entityType,
            'duplicates'  => count($matches),
        ], $traceId);

        // Issue #12 remediation: append-only audit with full request metadata
        AuditService::log(
            'entity_created',
            (int)$user['id'],
            'entity_profile',
            (int)$profileId,
            null,
            [
                'entity_type'        => $entityType,
                'display_name'       => $displayName,
                'address'            => $address,
                'has_id_last4'       => !empty($idLast4),
                'has_license_last4'  => !empty($licenseLast4),
                'duplicate_flag'     => (bool)$duplicateFlag,
            ],
            RequestContext::ip(),
            RequestContext::device(),
            $traceId
        );

        return [
            'id'             => $profileId,
            'duplicate_flag' => $duplicateFlag,
        ];
    }

    /**
     * Update an existing entity profile.
     */
    public static function update(int $id, array $data, array $user, string $traceId = ''): array
    {
        $entity = Db::table('entity_profiles')->where('id', $id)->find();
        if (!$entity) {
            throw new \think\exception\HttpException(404, 'Entity not found');
        }

        // Scope check
        if (!ScopeService::canAccess($user, $entity['geo_scope_level'], (int)$entity['geo_scope_id'])) {
            throw new \think\exception\HttpException(403, 'Access denied: entity outside your scope');
        }

        $updateData = [];
        if (isset($data['display_name'])) {
            $updateData['display_name'] = trim($data['display_name']);
        }
        if (isset($data['address'])) {
            $updateData['address'] = trim($data['address']);
        }
        if (isset($data['extra_fields'])) {
            // Issue #10 remediation: validate against definitions on update too
            self::validateExtraFields($entity['entity_type'], $data['extra_fields']);
            $updateData['extra_fields_json'] = json_encode($data['extra_fields']);
        }
        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }

        if (!empty($updateData)) {
            Db::table('entity_profiles')->where('id', $id)->update($updateData);
        }

        // Re-check duplicates after update
        $displayName = $updateData['display_name'] ?? $entity['display_name'];
        $address = $updateData['address'] ?? $entity['address'];
        $duplicateFlag = null;
        $matches = DuplicateService::findMatches(
            $displayName, $address,
            $entity['id_last4'], $entity['license_last4'], $id
        );
        if (!empty($matches)) {
            $flagCount = DuplicateService::flagDuplicates($id, $matches);
            if ($flagCount > 0) {
                $duplicateFlag = true;
            }
        }

        LogService::info('entity_updated', ['profile_id' => $id], $traceId);

        return ['id' => $id, 'duplicate_flag' => $duplicateFlag];
    }

    /** Core profile columns that the resolution map may reference. */
    private const MERGE_CORE_FIELDS = [
        'display_name', 'address', 'id_last4', 'license_last4', 'entity_type',
    ];

    /**
     * Merge two profiles: source into target.
     *
     * The `resolution_map` drives which field values survive on the target:
     *   - core fields  : keys from MERGE_CORE_FIELDS, value "source" or "target"
     *   - extra fields  : keys prefixed "ef_<key>",      value "source" or "target"
     *
     * The entire merge (apply + deactivate + flag close + history) is wrapped
     * in a single DB transaction so a failure at any step rolls back cleanly.
     */
    public static function merge(int $sourceId, int $targetId, array $resolutionMap, array $user, string $traceId = ''): array
    {
        $source = Db::table('entity_profiles')->where('id', $sourceId)->find();
        $target = Db::table('entity_profiles')->where('id', $targetId)->find();

        if (!$source || !$target) {
            throw new \think\exception\HttpException(404, 'Profile not found');
        }

        // Scope check both
        if (!ScopeService::canAccess($user, $source['geo_scope_level'], (int)$source['geo_scope_id']) ||
            !ScopeService::canAccess($user, $target['geo_scope_level'], (int)$target['geo_scope_id'])) {
            throw new \think\exception\HttpException(403, 'Access denied: profiles outside your scope');
        }

        // ── Validate + build update set from resolution_map ──────────
        $targetUpdate = [];
        $sourceExtra = !empty($source['extra_fields_json'])
            ? json_decode($source['extra_fields_json'], true) : [];
        $targetExtra = !empty($target['extra_fields_json'])
            ? json_decode($target['extra_fields_json'], true) : [];
        $mergedExtra = $targetExtra; // start from target's extras
        $extraTouched = false;

        foreach ($resolutionMap as $key => $choice) {
            if (!is_string($choice) || !in_array($choice, ['source', 'target'], true)) {
                throw new \think\exception\HttpException(400,
                    "resolution_map['{$key}'] must be 'source' or 'target'"
                );
            }

            // Extra-field key (frontend sends "ef_<field_key>")
            if (str_starts_with($key, 'ef_')) {
                $efKey = substr($key, 3);
                if ($choice === 'source') {
                    $mergedExtra[$efKey] = $sourceExtra[$efKey] ?? null;
                }
                // "target" means keep existing target value — already in $mergedExtra
                $extraTouched = true;
                continue;
            }

            // Core-field key
            if (!in_array($key, self::MERGE_CORE_FIELDS, true)) {
                throw new \think\exception\HttpException(400,
                    "resolution_map contains unknown field '{$key}'"
                );
            }

            if ($choice === 'source') {
                $targetUpdate[$key] = $source[$key];
            }
            // "target" → keep existing value, nothing to write
        }

        if ($extraTouched) {
            $targetUpdate['extra_fields_json'] = json_encode($mergedExtra);
        }

        // ── Atomic merge transaction ─────────────────────────────────
        Db::startTrans();
        try {
            // 1. Apply resolved fields to target profile
            if (!empty($targetUpdate)) {
                Db::table('entity_profiles')
                    ->where('id', $targetId)
                    ->update($targetUpdate);
            }

            // 2. Record merge history (includes pre-merge snapshots + resolution)
            $historyId = Db::table('profile_merge_history')->insertGetId([
                'source_profile_id' => $sourceId,
                'target_profile_id' => $targetId,
                'merged_by'         => $user['id'],
                'diff_json'         => json_encode([
                    'source'     => $source,
                    'target'     => $target,
                    'resolution' => $resolutionMap,
                    'applied'    => $targetUpdate,
                ]),
            ]);

            // 3. Deactivate source
            Db::table('entity_profiles')
                ->where('id', $sourceId)
                ->update(['status' => 'inactive']);

            // 4. Close related duplicate flags
            Db::table('duplicate_flags')
                ->where(function ($q) use ($sourceId, $targetId) {
                    $q->where('left_profile_id', $sourceId)->where('right_profile_id', $targetId);
                })
                ->whereOr(function ($q) use ($sourceId, $targetId) {
                    $q->where('left_profile_id', $targetId)->where('right_profile_id', $sourceId);
                })
                ->update(['status' => 'merged']);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        LogService::info('entity_merged', [
            'source_id'    => $sourceId,
            'target_id'    => $targetId,
            'fields_applied' => array_keys($targetUpdate),
        ], $traceId);

        return [
            'merged_profile_id'   => $targetId,
            'change_history_id'   => $historyId,
        ];
    }
}
