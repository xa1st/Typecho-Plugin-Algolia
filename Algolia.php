<?php
namespace TypechoPlugin\AlgoliaSearch;

use Typecho\Http\Client as HttpClient;

/**
 * Algolia 核心通讯工具类
 * @package AlgoliaSearch
 * @author Alex Xu
 */

class Algolia {
    /**
     * 应用 ID
     * @var string
     */
    private $appId;
    /**
     * API 密钥
     * @var string
     */
    private $apiKey;
    /**
     * 索引名称
     * @var string
     */
    private $indexName;
    
    /**
     * 错误信息
     * @var string
     */
    private $Error;

    /**
      * 构造函数，初始化应用ID、API密钥和索引名称
      * 
      * @param string $appId 应用程序ID
      * @param string $apiKey API访问密钥
      * @param string $indexName 索引名称
      */
    public function __construct($appId, $apiKey, $indexName) {
        $this->appId = $appId;
        $this->apiKey = $apiKey;
        $this->indexName = $indexName;
    }

    /**
     * 执行搜索查询
     * @param string $keywords 关键词
     * @param array $args 额外搜索参数（如 hitsPerPage, attributesToRetrieve）
     * @return array|false 返回搜索结果数组，失败返回 false
     */
    public function query($keywords, array $args = []) {
        // 搜索建议使用 -dsn 域名，具有更高的可用性
        $url = "https://{$this->appId}-dsn.algolia.net/1/indexes/{$this->indexName}/query";
        // 构造请求参数
        $args['query'] = $keywords;
        // 注意：搜索需要返回完整数据，所以最后一个参数传 true
        return $this->request('POST', $url, $args, true);
    }

    /**
     * 推送或更新单条记录
     * 
     * @param string|int $id 记录的唯一标识符
     * @param array $data 要推送或更新的数据数组
     * @return mixed API请求的响应结果
     * 
     */
    public function push($id, array $data) {
        // 这里单条推送的怎么也通不了，直接曲线救国，调用批量推送接口
        // -------------------------------------------------------------
        // 构造请求URL
        // $url = "https://{$this->appId}.algolia.net/1/indexes/{$this->indexName}/" . urlencode($id);
        // 构造请求数据
        // $data['objectID'] = (string)$id;
        // 发送请求
        // return $this->request('POST', $url, $data);
        // -------------------------------------------------------------
        // 补全字段
        $data['objectID'] = (string)$id;
        // 调用批量推送
        return $this->pushAll([$data]);
    }

    /**
     * 批量推送记录到Algolia搜索服务
     * 
     * @param array $records 要推送的记录数组，每个元素为一个记录对象
     * @return mixed 请求响应结果
     */
    public function pushAll(array $records) {
        // 构造请求URL
        $url = "https://{$this->appId}-dsn.algolia.net/1/indexes/{$this->indexName}/batch";
        // 构造请求数据
        $requests = array_map(function($item) {
            return [
                'action' => 'addObject',
                'body'   => $item
            ];
        }, $records);
        // 发送请求
        return $this->request('POST', $url, ['requests' => $requests]);
    }

    /**
     * 删除单条记录
     * 
     * @param string|int $id 要删除的记录ID
     * @return mixed 删除操作的响应结果
     */
    public function delete($id) {
        // 构造请求URL
        $url = "https://{$this->appId}-dsn.algolia.net/1/indexes/{$this->indexName}/{$id}";
        // 发送请求
        return $this->request('DELETE', $url);
    }

    /**
     * 获取错误信息
     * @return string 错误信息
     */
    public function getError() {
        return $this->Error;
    }

    /**
     * 发送HTTP请求到Algolia API
     * 
     * @param string $method HTTP请求方法（GET、POST、PUT、DELETE等）
     * @param string $url 请求的目标URL
     * @param array|null $data 要发送的请求数据，可选参数
     * @return bool 请求是否成功（状态码在200-299范围内返回true，否则返回false）
     */
    private function request($method, $url, $data = null) {
        try {
            // http 客户端
            $http = HttpClient::get();
            // 将请求方法转换为大写
            $method = strtoupper($method);
            // 设置请求方法
            $http->setMethod($method)
                 ->setHeader('X-Algolia-Application-Id', $this->appId)
                 ->setHeader('X-Algolia-API-Key', $this->apiKey)
                 ->setHeader('Content-Type', 'application/json')
                 ->setTimeout(10);
            // 设置请求数据
            $data = !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : '';
            // 设置请求数据
            if($data) $http->setData($data);
            // 发送请求, 因为PUT请求需要单独设置第2个参数
            $http->send($url);
            // 获取响应状态码和响应数据
            $status = $http->getResponseStatus();
            // 获取响应数据
            $response = $http->getResponseBody();
            // 解析响应数据
            $result = json_decode($response, true);
            // 关键：检查 API 是否返回了逻辑错误
            if ($status < 200 || $status >= 300) {
                // 逻辑错误处理
                $this->Error = $result['message'] ?? "HTTP Error: {$status}";
                // 返回失败
                return false;
            }
            // 返回响应结果
            return $result;
        } catch (\Exception $e) {
            // 网络错误处理
            $this->Error = "Network Error: " . $e->getMessage();
            // 返回失败
            return false;
        }
    }
}