# Kuaidi100 Logistics Service
Use APIs exposed by [Kuaidi100](http://www.kuaidi100.com/openapi/) to implement logisitics query service.

## Demo Snippt
Request API key (the <KEY> below) from Kuaidi1000, and try the following code:

```php
use Homer\Logistics\Kuaidi100\Service as LogisticsService;

$service = new LogisticsService(<YOUR_COMPANY_NAME>, <KEY>);
// - or the full version
// $service = new YunbaPushService(<YOUR_COMPANY_NAME>, <KEY>, $optionsOfService, $instanceOfClient);

// to start tracking waybill
$service->track(<waybill#>, $options);

// handle on waybill's status update
$service->handleWaybillUpdated($notification, Closure $callback);

// eagerly query logistics of some waybill
$logistics = $service->query(<CODE_OF_LOGISTICS_COMPANY>, <waybill#>, <FROM_LOCATION>, <TO_LOCATION>);
```

## API
### construct
`__construct($name, $key, array $options = [], ClientInterface $client = null)`

* ``$name``       name of the your company (it will be used when eagerly query logistics)
* ``$key``      API key (from Kuaidi100)
* ``$options``    some configurations, including:
    * ``notification_url``   the url to receive notification on waybill's status update
    * ``salt``   (optional) a globally choosen value for response's signature verification.
* $client  (optional)http client

### start tracking some waybill

You can start tracking some waybill's logistics by calling the `track` method.

`track($waybillNo, array $options = [])`

* ``$waybillNo``  waybill's number (of the waybill to track)
* ``$options``    options, including:
   * company  &nbsp;&nbsp;&nbsp; 快递公司[编码](http://www.kuaidi100.com/download/api_kuaidi100_com(20140729).doc)
   * from     &nbsp;&nbsp;&nbsp; 出发地城市，格式: 省市区。例如: 广东省深圳市南山区
   * to   &nbsp;&nbsp;&nbsp; 目的地城市，格式: 省市区。例如: 北京市朝阳区
   * salt  &nbsp;&nbsp;&nbsp;(optional)签名用随机字符串(如果指定将覆盖构造函数中设定的salt，如果两处都没有设定，将使用运单号即waybillNo作为盐值)
   * international  &nbsp;&nbsp;&nbsp;&nbsp;(optional)是否开启国际订单 true开启(缺省), false不开启
   * notification\_url &nbsp;&nbsp;&nbsp; (optional)接受通知的地址(如果使用,将覆盖构造函数中指定的notification_url)

Return value of this method is a boolean value, true on success, false otherwise. Status/new logistics of the waybill will be notified once tracking is applied.

### handle waybill's status update

``handleWaybillUpdated($notification, Closure $callback, $salt = null)``

* $notification &nbsp;&nbsp;&nbsp;(string) waybill status update
* $salt &nbsp;&nbsp;&nbsp; (string|null) salt used for signature verification
   
The returned value is an object containing  updated status (with 'tracking', 'domestic' and an optional 'overseas' fields).

* tracking    跟踪
* &nbsp;&nbsp;&nbsp; |  |---- status   跟踪状态, polling:监控中，shutdown:结束，abort:中止，updateall：重新推送。
* &nbsp;&nbsp;&nbsp; |  |---- message  跟踪状态相关消息
* &nbsp;&nbsp;&nbsp; |  |---- fake     是否被认为是假运单(到快递公司查不到运单)
* &nbsp;&nbsp;&nbsp; |
* domestic    国内物流
* &nbsp;&nbsp;&nbsp; |  |---- state    当前签收状态，0在途中、1已揽收、2疑难、3已签收、4退签、5同城派送中、6退回、7转单
* &nbsp;&nbsp;&nbsp; |  |---- signed   是否签收 false未签收、true已签收
* &nbsp;&nbsp;&nbsp; |  |---- waybillNo 运单号
* &nbsp;&nbsp;&nbsp; |  |---- company  快递公司编码
* &nbsp;&nbsp;&nbsp; |  |---- items    物流信息(多项)
* &nbsp;&nbsp;&nbsp; | &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;         |---- time    时间
* &nbsp;&nbsp;&nbsp; | &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; |---- state   状态
* &nbsp;&nbsp;&nbsp; | &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; |---- desc    内容
* &nbsp;&nbsp;&nbsp; |
* overseas    国际物流     数据结构与国内物流相同


### (sync) Query the waybill's status

Eagerly check status of some waybill.

``query($company, $waybillNo, $from, $to)``

* ``$company``    the logistics company's [code](http://www.kuaidi100.com/download/api_kuaidi100_com(20140729).doc)
* ``$waybillNo``  waybill's number 
* ``$from``  出发地城市，格式: 省市区。例如: 广东省深圳市南山区
* ``$to``    目的地城市，格式: 省市区。例如: 北京市朝阳区

The return value contains only domestic logistics for now.
