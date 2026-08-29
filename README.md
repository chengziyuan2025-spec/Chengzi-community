# cw小窝 · 社区版（Community Edition）

基于 Lychee 相册系统裁剪出的**纯动态社区版**：所有登录用户发布的动态互相可见，仅保留登录、动态与管理员能力，移除相册/照片相关功能。

## 功能

- 登录与会话恢复（CSRF + 同源 Cookie）
- 动态流 + 无限滚动（每页 15 条，加密游标/分页）
- 发布动态：标题 ≤120 字、正文 ≤5000 字、最多 20 个媒体文件（JPG/PNG/WebP/GIF/HEIC/HEIF + MP4/MOV/WebM）
- 动态详情：图片轮播、视频播放、评论与回复（≤500 字）
- 进入记录（仅管理员可查看）
- 管理员中心 `/gallery/myadmin`：进入记录、设备访问限制、操作审计
- 全界面中文，支持暗色模式
- 强制 HTTPS、CSRF 防护、XSS 过滤

## 目录结构

| 文件 | 用途 |
|------|------|
| `index.html` | 社区版前端入口 |
| `app.js` | 前端逻辑（API 通信、动态流、发布、评论） |
| `styles.css` | 毛玻璃风格样式表，响应式 + 暗色模式 |
| `admin/` | `/gallery/myadmin` 管理员中心 |
| `AccountLoginController.php` | 进入记录 API |
| `ActivityController.php` | 动态 / 评论 API |
| `LoginSecurityController.php` / `LoginSecurityMiddleware.php` | 设备信任与登录封禁 |
| `OperationAuditController.php` / `OperationAuditMiddleware.php` | 操作审计 |
| `gallery-extension/` | 后端扩展源（路由、请求、模型、服务、任务、迁移） |
| `default.conf` | Nginx 安全加固 |
| `20-install-custom-gallery.sh` | 一键安装脚本 |
| `robots.txt` | 禁止爬虫 |

## 部署

### 前置要求

- Lychee（LinuxServer）容器，PHP 8.x + MySQL/MariaDB/SQLite
- Nginx、FFmpeg（需含 `libx264`）、ImageMagick
- Laravel 队列 worker（媒体转码，生产环境使用 `database`/Redis，不能用 `sync`）

### 安装

将本目录放到容器映射目录 `/config/codex-custom-frontend/`，Docker Compose 挂载持久启动脚本目录：

```yaml
volumes:
  - /opt/photo/config:/config
  - /opt/photo/config/custom-cont-init.d:/custom-cont-init.d:ro
```

执行：

```sh
bash /config/codex-custom-frontend/20-install-custom-gallery.sh
docker restart <container>
```

注意：社区版与主项目共用线上路径 `/custom-gallery`，**部署会替换现有前端**；回滚 = 用主项目文件重跑安装脚本。动态数据保留在 `gallery_activities` 等表中，不影响数据库。

### 安全

`default.conf` 已含 HSTS、速率限制、敏感路径屏蔽；安装脚本会强制 `APP_DEBUG=false`、`SESSION_SECURE_COOKIE=true`、`expose_php=Off`。

- 登录安全：同设备连续 5 次密码错误封禁 5 天；管理员可开启「仅允许已信任的电脑登录」
- 操作审计：记录 API 写操作，不含密码、令牌与内容正文
- 跨账号提醒：Cheng/Wu 上传照片、发布动态时向对方邮箱发送提醒

## 本地开发

容器运行后前端文件在 `/app/www/public/custom-gallery/`，修改源码后参照主项目 `sync-to-container.sh` 同步（脚本会自动更新版本号防浏览器缓存）。

## 验证清单（部署后人工点检）

1. 登录 → 默认进入动态流，底部导航只有「首页 / 发布 / 我的」
2. 动态流能看到所有用户发布的动态
3. 发布带图片/视频的动态 → 上传成功并出现在流顶部
4. 打开动态详情 → 评论、回复正常
5. 「我的」页面无统计区，退出登录正常
6. 全站无任何相册/共享码入口；直接访问旧接口（如 `/api/v2/AlbumInviteCodes`）应返回 404/405
