<?php
declare(strict_types=1);

namespace tests\api;

use PHPUnit\Framework\TestCase;

/**
 * Guided merge workflow test (Fix B — resolution applied).
 *
 * Verifies:
 *  - resolution_map field choices are APPLIED to the target profile
 *  - extra-field choices are applied correctly
 *  - source profile is deactivated
 *  - duplicate flags are closed
 *  - merge history is recorded and visible
 *  - invalid map values are rejected with 400
 */
class GuidedMergeTest extends TestCase
{
    private string $baseUrl;
    private string $token;

    protected function setUp(): void
    {
        $this->baseUrl = getenv('API_BASE_URL') ?: 'http://localhost:8000';
        $this->token = $this->createFarmer();
    }

    // ──────────────────────────────────────────────────────────────
    //  Core-field resolution applied to target
    // ──────────────────────────────────────────────────────────────

    /**
     * Create two entities with DIFFERENT addresses, merge choosing the
     * source address, then verify the target row actually has it.
     */
    public function testCoreFieldResolutionAppliedToTarget(): void
    {
        $ts = microtime(true);
        $srcName = 'Src_' . $ts;
        $tgtName = 'Tgt_' . $ts;
        $srcAddr = '111 Source Road';
        $tgtAddr = '222 Target Lane';

        $src = $this->post('/entities', [
            'entity_type' => 'farmer',
            'display_name' => $srcName,
            'address'      => $srcAddr,
            'id_last4'     => '7777',
        ], $this->token);
        $this->assertEquals(201, $src['status']);
        $srcId = $src['data']['id'];

        $tgt = $this->post('/entities', [
            'entity_type' => 'farmer',
            'display_name' => $tgtName,
            'address'      => $tgtAddr,
            'id_last4'     => '8888',
        ], $this->token);
        $this->assertEquals(201, $tgt['status']);
        $tgtId = $tgt['data']['id'];

        // Merge: keep source display_name + address, keep target id_last4
        $mergeResp = $this->post('/entities/' . $srcId . '/merge', [
            'target_id'      => $tgtId,
            'resolution_map' => [
                'display_name' => 'source',
                'address'      => 'source',
                'id_last4'     => 'target',
            ],
        ], $this->token);
        $this->assertEquals(200, $mergeResp['status'], 'Merge: ' . json_encode($mergeResp['data']));
        $this->assertEquals($tgtId, $mergeResp['data']['merged_profile_id']);
        $this->assertArrayHasKey('change_history_id', $mergeResp['data']);

        // Read target and assert fields were applied
        $detail = $this->get('/entities/' . $tgtId, $this->token);
        $this->assertEquals(200, $detail['status']);
        $p = $detail['data']['profile'];

        $this->assertEquals($srcName, $p['display_name'],
            'display_name should be source value after merge');
        $this->assertEquals($srcAddr, $p['address'],
            'address should be source value after merge');
        $this->assertEquals('8888', $p['id_last4'],
            'id_last4 should remain target value after merge');
    }

    // ──────────────────────────────────────────────────────────────
    //  Extra-field resolution applied to target
    // ──────────────────────────────────────────────────────────────

    public function testExtraFieldResolutionApplied(): void
    {
        $ts = microtime(true);

        // Source has primary_crop = "Corn"
        $src = $this->post('/entities', [
            'entity_type' => 'farmer',
            'display_name' => 'EFSrc_' . $ts,
            'address'      => 'EF Road',
            'extra_fields' => ['primary_crop' => 'Corn', 'land_area_acres' => 10],
        ], $this->token);
        $this->assertEquals(201, $src['status']);

        // Target has primary_crop = "Wheat"
        $tgt = $this->post('/entities', [
            'entity_type' => 'farmer',
            'display_name' => 'EFTgt_' . $ts,
            'address'      => 'EF Road',
            'extra_fields' => ['primary_crop' => 'Wheat', 'land_area_acres' => 20],
        ], $this->token);
        $this->assertEquals(201, $tgt['status']);

        // Merge: take primary_crop from source, land_area_acres from target
        $mergeResp = $this->post('/entities/' . $src['data']['id'] . '/merge', [
            'target_id'      => $tgt['data']['id'],
            'resolution_map' => [
                'ef_primary_crop'    => 'source',
                'ef_land_area_acres' => 'target',
            ],
        ], $this->token);
        $this->assertEquals(200, $mergeResp['status']);

        // Read target and verify extra fields
        $detail = $this->get('/entities/' . $tgt['data']['id'], $this->token);
        $extra = $detail['data']['profile']['extra_fields'];

        $this->assertEquals('Corn', $extra['primary_crop'],
            'primary_crop should be source value after merge');
        $this->assertEquals(20, $extra['land_area_acres'],
            'land_area_acres should remain target value after merge');
    }

    // ──────────────────────────────────────────────────────────────
    //  Existing outcomes preserved
    // ──────────────────────────────────────────────────────────────

