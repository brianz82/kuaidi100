<?php

namespace Homer\Logistics\Kuaidi100;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\ClientInterface;

/**
 * Logistics service implemented with Kuaidi100's api
 */
class Service
{
    /**
     * 公司编号
     * @var string
     */
    private $name;

    /**
     * 授权码
     * @var string
     */
    private $key;

    /**
     * more options
     *
     * @var array
     */
    private $options;

    /**
     * http client
     * @var \GuzzleHttp\ClientInterface
     */
    private $client;

    /**
     * @param string $name     快递100分配的公司编号(不是快递公司编号)
     * @param string $key      授权码
     * @param array $options   some more configurations:
     *                         - notification_url  url to receive notification
     *                         - salt              salt for response signature verification
     * @param ClientInterface $client   client used to sending http request
     */
    public function __construct($name, $key, $options = [], ClientInterface $client = null)
    {
        $this->name = $name;
        $this->key = $key;
        $this->options = $options;

        $this->client = $client ?: $this->createDefaultHttpClient();
    }

    /**
     *
     * track the logistics of given order
     *
     * @param string $waybillNo    the waybill# to track
     * @param array $options      options for tracking (all options are optional)
     *                            - company   快递公司编码
     *                            - from      出发地城市，格式: 省市区。例如: 广东省深圳市南山区
     *                            - to        目的地城市，格式: 省市区。例如: 北京市朝阳区
     *                            - salt      签名用随机字符串
     *                            - international     是否开启国际订单 true开启(缺省), false不开启
     *                            - notification_url  接受通知的地址(如果使用,将覆盖构造函数中指定的notification_url)
     * @return bool               true on success
     */
    public function track($waybillNo, array $options = [])
    {
        $this->validateWaybillNo($waybillNo);

        $notificationUrl = array_get($options, 'notification_url', array_get($this->options, 'notification_url'));
        return $this->subscribeTracking($waybillNo, $notificationUrl, $options);
    }

    private function subscribeTracking($waybillNo, $notificationUrl, array $options = [])
    {
        // send request for track subscription
        $response = $this->client->request('POST', 'http://poll.kuaidi100.com/poll', [
            RequestOptions::FORM_PARAMS => $this->buildRequestForTracking($waybillNo, $notificationUrl, $options)
        ]);
        if ($response->getStatusCode() != 200) {
            throw new \Exception('快递100服务异常');
        }

        // parse the response
        $parsed = safe_json_decode((string)$response->getBody());
        if ($parsed == false) {
            throw new \Exception('快递100服务异常: ' . (string)$response->getBody());
        }

        if ($parsed->result && $parsed->returnCode == '200') {  // subscribed
            return true;
        }

        throw new \Exception($parsed->message, $parsed->returnCode);
    }

    private function buildRequestForTracking($waybillNo, $notificationUrl, array $options = [])
    {
        $company = array_get($options, 'company');

        return [
            'schema' => 'json',
            'param'  => json_encode(array_filter([
                'company' => $company, // 快递公司编码
                'number'  => $waybillNo,  // 快递单号
                'from'    => array_get($options, 'from'),
                'to'      => array_get($options, 'to'),
                'key'     => $this->key,
                'parameters' => array_filter([
                    'callbackurl' => $notificationUrl,
                    'salt'        => array_get($options, 'salt', $this->getSalt($waybillNo)), // by default use waybill number as salt
                    'resultv2'    => '1',  // 开通行政区域解析功能
                    'autoCom'     => empty($company) ? '1' : '0',
                    'interCom'    => array_get($options, 'international', true) ? '1' : '0', // 缺省开启国际版
                ])
            ]))
        ];
    }


    /**
     * handle the notification on waybill's status update
     *
     * @param string $notification    waybill status update
     * @param string|null $salt       salt used for signature verification
     *
     * @return object  updated status.
     *                  tracking    跟踪
     *                    |  |---- status   跟踪状态, polling:监控中，shutdown:结束，abort:中止，updateall：重新推送。
     *                    |  |---- message  跟踪状态相关消息
     *                    |  |---- fake     是否被认为是假运单(到快递公司查不到运单)
     *                    |
     *                 domestic    国内物流
     *                    |  |---- state    当前签收状态，0在途中、1已揽收、2疑难、3已签收、4退签、5同城派送中、6退回、7转单
     *                    |  |---- signed   是否签收 false未签收、true已签收
     *                    |  |---- waybillNo 运单号
     *                    |  |---- company  快递公司编码
     *                    |  |---- items    物流信息(多项)
     *                    |          |---- time    时间
     *                    |          |---- state   状态
     *                    |          |---- desc    内容
     *                    |
     *                 overseas    国际物流     数据结构与国内物流相同
     *
     */
    public function handleWaybillUpdated($notification, Closure $callback, $salt = null)
    {
        $parsed = $this->validateNotification($notification, $salt);

        // alarm: the waybill is reported as a fake one, a check is needed
        $reportedAsFake = $parsed->status == 'abort' && strpos($parsed->message, '3天') > 0 && empty($parsed->comNew);

        // TODO: handle non-blank comNew, autoCheck + polling status

        $handled = $callback((object)array_filter([
            'tracking' => (object)[
                'status'  => $parsed->status,
                'message' => $parsed->message,
                'fake'    => $reportedAsFake,
            ],
            'domestic' => $this->parseForLogistics($parsed->lastResult),
            'overseas' => isset($parsed->destResult) ? $this->parseForLogistics($parsed->destResult) : null
        ]));

        echo json_encode($this->morphNotificationResponse($handled));
    }

