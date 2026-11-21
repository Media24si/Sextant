<?php

namespace App\Versions;

use App\Enums\Software;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A parsed `versions.json`, schema 1 (SPEC §11.3). Software that isn't
 * installed is null; fields Sextant doesn't know are ignored.
 */
final readonly class VersionsFile
{
    public const SCHEMA = 1;

    private const KEYS = ['os', 'kernel', 'nginx', 'php', 'mysql', 'openssl'];

    /**
     * @param  list<SoftwareReading>  $readings
     */
    public function __construct(
        public CarbonImmutable $generatedAt,
        public ?string $hostname,
        public ?string $osPrettyName,
        public array $readings,
    ) {}

    /**
     * @throws InvalidVersionsFile
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new InvalidVersionsFile('not JSON');
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidVersionsFile
     */
    public static function fromArray(array $data): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidVersionsFile('unsupported schema '.json_encode($data['schema'] ?? null));
        }

        foreach (['generated_at', ...self::KEYS] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidVersionsFile("missing {$key}");
            }
        }

        try {
            $generatedAt = CarbonImmutable::parse(self::string($data['generated_at'], 'generated_at'))->utc();
        } catch (InvalidVersionsFile $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InvalidVersionsFile('generated_at is not a date');
        }

        $readings = [];
        $os = self::object($data['os'], 'os');

        if ($os !== null) {
            $readings[] = new SoftwareReading(Software::Os, '', self::string($os['version_id'] ?? $os['id'] ?? null, 'os.version_id'), null, [
                'id' => is_string($os['id'] ?? null) ? $os['id'] : null,
                'pretty_name' => is_string($os['pretty_name'] ?? null) ? $os['pretty_name'] : null,
            ]);
        }

        $kernel = self::object($data['kernel'], 'kernel');

        if ($kernel !== null) {
            $readings[] = new SoftwareReading(Software::Kernel, '', self::string($kernel['version'] ?? null, 'kernel.version'));
        }

        foreach ([Software::Nginx, Software::Mysql, Software::Openssl] as $software) {
            $entry = self::object($data[$software->value], $software->value);

            if ($entry !== null) {
                $readings[] = new SoftwareReading($software, '', self::string($entry['version'] ?? null, "{$software->value}.version"), self::optionalString($entry['package'] ?? null));
            }
        }

        if (! is_array($data['php']) || ! array_is_list($data['php'])) {
            throw new InvalidVersionsFile('php must be a list');
        }

        foreach ($data['php'] as $index => $php) {
            if (! is_array($php)) {
                throw new InvalidVersionsFile("php.{$index} must be an object");
            }

            $readings[] = new SoftwareReading(
                Software::Php,
                self::string($php['branch'] ?? null, "php.{$index}.branch"),
                self::string($php['version'] ?? null, "php.{$index}.version"),
                self::optionalString($php['package'] ?? null),
                ['cli' => ($php['cli'] ?? false) === true, 'fpm' => ($php['fpm'] ?? false) === true],
            );
        }

        return new self(
            generatedAt: $generatedAt,
            hostname: self::optionalString($data['hostname'] ?? null),
            osPrettyName: is_array($os) ? self::optionalString($os['pretty_name'] ?? null) : null,
            readings: $readings,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value, string $key): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidVersionsFile("{$key} must be an object or null");
        }

        return $value;
    }

    private static function string(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 100) {
            throw new InvalidVersionsFile("{$key} must be a short string");
        }

        return trim($value);
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_strimwidth(trim($value), 0, 100) : null;
    }
}
