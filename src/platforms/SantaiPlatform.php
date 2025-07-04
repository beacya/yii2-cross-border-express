<?php
/**
 * author     : forecho <caizhenghai@gmail.com>
 * createTime : 2019/5/19 10:54 AM
 * description:
 */

namespace yiier\crossBorderExpress\platforms;

use nusoap_client;
use yii\helpers\ArrayHelper;
use yiier\crossBorderExpress\contracts\Order;
use yiier\crossBorderExpress\contracts\OrderFee;
use yiier\crossBorderExpress\contracts\OrderResult;
use yiier\crossBorderExpress\contracts\Transport;
use yiier\crossBorderExpress\CountryCodes;
use yiier\crossBorderExpress\exceptions\ExpressException;
use yiier\graylog\Log;
use SoapClient;

class SantaiPlatform extends Platform
{

    const ENDPOINT = 'http://www.sfcservice.com/ishipsvc/web-service?wsdl';
    const HOST = 'https://www.sfcservice.com/ishipsvc/http-api';
    /**
     * @var string
     */
    protected $endpoint;

    /**
     * @return nusoap_client
     */
    public function getClient()
    {
        $this->endpoint = $this->endpoint ?: self::ENDPOINT;
        $client = new nusoap_client($this->endpoint, true);
        $client->soap_defencoding = 'UTF-8';
        $client->decode_utf8 = false;
        return $client;
    }

