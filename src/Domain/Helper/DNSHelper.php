<?php

namespace OpenCCK\Domain\Helper;

use Amp\Dns\DnsConfig;
use Amp\Dns\DnsConfigLoader;

use Amp\Dns\DnsRecord;
use Amp\Dns\HostLoader;
use Amp\Dns\Rfc1035StubDnsResolver;

use OpenCCK\Infrastructure\API\App;
use Throwable;

use function Amp\delay;
use function Amp\Dns\dnsResolver;
use function Amp\Dns\resolve;

class DNSHelper {
    private float $resolveDelay;

    public function __construct(private array $dnsServers = []) {
        $this->resolveDelay = (\OpenCCK\getEnv('SYS_DNS_RESOLVE_DELAY') ?? 500) / 1000;
    }

    /**
     * @param array $dnsServers
     * @return void
     */
    private function setResolver(array $dnsServers): void {
        dnsResolver(
            new Rfc1035StubDnsResolver(
                null,
                new class ($dnsServers) implements DnsConfigLoader {
                    public function __construct(private readonly array $dnsServers = []) {
                    }

                    public function loadConfig(): DnsConfig {
                        return new DnsConfig($this->dnsServers, (new HostLoader())->loadHosts());
                    }
                }
            )
        );
    }

    /**
     * @param string $domain
     * @return array[]
     */
    public function resolve(string $domain): array {
        $ipv4 = [];
        $ipv6 = [];
        foreach ($this->dnsServers as $server) {
            try {
                $this->setResolver([$server]);
                $ipv4 = array_merge(
                    $ipv4,
                    array_map(fn(DnsRecord $record) => $record->getValue(), resolve($domain, DnsRecord::A))
                );
            } catch (Throwable $e) {
                App::getLogger()->error($e->getMessage(), [$server]);
            }
            delay($this->resolveDelay);

            try {
                $this->setResolver([$server]);
                $ipv6 = array_merge(
                    $ipv6,
                    array_map(fn(DnsRecord $record) => $record->getValue(), resolve($domain, DnsRecord::AAAA))
                );
            } catch (Throwable $e) {
                App::getLogger()->error($e->getMessage(), [$server]);
            }
            delay($this->resolveDelay);
        }
        App::getLogger()->debug('resolve: ' . $domain, [count($ipv4), count($ipv6)]);
        return [$ipv4, $ipv6];
    }
}
