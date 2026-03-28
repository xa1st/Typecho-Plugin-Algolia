<?php

namespace TypechoPlugin\AlgoliaSearch;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Widget\Options;
use Helper;

/**
 * Algolia 高性能实时搜索插件
 * 
 * @package AlgoliaSearch
 * @author 猫东东
 * @version 1.0.0
 * @link https://github.com/xa1st/Typecho-Plugin-AlgoliaSearch
 */
class Plugin implements PluginInterface {

    /**
     * 静态 Redis 实例
     * @var \Redis|null
     */
    private static $_redis = null;

    /**
     * 激活插件
     */
    public static function activate() {
        // 绑定文章保存/修改钩子
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = [__CLASS__, 'handlePush'];
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = [__CLASS__, 'handlePush'];
        // 绑定文章删除钩子
        \Typecho\Plugin::factory('Widget_Contents_Post_Edit')->finishDelete = [__CLASS__, 'handleRemove'];
        \Typecho\Plugin::factory('Widget_Contents_Page_Edit')->finishDelete = [__CLASS__, 'handleRemove'];
        // 绑定搜索钩子
        \Typecho\Plugin::factory('Widget_Archive')->search = [__CLASS__, 'handleSearch'];
        // 注册全量同步的 Action 路由
        Helper::addAction('algolia-sync', Action::class);
        // 抛出激活提示
        return _t('插件已激活，请配置 API 信息');
    }

    /**
     * 禁用插件
     */
    public static function deactivate() {
        Helper::removeAction('algolia-sync');
    }

    /**
     * 插件配置面板
     * 创建插件配置表单，包含应用ID、API密钥、索引名称等配置项，并提供全量同步功能
     *
     * @param Form $form 配置表单对象
     * @return void
     */
    public static function config(Form $form) {
        // 应用ID
        $appId = new Text('appId', NULL, '', _t('Application ID'), _t('从<a href="https://algolia.com" title="Algolia.Com" target="_blank">Algolia.Com</a>中获取而来'));
        $form->addInput($appId->addRule('required', _t('必须填写 App ID')));
        // API密钥
        $apiKey = new Password('apiKey', NULL, '', _t('Write API Key / Admin API Key'), _t('从<a href="https://algolia.com" title="Algolia.Com" target="_blank">Algolia.Com</a>中获取而来，请尽量使用writekey而非adminkey'));
        $form->addInput($apiKey->addRule('required', _t('必须填写 Write API Key / Admin API Key')));
        // 索引名称
        $indexName = new Text('indexName', NULL, 'typecho_blog', _t('索引名称'), _t('从<a href="https://algolia.com" title="Algolia.Com" target="_blank">Algolia.Com</a>中获取而来，就是从Algolia的Indexes的名称'));
        $form->addInput($indexName->addRule('required', _t('必须填写索引名称')));
        // 是否使用缓存
        $useCache = new Radio('useCache', ['0' => _t('不使用缓存'), '1' => _t('使用缓存')], 0, _t('使用缓存'), _t('使用Redis缓存可以提高搜索速度，如果你没有可以在<a href="https://redis.io" title="Redis.io" target="_blank">Redis.io</a>中获取Redis服务，并填写Redis信息'));
        $form->addInput($useCache);
        // Redis服务器地址
        $redisHost = new Text('redisHost', null, _t('127.0.0.1'), _t('Redis 主机地址'), _t('Redis 服务器地址'));
        $form->addInput($redisHost);
        // Redis端口
        $redisPort = new Text('redisPort', null, _t('6379'), _t('Redis 端口'), _t('Redis 端口号，默认是6379'));
        $form->addInput($redisPort);
        // Redis密码
        $redisPassword = new Password('redisPassword', null, '', _t('Redis 密码'), _t('Redis 认证密码，留空表示无密码'));
        $form->addInput($redisPassword);
        // Redis数据库索引
        $redisDb = new Text('redisDb', null, '0', _t('Redis 数据库索引'), _t('Redis 数据库索引，默认是0'));
        $form->addInput($redisDb);
        // SSL证书路径
        $redisPem = new Text('redisPem', null, '', _t('Redis SSL证书路径(可选)'), _t('Redis 服务器端的SSL证书路径，如：/path/to/cert.pem'));
        $form->addInput($redisPem);
        // 缓存前缀
        $cachePrefix = new Text('cachePrefix', NULL, _t('algolia_'), _t('缓存前缀'), _t('缓存前缀，如果当前有多个索引，请填写不同的前缀，留空默认为algolia_search_'));
        $form->addInput($cachePrefix);
        // 缓存时间
        $cacheTimeOut = new Text('cacheTimeOut', NULL, 600, _t('缓存时间'), _t('搜索缓存时间，默认为300秒，即同一结果600秒内只会请求algolia一次'));
        $form->addInput($cacheTimeOut);
        // 搜索间隔时间
        $interval = new Text('interval', NULL, 5, _t('搜索间隔时间（秒）'), _t('搜索间隔，默认是5秒，填0表示关闭搜索间隔时间'));
        $form->addInput($interval);
        // 打开Debug
        $debug = new Radio('debug', ['0' => _t('关闭'), '1' => _t('开启')], 0, _t('Debug模式'), _t('打开会在文章下方多一个AlgoliaSearchDebug字段，出错的时候可以进行调试'));
        $form->addInput($debug);

        // 批量同步 UI
        echo '<div style="background:#fff; padding:20px; border:1px solid #d9d9d9; margin-top:20px;">';
        echo '<label class="typecho-label">' . _t('全量同步') . '</label>';
        echo '<p class="description">' . _t('点击下方按钮将已有文章推送到 Algolia。同步过程中请勿关闭页面。') . '</p>';
        echo '<button type="button" id="sync-btn" class="btn primary">' . _t('立即开始同步') . '</button>';
        echo '<span id="sync-msg" style="margin-left:10px; color:#467b96;"></span>';
        echo '</div>';
    
        // 注入全量同步 JS
        $syncUrl = Helper::options()->index . '/action/algolia-sync';
        echo "
        <script>
            document.getElementById('sync-btn').onclick = function() {
                if(!confirm('确定要全量同步吗？')) return;
                const btn = this;
                const msg = document.getElementById('sync-msg');
                btn.disabled = true;
    
                function doSync(offset) {
                    msg.innerText = '正在推送从 ' + offset + ' 开始的文章...';
                    fetch('{$syncUrl}?offset=' + offset)
                        .then(res => res.json())
                        .then(data => {
                            if (data.finished) {
                                msg.innerText = '✅ 同步圆满完成！';
                                btn.disabled = false;
                            } else {
                                setTimeout(() => doSync(data.nextOffset), 500);
                            }
                        }).catch(() => {
                            msg.innerText = '❌ 同步失败，请检查配置或网络';
                            btn.disabled = false;
                        });
                }
                doSync(0);
            };
        </script>";
    }

