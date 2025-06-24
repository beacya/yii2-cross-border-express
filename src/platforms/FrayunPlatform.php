<?php

namespace yiier\crossBorderExpress\platforms;

use yiier\crossBorderExpress\contracts\Order;
use yiier\crossBorderExpress\contracts\OrderFee;
use yiier\crossBorderExpress\contracts\OrderResult;
use yiier\crossBorderExpress\contracts\Transport;
use yiier\crossBorderExpress\exceptions\ExpressException;
use SoapClient;
/**
 * frayun.com
 */
class FrayunPlatform extends Platform
{
    const HOST = 'https://services.frayun.com/Service.asmx';

    const CACHE_KEY_FEITE_ACCESS_TOKEN = 'yiier.crossBorderExpress.frayun.token';

    /**
     * @var string
     */
    private $host;

    // EXPRESS(“国内邮寄”), SELF(“自己送货”), ESHIP(“上门取货”);
    private $takeType = ['EXPRESS', 'SELF', 'ESHIP'];
    private $wsdl = 'https://services.frayun.com/Service.asmx?wsdl';
    private $username = 'SZ-BCGJ';
    private $password = '786A6B6D53F548D0B946A8C306832941';
    private $sendType = "USPS-N-2F";//USPS-N-2F,USPS-N-2P

    /**
     *
     * @throws
     */
    public function getClient()
    {
        $headers = [
            'Content-Type' => 'application/json; charset=utf8',
        ];

        $headers = [
            'Content-Type' => 'application/xml; charset=utf8',
            //'Accept' => 'application/xml',
        ];
        $client = new \GuzzleHttp\Client([
            'headers' => $headers,
            'timeout' => method_exists($this, 'getTimeout') ? $this->getTimeout() : 5.0,
        ]);
        $this->username = $this->config->get('username') ?: $this->username;
        $this->password = $this->config->get('password') ?: $this->password;
        $this->host = $this->config->get('host') ?: self::HOST;
        $this->host = $this->config->get('send_type') ?: $this->sendType;
        return new SoapClient($this->wsdl);
        //return $client;
    }

    /**
     *  不能根据国家获取渠道
     * @param string $countryCode
     * @return Transport[]|array
     * @throws \Exception
     */
    public function getTransportsByCountryCode(string $countryCode): array
    {
        return [];
    }

    /**
     * Create platform Order
     * @param Order $order
     * @return OrderResult
     * @throws ExpressException
     * @throws \Exception
     */
    public function createOrder(Order $order): OrderResult
    {
        $orderResult = new OrderResult();
        // 只支持上传一个订单
        $orderData = $this->formatOrder($order);
        $jsonOrder = json_encode($orderData);
        $response = $this->client->CreatePreToJson(['UserName' => $this->username, 'PassWord' => $this->password, 'JsonData' => $jsonOrder]);
        $result = json_decode($response->CreatePreToJsonResult,true);
        if (!empty($result) && $result[0]['ResultCode'] == '00000') {
            $orderResult->expressAgentNumber = $result[0]['DpdNo'];// 专线单号
            $orderResult->expressNumber = $result[0]['DpdNo'];
            $orderResult->expressTrackingNumber = $result[0]['TrunNo'];
        } else {
            throw new ExpressException($result[0]['ErrorMsg'], (array)$result);
        }
        $orderResult->data = json_encode($result, JSON_UNESCAPED_UNICODE);

        return $orderResult;
    }

    /**
     * Get print url
     * @param string $orderNumber
     * @return string
     * @throws \Exception
     */
    public function getPrintUrl(string $orderNumber, array $params = []): string
    {
        $response = $this->client->GetPdfPre(['UserName' => $this->username, 'PassWord' => $this->password, 'DpdNo' => $orderNumber]);
        $result = json_decode($response->GetPdfPreResult,true);

        if (isset($result[0]['PdfUrl'])) {
            return $result[0]['PdfUrl'];
        }else{
            throw new ExpressException('获取打印地址失败', (array)$result);
        }

    }

    /**
     * Get platform order fee
     * @param string $orderNumber 订单跟踪号
     * @return OrderFee
     * @throws \Exception
     */
    public function getOrderFee(string $orderNumber): OrderFee
    {
        $orderFee = new OrderFee();
        return $orderFee;
    }

