<?php

namespace S3Server\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use S3Server\Model\User;

class S3AuthMiddleware implements MiddlewareInterface {
    private const SKIP_AUTH_PATHS = [
        '/' => true,
        '/favicon.ico' => true
    ];

    public function process(Request $request, RequestHandler $handler): Response {
        $path = $request->getUri()->getPath();
        
        // 跳过不需要认证的路径
        if (isset(self::SKIP_AUTH_PATHS[$path])) {
            return $handler->handle($request);
        }

        // 获取认证信息
        $authHeader = $request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            return $this->unauthorized('Missing Authorization header');
        }

        // 解析认证头
        if (!preg_match('/AWS\s+([^:]+)/', $authHeader, $matches)) {
            return $this->unauthorized('Invalid Authorization header format');
        }

        $accessKey = $matches[1];

        // 验证用户
        $user = User::findByAccessKey($accessKey);
        if (!$user) {
            return $this->unauthorized('Invalid access key');
        }

        // 将用户信息添加到请求属性
        return $handler->handle(
            $request->withAttribute('userId', $user['id'])
                   ->withAttribute('username', $user['username'])
        );
    }

    private function unauthorized(string $message): Response {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Error>' .
            '<Code>SignatureDoesNotMatch</Code>' .
            '<Message>' . htmlspecialchars($message) . '</Message>' .
            '<RequestId></RequestId>' .
            '<HostId></HostId>' .
            '</Error>'
        );

        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/xml');
    }

    private function getStringToSign(Request $request): string {
        $method = $request->getMethod();
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        // 获取需要签名的头部
        $amzHeaders = [];
        foreach ($request->getHeaders() as $name => $values) {
            $name = strtolower($name);
            if (strpos($name, 'x-amz-') === 0) {
                $amzHeaders[$name] = implode(',', $values);
            }
        }

        // 按键排序 AMZ 头部
        ksort($amzHeaders);

        // 构建规范化的 AMZ 头部
        $canonicalAmzHeaders = '';
        foreach ($amzHeaders as $name => $value) {
            $canonicalAmzHeaders .= $name . ':' . trim($value) . "\n";
        }

        // 获取内容类型
        $contentType = $request->getHeaderLine('Content-Type');
        if (empty($contentType)) {
            $contentType = '';
        }

        // 获取日期
        $date = $request->getHeaderLine('Date');
        if (empty($date)) {
            $date = $request->getHeaderLine('x-amz-date');
        }

        // 构建规范化的资源路径
        $canonicalizedResource = $this->getCanonicalizedResource($path, $query);

        // 构建签名字符串
        return implode("\n", [
            $method,
            $request->getHeaderLine('Content-MD5'),
            $contentType,
            $date,
            $canonicalAmzHeaders . $canonicalizedResource
        ]);
    }

    private function getCanonicalizedResource(string $path, string $query): string {
        // 处理子资源
        $subresources = [
            'acl', 'lifecycle', 'location', 'logging', 'notification',
            'partNumber', 'policy', 'requestPayment', 'torrent',
            'uploadId', 'uploads', 'versionId', 'versioning',
            'versions', 'website'
        ];

        $resource = $path;

        if (!empty($query)) {
            $params = [];
            parse_str($query, $params);

            $canonicalizedParams = [];
            foreach ($params as $key => $value) {
                if (in_array($key, $subresources)) {
                    if ($value === '') {
                        $canonicalizedParams[] = $key;
                    } else {
                        $canonicalizedParams[] = $key . '=' . $value;
                    }
                }
            }

            if (!empty($canonicalizedParams)) {
                sort($canonicalizedParams);
                $resource .= '?' . implode('&', $canonicalizedParams);
            }
        }

        return $resource;
    }

    private function logAuthError(string $message, array $context = []): void {
        $logMessage = date('Y-m-d H:i:s') . " - Authentication Error: " . $message;
        if (!empty($context)) {
            $logMessage .= "\nContext: " . json_encode($context, JSON_PRETTY_PRINT);
        }
        error_log($logMessage);
    }
}
