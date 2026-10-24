<?php
declare(strict_types=1);
namespace Respawn\Integration\KamailioReference;

use RuntimeException;

final class RoutingControlClient
{
    public function __construct(private readonly array $configuration, private readonly ?\Closure $transport = null)
    {
        $endpoint = $configuration['endpoint'] ?? null;
        $url = is_string($endpoint) ? parse_url($endpoint) : false;
        $allowedHosts = $configuration['allowedHosts'] ?? null;
        if (($configuration['schema'] ?? '') !== 'respawn.routing-control-client-config.v1'
            || !is_string($endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL) || !is_array($url)
            || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || strlen($endpoint) > 2048 || !is_array($allowedHosts) || !array_is_list($allowedHosts) || $allowedHosts === []
            || count(array_filter($allowedHosts, static fn ($host): bool => !is_string($host)
                || strlen($host) > 253 || !preg_match('/^[a-zA-Z0-9.-]+$/D', $host))) !== 0
            || !in_array(strtolower($url['host']), array_map('strtolower', $allowedHosts), true)
            || !is_string($configuration['credentialRef'] ?? null) || !str_starts_with($configuration['credentialRef'], '/')
            || str_contains($configuration['credentialRef'], '..')
            || !preg_match('#^/[a-zA-Z0-9/_\-.]{1,255}$#D', $configuration['credentialRef'])
            || ($configuration['activationEnabled'] ?? null) !== false) {
            throw new RuntimeException('ROUTING_CONTROL_CONFIGURATION_INVALID');
        }
    }

