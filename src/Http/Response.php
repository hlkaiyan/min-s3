<?php
namespace MinS3\Http;

/**
 * 不可变 HTTP 响应。
 */
final class Response
{
    use MessageTrait;

    private const PHRASES = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        416 => 'Range Not Satisfiable',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    private int $statusCode;
    private string $reasonPhrase;

    public function __construct(
        int $status = 200,
        array $headers = [],
        mixed $body = null,
        string $version = '1.1',
        ?string $reason = null
    ) {
        $this->statusCode = $status;
        $this->setHeaders($headers);
        $this->protocol = $version;
        $this->reasonPhrase = $reason ?? (self::PHRASES[$status] ?? '');

        if ($body !== null && $body !== '') {
            $this->stream = Stream::create($body);
        }
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): self
    {
        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (self::PHRASES[$code] ?? '');

        return $new;
    }
}
