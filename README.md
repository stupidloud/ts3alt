# S3 兼容服务器

这是一个基于 PHP 的 S3 兼容服务器，实现了 Amazon S3 API 的核心功能。它使用本地文件系统存储文件，可以与 rclone 等工具配合使用。

## 系统要求

- PHP 8.0 或更高版本
- Composer
- SQLite3

## 安装步骤

1. 克隆此仓库
2. 运行 `composer install` 安装依赖
3. 确保 `storage` 和 `data` 目录可写：
   ```bash
   chmod -R 777 storage data
   ```
4. 配置 Web 服务器（Apache/Nginx）指向 `public` 目录
5. 默认用户凭据：
   - 访问密钥（Access Key）：minioadmin
   - 密钥（Secret Key）：minioadmin

## 支持的 S3 操作

### 基础操作
- ListBuckets（列出所有存储桶）
- CreateBucket（创建存储桶）
- DeleteBucket（删除存储桶）
- ListObjects（列出存储桶中的对象）
- PutObject（上传对象）
- GetObject（下载对象）
- DeleteObject（删除对象）
- HeadObject（获取对象元数据）

### 分片上传
- 初始化分片上传
- 上传分片
- 完成分片上传
- 终止分片上传
- 列出进行中的分片上传
- 列出指定上传的所有分片

## 配置说明

### Rclone 配置示例

```conf
[local-s3]
type = s3
provider = Other
env_auth = false
access_key_id = minioadmin
secret_access_key = minioadmin
endpoint = http://your-server-address
force_path_style = true
```

### 分片上传配置
- 建议的分片大小：5MB 到 5GB
- 单个文件最多支持 10,000 个分片
- 强制使用分片上传：
  ```conf
  chunk_size = 5M
  ```

## 缓存系统

本项目使用 Redis 作为缓存后端，提供高性能的数据缓存和分布式锁支持。

### Redis 配置

可以通过环境变量配置 Redis 连接：

- `REDIS_HOST`: Redis 服务器地址（默认：localhost）
- `REDIS_PORT`: Redis 端口（默认：6379）
- `REDIS_PASSWORD`: Redis 密码（可选）
- `REDIS_DB`: Redis 数据库编号（默认：0）
- `REDIS_PREFIX`: 缓存键前缀（默认：s3server:）

### 缓存策略

- 用户数据：1小时
- Bucket元数据：1小时
- 对象元数据：30分钟
- 认证信息：5分钟
- 上传状态：5-10分钟

### Redis 特性支持

- 分布式锁
- 事务支持
- 计数器
- 列表操作
- 集合操作
- 有序集合
- 哈希表

### 使用 Docker

使用 Docker Compose 启动服务：

```bash
docker-compose up -d
```

这将启动应用服务器和 Redis 服务器。

## 数据库结构

使用 SQLite3 数据库存储元数据：

- users：用户信息
  - id, username, access_key, secret_key
- buckets：存储桶信息
  - id, name, user_id
- objects：对象信息
  - id, bucket_id, key_name, size, etag, content_type, storage_path
- multipart_uploads：分片上传信息
  - id, bucket_id, key_name, upload_id, user_id, content_type, status
- parts：分片信息
  - id, upload_id, part_number, size, etag, storage_path

## 注意事项

1. 这是一个基础实现，生产环境使用需要注意：
   - 实现完整的 AWS 签名 V4 验证
   - 添加更多的错误处理和日志记录
   - 实现更完整的访问控制
   - 添加速率限制
   - 定期清理未完成的分片上传

2. 安全建议：
   - 修改默认的访问密钥
   - 限制上传文件的大小和类型
   - 配置服务器防火墙
   - 使用 HTTPS

3. 性能优化：
   - 配置适当的分片大小
   - 定期维护数据库
   - 监控磁盘使用情况

## 使用示例

### 基本操作
```bash
# 上传文件
rclone copy local_file.txt local-s3:bucket/

# 下载文件
rclone copy local-s3:bucket/file.txt ./

# 列出文件
rclone ls local-s3:bucket/
```

### 分片上传
大文件会自动使用分片上传：
```bash
rclone copy large_file.dat local-s3:bucket/
```

## 问题排查

1. 权限问题
   - 检查 storage 和 data 目录权限
   - 确保 Web 服务器用户有写入权限

2. 上传失败
   - 检查文件大小限制
   - 查看服务器错误日志
   - 确认存储空间充足

3. 认证问题
   - 验证访问密钥和密钥是否正确
   - 检查请求时间戳是否准确

## 技术支持

如遇到问题，请检查：
1. PHP 错误日志
2. Web 服务器日志
3. 数据库日志
4. 应用程序日志
