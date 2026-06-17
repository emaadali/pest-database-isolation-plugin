<?php

declare(strict_types=1);

namespace Emaadali\PestNeondbPlugin;

use RuntimeException;

final class NeonApi
{
    public static function createBranch(string $parentBranchId, string $name, bool $schemaOnly = false, ?int $ttlSeconds = null, bool $withEndpoint = true): NeonBranch
    {
        $startedAt = hrtime(true);

        NeonTiming::log('neon.branch.create.start', [
            'parent_branch_id' => $parentBranchId,
            'branch_name' => $name,
            'schema_only' => $schemaOnly,
            'ttl_seconds' => $ttlSeconds,
            'with_endpoint' => $withEndpoint,
        ]);

        $branch = [
            'parent_id' => $parentBranchId,
            'name' => $name,
        ];

        if ($schemaOnly) {
            $branch['init_source'] = 'schema-only';
        }

        if ($ttlSeconds !== null) {
            $branch['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + $ttlSeconds);
        }

        $payload = [
            'branch' => $branch,
        ];

        if ($withEndpoint) {
            $payload['endpoints'] = [
                ['type' => 'read_write'],
            ];
        }

        $response = self::request('POST', '/branches', $payload, "creating Neon branch {$name}");

        $createdBranch = $response['branch'] ?? null;
        $endpoints = $response['endpoints'] ?? [];

        if (! is_array($createdBranch) || ! is_array($endpoints)) {
            throw new RuntimeException('Neon create branch response is missing branch data.');
        }

        $id = $createdBranch['id'] ?? null;
        $branchName = $createdBranch['name'] ?? null;
        [$host, $poolerHost] = self::endpointHosts($endpoints);

        if (! is_string($id) || $id === '' || ! is_string($branchName) || $branchName === '' || ($withEndpoint && $host === null)) {
            throw new RuntimeException('Neon create branch response is missing required branch or endpoint fields.');
        }

        $branch = new NeonBranch($id, $branchName, $host ?? '', $poolerHost);

        NeonTiming::log('neon.branch.create.end', [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'host' => $branch->host,
            'pooler_host' => $branch->poolerHost,
            'duration_ms' => self::durationMs($startedAt),
        ]);

        return $branch;
    }

    public static function deleteBranch(string $branchId): void
    {
        $startedAt = hrtime(true);

        NeonTiming::log('neon.branch.delete.start', [
            'branch_id' => $branchId,
        ]);

        self::request('DELETE', "/branches/{$branchId}", null, "deleting Neon branch {$branchId}", throw: false);

        NeonTiming::log('neon.branch.delete.end', [
            'branch_id' => $branchId,
            'duration_ms' => self::durationMs($startedAt),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<mixed, mixed>
     */
    private static function request(string $method, string $path, ?array $payload, string $action, bool $throw = true): array
    {
        $command = [
            'curl',
            '--fail-with-body',
            '--silent',
            '--show-error',
            '--connect-timeout',
            '10',
            '--max-time',
            '60',
            '--request',
            $method,
            'https://console.neon.tech/api/v2/projects/'.NeonEnvironment::required('NEON_PROJECT_ID').$path,
            '--header',
            'Accept: application/json',
            '--header',
            'Authorization: Bearer '.NeonEnvironment::required('NEON_API_KEY'),
        ];

        if ($payload !== null) {
            $command[] = '--header';
            $command[] = 'Content-Type: application/json';
            $command[] = '--data';
            $command[] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException("Could not start curl while {$action}.");
        }

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            $details = mb_trim($output) !== '' ? $output : $errorOutput;

            if ($throw) {
                throw new RuntimeException("Neon failed while {$action}: {$details}");
            }

            fwrite(STDERR, "Neon failed while {$action}: {$details}".PHP_EOL);

            return [];
        }

        if ($output === '') {
            return [];
        }

        $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($response)) {
            throw new RuntimeException("Neon returned an invalid response while {$action}.");
        }

        return $response;
    }

    /**
     * @param  array<mixed, mixed>  $endpoints
     */
    /**
     * @param  array<mixed, mixed>  $endpoints
     * @return array{0: ?string, 1: ?string}
     */
    private static function endpointHosts(array $endpoints): array
    {
        $directHost = null;
        $poolerHost = null;

        foreach ($endpoints as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }

            $host = $endpoint['host'] ?? null;
            if (is_string($host) && $host !== '') {
                if (str_contains($host, '-pooler.')) {
                    $poolerHost ??= $host;

                    continue;
                }

                $directHost ??= $host;
            }
        }

        $directHost ??= $poolerHost !== null ? NeonEnvironment::directHost($poolerHost) : null;
        $poolerHost ??= $directHost !== null ? NeonEnvironment::poolerHost($directHost) : null;

        return [$directHost, $poolerHost];
    }

    private static function durationMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
