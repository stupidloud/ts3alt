<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use S3Server\Middleware\S3AuthMiddleware;
use S3Server\Controller\S3Controller;
use S3Server\Model\Database;

// Initialize database
Database::initialize(__DIR__ . '/../data/s3server.db');

// Create data directory if it doesn't exist
if (!file_exists(__DIR__ . '/../data')) {
    mkdir(__DIR__ . '/../data', 0777, true);
}

// Create storage directory if it doesn't exist
if (!file_exists(__DIR__ . '/../storage')) {
    mkdir(__DIR__ . '/../storage', 0777, true);
}

$app = AppFactory::create();

// Add parsing middleware
$app->addBodyParsingMiddleware();

// Add error middleware
$app->addErrorMiddleware(true, true, true);

// Add S3 authentication middleware
$app->add(new S3AuthMiddleware());

// Routes
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write("S3 Compatible Server");
    return $response;
});

// S3 API endpoints
$s3Controller = new S3Controller();

// Service operations
$app->get('/', [$s3Controller, 'listBuckets']);

// Bucket operations
$app->put('/{bucket}', [$s3Controller, 'createBucket']);
$app->delete('/{bucket}', [$s3Controller, 'deleteBucket']);

// Multipart upload operations
$app->post('/{bucket}/{key:.*}', [$s3Controller, 'initiateMultipartUpload'])
    ->add(function ($request, $handler) {
        if (!isset($request->getQueryParams()['uploads'])) {
            return $handler->handle($request);
        }
        return $handler->handle($request);
    });

$app->put('/{bucket}/{key:.*}', [$s3Controller, 'putObject'])
    ->add(function ($request, $handler) {
        $uploadId = $request->getQueryParams()['uploadId'] ?? '';
        $partNumber = $request->getQueryParams()['partNumber'] ?? '';
        if ($uploadId !== '' && $partNumber !== '') {
            return $handler->handle($request->withAttribute('operation', 'uploadPart'));
        }
        return $handler->handle($request->withAttribute('operation', 'putObject'));
    });

$app->post('/{bucket}/{key:.*}', [$s3Controller, 'completeMultipartUpload'])
    ->add(function ($request, $handler) {
        $uploadId = $request->getQueryParams()['uploadId'] ?? '';
        if ($uploadId === '') {
            return $handler->handle($request);
        }
        return $handler->handle($request->withAttribute('operation', 'completeMultipartUpload'));
    });

$app->delete('/{bucket}/{key:.*}', [$s3Controller, 'deleteObject'])
    ->add(function ($request, $handler) {
        $uploadId = $request->getQueryParams()['uploadId'] ?? '';
        if ($uploadId !== '') {
            return $handler->handle($request->withAttribute('operation', 'abortMultipartUpload'));
        }
        return $handler->handle($request->withAttribute('operation', 'deleteObject'));
    });

// List operations
$app->get('/{bucket}', [$s3Controller, 'listObjects'])
    ->add(function ($request, $handler) {
        if (isset($request->getQueryParams()['uploads'])) {
            return $handler->handle($request->withAttribute('operation', 'listMultipartUploads'));
        }
        return $handler->handle($request->withAttribute('operation', 'listObjects'));
    });

$app->get('/{bucket}/{key:.*}', [$s3Controller, 'getObject'])
    ->add(function ($request, $handler) {
        $uploadId = $request->getQueryParams()['uploadId'] ?? '';
        if ($uploadId !== '') {
            return $handler->handle($request->withAttribute('operation', 'listParts'));
        }
        return $handler->handle($request->withAttribute('operation', 'getObject'));
    });

// Object operations
$app->head('/{bucket}/{key:.*}', [$s3Controller, 'headObject']);

$app->run();
