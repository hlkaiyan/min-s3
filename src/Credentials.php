<?php
namespace MinS3;

/**
 * 访问凭证。
 *
 * 自建 S3 通常直接传入静态的 key/secret；如果服务端签发的是临时凭证，
 * 一并传 token 与 expires 即可。
 */
class Credentials
{
    private string $key;
    private string $secret;
    private ?string $token;
    private ?int $expires;

    public function __construct(
        string $key,
        string $secret,
        ?string $token = null,
        ?int $expires = null
    ) {
        $this->key = $key;
        $this->secret = $secret;
        $this->token = $token;
        $this->expires = $expires;
    }

    /**
     * 由数组构造，接受 aws-sdk-php 的键名写法。
     *
     * @param array $config key/secret/token/expires
     */
    public static function fromArray(array $config): self
    {
        if (!isset($config['key'], $config['secret'])) {
            throw new \InvalidArgumentException(
                'credentials 数组必须同时包含 key 与 secret'
            );
        }

        return new self(
            $config['key'],
            $config['secret'],
            $config['token'] ?? null,
            $config['expires'] ?? null
        );
    }

    public function getAccessKeyId(): string
    {
        return $this->key;
    }

    public function getSecretKey(): string
    {
        return $this->secret;
    }

    public function getSecurityToken(): ?string
    {
        return $this->token;
    }

    public function getExpiration(): ?int
    {
        return $this->expires;
    }

    public function isExpired(): bool
    {
        return $this->expires !== null && time() >= $this->expires;
    }

    public function toArray(): array
    {
        return [
            'key'     => $this->key,
            'secret'  => $this->secret,
            'token'   => $this->token,
            'expires' => $this->expires,
        ];
    }

    /**
     * 避免密钥出现在 var_dump / 异常堆栈里。
     */
    public function __debugInfo(): array
    {
        return [
            'key'     => $this->key,
            'secret'  => '***',
            'token'   => $this->token === null ? null : '***',
            'expires' => $this->expires,
        ];
    }
}