    public static function personalConfig(Form $form) {}

    /**
     * 处理内容推送到 Algolia 搜索引擎
     * 
     * 该方法负责将文章内容处理后推送到 Algolia 搜索服务，包括状态检查、
     * 数据清洗、字段映射等操作
     * 
     * @param array $contents 文章内容对象，包含标题、内容、分类、标签等信息
     * @return void
     */
    public static function handlePush(array $contents, $obj) {
        // 状态过滤：只同步前台可见且未加密的内容
        if ($contents['visibility'] != 'publish' || !empty($contents->password)) return;
        // 如果没有cid，则直接返回
        if (!$obj->cid) return;
        // 获取插件配置
        $options = Options::alloc()->plugin('AlgoliaSearch');
        // 创建Algolia对象
        $algolia = new Algolia($options->appId, $options->apiKey, $options->indexName);
        // 清理文本：去除 Markdown 符号与 HTML 标签
        $cleanText = str_replace(['', '`', '#', '*'], '', $contents['text']);
        // 截取前 2000 个字符
        $excerpt = mb_substr(strip_tags($cleanText), 0, 2000, 'UTF-8');
        // 构造数据
        $data = [
            'title'     => $contents['title'],
            'slug'      => $contents['slug'] ?? '',
            'permalink' => $obj->permalink,
            'date'      => (int)$contents['created'], // 转为整型方便 Algolia 进行时间排序
            'category'  => $obj->categories ? $obj->categories[0]['name'] : _t('默认'),
            'tags'      => array_column((array)$contents['tags'], 'name'),
            'text'      => $excerpt,
        ];
        // 推送数据，无论成败，防止卡住主线程
        $result = $algolia->push($obj->cid, $data); 
        // 如果打开Debug，就把当前状态添加到字段中
        if ($options->debug && !$result) {
            // 获取数据库对象
            $db = \Typecho\Db::get();
            // 查询当前字段是否存在，存在则更新，不存在则添加
            $exists = $db->fetchRow($db->select('str_value')->from('table.fields')->where('cid = ? AND name = ?', $obj->cid, 'AlgoliaSearchDebug'));
            // 如果存在
            if ($exists) {
                // 更新字段值
                $db->query($db->update('table.fields')->rows(['str_value' => $algolia->getError()])->where('cid = ? AND name = ?', $obj->cid, 'AlgoliaSearchDebug'));
            } else {
                // 插入字段值
                $db->query($db->insert('table.fields')->rows(['cid' => $obj->cid, 'name' => 'AlgoliaSearchDebug', 'str_value' => $algolia->getError()]));
            }
        }
        // 删除缓存
        self::clearSearchCache();
    }