    public function getHttpClient()
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
     * @param string $countryCode
     * @return Transport[]|array
     */
    public function getTransportsByCountryCode(string $countryCode): array
    {
        $url = 'https://www.sfcservice.com/ishipsvc/http-api?apiName=getShipTypes&parameter={"HeaderRequest":{"userId":"'.$this->config['userId'].'","appKey":"'.$this->config['appKey'].'","token":"'.$this->config['token'].'"}}';
        $parameter = ['apiName' => "getShipTypes",'parameter' => $this->getAuthParams()];
        try {
            //$result = $this->getHttpClient()->get($this->host."?apiName=getShipTypes&parameter=".json_encode($this->getAuthParams()))->getBody()->getContents();
            //echo $url;die;
            $result = $this->getHttpClient()->get($url)->getBody()->getContents();
            //$result = $this->client->call('getShipTypes', $parameter);
        } catch (\Exception $e) {
            Log::error("SantaiPlatform getTransportsByCountryCode error", ['error' => $e->getMessage(), 'result' => $result]);
            throw new ExpressException("SantaiPlatform getTransportsByCountryCode error: {$e->getMessage()}");
        }
//        if (empty($result['rates'])) {
//            return [];
//        }

        $result = json_decode($result, true);
        $transport = new Transport();
        $transports = [];
        foreach ($result as $value) {
            $_transport = clone $transport;
            $_transport->countryCode = $countryCode;
            $_transport->code = $value['method_code'];
            $_transport->cnName = $value['cn_name'];
            $_transport->enName = $value['en_name'];
            $_transport->ifTracking = $value['iftracking'] === 'YES';
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
     */
    public function createOrder(Order $order): OrderResult
    {
        $orderResult = new OrderResult();
        $parameter = array_merge($this->getAuthParams(), ['addOrderRequestInfo' => $this->formatOrder($order)]);
        Log::error("create order", $parameter);
        $result = $this->client->call('addOrder', $parameter);
        Log::error("create order result", $result);
        if (isset($result['orderActionStatus'])) {
            if ($result['orderActionStatus'] == 'Y') {
                $orderResult->expressTrackingNumber = $result['trackingNumber'];
                $orderResult->expressAgentNumber = self::dataGet($result, 'trackingNumberUsps');
                $orderResult->expressNumber = $result['orderCode'];
            } else {
                throw new ExpressException($result['note']);
            }
        } else {
            throw new ExpressException('订单提交返回失败', (array)$result);
        }
        $orderResult->data = json_encode($result, JSON_UNESCAPED_UNICODE);

        return $orderResult;
    }

    /**
     * Get platform print url
     * @param string $orderNumber
     * @return string
     */
    public function getPrintUrl(string $orderNumber, array $params = []): string
    {
        $host = 'http://www.sfcservice.com/api/label';
        $printType = 1;
        $fileType = 'pdf';

        return "{$host}?orderCodeList={$orderNumber}&printType={$printType}&print_type={$fileType}";
    }


    /**
     * Get platform order fee
     * @param string $orderNumber
     * @return OrderFee
     * @throws \Exception
     */
    public function getOrderFee(string $orderNumber): OrderFee
    {
        $this->endpoint = 'http://www.sfcservice.com/ishipsvc/web-service?wsdl';
        $client = $this->getClient();
        $orderNumber = substr($orderNumber, strpos($orderNumber, 'WW'));
        $query = [
            'orderCode' => $orderNumber,
        ];
        $parameter = array_merge($this->getAuthParams(), $query);
        $result = $client->call('getFeeByOrderCode', $parameter);
        if (isset($result['ask']) && $result['ask'] === 'Success' && $result['msg'] == 'Success') {
            $orderFee = new OrderFee();
            return $this->formatReturnOrder($orderFee, $result);
        }
        throw new ExpressException('获取订单费用失败：' . json_encode($result), (array)$result);
    }


    /**
     * Get platform all order fee
     * @param array $query
     * @return OrderFee[]
     * @throws ExpressException
     */
    public function getOrderAllFee(array $query = []): array
    {
        $this->endpoint = 'http://www.sfcservice.com/ishipsvc/web-service?wsdl';
        $client = $this->getClient();
        $defaultQuery = [
            'startime' => date('Y-m-d H:i:s', strtotime("-3 months")),
            'endtime' => date('Y-m-d H:i:s'),
            'page' => 1,
        ];
        $parameter = array_merge($this->getAuthParams(), array_merge($defaultQuery, $query));
        $result = $client->call('getFeeByTime', $parameter);
        if (isset($result['ask']) && $result['ask'] === "Success" && isset($result['data'])) {
            $data = (array)$result['data'];
            $orderFee = new OrderFee();
            $items = [];
            unset($result['data']);
            foreach ($data as $key => $datum) {
                $_orderFee = clone $orderFee;
                $items[$key] = $this->formatReturnOrder($_orderFee, $datum);
            }
            return $items;
        }
        throw new ExpressException('获取订单费用失败', (array)$result);
    }


    /**
     * @param OrderFee $orderFee
     * @param array $data
     * @return OrderFee
     */
    protected function formatReturnOrder(OrderFee $orderFee, array $data)
    {
        $orderFee->chargeWeight = $data['feeWeight'];
        $orderFee->orderNumber = $data['orderCode'];
        $orderFee->freight = $data['baseFee'];
        $orderFee->fuelCosts = 0;
        $orderFee->registrationFee = $data['regFee'];
        $orderFee->processingFee = $data['dealFee'];
        $orderFee->otherFee = isset($data['otherFees']) ?
            array_sum(ArrayHelper::getColumn($data['otherFees'], 'total_fee')) : 0;
        $orderFee->totalFee = bcadd($data['totalFee'], $orderFee->otherFee, 2); // 三态其他费用不包括在运费中
        $orderFee->customerOrderNumber = $data['customerOrderNo'];
        $orderFee->country = '';
        $orderFee->transportCode = $data['shipTypeCode'];
        $orderFee->datetime = date('c', strtotime($data['chargebackTime']));
        $orderFee->data = json_encode($data + ['data' => $data], JSON_UNESCAPED_UNICODE);
        return $orderFee;
    }

    /**
     * @param Order $orderClass
     * @return array
     */
    protected function formatOrder(Order $orderClass)
    {
        $shipper = [];
        $goods = [];
        if ($orderClass->shipper) {
            $shipper = [
                'shipperName' => $orderClass->shipper->name,
                'shipperEmail' => $orderClass->shipper->email,
                'shipperAddress' => $orderClass->shipper->address,
                'shipperZipCode' => $orderClass->shipper->zip,
                'shipperPhone' => $orderClass->shipper->phone,
                'shipperCompanyName' => $orderClass->shipper->company,
                'shipperState' => $orderClass->shipper->state,
                'shipperCity' => $orderClass->shipper->city,
            ];
        }

        $recipient = [
            'recipientCountry' => CountryCodes::getEnName($orderClass->recipient->countryCode),
            'recipientName' => $orderClass->recipient->name,
            'recipientEmail' => $orderClass->recipient->email,
            'recipientState' => $orderClass->recipient->state,
            'recipientCity' => $orderClass->recipient->city,
            'recipientAddress' => $orderClass->recipient->address,
            'doorplate' => $orderClass->recipient->doorplate,
            'recipientZipCode' => $orderClass->recipient->zip,
            'recipientPhone' => $orderClass->recipient->phone,
            'recipientOrganization' => $orderClass->recipient->company,
        ];


        $package = [
            'goodsDescription' => $orderClass->package->description,
            'goodsQuantity' => $orderClass->package->quantity,
            'goodsDeclareWorth' => $orderClass->package->declareWorth,
            'goodsWeight' => $orderClass->package->weight,
            'goodsLength' => $orderClass->package->length,
            'goodsWidth' => $orderClass->package->width,
            'goodsHeight' => $orderClass->package->height,
        ];

        foreach ($orderClass->goods as $key => $value) {
            $goods[$key] = [
                'detailDescription' => $value->description,
                'detailDescriptionCN' => $value->cnDescription,
                'detailQuantity' => $value->quantity,
                'detailWorth' => $value->worth,
                'detailWeight' => $value->weight,
                'hsCode' => $value->hsCode,
                'detailCustomLabel' => $value->sku,
                'enMaterial' => $value->enMaterial,
                'cnMaterial' => $value->cnMaterial,
            ];
        }

        $order = [
            'customerOrderNo' => $orderClass->customerOrderNo,
            // 发货地址类型，1 为用户系统默认地址，2 为用户传送的地址信息
//            'shipperAddressType' => $orderClass->shipper ? 2 : 1,
            'shipperAddressType' => 1,
            'shippingMethod' => $orderClass->transportCode,
            'goodsDetails' => $goods,
            // 提交订单 confirmed，订单预提交状态 preprocess，提交且交寄订单 sumbmitted
            'orderStatus' => 'sumbmitted',
//            'evaluate' => $orderClass->evaluate,
            'taxesNumber' => $orderClass->taxesNumber,
            'isRemoteConfirm' => $orderClass->isRemoteConfirm,
            'isReturn' => $orderClass->isReturn,
            'withBattery' => $orderClass->withBattery,
            'iossNo' => $orderClass->taxesNumber,
            'common' => $orderClass->common
        ];

        $specialTransportCodes = [
            'HKDHL',
            'HKDHL1',
            'CNUPS',
            'SZUPS',
            'HKUPS',
            'SGDHL',
            'EUTLP',
            'CNFEDEX',
            'HKFEDEX',
            'CNS FEDEX',
            'HKSFEDEX',
            'AUEXPIE',
            'EUEXP3'
        ];

        if (in_array($orderClass->transportCode, $specialTransportCodes)) {
            $order['pieceNumber'] = $orderClass->pieceNumber ?: 1;
        }

        return array_merge($order, $shipper, $recipient, $package);
    }

    /**
     * @return array
     */
    protected function getAuthParams()
    {
        return [
            'HeaderRequest' => [
                'appKey' => $this->config['appKey'],
                'token' => $this->config['token'],
                'userId' => $this->config['userId'],
            ],
        ];
    }
}

