<?php
namespace Garanti\Tests;

use Garanti\Http\HttpClient;

class HttpFake implements HttpClient
{
    /** @var array<int,array> */
    public array $calls = [];
    /** @var array<int,array{status:int,body:string}> */
    private array $queue = [];

    public function push(int $status, string $body): void
    {
        $this->queue[] = ['status' => $status, 'body' => $body];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return array_shift($this->queue) ?? ['status' => 500, 'body' => ''];
    }
}
