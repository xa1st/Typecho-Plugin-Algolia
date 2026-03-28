<?php

namespace TypechoPlugin\AlgoliaSearch;

use Typecho\Widget;
use Typecho\Db;
use Typecho\Router;
use Widget\Options;

/**
 * 注册动作，执行数据的全量同步
 * @package AlgoliaSearch
 * @author Alex Xu
 */
class Action extends Widget implements \Widget\ActionInterface {

    public function action() {
        // --- 1. 环境与权限校验 ---
        $user = \Widget\User::alloc();
        // 确保只有管理员有权触发同步，防止恶意调用接口消耗 Algolia 额度
        if (!$user->pass('administrator', true)) {
            $this->response->throwJson(['success' => false, 'message' => _t('权限不足')]);
        }
        // --- 2. 获取分页参数 ---
        // $offset: 跳过的记录数，$limit: 每次处理的数量（默认20，防止 PHP 内存溢出或超时）
        $offset = $this->request->get('offset', 0);
        $limit = $this->request->get('limit', 20);

        // --- 3. 执行文章主查询 ---
        $db = Db::get();
        $select = $db->select()->from('table.contents')
            ->where('type = ?', 'post')       // 只同步文章，排除页面(page)
            ->where('status = ?', 'publish') // 只同步已发布的，排除草稿
            ->where('password IS NULL')      // 排除加密文章，保护隐私
            ->offset($offset)
            ->limit($limit)
            ->order('cid', Db::SORT_ASC);    // 按 ID 升序排列，方便分页逻辑
        $posts = $db->fetchAll($select);

        // 如果本次查询结果为空，说明全量同步已全部完成
        if (empty($posts)) $this->response->throwJson(['finished' => true]);

        // --- 4. 高性能预加载：批量获取 Meta 信息 (分类 & 标签) ---
        $cids = array_column($posts, 'cid');

        // 批量获取分类和标签信息
        $metaData = [];

        if (!empty($cids)) {
            // 1. 初始化数据库连接
            $db = Db::get();
            // 2. 为 CID 数组生成对应数量的问号占位符，例如: "?,?,?,?"
            $cidPlaceholders = implode(',', array_fill(0, count($cids), '?'));
            // 3. 构造查询，注意这里我们手动写 IN (...) 
            $metaSelect = $db->select('table.relationships.cid', 'table.metas.name', 'table.metas.type')
                ->from('table.metas')
                ->join('table.relationships', 'table.metas.mid = table.relationships.mid')
                ->where('table.relationships.cid IN (' . $cidPlaceholders . ')', ...$cids)
                ->where('table.metas.type IN (?, ?)', 'category', 'tag');

            $metaData = $db->fetchAll($metaSelect);
        }
        
        // 遍历 $metaData，将分类和标签信息映射到对应的文章 ID 上
        foreach ($metaData as $row) {
            // 确保每个文章只被映射一次
            $cid = $row['cid'];
            // 判定是分类还是标签
            if ($row['type'] == 'category') {
                // 如果文章有多个分类，通常搜索展示只取第一个作为主分类
                if (!isset($categoriesMap[$cid])) $categoriesMap[$cid] = $row['name'];
            } else {
                // 标签通常是多个，存入数组
                $tagsMap[$cid][] = $row['name'];
            }
        }

        // --- 5. 准备 Algolia 客户端 ---
        $pluginOptions = Options::alloc()->plugin('AlgoliaSearch');
        // 初始化 Algolia 搜索服务
        $algolia = new Algolia($pluginOptions->appId, $pluginOptions->apiKey, $pluginOptions->indexName);

        // --- 6. 构造批量推送数据 (PostData Records) ---
        $postData = [];
        foreach ($posts as $post) {
            // 6.1 生成文章永久链接 (Permalink)
            // Router::url 会根据后台“永久链接”设置自动拼装地址
            $permalink = Router::url('post', $post, Options::alloc()->index);

            // 6.2 文本清洗与截取
            // 搜索索引不需要复杂的 Markdown 语法，直接剥离 HTML 标签转为纯文本
            // 限制 2000 字符是为了控制 Algolia Record 的体积（通常限制 10KB/记录）
            $excerpt = mb_substr(strip_tags($post['text']), 0, 2000, 'UTF-8');

            // 6.3 组装 Algolia 格式要求的数组
            $postData[] = [
                'objectID'  => (string)$post['cid'], // Algolia 必须有一个唯一的 objectID
                'title'     => $post['title'],
                'slug'      => $post['slug'],
                'permalink' => $permalink,
                'date'      => (int)$post['created'], // 转为整型方便 Algolia 进行时间排序
                'category'  => $categoriesMap[$post['cid']] ?? _t('默认'), // 从映射表取值
                'tags'      => $tagsMap[$post['cid']] ?? [],          // 从映射表取值
                'text'      => $excerpt
            ];
        }
        // --- 7. 提交数据并返回响应 ---
        try {
            // 批量推送到 Algolia (减少 HTTP 请求次数)
            if (!$algolia->pushAll($postData)) throw new \Exception($algolia->getError());
            // 返回进度给前端：如果本次获取的数据少于 limit，说明没下文了
            $this->response->throwJson(['finished' => count($posts) < $limit,  'nextOffset' => (int)$offset + (int)$limit]);
        } catch (\Exception $e) {
            // 捕获推送过程中可能出现的网络或 API 错误
            $this->response->throwJson(['finished' => true, 'error' => $e->getMessage()]);
        }
    }
}