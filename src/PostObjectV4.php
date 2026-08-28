<?php
namespace MinS3;

use MinS3\Signature\SignatureV4;

/**
 * 生成浏览器直传表单（POST Object）。
 *
 * 文件从浏览器直接传到 S3，不经过你的服务器，也不暴露密钥。
 * 逻辑取自 aws-sdk-php 的 PostObjectV4。
 *
 *     $post = new PostObjectV4($s3, 'my-bucket',
 *         ['key' => 'uploads/${filename}', 'acl' => 'private'],
 *         [['bucket' => 'my-bucket'], ['starts-with', '$key', 'uploads/'],
 *          ['content-length-range', 0, 10485760]],
 *         '+1 hours'
 *     );
 *     // 渲染成 <form> 时：
 *     $post->getFormAttributes();  // action / method / enctype
 *     $post->getFormInputs();      // 全部隐藏字段，含 Policy 与签名
 *
 * @link https://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-post-example.html
 */
class PostObjectV4
{
    private S3Client $client;
    private string $bucket;
    private array $formAttributes;
    private array $formInputs;

    /**
     * @param array $formInputs 表单字段，如 key / acl / Content-Type
     * @param array $options    策略条件数组
     * @param int|string|\DateTimeInterface $expiration 表单有效期
     */
    public function __construct(
        S3Client $client,
        string $bucket,
        array $formInputs,
        array $options = [],
        int|string|\DateTimeInterface $expiration = '+1 hours'
    ) {
        $this->client = $client;
        $this->bucket = $bucket;

        $this->formAttributes = [
            'action'  => $this->generateUri(),
            'method'  => 'POST',
            'enctype' => 'multipart/form-data',
        ];

        $credentials = $client->getCredentials();

        if ($securityToken = $credentials->getSecurityToken()) {
            $options[] = ['x-amz-security-token' => $securityToken];
            $formInputs['X-Amz-Security-Token'] = $securityToken;
        }

        $policy = [
            'expiration' => gmdate('Y-m-d\TH:i:s\Z', $this->toTimestamp($expiration)),
            'conditions' => $options,
        ];

        // 没给 key 时用 ${filename} 占位，S3 会用上传的文件名
        $this->formInputs = $formInputs + ['key' => '${filename}'];
        $this->formInputs += $this->getPolicyAndSignature($credentials, $policy);
    }

    public function getClient(): S3Client
    {
        return $this->client;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * @return array action / method / enctype
     */
    public function getFormAttributes(): array
    {
        return $this->formAttributes;
    }

    public function setFormAttribute(string $attribute, string $value): void
    {
        $this->formAttributes[$attribute] = $value;
    }

    /**
     * @return array 全部表单字段，直接渲染成 hidden input
     */
    public function getFormInputs(): array
    {
        return $this->formInputs;
    }

    public function setFormInput(string $field, string $value): void
    {
        $this->formInputs[$field] = $value;
    }

    private function generateUri(): string
    {
        $uri = $this->client->getEndpoint();

        if ($this->client->getConfig('use_path_style_endpoint') === true
            || ($uri->getScheme() === 'https' && str_contains($this->bucket, '.'))
        ) {
            return (string) $uri->withPath("/{$this->bucket}");
        }

        if (!str_starts_with($uri->getHost(), $this->bucket . '.')) {
            $uri = $uri->withHost($this->bucket . '.' . $uri->getHost());
        }

        return (string) $uri;
    }

    private function getPolicyAndSignature(Credentials $credentials, array $policy): array
    {
        $ldt = gmdate(SignatureV4::ISO8601_BASIC);
        $sdt = substr($ldt, 0, 8);
        $region = $this->client->getRegion();

        $policy['conditions'][] = ['X-Amz-Date' => $ldt];

        $scope = SignatureV4::createScope($sdt, $region, 's3');
        $creds = "{$credentials->getAccessKeyId()}/{$scope}";
        $policy['conditions'][] = ['X-Amz-Credential' => $creds];
        $policy['conditions'][] = ['X-Amz-Algorithm' => 'AWS4-HMAC-SHA256'];

        $jsonPolicy64 = base64_encode(json_encode($policy));
        $key = SignatureV4::createSigningKey($sdt, $region, 's3', $credentials->getSecretKey());

        return [
            'X-Amz-Credential' => $creds,
            'X-Amz-Algorithm'  => 'AWS4-HMAC-SHA256',
            'X-Amz-Date'       => $ldt,
            'Policy'           => $jsonPolicy64,
            'X-Amz-Signature'  => bin2hex(hash_hmac('sha256', $jsonPolicy64, $key, true)),
        ];
    }

    private function toTimestamp(int|string|\DateTimeInterface $expiration): int
    {
        if ($expiration instanceof \DateTimeInterface) {
            return $expiration->getTimestamp();
        }

        if (is_int($expiration)) {
            return $expiration;
        }

        $timestamp = strtotime($expiration);
        if ($timestamp === false) {
            throw new \InvalidArgumentException("无法解析过期时间: {$expiration}");
        }

        return $timestamp;
    }
}
