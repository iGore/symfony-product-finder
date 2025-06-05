<?php

namespace App\Factory;

use HelgeSverre\Milvus\Milvus as MilvusClient;

/**
 * Factory for creating Milvus client instances
 * 
 * This factory provides a static method for creating properly configured
 * Milvus client instances for connecting to the Zilliz/Milvus vector database.
 */
class MilvusClientFactory
{
    /**
     * Creates and returns a new Milvus client configured with the specified authentication token, host, and port.
     *
     * @param string $token Authentication token for the Milvus server.
     * @param string $host Hostname or IP address of the Milvus server.
     * @param int $port Port number of the Milvus server.
     * @return MilvusClient Configured Milvus client instance.
     */
    public static function create(string $token, string $host, int $port): MilvusClient
    {
        return new MilvusClient(token: $token, host: $host, port: $port);
    }
}
