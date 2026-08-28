<?php
namespace MinS3\Exception;

use MinS3\Command;
use MinS3\Http\Request;
use MinS3\Http\Response;
use MinS3\Result;

/**
 * S3 操作失败时抛出的异常。
 *
 * 取值方法名与 aws-sdk-php 的 AwsException 保持一致（getAwsErrorCode 等），
 * 便于从原 SDK 迁移；同时提供去掉 Aws 前缀的短别名。
 */
class S3Exception extends \RuntimeException
{
    private ?Command $command;
    private ?Response $response = null;
    private ?Request $request = null;
    private ?Result $result = null;

    private ?string $errorCode = null;
    private ?string $errorType = null;
    private ?string $requestId = null;
    private ?string $errorMessage = null;
    private bool $connectionError = false;
    private array $transferInfo = [];

    public function __construct(
        string $message,
        ?Command $command = null,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        $this->command = $command;
        $this->response = $context['response'] ?? null;
        $this->request = $context['request'] ?? null;
        $this->result = $context['result'] ?? null;
        $this->errorCode = $context['code'] ?? null;
        $this->errorType = $context['type'] ?? null;
        $this->requestId = $context['request_id'] ?? null;
        $this->errorMessage = $context['message'] ?? null;
        $this->connectionError = (bool) ($context['connection_error'] ?? false);
        $this->transferInfo = $context['transfer_stats'] ?? [];

        parent::__construct($message, 0, $previous);
    }

    public function getCommand(): ?Command
    {
        return $this->command;
    }

    /**
     * 操作名，例如 PutObject。
     */
    public function getCommandName(): ?string
    {
        return $this->command?->getName();
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }

    public function getResult(): ?Result
    {
        return $this->result;
    }

    /**
     * HTTP 状态码，无响应时返回 null。
     */
    public function getStatusCode(): ?int
    {
        return $this->response?->getStatusCode();
    }

    /**
     * S3 错误码，例如 NoSuchBucket、AccessDenied。
     */
    public function getAwsErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * 'client'（4xx）或 'server'（5xx）。
     */
    public function getAwsErrorType(): ?string
    {
        return $this->errorType;
    }

    public function getAwsErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getAwsRequestId(): ?string
    {
        return $this->requestId;
    }

    /** getAwsErrorCode 的短别名 */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** getAwsErrorMessage 的短别名 */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * 是否为连接层错误（DNS、超时、TLS 握手等），而非服务端返回的错误。
     */
    public function isConnectionError(): bool
    {
        return $this->connectionError;
    }

    public function getTransferInfo(?string $name = null): mixed
    {
        if ($name === null) {
            return $this->transferInfo;
        }

        return $this->transferInfo[$name] ?? null;
    }
}
