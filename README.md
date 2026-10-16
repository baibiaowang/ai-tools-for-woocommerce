# AI Tools (Store Infrastructure)

> **用途**：一个 WordPress 插件 = 一个 MCP 服务器，用内置代码替代店铺平时要装的一堆小插件——数据库+上传备份、API 邮件（SES/Mailgun/Postmark）、Cookie 同意 + CPRA/GPC、受同意控制的广告像素 + 服务端转化（Meta CAPI / TikTok Events API / GA4）、可选页面缓存、Google Merchant 商品 feed、登录加固、邮件通知开关、合规工具（年龄门槛 / Prop 65 / 无障碍）。
> **状态**：开发中（**v1.1.0**）。本地 Docker 真店验证通过；**未发布**到 wordpress.org。
> **配套仓库**：[`baibiaowang/aihand`](https://github.com/baibiaowang/aihand)（运营）· 本仓（基础设施替代）· [`baibiaowang/aidesign`](https://github.com/baibiaowang/aidesign)（美观）。三者**可共存**（共享 `hsm-core.php`、命名空间 `ai-tools/`、option 前缀 `hsm_`）。
> **快速开始**：见 [安装](#安装)。

## 依赖
WordPress 6.9+ · WooCommerce（部分模块需要）· 官方 **MCP Adapter** 插件。

## 安装
同 ai-hand。server 名 `ai-tools`，端点 `https://<站点>/wp-json/ai-tools/mcp`。

## 工具（3）
| 工具 | 作用 |
|---|---|
| `maintenance` | 聚合入口（按 `action`）：`backup_list` / `backup_verify` / `backup_run`（要确认）/ `mail_status` / `mail_log` / `mail_test` / `consent_status` / `followup_status` / `cache_status` / `cache_purge` / `ads_status` |
| `notifications` | 邮件通知开关 |
| `compliance` | 合规（年龄门槛 / Prop 65 / 无障碍） |

## 密钥是 owner-only（AI 看不到）
邮件 API key、Meta CAPI / TikTok / GA4 token 只由店主在后台（**WooCommerce > Hark Ads / Hark Mail**）或 `wp-config` 常量设置；MCP 只能看到"是否已设置"（`key_set` 布尔）。

## 后台/WP-CLI
- 备份：`wp hark-store backup [list|run|verify <name>|path|set <enabled|frequency|keep|uploads> <value>]`
- 邮件：`wp hark-store mail ...`
- 通过 MCP **不能删备份**；只有保留策略会清理，且至少留一份。

## 模块 → 替代对象
backup（UpdraftPlus 等）· mailer（WP Mail SMTP 等）· consent（Cookie 同意插件）· pixels + capi（像素/转化插件）· page-cache（WP Super Cache 等）· feed（Merchant feed 插件）· hardening（安全加固）· notifications · compliance（合规插件）
