<?php
declare(strict_types=1);
namespace Respawn;

use RuntimeException;
use Throwable;

final class Diagnostic
{
    private const MESSAGES = [
        'SECRET_MANIFEST_MISSING' => ['The protected secret manifest is missing.', 'Stage the source-bound manifest with the approved secret workflow, then retry export.'],
        'SECRET_MANIFEST_UNSAFE' => ['The secret manifest permissions or file type are unsafe.', 'Use a regular file with trusted parent directories and mode 0600; do not weaken permissions.'],
        'SECRET_REFERENCE_FILE_UNSAFE' => ['A referenced secret file is unsafe or inaccessible.', 'Restore the protected immutable secret reference with trusted parent directories and mode 0600.'],
        'SECRET_MANIFEST_INVALID' => ['The protected secret manifest is malformed.', 'Repair the manifest structure without exposing secret values, then rerun the non-mutating plan.'],
        'SECRET_REFERENCE_FILE_UNSAFE' => ['A referenced original secret file has unsafe permissions or type.', 'Restore the protected immutable secret file with trusted parents and mode 0600.'],
        'SECRET_REFERENCE_UNRESOLVED' => ['A required original authentication secret could not be resolved.', 'Restore the exact immutable source secret reference. Do not generate a replacement credential.'],
        'SECRET_SCOPE_MISMATCH' => ['The secret reference belongs to a different PBX identity.', 'Use the manifest enrolled for this PBX and inspect the blueprint source identity.'],
        'USER_UNSUPPORTED_CONFIGURATION' => ['The source user contains settings outside the selected supported profile.', 'Review the documented coverage gaps; do not silently export a partial user.'],
        'SOURCE_CONFIGURATION_UNSUPPORTED' => ['The source contains fields outside the supported reconstruction profile.', 'Review the coverage report and keep backup recovery for unsupported fields; do not silently omit configuration.'],
        'MODULE_VERSION_MISMATCH' => ['The target module versions do not match the blueprint prerequisites.', 'Install/enable the exact compatible modules using the approved FreePBX procedure, then re-plan.'],
        'MODULE_VERSION_INCOMPATIBLE' => ['A required module is unavailable or has an incompatible version.', 'Check enabled module versions against the blueprint prerequisites before mutation.'],
        'TARGET_CONFIGURATION_CONFLICT' => ['A target object differs from the intended configuration.', 'Inspect the object and reconcile it explicitly; Respawn will not overwrite it.'],
        'PROVIDER_CONNECTION_FAILED' => ['The provider connection could not be verified.', 'Check the saved endpoint, allowed host, protected credential reference and network/TLS policy.'],
        'PROVIDER_RESPONSE_REJECTED' => ['The provider response failed identity, schema or freshness validation.', 'Retain the correlation reference and reconcile through the same provider connection.'],
        'PUBLICATION_OUTCOME_UNKNOWN' => ['The publication request may have been accepted, but no valid acknowledgement was received.', 'Reconcile the original content hash with the same provider connection before retrying.'],
        'LAUNCH_OUTCOME_UNKNOWN' => ['The launch request may have been accepted, but no valid operation receipt was received.', 'Reconcile the original submission ID; do not create a new launch.'],
        'READINESS_EXPIRED' => ['Historical readiness evidence has expired.', 'Refresh verification evidence before activation; reconciliation, cancellation and explicit teardown remain available.'],
        'REVISION_CONFLICT' => ['The operation changed since this control was rendered.', 'Refresh authoritative status and retry only the intended transition.'],
        'TEARDOWN_PARTIAL' => ['Some operation-owned resources remain after teardown.', 'Review the listed resource references and retry cleanup through the original provider connection.'],
        'PROVIDER_NOT_FOUND' => ['The provider authoritatively reports no operation for this submission key.', 'No launch was resubmitted. Verify the provider receipt and choose a deliberate new launch only after review.'],
        'PROVIDER_SWITCH_REQUIRES_RECONCILIATION' => ['This PBX has unresolved work on another provider connection.', 'Reconcile pending submissions and explicitly tear down or retain existing operations through their original connections before launching here.'],
        'PROVIDER_CONNECTION_IDENTITY_MISMATCH' => ['The configured provider connection identity is inconsistent.', 'Restore the original endpoint/trust configuration or explicitly resolve its retained operations first.'],
        'PROVIDER_OPERATION_READINESS_INVALID' => ['The operation is visible but its readiness evidence is malformed or bound to a different request.', 'Do not activate. Reconcile with the original provider and obtain fresh evidence for this exact blueprint.'],
        'PROVIDER_OPERATION_SIMULATED_EVIDENCE_REJECTED' => ['Development or simulated evidence cannot authorize a production operation.', 'Use evidence from the authenticated production provider and its independently verified readiness checks.'],
        'OPERATION_NOT_FOUND_ON_CONNECTION' => ['The operation is not present on this provider connection.', 'Check the original connection record; do not retry management against the currently selected provider.'],
        'SUBMISSION_NOT_OWNED_OR_QUARANTINED' => ['The submission is not authorised for this PBX or its provider connection is unknown.', 'Use the owning PBX session or explicitly reconcile the quarantined legacy record.'],
        'SUBMISSION_ID_INVALID' => ['The local submission identifier is invalid.', 'Select a listed pending submission; do not post a request body or launch key.'],
        'OPERATOR_CSRF_INVALID' => ['The operator request did not pass session CSRF validation.', 'Refresh the authenticated FreePBX page and submit its current form token.'],
        'OPERATOR_PBX_IDENTITY_MISMATCH' => ['The authenticated administrator context does not match the requested PBX identity.', 'Use an administrator session authorised for this PBX.'],
        'OPERATOR_REQUEST_TOO_LARGE' => ['The submitted operator request exceeds the local request limit.', 'Submit only the action and stored identifier, not a copied blueprint or request body.'],
        'OPERATOR_ACTION_INVALID' => ['The requested operator action is not supported.', 'Use a listed Respawn control or the documented CLI command.'],
        'PROVIDER_OPERATION_FAILED' => ['The provider reports a failed operation stage.', 'Review its stage and diagnostic reference, then reconcile before retrying.'],
        'PROVIDER_CAPABILITY_INVALID' => ['The provider returned an unsupported capability value.', 'Check provider contract compatibility and request a validated capability response.'],
        'PROVIDER_PUBLICATION_ACK_INVALID' => ['The provider acknowledgement is not bound to this PBX, connection and blueprint.', 'Reconcile the original publication through this provider connection.'],
    ];