    /**
     * 处理删除操作
     * 
     * 该方法用于从Algolia搜索索引中删除指定的内容
     * 
     * @param int $cid 直接传入要删除的内容ID
     * @return void
     */
    public static function handleRemove(int $cid) {
        // 获取Algolia搜索插件配置选项
        $options = Options::alloc()->plugin('AlgoliaSearch');
        // 初始化Algolia客户端
        $algolia = new Algolia($options->appId, $options->apiKey, $options->indexName);
        // 执行删除操作，根据内容ID从索引中移除对应记录
        $algolia->delete($cid);
        // 删除缓存
        self::clearSearchCache();
    }

    /**
     * 接管原生搜索逻辑（集成 MddCache 缓存）
     * * 该方法通过钩子接管 Typecho 原生搜索，优先从本地缓存读取结果，
     * 穿透后请求 Algolia 云端索引，最终通过主键查询回表，彻底规避 LIKE 扫表。
     * * @param string $keywords 搜索关键词
     * @param \Widget\Archive $archive 归档对象
     * @return bool 返回 true 以截断内核原生搜索逻辑（设置 $hasPushed 为 true）
     */
    public static function handleSearch($keywords, $archive) {
        // 获取插件配置
        $options = Options::alloc()->plugin('AlgoliaSearch');
        // 反射注入 Archive 类属性, 用于修复 gWidget\Archive::$countSql 错误
        try {
            // 反射一下 Archive 类
            $ref = new \ReflectionClass($archive);
            // 获取属性 
            $prop = $ref->getProperty('countSql');
            // 允许访问
            $prop->setAccessible(true);
            // 赋一个空的 Select 对象，防止 PHP 报错，同时不影响逻辑
            $prop->setValue($archive, \Typecho\Db::get()->select());
        } catch (\Exception $e) {
            // 忽略不支持反射的情况
        }
        // 创建缓存实例
        $cache = self::getCache();
        // 频率限制逻辑 
        if (intval($options->interval) > 0) {
            if ($cache) {
                // 获取 IP
                $ip = $archive->request->getIp();
                // 缓存键
                $ipKey = $options->cachePrefix . '_limit_' . md5($ip);
                // 频率限制锁定
                if ($cache->get($ipKey)) throw new \Typecho\Exception(_t('搜索太频繁了，请稍后再试'));
                // 写入频率限制锁定，时长从配置读取
                $cache->set($ipKey, "1", intval($options->interval));
            } else {
                // 用cookie判定
                $cookieName = $options->cachePrefix . '_limit_' . md5($archive->request->getCookie('__algolia_search'));
                // 频率限制锁定
                if ($archive->request->cookie($cookieName)) throw new \Typecho\Exception(_t('搜索太频繁了，请稍后再试'));
                // 写入频率限制锁定，时长从配置读取
                $archive->response->setCookie($cookieName, 1, time() + intval($options->interval));
            }
        }
        // 获取当前分页页码
        $currentPage = $archive->request->get('page', 1);
        // 使用系统设定的每页文章数
        $pageSize = $archive->options->pageSize ?? 5;
        // 因为只打算缓存当前页的ids，所以键值要带页码
        $cacheKey = $options->cachePrefix . md5($keywords) . '_p' . $currentPage;
        // 尝试从缓存获取 ID 集合
        $cachedData = $cache ? unserialize($cache->get($cacheKey)) : false;
        // cid集合
        $cids = [];
        // 总数
        $totalFound = 0;
        // 判定缓存是否命中，而且还需要有值，如果没有，则尝试从Algolia云端索引中获取数据
        if ($cachedData && !empty($cachedData['cids'])) {
            // 缓存命中，则从缓存中获取数据
            // cids集合
            $cids = $cachedData['cids'];
            // 总数
            $totalFound = $cachedData['total'];
            // 调试用
            if ($options->debug) echo('[缓存命中]当前缓存键值:' .$cacheKey . ' ,得到的值:' . json_encode($cachedData));
        } else {
            // 未命中缓存，则从Algolia云端索引中获取数据
            $algolia = new Algolia($options->appId, $options->apiKey, $options->indexName);
            // 搜索
            $searchResponse = $algolia->query($keywords, ['attributesToRetrieve' => ['objectID'], 'hitsPerPage' => $pageSize, 'page' => $currentPage - 1]);
            // 搜到了结果
            if ($searchResponse && !empty($searchResponse['hits'])) {
                // id 列表
                $cids = array_column($searchResponse['hits'], 'objectID');
                // 总数
                $totalFound = $searchResponse['nbHits'];
                // 将 ID 列表存入缓存，600秒，其实可以永久，因为改文章的时候会触发删除逻辑
                if ($cache) $cache->set($cacheKey, serialize(['cids' => $cids, 'total' => $totalFound]), $options->cacheTimeOut ?? 600);
            }
            // 调试用
            if ($options->debug) echo('[缓存未命中]当前缓存键值:' .$cacheKey . ' , 需要存储的值:' . json_encode(['cids' => $cids, 'total' => $totalFound]));
        }
        // 执行数据库回表查询（Primary Key 查询，极快）
        if (!empty($cids)) {
            // 从本地数据库中获取数据
            $select = $archive->select()->where('table.contents.cid IN (?)', $cids);
            // 保持 Algolia 的智能排序权重
            $select->order('FIELD(table.contents.cid, ' . implode(',', $cids) . ')');
            // 输出数据
            $archive->setCount($totalFound);
            // 输出数据
            $archive->query($select);
            // 截断内核逻辑
            return true;
        }
        // 兜底：未找到结果
        $archive->setCount(0);
        // 截断内核逻辑
        return true;
    }

