<?php
namespace Garanti\Http;

interface HttpClient
{
    /** @return array{status:int, body:string} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