    public static function forThrowable(Throwable $exception, string $stage, ?string $reference = null): array
    {
        $raw = $exception instanceof DiagnosticException ? $exception->diagnostic : null;
        $message = $exception->getMessage();
        $code = $raw['code'] ?? self::infer($message, $stage);
        return self::forCode($code, $raw['stage'] ?? $stage, $reference ?? ($raw['correlationId'] ?? null),
            $raw['outcome'] ?? (str_contains($code, 'UNKNOWN') ? 'unknown' : 'failed'),
            $raw['retryable'] ?? in_array($code, ['PROVIDER_CONNECTION_FAILED','PROVIDER_NOT_FOUND'], true));
    }

    public static function forCode(string $code, string $stage, ?string $reference = null, string $outcome = 'failed', bool $retryable = false): array
    {
        if (!preg_match('/^[A-Z0-9_]{3,64}$/D', $code)) { $code = 'PROVIDER_OPERATION_INVALID'; }
        [$safe, $next] = self::MESSAGES[$code] ?? ['The ' . self::stageName($stage) . ' action could not be confirmed.', 'Inspect the sanitised diagnostic reference, reconcile authoritative state, then retry only if safe.'];
        return ['code' => $code, 'stage' => $stage,
            'outcome' => $outcome,
            'retryable' => $retryable,
            'correlationId' => $reference ?? 'diag-' . bin2hex(random_bytes(8)),
            'message' => $safe, 'nextAction' => $next];
    }

