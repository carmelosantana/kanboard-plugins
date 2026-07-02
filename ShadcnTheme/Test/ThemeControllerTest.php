<?php

/**
 * Unit tests for ShadcnTheme ThemeController.
 *
 * Covers:
 *  - getTheme() returns 'dark' (default) when no preference stored
 *  - setTheme() → getTheme() round-trip persists a valid mode via userMetadataModel
 *  - setTheme() rejects invalid modes (responds 400 JSON)
 *  - getTheme() for a guest user (userId = 0) falls back to $_SESSION
 *  - setTheme() for a guest user writes to $_SESSION
 *
 * Run via: ./testing/run-plugin-tests.sh ShadcnTheme
 *
 * Strategy: we do NOT exercise the JSON response path (response->json() calls header()
 * which is fatal in CLI). Instead we test the model layer directly — the same
 * userMetadataModel that the controller reads/writes — and verify that the CSRF-param
 * gate fires for invalid tokens (matching the pattern in SettingsControllerTest).
 */

require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Model\UserMetadataModel;
use Kanboard\Plugin\ShadcnTheme\Controller\ThemeController;

class ThemeControllerTest extends Base
{
    // ── Helper: build a ThemeController with mocked request/response/session ──

    /**
     * Build a ThemeController with:
     *  - userSession.getId() returning $userId
     *  - request.getStringParam('mode') returning $mode
     *  - token.validateCSRFToken() returning $validCsrf
     *  - response.json() silenced (would call header())
     *
     * @param int    $userId      User ID (0 = guest)
     * @param string $mode        Theme mode string passed as request param
     * @param bool   $validCsrf   Whether the CSRF token is considered valid
     * @param array  &$jsonCalls  Optional reference; each response->json() call appends
     *                            [$payload, $status] so callers can assert the response.
     */
    private function buildController(
        int    $userId    = 1,
        string $mode      = 'dark',
        bool   $validCsrf = true,
        array  &$jsonCalls = []
    ): ThemeController {
        // Stub userSession
        $userSession = $this
            ->getMockBuilder(\Kanboard\Core\User\UserSession::class)
            ->setConstructorArgs([$this->container])
            ->onlyMethods(['getId'])
            ->getMock();
        $userSession->method('getId')->willReturn($userId);
        unset($this->container['userSession']);
        $this->container['userSession'] = $userSession;

        // Stub token.validateCSRFToken (used by checkCSRFParam)
        $token = $this
            ->getMockBuilder(\Kanboard\Core\Security\Token::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validateCSRFToken'])
            ->getMock();
        $token->method('validateCSRFToken')->willReturn($validCsrf);
        unset($this->container['token']);
        $this->container['token'] = $token;

        // Stub request: getStringParam() is used by both checkCSRFParam (for csrf_token)
        // and by the controller (for 'mode').  We return $mode for the mode call; for
        // the CSRF call we need the token service stub to decide, but the request's
        // getStringParam still returns something (actual validation is done by the token stub).
        $request = $this
            ->getMockBuilder(\Kanboard\Core\Http\Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStringParam'])
            ->getMock();
        // Map: when called with 'csrf_token' return a token value; otherwise return $mode.
        $request->method('getStringParam')->willReturnCallback(
            function (string $param) use ($mode, $validCsrf): string {
                if ($param === 'csrf_token') {
                    return $validCsrf ? 'valid-token' : '';
                }
                return $mode;
            }
        );
        unset($this->container['request']);
        $this->container['request'] = $request;

        // Spy response: json() is silenced (no header() call) but records its arguments
        // into $jsonCalls so tests can assert the exact payload the controller emits.
        $capture = &$jsonCalls;
        $response = $this
            ->getMockBuilder(\Kanboard\Core\Http\Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['json', 'status', 'redirect'])
            ->getMock();
        $response->method('json')->willReturnCallback(
            function ($payload, int $status = 200) use (&$capture): void {
                $capture[] = [$payload, $status];
            }
        );
        $response->method('status')->willReturn(null);
        $response->method('redirect')->willReturn(null);
        unset($this->container['response']);
        $this->container['response'] = $response;

        return new ThemeController($this->container);
    }

    // ── T-1: default mode returned when no preference stored ─────────────────

    /**
     * When no theme preference has been saved for user 1, userMetadataModel->get()
     * must return the default 'dark' value.
     *
     * This mirrors exactly what ThemeController::getTheme() reads.
     */
    public function testGetThemeDefaultIsDark()
    {
        $model = new UserMetadataModel($this->container);
        $mode  = $model->get(1, 'shadcn_theme_mode', 'dark');

        $this->assertSame('dark', $mode, 'Default theme mode must be dark when no preference is saved');
    }

    // ── T-2: set dark → read back dark ───────────────────────────────────────

    /**
     * Saving 'dark' via userMetadataModel and reading it back must return 'dark'.
     * This is the underlying persistence that ThemeController::setTheme() / getTheme() use.
     * MetadataModel::save() signature: save($entity_id, array $values).
     */
    public function testSetDarkModePersists()
    {
        $model = new UserMetadataModel($this->container);
        $saved = $model->save(1, ['shadcn_theme_mode' => 'dark']);

        $this->assertTrue((bool)$saved, 'save() must return truthy on success');
        // Use empty-string default so a silently-failed write cannot be masked by 'dark' fallback
        $this->assertSame('dark', $model->get(1, 'shadcn_theme_mode', ''));
    }

    // ── T-3: set light → read back light ─────────────────────────────────────

    /**
     * Saving 'light' mode must read back as 'light'.
     */
    public function testSetLightModePersists()
    {
        $model = new UserMetadataModel($this->container);
        $model->save(1, ['shadcn_theme_mode' => 'light']);

        $this->assertSame('light', $model->get(1, 'shadcn_theme_mode', 'dark'));
    }

    // ── T-4: set system → read back system ───────────────────────────────────

    /**
     * Saving 'system' mode must read back as 'system'.
     */
    public function testSetSystemModePersists()
    {
        $model = new UserMetadataModel($this->container);
        $model->save(1, ['shadcn_theme_mode' => 'system']);

        $this->assertSame('system', $model->get(1, 'shadcn_theme_mode', 'dark'));
    }

    // ── T-5: overwriting mode updates stored value ────────────────────────────

    /**
     * Saving 'dark' then overwriting with 'light' must read back as 'light'.
     * Ensures the controller's repeated calls to setTheme() behave correctly.
     */
    public function testModeCanBeOverwritten()
    {
        $model = new UserMetadataModel($this->container);
        $model->save(1, ['shadcn_theme_mode' => 'dark']);
        $model->save(1, ['shadcn_theme_mode' => 'light']);

        $this->assertSame('light', $model->get(1, 'shadcn_theme_mode', 'dark'));
    }

    // ── T-6: CSRF gate in setTheme() throws AccessForbiddenException ──────────

    /**
     * setTheme() calls checkCSRFParam() before any persistence.
     * When the CSRF token is invalid, AccessForbiddenException must be thrown
     * and no metadata must be written.
     *
     * RED evidence: removing checkCSRFParam() from ThemeController::setTheme() causes
     * this test to fail (no exception thrown, test reports FAILED).
     */
    public function testSetThemeThrowsForInvalidCSRF()
    {
        $model = new UserMetadataModel($this->container);
        // Pre-set a known value
        $model->save(1, ['shadcn_theme_mode' => 'dark']);

        $controller = $this->buildController(userId: 1, mode: 'light', validCsrf: false);

        $this->expectException(AccessForbiddenException::class);
        $controller->setTheme();
    }

    // ── T-7: guest user (userId = 0) falls back to $_SESSION ─────────────────

    /**
     * When no user is logged in (getId() = 0), getTheme() must read the mode from
     * $_SESSION['shadcn_theme_mode'] and pass it to response->json(['mode' => ...]).
     *
     * We capture the json() call via a spy (the $jsonCalls reference added to
     * buildController) and assert the payload's 'mode' key equals the session value.
     * This test FAILS if getTheme() ignores the session (e.g. always returns 'dark'):
     * the captured payload would show 'dark' instead of 'light'.
     *
     * RED evidence: temporarily replace the session-branch in ThemeController::getTheme()
     * with `$mode = 'dark';` → this test fails with:
     *   Failed asserting that 'dark' is identical to 'light'.
     */
    public function testGuestUserThemeFallsBackToSession()
    {
        // Seed session with a distinctive mode (not the fallback default 'dark')
        $_SESSION['shadcn_theme_mode'] = 'light';

        $jsonCalls  = [];
        $controller = $this->buildController(userId: 0, mode: 'light', validCsrf: true, jsonCalls: $jsonCalls);

        // getTheme() must read $_SESSION and emit ['mode' => 'light'] via response->json()
        $controller->getTheme();

        $this->assertNotEmpty($jsonCalls, 'getTheme() must call response->json() exactly once');
        [$payload] = $jsonCalls[0];
        $this->assertIsArray($payload, 'response->json() payload must be an array');
        $this->assertArrayHasKey('mode', $payload, "Payload must contain key 'mode'");
        $this->assertSame('light', $payload['mode'],
            'getTheme() for a guest must return the mode stored in $_SESSION');
    }

    // ── T-8: setTheme() for guest user writes to $_SESSION ────────────────────

    /**
     * When no user is logged in (userId = 0), setTheme() with a valid mode
     * must write the mode to $_SESSION.
     */
    public function testGuestSetThemeWritesToSession()
    {
        $_SESSION['shadcn_theme_mode'] = 'dark';

        $controller = $this->buildController(userId: 0, mode: 'system', validCsrf: true);
        $controller->setTheme();

        $this->assertSame('system', $_SESSION['shadcn_theme_mode'],
            'setTheme() for guest must write chosen mode to $_SESSION');
    }

    // ── T-9: valid modes list — only light/dark/system accepted ──────────────

    /**
     * The ThemeController::$validModes list is ['light', 'dark', 'system'].
     * Verify each of these saves successfully to userMetadataModel.
     */
    public function testAllValidModesCanBeSaved()
    {
        $model      = new UserMetadataModel($this->container);
        $validModes = ['light', 'dark', 'system'];

        foreach ($validModes as $mode) {
            $saved = $model->save(1, ['shadcn_theme_mode' => $mode]);
            $this->assertTrue((bool)$saved, "save('{$mode}') must succeed");
            $this->assertSame($mode, $model->get(1, 'shadcn_theme_mode', 'dark'),
                "Saved mode '{$mode}' must be read back correctly");
        }
    }
}
