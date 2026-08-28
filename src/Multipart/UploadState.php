<?php
namespace MinS3\Multipart;

/**
 * 分片上传的进度状态。
 *
 * 上传失败时可以从异常里取出它，序列化保存，之后作为 'state' 选项
 * 传给新的 MultipartUploader 续传，已完成的分片不会重传。
 */
class UploadState implements \Serializable
{
    public const CREATED = 0;
    public const INITIATED = 1;
    public const COMPLETED = 2;

    /** @var array{Bucket: string, Key: string, UploadId?: string} */
    private array $id;

    private ?int $partSize = null;

    /** @var array<int, array> 分片号 => 完成信息（含 ETag） */
    private array $uploadedParts = [];

    private int $status = self::CREATED;

    /**
     * @param array $id Bucket / Key（以及续传时的 UploadId）
     */
    public function __construct(array $id)
    {
        $this->id = $id;
    }

    /**
     * @return array Bucket / Key / UploadId
     */
    public function getId(): array
    {
        return $this->id;
    }

    public function getUploadId(): ?string
    {
        return $this->id['UploadId'] ?? null;
    }

    public function setUploadId(string $uploadId): void
    {
        $this->id['UploadId'] = $uploadId;
    }

    public function getPartSize(): ?int
    {
        return $this->partSize;
    }

    public function setPartSize(int $partSize): void
    {
        $this->partSize = $partSize;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function isInitiated(): bool
    {
        return $this->status >= self::INITIATED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function hasPart(int $partNumber): bool
    {
        return isset($this->uploadedParts[$partNumber]);
    }

    /**
     * @param array $partData 至少包含 PartNumber 与 ETag
     */
    public function markPartUploaded(int $partNumber, array $partData): void
    {
        $this->uploadedParts[$partNumber] = $partData;
    }

    /**
     * @return array<int, array> 按分片号升序，CompleteMultipartUpload 要求有序
     */
    public function getUploadedParts(): array
    {
        ksort($this->uploadedParts);

        return $this->uploadedParts;
    }

    public function countUploadedParts(): int
    {
        return count($this->uploadedParts);
    }

    /**
     * 已上传的字节数，用于展示进度。
     */
    public function getUploadedBytes(): int
    {
        $bytes = 0;
        foreach ($this->uploadedParts as $part) {
            $bytes += $part['@size'] ?? 0;
        }

        return $bytes;
    }

    public function __serialize(): array
    {
        return [
            'id'            => $this->id,
            'partSize'      => $this->partSize,
            'uploadedParts' => $this->uploadedParts,
            'status'        => $this->status,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->partSize = $data['partSize'];
        $this->uploadedParts = $data['uploadedParts'];
        $this->status = $data['status'];
    }

    /**
     * @deprecated 由 __serialize 取代，保留是为了兼容 PHP 的 Serializable 接口
     */
    public function serialize(): string
    {
        return serialize($this->__serialize());
    }

    /**
     * @deprecated 由 __unserialize 取代
     */
    public function unserialize($data): void
    {
        $this->__unserialize(unserialize($data));
    }
}