    private function morphNotificationResponse($handled)
    {
        if ($handled === true) {
            return ['result' => true,  'returnCode' => '200', 'message' => '成功'];
        } else {
            $response = [ 'returnCode' => '500', 'message' => '失败' ];
            if (is_int($handled)) {  // the error code only
                $response['returnCode'] = $handled;
            } else if (is_string($handled)) {  // the error message only
                $response['message'] = $handled;
            } else if (is_array($handled)) {  // both error code and message
                list($response['returnCode'], $response['message']) = $handled;
            }

            return array_merge([ 'result' => false ], $response);
        }
    }

    private function parseForLogistics($logistic)
    {
        return (object)[
            'state'  => $logistic->state, /* 当前签收状态，0在途中、1已揽收、2疑难、3已签收、4退签、5同城派送中、6退回、7转单等 */
            'signed' => $logistic->ischeck == '1',  /* 是否签收 0未签收、1已签收 */
            'waybillNo' => $logistic->nu,      /* 运单号 */
            'company'   => $logistic->com,     /* 快递公司编码 */
            'items' => empty($logistic->data) ? null : array_map(function ($item) {
                return (object)[
                    'time'  => $item->ftime,
                    'state' => $item->status,
                    'desc'  => $item->context
                ];
            }, $logistic->data)
        ];
    }

    private function validateNotification($notification, $salt = null)
    {
        parse_str($notification);  /* $notification in 'sign=<sign>&param=<param>' form, so after parsing,
                                    * we'll get $sign and $param */
        if (!isset($sign) || !isset($param)) {
            throw new \InvalidArgumentException('非法通知消息');
        }

        $parsed = safe_json_decode($param);
        if (!isset($parsed->lastResult) || !isset($parsed->lastResult->data) ||
            !in_array($parsed->status, [ 'polling', 'shutdown', 'abort', 'updateall' ])) {
            throw new \InvalidArgumentException('非法通知消息');
        }

        if (empty($salt)) { // no explicit salt
            $salt = $this->getSalt($parsed->lastResult->nu);
        }

        if (md5($param . $salt) != $sign) {
            throw new \InvalidArgumentException('非法通知消息(Forged)');
        }

        return $parsed;
    }

    /**
     * (sync) query logistics inforamtion
     *
     * @param string $company
     * @param $waybillNo
     * @param $from
     * @param $to
     * @return object
     * @throws \Exception
     */
    public function query($company, $waybillNo, $from, $to)
    {
        $this->validateWaybillNo($waybillNo);

        // send request
        $response = $this->client->request('POST', 'http://poll.kuaidi100.com/poll/query.do', [
            RequestOptions::FORM_PARAMS => $this->buildRequestForQuery($company, $waybillNo, $from, $to)
        ]);
        if ($response->getStatusCode() != 200) {
            throw new \Exception('快递100服务异常');
        }

        return $this->parseQueryResponse((string)$response->getBody());
    }

    private function buildRequestForQuery($company, $waybillNo, $from, $to)
    {
        $params = [ 'com' => $company, 'num' => $waybillNo, 'from' => $from, 'to' => $to ];
        $params['sign'] = md5(json_encode($params) . $this->key . $this->name);
        $params['customer'] = $this->name;

        return $params;
    }

    private function parseQueryResponse($response)
    {
        $parsed = safe_json_decode($response);
        if ($parsed == false) {
            throw new \Exception('快递100服务异常: ' . $response);
        }

        if (isset($parsed->result) && $parsed->result == false) { // something wrong
            throw new \Exception($parsed->message, $parsed->returnCode);
        }

        return $this->parseForLogistics($parsed);
    }

    /*
     * validate params for tracking
     */
    private function validateWaybillNo($waybillNo)
    {
        // waybill number can not be neither blank nor larger than 32 in length
        if (empty($waybillNo) || strlen($waybillNo) > 32) {
            throw new \InvalidArgumentException('错误的运单号');
        }
    }

    /*
     * get salt for request signature/response verification
     *
     * @return string
     */
    private function getSalt($defaultSalt)
    {
        return array_get($this->options, 'salt', $defaultSalt);
    }

    /*
     * create default http client
     *
     * @param array $config        Client configuration settings. See \GuzzleHttp\Client::__construct()
     * @return \GuzzleHttp\Client
     */
    private function createDefaultHttpClient(array $config = [])
    {
        return new Client($config);
    }
}