    /**
     * 获取缓存实例
     * 这里是私货了，用自己已经写的一个插件来缓存搜索结果
     * @return object|null
     */
    private static function getCache() {
        // 如果已经连接过了，直接返回之前的实例
        if (self::$_redis !== null) return self::$_redis;
        // 获取插件配置
        $options = Options::alloc()->plugin('AlgoliaSearch');
        // 如果不使用缓存或者当前不支持redis，则返回 null
        if (!extension_loaded('redis') || !$options->useCache) return null;
        // 创建缓存实例
        try {
            $redis = new \Redis();
            // 建议增加一个连接超时设置，防止 Redis 挂了卡死整个网页
            $redis->connect($options->redisHost, $options->redisPort, 1.5); 
            // 密码认证
            if ($options->redisPassword) $redis->auth($options->redisPassword);
            // 选择数据库
            if ($options->redisDb) $redis->select($options->redisDb);
            // 存入静态变量，下次调用直接拿
            self::$_redis = $redis;
            // 返回缓存实例
            return self::$_redis;
        } catch (\Exception $e) {
            // 出错时显式设为 false 或记录日志，避免重复尝试连接已挂掉的服务器
            self::$_redis = null; 
            // 写一条错误日志
            error_log('[ERROR][Typecho-AlgoliaSearch-Plugin] Redis connection failed: ' . $e->getMessage());
            // 返回 null
            return null;
        }
    }

    /**
     * 清理所有搜索相关的缓存
     * 建议：由于搜索词是无限的，无法精准清理某一个词，
     * 通常的做法是配合缓存标签，或者在文章更新时清理高频搜索词缓存。
    */
    public static function clearSearchCache() {
        // 获取缓存实例
        $cache = self::getCache();
        // 尝试清理缓存
        if ($cache) {
            // 获取插件配置
            $options = \Widget\Options::alloc()->plugin('AlgoliaSearch');
            // 获取指定的前缀的缓存
            $keys = $cache->keys(($options->cachePrefix ?? 'algolia_search_') . '*');
            // 删除所有缓存
            if (!empty($keys)) $cache->del($keys);
        }
    }
}