    /**
     * Get platform all order fee
     * 暂未提供此方法
     * @param array $query
     * @return OrderFee[]
     */
    public function getOrderAllFee(array $query = []): array
    {
        return [];
    }

    /**
     * @param Order $orderClass
     * @return array
     */
    protected function formatOrder(Order $orderClass)
    {
        $JsonProductInfo = [];
        foreach ($orderClass->goods as $key => $value) {
            $JsonProductInfo[] = [
                "ProduceNameCN" => $value->cnDescription,
                "ProduceNameEN" => $value->description,
                "Packages" => $value->quantity,
                "USD" => $value->worth,
                "Weight" => $value->weight,
                "Sku" => $value->sku,
                "SalePrice" => 20, // 销售价格
                "CaseLong" => $orderClass->package->length,
                "CaseWide" => $orderClass->package->width,
                "CaseHeight" => $orderClass->package->height,
            ];

        }


        $order = [
            "ClientNumber" => $orderClass->customerOrderNo,
            "ReceiveName" => is_null($orderClass->recipient->name) ? '' : $orderClass->recipient->name, // 名称,
            "ReceiveComName" => is_null($orderClass->recipient->company) ? '' : $orderClass->recipient->company,
            "ReceiveTel" => $orderClass->recipient->phone, // 电话,
            "ReceiveEmail" => $orderClass->recipient->email,
            "ReceiveAddress" => is_null($orderClass->recipient->address) ? '' : $orderClass->recipient->address, // 地址1,
            "ReceiveCity" => $orderClass->recipient->city, // 城市
            "ReceiveProvince" => $orderClass->recipient->state, // 省州,
            "ReceiveCountry" => $orderClass->recipient->countryCode, // 国家,
            "ReceiveCode" => $orderClass->recipient->zip, // 邮编,
            "SendType" => $this->sendType, // 聚能提供的服务类型,
            "IsCharged" => 0,
            "ActualWeight" => $orderClass->package->weight,
            "ShipperName" => $orderClass->shipper->name, // 名称,
            "ShipperComName" => is_null($orderClass->shipper->company) ? '' : $orderClass->shipper->company,
            "ShipperAddress" => is_null($orderClass->shipper->address) ? '' : $orderClass->shipper->address, // 地址1,
            "ShipperCity" => $orderClass->shipper->city, // 城市,
            "ShipperState" => $orderClass->shipper->state, // 省州,
            "ShipperPostCode" => $orderClass->shipper->zip, // 邮编,
            "ShipperCountry" => "CN",
            "ShipperTelephone" => $orderClass->shipper->phone, // 电话,
            //"ShipperSuburb"=> "",
            "VolumeHeight" => $orderClass->package->height, // 体积包裹高度cm
            "VolumeLength" => $orderClass->package->length, // 体积包裹长度cm
            "VolumeWidth" => $orderClass->package->width,   // 体积包裹宽度cm
            "VolumeWeight" => $orderClass->package->weight, // 体积包裹重量kg
            "SerialNumber" => "", // 地址编号,多地址时使用
            //"Ref"=> "Ref",
            //"Ref2"=> "Ref2",
            "DoorPlate" => is_null($orderClass->recipient->doorplate) ? '' : $orderClass->recipient->doorplate, // 地址2
            "IsScanForm" => 1,
            "ShipDate" => date('Y-m-d H:i:s'),
            "JsonProductInfo" => $JsonProductInfo,
        ];


        return $order;
    }


    protected function getBaseParams()
    {
        return [
            'UserName' => $this->config->get('username'),
            'PassWord' => $this->config->get('password'),
        ];
    }


    /**
     * Parse result
     *
     * @param string $result
     * @param $key
     * @return array
     * @throws \Exception
     */
    protected function parseResult($result, $key = '')
    {
        $arr = json_decode($result, true);
        if (empty($arr)) {
            throw new \Exception('Invalid response: ' . $result, 400);
        }

        if ($arr['"ResultCode'] == "00000") {
            return $arr;
        } else {
            throw new ExpressException($arr["ErrorMsg"], (array)$arr);
        }
    }
}


