<?php

namespace S3Server\Util;

class SignatureUtil
{
    /**
     * 生成预签名URL
     * @param string $method HTTP方法 (GET/PUT)
     * @param string $bucket 桶名
     * @param string $key 对象键
     * @param string $accessKey 访问密钥
     * @param string $secretKey 密钥
     * @param int $expires 过期时间(秒)
     * @param array $headers 额外的头部
     * @param array $queryParams 额外的查询参数
     * @return string
     */
    public static function generatePresignedUrl(
        string $method,
        string $bucket,
        string $key,
        string $accessKey,
        string $secretKey,
        int $expires = 3600,
        array $headers = [],
        array $queryParams = []
    ): string {
        $timestamp = time();
        $expiration = $timestamp + $expires;
        
        // 基本参数
        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $accessKey . '/' . gmdate('Ymd', $timestamp) . '/us-east-1/s3/aws4_request',
            'X-Amz-Date' => gmdate('Ymd\THis\Z', $timestamp),
            'X-Amz-Expires' => $expires,
            'X-Amz-SignedHeaders' => 'host'
        ];
        
        // 合并额外的查询参数
        $params = array_merge($params, $queryParams);
        
        // 规范化请求
        $canonicalRequest = self::createCanonicalRequest($method, $bucket, $key, $params, $headers);
        
        // 计算签名
        $stringToSign = self::createStringToSign($canonicalRequest, $timestamp);
        $signature = self::calculateSignature($stringToSign, $secretKey, $timestamp);
        
        // 构建URL
        $params['X-Amz-Signature'] = $signature;
        $queryString = http_build_query($params);
        
        return sprintf('/%s/%s?%s', $bucket, $key, $queryString);
    }
    
    /**
     * 验证预签名URL
     * @param string $method HTTP方法
     * @param string $bucket 桶名
     * @param string $key 对象键
     * @param array $params 查询参数
     * @param string $secretKey 密钥
     * @return bool
     */
    public static function verifyPresignedUrl(
        string $method,
        string $bucket,
        string $key,
        array $params,
        string $secretKey
    ): bool {
        // 验证过期时间
        $expires = (int)($params['X-Amz-Expires'] ?? 0);
        $timestamp = strtotime(substr($params['X-Amz-Date'], 0, 8));
        if (time() > $timestamp + $expires) {
            return false;
        }
        
        // 获取原始签名
        $originalSignature = $params['X-Amz-Signature'] ?? '';
        unset($params['X-Amz-Signature']);
        
        // 重新计算签名
        $canonicalRequest = self::createCanonicalRequest($method, $bucket, $key, $params, []);
        $stringToSign = self::createStringToSign($canonicalRequest, $timestamp);
        $calculatedSignature = self::calculateSignature($stringToSign, $secretKey, $timestamp);
        
        return hash_equals($originalSignature, $calculatedSignature);
    }
    
    private static function createCanonicalRequest(
        string $method,
        string $bucket,
        string $key,
        array $params,
        array $headers
    ): string {
        $canonicalUri = '/' . $bucket . '/' . $key;
        
        // 规范化查询字符串
        ksort($params);
        $canonicalQueryString = http_build_query($params);
        
        // 规范化头部
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim($value) . "\n";
        }
        
        $signedHeaders = implode(';', array_map('strtolower', array_keys($headers)));
        
        return implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            'UNSIGNED-PAYLOAD'
        ]);
    }
    
    private static function createStringToSign(string $canonicalRequest, int $timestamp): string
    {
        return implode("\n", [
            'AWS4-HMAC-SHA256',
            gmdate('Ymd\THis\Z', $timestamp),
            gmdate('Ymd', $timestamp) . '/us-east-1/s3/aws4_request',
            hash('sha256', $canonicalRequest)
        ]);
    }
    
    private static function calculateSignature(string $stringToSign, string $secretKey, int $timestamp): string
    {
        $date = gmdate('Ymd', $timestamp);
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $regionKey = hash_hmac('sha256', 'us-east-1', $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        
        return hash_hmac('sha256', $stringToSign, $signingKey);
    }
}
