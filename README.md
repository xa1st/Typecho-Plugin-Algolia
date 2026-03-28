<div align="center">

# AlgoliaSearch

[![Release Version](https://img.shields.io/github/v/release/xa1st/Typecho-Plugin-AlgoliaSearch?style=flat-square)](https://github.com/xa1st/Typecho-Plugin-AlgoliaSearch/releases/latest)
[![License](https://img.shields.io/badge/License-MulanPSL2-red.svg?style=flat-square)](https://license.coscl.org.cn/MulanPSL2)
[![Typecho 1.2.1+](https://img.shields.io/badge/Typecho-1.2.1%2B-167B94?style=flat-square)](https://typecho.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square)](https://www.php.net/)
[![Algolia](https://img.shields.io/badge/Search-Powered%20by%20Algolia-5468FF.svg?style=flat-square)](https://www.algolia.com/)

将 Typecho 内容同步到 Algolia，并接管站内搜索请求的搜索增强插件。

</div>

## 功能概览

- 发布或更新文章、独立页面时，自动推送到 Algolia
- 删除文章、独立页面时，自动从 Algolia 删除对应记录
- 接管 Typecho 原生搜索，优先使用 Algolia 返回结果
- 后台提供全量同步按钮，适合首次接入或重建索引
- 可选使用 Redis/Valkey 做搜索结果缓存
- 支持简单的搜索频率限制
- 可开启 Debug 模式，将推送错误写入 `AlgoliaSearchDebug` 字段

## 运行要求

- Typecho `1.2.1+`
- PHP `8.1+`
- 已准备好可写入的 Algolia Index
- 如果要启用缓存，服务器需安装 `phpredis` 扩展

## 同步字段

插件当前会向 Algolia 写入这些字段：

- `objectID`：内容 `cid`
- `title`：标题
- `slug`：短链接别名
- `permalink`：永久链接
- `date`：发布时间戳
- `category`：主分类名称
- `tags`：标签数组
- `text`：去标签后的正文摘要，最长约 2000 个字符

## 安装

1. 将插件目录放到 `usr/plugins/AlgoliaSearch/`
2. 进入 Typecho 后台启用 `AlgoliaSearch`
3. 在插件设置中填写 Algolia 配置
4. 首次启用后，建议在插件设置页执行一次全量同步

## 配置说明

### 必填项

- `Application ID`：Algolia 应用 ID
- `Write API Key / Admin API Key`：写入索引所需密钥，建议优先使用 Write Key
- `索引名称`：目标 Index 名称，默认是 `typecho_blog`

### 可选项

- `使用缓存`：开启后会尝试连接 Redis/Valkey
- `Redis 主机地址`
- `Redis 端口`
- `Redis 密码`
- `Redis 数据库索引`
- `Redis SSL证书路径(可选)`：当前版本配置项已预留，但缓存连接逻辑仍按普通 TCP 连接处理
- `缓存前缀`：默认 `algolia_`
- `缓存时间`：默认 `600` 秒
- `搜索间隔时间（秒）`：默认 `5` 秒，填 `0` 表示关闭频率限制
- `Debug 模式`

## 使用说明

### 日常使用

插件启用并配置完成后：

- 发布或更新文章、页面时，会自动同步到 Algolia
- 删除文章、页面时，会自动从 Algolia 删除对应记录
- 前台搜索时，会优先走 Algolia，而不是 Typecho 默认模糊搜索

### 首次接入

首次启用后，旧内容不会自动补进索引。请进入插件设置页，点击全量同步按钮。

当前全量同步接口会分批处理数据，避免单次请求过重。同步过程中请保持后台页面开启。

## Algolia 索引建议

建议在 Algolia 后台提前配置好索引设置，例如：

- `searchableAttributes`：`title`、`text`、`category`、`tags`
- `attributesForFaceting`：`category`、`tags`
- `customRanking`：可按 `desc(date)` 排序

## 缓存与限流

- 搜索结果会按“关键词 + 页码”缓存
- 命中缓存时，不会重复请求 Algolia
- 启用缓存依赖 `phpredis` 扩展
- 未启用缓存时，搜索频率限制会退化为基于 Cookie 的简单限制
- 内容新增、更新、删除后，插件会尝试清理对应前缀下的搜索缓存

## 注意事项

1. 当前搜索结果排序依赖 SQL `FIELD(...)`，更适合 MySQL / MariaDB 环境。
2. 后台“全量同步”当前只批量同步 `post` 类型内容，不包含 `page`；但页面在单篇发布或更新时仍会自动同步。
3. 插件不会替你创建 Algolia 索引，请先在 Algolia 后台准备好索引名称和写入密钥。
4. 正文入库前会被截断为约 2000 个字符，超长全文不会完整写入索引。
5. 当前缓存连接逻辑使用普通 Redis TCP 连接，没有实际启用 SSL 连接参数。
6. 如果使用 `aiven.io` 的 Valkey 替代 Redis，请直接在 `aiven.io` 后台关闭强制使用 SSL 连接，并把允许访问的 IP 设置为网站服务器 IP，否则可能连不上。

## 项目结构

```text
AlgoliaSearch/
|-- Plugin.php    # 插件入口、配置面板、搜索接管逻辑
|-- Algolia.php   # Algolia API 请求封装
|-- Action.php    # 后台全量同步 Action
|-- README.md     # 项目说明
`-- LICENSE       # 许可证
```

## 许可证

[Mulan PSL v2](LICENSE)

## 作者

Alex Xu <xa1st@outlook.com>

## 链接

- GitHub: https://github.com/xa1st/Typecho-Plugin-AlgoliaSearch
- Issues: https://github.com/xa1st/Typecho-Plugin-AlgoliaSearch/issues