    public function request(array $request): array
    {
        $this->validateRequest($request);
        if ($request['action'] === 'activate') {
            return $this->blockedReceipt($request, 'ROUTING_ACTIVATION_NOT_IMPLEMENTED');
        }
        $body = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > 65536) { throw new RuntimeException('ROUTING_REQUEST_TOO_LARGE'); }
        $response = $this->transport ? ($this->transport)($request) : $this->https($body);
        if (!is_array($response) || ($response['schema'] ?? '') !== 'respawn.routing-control.v1'
            || ($response['action'] ?? '') !== $request['action'] || ($response['operationId'] ?? '') !== $request['operationId']
            || ($response['pbxId'] ?? '') !== $request['pbxId'] || ($response['targetId'] ?? '') !== $request['targetId']
            || ($response['blueprintHash'] ?? '') !== $request['blueprintHash']
            || ($response['revision'] ?? null) !== $request['expectedRevision']
            || ($response['authorityEpoch'] ?? null) !== $request['authorityEpoch']) {
            throw new RuntimeException('ROUTING_CONTROL_OUTCOME_UNKNOWN');
        }
        $observed = $this->timestamp($response['observedAt'] ?? null);
        $expires = $this->timestamp($response['expiresAt'] ?? null);
        if ($observed === false || $expires === false || $observed > time() + 30 || $observed < time() - 60
            || $expires <= $observed || $expires > $observed + 300 || $expires <= time()) {
            throw new RuntimeException('ROUTING_CONTROL_RECEIPT_STALE');
        }
        $allowedStates = $request['action'] === 'prepare' ? ['prepared','blocked','unavailable','unknown']
            : ['verified','blocked','unavailable','unknown'];
        if (!in_array($response['state'] ?? '', $allowedStates, true)) {
            throw new RuntimeException('ROUTING_CONTROL_RECEIPT_INVALID');
        }
        if (in_array($response['state'], ['prepared','verified'], true)
            && (!is_string($response['evidenceRef'] ?? null) || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $response['evidenceRef']))) {
            throw new RuntimeException('ROUTING_CONTROL_EVIDENCE_RECEIPT_MISSING');
        }
        if (in_array($response['state'], ['blocked','unavailable','unknown'], true)
            && (!is_string($response['failureCode'] ?? null) || !preg_match('/^[A-Z0-9_]{3,64}$/D', $response['failureCode']))) {
            throw new RuntimeException('ROUTING_CONTROL_FAILURE_UNSPECIFIED');
        }
        return $response;
    }

    private function validateRequest(array $request): void
    {
        $required = ['schema','action','operationId','pbxId','targetId','blueprintHash','expectedRevision','authorityEpoch','idempotencyKey','readinessEvidence'];
        if (array_diff($required, array_keys($request)) || array_diff(array_keys($request), $required)
            || ($request['schema'] ?? '') !== 'respawn.routing-control.v1'
            || !in_array($request['action'] ?? '', ['prepare','verify','activate'], true)
            || !is_int($request['expectedRevision'] ?? null) || $request['expectedRevision'] < 1
            || !is_int($request['authorityEpoch'] ?? null) || $request['authorityEpoch'] < 1
            || !is_string($request['blueprintHash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['blueprintHash'])
            || !is_string($request['idempotencyKey'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['idempotencyKey'])
            || !is_array($request['readinessEvidence'] ?? null) || !array_is_list($request['readinessEvidence'])) {
            throw new RuntimeException('ROUTING_CONTROL_REQUEST_INVALID');
        }
        foreach (['operationId','pbxId','targetId'] as $field) {
            if (!is_string($request[$field]) || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $request[$field])) {
                throw new RuntimeException('ROUTING_CONTROL_IDENTITY_INVALID');
            }
        }
        foreach ($request['readinessEvidence'] as $evidence) {
            if (!is_array($evidence) || array_diff(['check','state','evidenceRef','observedAt','expiresAt'], array_keys($evidence))
                || array_diff(array_keys($evidence), ['check','state','evidenceRef','observedAt','expiresAt'])
                || !in_array($evidence['check'] ?? '', ['blueprintCoverage','reconstruction','apply','configurationMatch',
                    'connectivity','routingPreparation','registration','inboundCall','outboundCall','bidirectionalAudio'], true)
                || !in_array($evidence['state'] ?? '', ['passed','failed','unknown'], true)
                || !is_string($evidence['evidenceRef'] ?? null)
                || !preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $evidence['evidenceRef'])) {
                throw new RuntimeException('ROUTING_EVIDENCE_INVALID');
            }
            $observed = $this->timestamp($evidence['observedAt'] ?? null);
            $expires = $this->timestamp($evidence['expiresAt'] ?? null);
            if ($observed === false || $expires === false || $observed > time() + 30 || $observed < time() - 60 || $expires <= time()) {
                throw new RuntimeException('ROUTING_EVIDENCE_STALE');
            }
        }
    }

    private function blockedReceipt(array $request, string $code): array
    {
        return ['schema'=>'respawn.routing-control.v1','action'=>'activate','state'=>'blocked',
            'operationId'=>$request['operationId'],'pbxId'=>$request['pbxId'],'targetId'=>$request['targetId'],
            'blueprintHash'=>$request['blueprintHash'],'revision'=>$request['expectedRevision'],
            'authorityEpoch'=>$request['authorityEpoch'],'observedAt'=>gmdate(DATE_ATOM),
            'expiresAt'=>gmdate(DATE_ATOM, time()+30),'failureCode'=>$code];
    }

    private function timestamp(mixed $value): int|false
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})$/D', $value)) { return false; }
        $normal = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normal);
        return $date && $date->format('Y-m-d\TH:i:sP') === $normal ? $date->getTimestamp() : false;
    }

    private function https(string $body): array
    {
        if (!function_exists('curl_init')) { throw new RuntimeException('PHP_CURL_REQUIRED'); }
        $path = $this->configuration['credentialRef'];
        if (!is_file($path) || is_link($path) || realpath($path) !== $path
            || (fileperms($path) & 0077) !== 0 || filesize($path) > 4096) { throw new RuntimeException('ROUTING_TOKEN_FILE_UNSAFE'); }
        for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
            $permissions = fileperms($parent);
            if (($permissions & 0022) !== 0 && !(($permissions & 01000) !== 0 && fileowner($parent) === 0)) {
                throw new RuntimeException('ROUTING_TOKEN_FILE_UNSAFE');
            }
        }
        $token = trim((string) file_get_contents($path));
        if (!preg_match('/^[a-zA-Z0-9._~-]{32,4096}$/D', $token)) { throw new RuntimeException('ROUTING_TOKEN_INVALID'); }
        $host = parse_url($this->configuration['endpoint'], PHP_URL_HOST);
        $addresses = gethostbynamel($host);
        if (!$addresses) { throw new RuntimeException('ROUTING_CONTROL_DNS_FAILED'); }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RuntimeException('ROUTING_CONTROL_ADDRESS_REJECTED');
            }
        }
        $port = parse_url($this->configuration['endpoint'], PHP_URL_PORT) ?: 443;
        $handle = curl_init($this->configuration['endpoint']);
        $response = '';
        curl_setopt_array($handle, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_PROXY=>'', CURLOPT_RESOLVE=>[$host.':'.$port.':'.$addresses[0]],
            CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 65536) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            }]);
        try {
            if (curl_exec($handle) === false || curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException('ROUTING_CONTROL_OUTCOME_UNKNOWN');
            }
        } finally { curl_close($handle); }
        $decoded = json_decode($response, true, 24, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) { throw new RuntimeException('ROUTING_CONTROL_OUTCOME_UNKNOWN'); }
        return $decoded;
    }
}