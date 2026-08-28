<?php
namespace MinS3\Exception;

use MinS3\Http\Request;

/**
 * 网络层失败：DNS 解析、连接超时、TLS 握手等，请求未能到达服务端
 * 或未收到完整响应。这类错误重试通常是安全的。
 */
class ConnectException extends \RuntimeException
{
    private ?Request $request;

    public function __construct(string $message, ?Request $request = null, ?\Throwable $previous = null)
    {
        $this->request = $request;
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }
}