    public static function log(array $diagnostic): void
    {
        $safe = array_intersect_key($diagnostic, array_flip(['code','stage','outcome','retryable','correlationId']));
        error_log('Respawn diagnostic ' . json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function infer(string $message, string $stage): string
    {
        if (str_contains($message, 'SECRET_MANIFEST_MISSING')) { return 'SECRET_MANIFEST_MISSING'; }
        if (str_contains($message, 'SECRET_MANIFEST_UNSAFE')) { return 'SECRET_MANIFEST_UNSAFE'; }
        if (str_contains($message, 'SECRET_REFERENCE_FILE_UNSAFE')) { return 'SECRET_REFERENCE_FILE_UNSAFE'; }
        if (str_contains($message, 'SECRET_REFERENCE')) { return 'SECRET_REFERENCE_UNRESOLVED'; }
        if (str_contains($message, 'SECRET_UNRESOLVED') || str_contains($message, 'SOURCE_DEVICE_OR_SECRET')) { return 'SECRET_REFERENCE_UNRESOLVED'; }
        if (str_contains($message, 'USER_UNSUPPORTED') || str_contains($message, 'PJSIP_PROFILE_UNSUPPORTED')) { return 'SOURCE_CONFIGURATION_UNSUPPORTED'; }
        if (str_contains($message, 'MODULE_VERSION_MISMATCH')) { return 'MODULE_VERSION_MISMATCH'; }
        if (str_contains($message, 'MODULE_VERSION_INCOMPATIBLE') || str_contains($message, 'MODULES_UNAVAILABLE')) { return 'MODULE_VERSION_INCOMPATIBLE'; }
        if (str_contains($message, 'TARGET_CONFIGURATION_CONFLICT') || str_contains($message, 'MISMATCH')) { return 'TARGET_CONFIGURATION_CONFLICT'; }
        if (str_contains($message, 'PROVIDER_CONNECTION_FAILED') || str_contains($message, 'transport failed') || str_contains($message, 'DNS resolution failed')) { return 'PROVIDER_CONNECTION_FAILED'; }
        if (str_contains($message, 'PROVIDER_RESPONSE_REJECTED') || str_contains($message, 'response rejected')) { return 'PROVIDER_RESPONSE_REJECTED'; }
        if (str_contains($message, 'READINESS_EXPIRED')) { return 'READINESS_EXPIRED'; }
        if (str_contains($message, 'REVISION_CONFLICT') || str_contains($message, 'STALE')) { return 'REVISION_CONFLICT'; }
        if (str_contains($message, 'TEARDOWN_PARTIAL')) { return 'TEARDOWN_PARTIAL'; }
        if (str_contains($message, 'PROVIDER_NOT_FOUND')) { return 'PROVIDER_NOT_FOUND'; }
        if (str_contains($message, 'PROVIDER_SWITCH_REQUIRES_RECONCILIATION')) { return 'PROVIDER_SWITCH_REQUIRES_RECONCILIATION'; }
        if (str_contains($message, 'PROVIDER_CONNECTION_IDENTITY_MISMATCH')) { return 'PROVIDER_CONNECTION_IDENTITY_MISMATCH'; }
        if (str_contains($message, 'PROVIDER_OPERATION_READINESS_INVALID')) { return 'PROVIDER_OPERATION_READINESS_INVALID'; }
        if (str_contains($message, 'PROVIDER_OPERATION_SIMULATED_EVIDENCE_REJECTED')) { return 'PROVIDER_OPERATION_SIMULATED_EVIDENCE_REJECTED'; }
        if (str_contains($message, 'OPERATION_NOT_FOUND_ON_CONNECTION')) { return 'OPERATION_NOT_FOUND_ON_CONNECTION'; }
        if (str_contains($message, 'SUBMISSION_NOT_OWNED_OR_QUARANTINED')) { return 'SUBMISSION_NOT_OWNED_OR_QUARANTINED'; }
        if (str_contains($message, 'SUBMISSION_ID_INVALID')) { return 'SUBMISSION_ID_INVALID'; }
        if (str_contains($message, 'OPERATOR_CSRF_INVALID')) { return 'OPERATOR_CSRF_INVALID'; }
        if (str_contains($message, 'OPERATOR_PBX_IDENTITY_MISMATCH')) { return 'OPERATOR_PBX_IDENTITY_MISMATCH'; }
        if (str_contains($message, 'OPERATOR_REQUEST_TOO_LARGE')) { return 'OPERATOR_REQUEST_TOO_LARGE'; }
        if (str_contains($message, 'OPERATOR_ACTION_INVALID')) { return 'OPERATOR_ACTION_INVALID'; }
        if (str_contains($message, 'PROVIDER_OPERATION_FAILED')) { return 'PROVIDER_OPERATION_FAILED'; }
        if (str_contains($message, 'PROVIDER_CAPABILITY_INVALID')) { return 'PROVIDER_CAPABILITY_INVALID'; }
        if (str_contains($message, 'PROVIDER_PUBLICATION_ACK_INVALID')) { return 'PROVIDER_PUBLICATION_ACK_INVALID'; }
        if ($stage === 'publish') { return 'PUBLICATION_OUTCOME_UNKNOWN'; }
        if ($stage === 'respawn' || $stage === 'reconcile') { return 'LAUNCH_OUTCOME_UNKNOWN'; }
        if ($stage === 'teardown') { return 'TEARDOWN_PARTIAL'; }
        return 'ACTION_FAILED';
    }

    private static function stageName(string $stage): string
    {
        return match ($stage) { 'respawn' => 'launch', 'publish' => 'publication', 'reconstruct' => 'reconstruction', default => $stage };
    }
}

final class DiagnosticException extends RuntimeException
{
    public function __construct(public readonly array $diagnostic, ?Throwable $previous = null)
    {
        parent::__construct($diagnostic['code'], 0, $previous);
    }
}