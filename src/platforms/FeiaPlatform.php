<?php
/**
 * author     : forecho <caizhenghai@gmail.com>
 * createTime : 2019/5/19 10:54 AM
 * description:
 */

namespace yiier\crossBorderExpress\platforms;

use yiier\crossBorderExpress\contracts\Order;
use yiier\crossBorderExpress\contracts\OrderFee;
use yiier\crossBorderExpress\contracts\OrderResult;
use yiier\crossBorderExpress\contracts\Transport;
use yiier\crossBorderExpress\exceptions\ExpressException;
use yiier\graylog\Log;

class FeiaPlatform extends Platform
{
    const HOST = 'http://api.17feia.com/eship-api';

    const CACHE_KEY_FEITE_ACCESS_TOKEN = 'yiier.crossBorderExpress.feia.token';

    /**
     * @var string
     */
    private $host;

    // EXPRESS(“国内邮寄”), SELF(“自己送货”), ESHIP(“上门取货”);
    private $takeType = ['EXPRESS', 'SELF', 'ESHIP'];

    /**
     * @return \GuzzleHttp\Client
     */
    public function getClient()
    {
        $headers = [
            'Content-Type' => 'application/json; charset=utf8',
        ];

        $client = new \GuzzleHttp\Client([
            'headers' => $headers,
            'timeout' => method_exists($this, 'getTimeout') ? $this->getTimeout() : 5.0,
        ]);

        $this->host = $this->config->get('host') ?: self::HOST;

        return $client;
    }

    /**
     *  不能根据国家获取渠道
     * @param string $countryCode
     * @return Transport[]|array
     * @throws \Exception
     */
    public function getTransportsByCountryCode(string $countryCode): array
    {
        $api = '/v1/products';
        $bodyData = $this->getBaseParams();
        $body = ['body' => json_encode($bodyData)];
        $response = $this->client->post($this->host . $api, $body);
        //var_dump($response->getBody()->getContents());die;
        $result = $this->parseResult($response->getBody()->getContents(), 'productInfos');
        $transport = new Transport();
        $transports = [];
        foreach ($result as $value) {
            $_transport = clone $transport;
            $_transport->code = $value['productCode'];
            $_transport->cnName = $value['productName'];
            $_transport->enName = $value['productNameEn'];
            //$_transport->countryCode = $value['"countryCodes'];
            $_transport->data = json_encode($value, JSON_UNESCAPED_UNICODE);
            $transports[] = $_transport;
        }
        return $transports;
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
        $api = '/v1/orders';
        // 只支持上传一个订单
        $bodyData = $this->getBaseParams() + ['apiOrders' => [$this->formatOrder($order)]];
        $body = ['body' => json_encode($bodyData)];
        Log::error("create order", $bodyData);
        $response = $this->client->post($this->host . $api, $body);
        $result = $this->parseResult($response->getBody()->getContents(), 'successOrders');
        Log::error("create order result", $result);
        if (!empty($result)) {
            $orderResult->expressAgentNumber = $result[0]['pdfPath'];// PDF 先存放在此
            $orderResult->expressNumber = $result[0]['insideNumber'];
            $orderResult->expressTrackingNumber = "";
        } else {
            throw new ExpressException('创建订单失败', (array)$result);
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
        $api = '/v1/apiSearch/requestPdfUrl';
        $data = [
            'orderNumbers' => [$orderNumber,"20241101085112"]
        ];
        $body = [
            'body' => json_encode($this->getBaseParams() + $data)
        ];

        $response = $this->client->post($this->host.$api, $body);

        $result = json_decode($response->getBody()->getContents(), true);

        if($result['flag'] ) {
            return $result['pdfUrls'][0]['pdfUrl'];
        }

       return "";
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
        $apiBoxes = [
            "boxWeight" => $orderClass->package->weight,
            "boxLength" => $orderClass->package->length,
            "boxWidth" => $orderClass->package->width,
            "boxHeight" => $orderClass->package->height,
            "apiGoodsList" => [],
        ];


        $deliveryAddress = [
            "consignee" => is_null($orderClass->recipient->name) ? '' : $orderClass->recipient->name, // 名称
            "province"=>$orderClass->recipient->state, // 省州,
            "city"=> $orderClass->recipient->city, // 城市
            "address"=>is_null($orderClass->recipient->address1) ? '' : $orderClass->recipient->address1, // 地址1
	                "address2"=>$orderClass->recipient->address2 . ' ' . $orderClass->recipient->address3, // 地址2,

            "postcode" => $orderClass->recipient->zip, // 邮编
            "cellphoneNo" =>$orderClass->recipient->phone, // 电话
            "email" => $orderClass->recipient->email,
            "houseNo" => $orderClass->recipient->doorplate, //门牌号(看情况，非必填，如德国必填)
            "customsClearanceNo" => $orderClass->common,
            "companyName" => is_null($orderClass->recipient->company) ? '' : $orderClass->recipient->company,
        ];
        $senderAddress = [
            "sender"=> $orderClass->shipper->name, // 名称
            "province"=>$orderClass->shipper->state, // 省州
            "city"=> $orderClass->shipper->city, // 城市
            "address"=>is_null($orderClass->shipper->address) ? '' : $orderClass->shipper->address, // 地址
            "postcode"=>$orderClass->shipper->zip, // 邮编
            "cellphoneNo"=>$orderClass->shipper->phone, // 电话
            "countryCode"=>"CN"
        ];
        foreach ($orderClass->goods as $key => $value) {
            $apiBoxes['apiGoodsList'][$key] = [
                "nameEn" => $value->description,
                "name" => $value->cnDescription,
                "sku" => $value->sku,
                "quantity" => $value->quantity,
                "reportPrice" => $value->worth,
                "weight"=>$value->weight,
                "material"=>$value->enMaterial,
            ];

        }


        $order = [
            "productName"=>$orderClass->transportName,
            "productCode"=>$orderClass->transportCode,
            "destinationNo"=> $orderClass->recipient->countryCode,
            "takeAwayType"=>$this->takeType[1],
            "referenceNo"=>$orderClass->customerOrderNo,
            "orderFromType"=>"API",
            "vatNo"=>$orderClass->taxesNumber,
            //"eoriNo"=>"1",
            "ioss" =>$orderClass->taxesNumber,
            "apiBoxes" => $apiBoxes,
            "deliveryAddress" => $deliveryAddress,
            "senderAddress" => $senderAddress,
        ];


        return $order;
    }


    protected function getBaseParams()
    {
        return [
            'apiName' => $this->config->get('apiName'),
            'apiToken' => $this->config->get('apiToken'),
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

        $fail = false;
        if ($arr['flag']) {
            if(isset($arr['failOrders']) && !empty($arr['failOrders'])){
                $message = $arr['failOrders']['0']['errorMessage'];
                $fail = true;
            }
            if(!$fail){
                return $key ? $arr[$key] : $arr;
            }else{
                throw new ExpressException($message, (array)$arr);
            }
        }
//        $message = isset($arr['ErpFailOrders']['0']['Remark']) ? $arr['ErpFailOrders']['0']['Remark'] :
//            (isset($arr['Remark']) ? $arr['Remark'] : $result);
//        $code = isset($arr['ErrorCode']) ? $arr['ErrorCode'] : 0;
//        throw new ExpressException($message, (array)$arr, $code);
    }
}