    public function testSourceDeactivatedAndFlagsClosed(): void
    {
        $name = 'FlagClose_' . time();
        $addr = '100 Flag Street';

        $src = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => $name,
            'address' => $addr, 'id_last4' => '5555',
        ], $this->token);
        $srcId = $src['data']['id'];

        $tgt = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => $name,
            'address' => $addr, 'id_last4' => '5555',
        ], $this->token);
        $tgtId = $tgt['data']['id'];
        $this->assertTrue($tgt['data']['duplicate_flag']);

        $this->post('/entities/' . $srcId . '/merge', [
            'target_id' => $tgtId,
            'resolution_map' => ['display_name' => 'target'],
        ], $this->token);

        // Source inactive
        $srcDetail = $this->get('/entities/' . $srcId, $this->token);
        $this->assertEquals('inactive', $srcDetail['data']['profile']['status']);

        // Merge history visible
        $tgtDetail = $this->get('/entities/' . $tgtId, $this->token);
        $this->assertGreaterThanOrEqual(1, count($tgtDetail['data']['merge_history']));

        // Duplicate flags between source/target closed
        $openFlags = array_filter($tgtDetail['data']['duplicate_flags'], function ($f) use ($srcId) {
            return ($f['left_profile_id'] == $srcId || $f['right_profile_id'] == $srcId)
                && $f['status'] === 'open';
        });
        $this->assertEmpty($openFlags);
    }

    // ──────────────────────────────────────────────────────────────
    //  Negative cases
    // ──────────────────────────────────────────────────────────────

    /** Invalid map value (not "source" or "target") -> 400 */
    public function testInvalidResolutionValueReturns400(): void
    {
        $src = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'BadVal ' . time(), 'address' => 'X',
        ], $this->token);
        $tgt = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'BadVal2 ' . time(), 'address' => 'Y',
        ], $this->token);

        $resp = $this->post('/entities/' . $src['data']['id'] . '/merge', [
            'target_id' => $tgt['data']['id'],
            'resolution_map' => ['display_name' => 'invalid_choice'],
        ], $this->token);
        $this->assertEquals(400, $resp['status'], 'Invalid resolution value must be rejected');
    }

    /** Unknown core-field key in resolution_map -> 400 */
    public function testUnknownFieldKeyReturns400(): void
    {
        $src = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'UKKey ' . time(), 'address' => 'X',
        ], $this->token);
        $tgt = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'UKKey2 ' . time(), 'address' => 'Y',
        ], $this->token);

        $resp = $this->post('/entities/' . $src['data']['id'] . '/merge', [
            'target_id' => $tgt['data']['id'],
            'resolution_map' => ['nonexistent_column' => 'source'],
        ], $this->token);
        $this->assertEquals(400, $resp['status'], 'Unknown field key must be rejected');
    }

    /** Merge without target_id returns 400 */
    public function testMergeMissingTargetReturns400(): void
    {
        $e = $this->post('/entities', [
            'entity_type' => 'farmer', 'display_name' => 'NoTarget ' . time(), 'address' => 'X',
        ], $this->token);
        $resp = $this->post('/entities/' . $e['data']['id'] . '/merge', [
            'target_id' => 0,
        ], $this->token);
        $this->assertEquals(400, $resp['status']);
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    private function createFarmer(): string
    {
        $u = 'mg_' . bin2hex(random_bytes(4));
        $p = 'SecureP@ss1234';
        $this->post('/auth/register', ['username' => $u, 'password' => $p, 'role' => 'farmer', 'geo_scope_level' => 'village', 'geo_scope_id' => 3]);
        $r = $this->post('/auth/login', ['username' => $u, 'password' => $p]);
        return $r['data']['access_token'];
    }

    private function post(string $path, array $body, ?string $token = null): array
    {
        if (in_array($path, ['/auth/register', '/auth/login'], true) && !isset($body['captcha_id'])) {
            $body = array_merge($body, $this->autoCaptcha());
        }
        $ch = curl_init($this->baseUrl . $path);
        $h = ['Content-Type: application/json'];
        if ($token) $h[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode($body)]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function get(string $path, string $token): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch); $s = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $s, 'data' => json_decode($body, true)];
    }

    private function autoCaptcha(): array
    {
        $ch = curl_init($this->baseUrl . '/auth/captcha');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $raw = curl_exec($ch); curl_close($ch);
        $d = json_decode($raw, true) ?: [];
        if (preg_match('/(-?\d+)\s*([+\-*])\s*(-?\d+)/', $d['question'] ?? '', $m)) {
            $a = (int)$m[1]; $op = $m[2]; $b = (int)$m[3];
            $ans = match ($op) { '+' => $a + $b, '-' => $a - $b, '*' => $a * $b, default => 0 };
            return ['captcha_id' => $d['challenge_id'] ?? '', 'captcha_answer' => (string)$ans];
        }
        return ['captcha_id' => '', 'captcha_answer' => ''];
    }
}
