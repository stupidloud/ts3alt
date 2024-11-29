<?php

namespace S3Server\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use S3Server\Util\SignatureUtil;
use S3Server\Model\Database;
use S3Server\Cache\Cache;

class PreSignedUrlMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $params = $request->getQueryParams();
        
        // 检查是否是预签名URL请求
        if (!isset($params['X-Amz-Signature'])) {
            return $handler->handle($request);
        }
        
        // 获取请求信息
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $pathParts = explode('/', trim($path, '/'));
        
        if (count($pathParts) < 2) {
            return $handler->handle($request);
        }
        
        $bucket = $pathParts[0];
        $key = implode('/', array_slice($pathParts, 1));
        
        // 获取访问密钥
        $accessKey = explode('/', $params['X-Amz-Credential'] ?? '')[0];
        if (!$accessKey) {
            return $handler->handle($request);
        }
        
        // 从数据库获取密钥
        $credential = $this->getCredential($accessKey);
        
        if (!$credential) {
            return $handler->handle($request);
        }
        
        // 验证签名
        if (!SignatureUtil::verifyPresignedUrl($method, $bucket, $key, $params, $credential['secret_key'])) {
            return $handler->handle($request->withAttribute('presignedUrlError', 'Invalid signature or expired URL'));
        }
        
        // 添加用户认证信息
        return $handler->handle($request
            ->withAttribute('userId', $credential['user_id'])
            ->withAttribute('isPresignedUrl', true)
            ->withAttribute('credentials', [
                'accessKey' => $accessKey,
                'secretKey' => $credential['secret_key']
            ])
        );
    }

    private function getCredential(string $accessKey): ?array
    {
        $cacheKey = Cache::makeKey('credential', 'access_key', $accessKey);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }
        
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT secret_key, user_id FROM credentials WHERE access_key = ?');
        $stmt->execute([$accessKey]);
        $credential = $stmt->fetch();
        
        if ($credential) {
            Cache::set($cacheKey, $credential, 300); // 5分钟过期
        }
        
        return $credential;
    }
}
