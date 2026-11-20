<?php

namespace App\Data;

use App\Models\ServerAddress;
use Spatie\LaravelData\Data;

class ServerAddressData extends Data
{
    public function __construct(
        public int $id,
        public string $ip,
        public int $version,
        public bool $is_primary,
    ) {}

    public static function fromModel(ServerAddress $address): self
    {
        return new self($address->id, $address->ip, $address->version, $address->is_primary);
    }
}
