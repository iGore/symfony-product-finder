<?php

namespace App\Factory;

use HelgeSverre\Milvus\Milvus as MilvusClient;

class MilvusClientFactory
{
    public static function create(string $host, int $port, $token): MilvusClient
    {
        return new MilvusClient(host: $host, port: $port, token: $token);
    }
}