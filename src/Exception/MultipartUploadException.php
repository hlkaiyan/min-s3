<?php
namespace MinS3\Exception;

use MinS3\Multipart\UploadState;

/**
 * 分片上传失败。
 *
 * 通过 getState() 拿到上传状态可以断点续传：把它作为 'state' 选项
 * 传给新的 MultipartUploader，已完成的分片不会重传。
 */
class MultipartUploadException extends \RuntimeException
{
    private UploadState $state;

    /** @var \Throwable[] 各分片的失败原因，键为分片号 */
    private array $partExceptions;

    public function __construct(
        UploadState $state,
        array|\Throwable $partExceptions = [],
        ?\Throwable $previous = null
    ) {
        $this->state = $state;

        if ($partExceptions instanceof \Throwable) {
            $previous = $previous ?? $partExceptions;
            $partExceptions = [];
        }
        $this->partExceptions = $partExceptions;

        $message = $this->buildMessage($state, $partExceptions, $previous);

        parent::__construct($message, 0, $previous);
    }

    public function getState(): UploadState
    {
        return $this->state;
    }

    /**
     * @return \Throwable[] 键为分片号
     */
    public function getPartExceptions(): array
    {
        return $this->partExceptions;
    }

    private function buildMessage(
        UploadState $state,
        array $partExceptions,
        ?\Throwable $previous
    ): string {
        $status = match ($state->getStatus()) {
            UploadState::CREATED   => '创建分片上传',
            UploadState::INITIATED => '上传分片',
            UploadState::COMPLETED => '完成分片上传',
            default                => '分片上传',
        };

        $message = "{$status}失败";

        if ($partExceptions !== []) {
            $details = [];
            foreach ($partExceptions as $partNumber => $e) {
                $details[] = "分片 {$partNumber}: " . $e->getMessage();
            }
            $message .= '（' . count($partExceptions) . ' 个分片出错）'
                . "\n  " . implode("\n  ", $details);
        } elseif ($previous !== null) {
            $message .= ': ' . $previous->getMessage();
        }

        if ($state->getUploadId() !== null) {
            $message .= "\nUploadId: " . $state->getUploadId()
                . '（可用 state 续传，或调用 abortMultipartUpload 清理）';
        }

        return $message;
    }
}
