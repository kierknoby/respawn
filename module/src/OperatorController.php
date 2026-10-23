<?php
declare(strict_types=1);
namespace Respawn;

use RuntimeException;

final class OperatorController
{
    private const ACTIONS = ['capabilities','export','publish','verify','respawn','operation','cancel','teardown','activate','advance','reconcileSubmission','retrySubmission'];

    public function handle(Application $application, string $authenticatedPbxId, string $expectedPbxId,
        array $post, string $sessionCsrf, int $contentLength): array
    {
        if (!hash_equals($expectedPbxId, $authenticatedPbxId)) { throw new RuntimeException('OPERATOR_PBX_IDENTITY_MISMATCH'); }
        if ($contentLength < 0 || $contentLength > 16384) { throw new RuntimeException('OPERATOR_REQUEST_TOO_LARGE'); }
        if (!is_string($post['csrf'] ?? null) || !hash_equals($sessionCsrf, $post['csrf'])) {
            throw new RuntimeException('OPERATOR_CSRF_INVALID');
        }
        $action = $post['action'] ?? '';
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) { throw new RuntimeException('OPERATOR_ACTION_INVALID'); }
        $arguments = [];
        if (in_array($action, ['reconcileSubmission','retrySubmission'], true)) {
            $submissionId = $post['submissionId'] ?? '';
            if (!is_string($submissionId) || !preg_match('/^sub-[a-f0-9]{64}$/D', $submissionId)) {
                throw new RuntimeException('SUBMISSION_ID_INVALID');
            }
            $arguments['submissionId'] = $submissionId;
        } else {
            foreach (['id','confirmation','disposition','area'] as $field) {
                if (isset($post[$field]) && is_string($post[$field]) && strlen($post[$field]) <= 256) { $arguments[$field] = $post[$field]; }
            }
            if ($action === 'respawn') {
                $sessionKey = $post['sessionLaunchKey'] ?? '';
                if (!is_string($sessionKey) || !preg_match('/^[a-f0-9]{48,96}$/D', $sessionKey)) {
                    throw new RuntimeException('SESSION_LAUNCH_KEY_INVALID');
                }
                $arguments['key'] = $sessionKey;
            }
            if (isset($post['revision']) && ctype_digit((string) $post['revision'])) { $arguments['revision'] = (int) $post['revision']; }
        }
        return $application->runSafely($action, $arguments);
    }
}