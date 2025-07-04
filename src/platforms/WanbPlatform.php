<?php
/**
 * Created by PhpStorm.
 * User: LatteCake
 * Email: solacowa@gmail.com
 * Date: 2020/7/15
 * Time: 00:02
 * File: WanbPlatform.php
 */

namespace yiier\crossBorderExpress\platforms;

use GuzzleHttp\Client;
use nusoap_client;
use yiier\AliyunOSS\OSS;
use yiier\crossBorderExpress\contracts\Order;
use yiier\crossBorderExpress\contracts\OrderFee;
use yiier\crossBorderExpress\contracts\OrderResult;
use yiier\crossBorderExpress\contracts\Transport;
use yiier\crossBorderExpress\exceptions\ExpressException;

class WanbPlatform extends Platform
{

    /**
     * default host
     */
    const HOST = 'http://api.wanbexpress.com';

    /**
     * @var string
     */
    private $host = '';

    /**
     * @var string $warehouseCode
     */
    private $warehouseCode = "SZ";

    /**
     * @inheritDoc
     */
    public function getClient()
    {
        $nounce = hash('sha512', strtoupper($this->makeRandomString()));;
        $headers = [
            'Content-Type' => 'application/json; charset=utf8',
            'Authorization' => sprintf("Hc-OweDeveloper %s;%s;%s",
                $this->config->get("account_no"),
                $this->config->get("token"),
                $nounce
            )
        ];

        $this->warehouseCode = $this->config->get("warehouse_code");

        $client = new \GuzzleHttp\Client([
            'headers' => $headers,
            'timeout' => method_exists($this, 'getTimeout') ? $this->getTimeout() : 5.0,
        ]);

        $this->host = $this->config->get("host") ? $this->config->get("host") : self::HOST;

        return $client;
    }

    /**
     * @inheritDoc
     */
    public function getTransportsByCountryCode(string $countryCode)
    {
        $result = $this->client->get($this->host . "/api/services")->getBody()->getContents();
        $result = json_decode($result, true);
        if (!isset($result['Data']['ShippingMethods'])) {
            throw new ExpressException(sprintf("获取运输方式失败"));
        }

        $transports = [];
        foreach ($result['Data']['ShippingMethods'] as $item) {
            $transport = new Transport();
            $transport->code = $item['Code'];
            $transport->cnName = $item['Name'];
            $transport->ifTracking = $item['IsTracking'];
            $transport->data = json_encode($item, JSON_UNESCAPED_UNICODE);
            $transports[] = $transport;
        }
        return $transports;
    }

    /**
     * @param Order $order
     * @return OrderResult
     * @throws ExpressException
     */
    public function createOrder(Order $order): OrderResult
    {
        $parameter = $this->formatOrder($order);
        try {
            $result = $this->client->post($this->host . "/api/parcels", [
                'body' => json_encode($parameter, true)
            ])->getBody();
            return $this->parseResult($result);
        } catch (ExpressException $exception) {
            throw new ExpressException(sprintf("创建包裹失败: %s", $exception->getMessage()));
        }
    }

    /**
     * @inheritDoc
     * @throws \OSS\Core\OssException
     */
    public function getPrintUrl(string $orderNumber, array $params = []): string
    {
        return $this->getPrintFile($orderNumber);
    }

    /**
     * @param string $orderNumber
     * @return string
     * @throws \OSS\Core\OssException
     */
    protected function getPrintFile(string $orderNumber): string
    {
        // PDF传到阿里云oss
        $oss = new OSS([
            "accessKeyId" => $this->config->get("oss_access_key_id"),
            "bucket" => $this->config->get("oss_bucket"),
            "accessKeySecret" => $this->config->get("oss_access_key_secret"),
            "lanDomain" => $this->config->get("oss_lan_domain"),
            "wanDomain" => $this->config->get("oss_wan_domain"),
            "isInternal" => false,
        ]);

        $fileName = sprintf("%s.pdf", $orderNumber);
        $filePath = "/tmp/" . $fileName;

        $storagePath = 'storage/express/';
        if ($oss->has($storagePath . $fileName)) {
            return sprintf("http://%s.%s/%s", $this->config->get("oss_bucket"), $this->config->get("oss_wan_domain"), $storagePath . $fileName);
        }

        $url = sprintf("%s/api/parcels/%s/label", $this->host, $orderNumber);
        $this->client->get($url, [
            "save_to" => $filePath
        ]);


        if (!$oss->has($storagePath)) {
            $oss->createDir($storagePath);
        }

        if ($res = $oss->upload($storagePath . $fileName, $filePath)) {
            unlink($filePath);
            return sprintf("http://%s/%s", $res["oss-requestheaders"]["Host"], $storagePath . $fileName);
        }
        unlink($filePath);
        return "";
    }


    /**
     * @param string $orderNumber
     * @return OrderFee
     */
    public function getOrderFee(string $orderNumber): OrderFee
    {
        return new OrderFee();
    }

    /**
     * @inheritDoc
     */
    public function getOrderAllFee(array $query = []): array
    {
        return [];
    }

    /**
     * @param int $bits
     * @return string
     */
    private function makeRandomString($bits = 256): string
    {
        $bytes = ceil($bits / 8);
        $return = '';
        for ($i = 0; $i < $bytes; $i++) {
            $return .= chr(mt_Rand(0, 255));
        }
        return $return;
    }

    /**
     * @param string $result
     * @return OrderResult
     * @throws ExpressException
     */
    protected function parseResult(string $result): OrderResult
    {
        $resData = $this->parseExpress($result);

        $orderResult = new OrderResult();
        $orderResult->data = $result;
        $orderResult->expressNumber = !empty($resData["ProcessCode"]) ? $resData["ProcessCode"] : "";
        $orderResult->expressTrackingNumber = !empty($resData["TrackingNumber"]) ? $resData["TrackingNumber"] : $this->getTracingNumber($resData["ProcessCode"]);
        return $orderResult;
    }

    /**
     * @param string $processCode
     * @return string
     * @throws ExpressException
     */
    protected function getTracingNumber(string $processCode): string
    {
        $url = $this->host . sprintf("/api/parcels/%s/confirmation", $processCode);
        $result = $this->client->post($url)->getBody();
        try {
            $res = $this->parseExpress($result);
            if (!empty($res["TrackingNumber"])) {
                return $res["TrackingNumber"];
            }
        } catch (ExpressException $e) {
            throw new ExpressException(sprintf("确认交运行包裹失败 %s", $e->getMessage()));
        }

        $url = $this->host . sprintf("/api/parcels/%s", $processCode);
        try {
            $res = $this->parseExpress($this->client->get($url)->getBody());
            if (!empty($res["FinalTrackingNumber"])) {
                return $res["FinalTrackingNumber"];
            }
        } catch (ExpressException $e) {
            throw new ExpressException(sprintf("获取包裹失败 %s", $e->getMessage()));
        }

        return "";
    }

    

    /**
     * @param string $result
     * @return array
     * @throws ExpressException
     */
    protected function parseExpress(string $result): array
    {
        $arr = json_decode($result, true);
        if (empty($arr) || !isset($arr['Succeeded'])) {
            throw new ExpressException('Invalid response: ' . $result, 400);
        }
        if ($arr["Succeeded"] != true) {
            if ($err = $arr["Error"]) {
                $message = $err["Message"];
            } else {
                $message = json_encode($arr, true);
            }
            throw new ExpressException($message);
        }
        return !empty($arr["Data"]) ? $arr["Data"] : [];
    }

    /**
     * 格式化所需要的数据
     *
     * @param Order $orderClass
     * @return array
     */
    protected function formatOrder(Order $orderClass): array
    {
        // 收件人
        $items = [];
        foreach ($orderClass->goods as $good) {
            $declareItems[] = [
                'name' => $good->description,
                'cnName' => $good->cnDescription,
                'pieces' => $good->quantity,
                'netWeight' => $good->weight,
                'unitPrice' => $good->worth,
                'customsNo' => $good->hsCode,
            ];
            $items[] = [
                "GoodsId" => "",
                "GoodsTitle" => $good->description,
                "DeclaredNameEn" => $good->description,
                "DeclaredNameCn" => "$good->cnDescription",
                "DeclaredValue" => [
                    "Code" => "USD",
                    "Value" => $good->worth
                ],
                "WeightInKg" => $good->weight,
                "Quantity" => $good->quantity,
                "HSCode" => $good->hsCode,
                "CaseCode" => "",
                "SalesUrl" => "",
                "IsSensitive" => false,
                "Brand" => "",
                "Model" => "",
                "MaterialCn" => $good->cnMaterial,
                "MaterialEn" => $good->enMaterial,
                "UsageCn" => "",
                "UsageEn" => "",
            ];
        }

        return [
            'ReferenceId' => $orderClass->customerOrderNo,
            'ShippingAddress' => [
                "Company" => $orderClass->recipient->company,
                "Street1" => $orderClass->recipient->address,
                "Street2" => $orderClass->recipient->doorplate,
                "City" => $orderClass->recipient->city,
                "Province" => $orderClass->recipient->state,
                "CountryCode" => $orderClass->recipient->countryCode,
                "Postcode" => $orderClass->recipient->zip,
                "Contacter" => $orderClass->recipient->name,
                "Tel" => $orderClass->recipient->phone,
                "Email" => $orderClass->recipient->email,
            ],
            'ShipperInfo' => [
                'Taxations' => [
                    [
                        'TaxType' => 'IOSS',
                        'Number' => $orderClass->taxesNumber,
                    ],
                ]
            ],
            'WeightInKg' => $orderClass->package->weight,
            'ItemDetails' => $items,
            'TotalValue' => [
                "Code" => "USD",
                "Value" => $orderClass->package->declareWorth,
            ],
            'TotalVolume' => [
                'Height' => $orderClass->package->height,
                'Length' => $orderClass->package->length,
                'Width' => $orderClass->package->width,
                'Unit' => "CM",
            ],
            'WithBatteryType' => $orderClass->withBattery == 1 ? "WithBattery" : "NOBattery", // NOBattery,WithBattery,Battery
            'Notes' => $orderClass->package->description,
            'WarehouseCode' => $this->warehouseCode,
            'ShippingMethod' => $orderClass->transportCode,
            'ItemType' => 'SPX',
            'TradeType' => 'B2C',
            'IsMPS' => false,
            'AllowRemoteArea' => true,
            'AutoConfirm' => true
        ];
    }

    /**
     * Query tracking points for a given tracking number.
     *
     * @param string $trackingNumber
     * @return array
     * @throws ExpressException
     */
    public function queryTrackPoints(string $trackingNumber): array
    {
        $url = sprintf("%s/api/trackPoints?trackingNumber=%s", $this->host, $trackingNumber);
        try {
            $response = $this->client->get($url)->getBody()->getContents();
            $data = json_decode($response, true);
            if (empty($data) || !isset($data['Data'])) {
                throw new ExpressException('Failed to retrieve tracking points.');
            }
            return $data['Data'];
        } catch (ExpressException $e) {
            throw new ExpressException(sprintf("Error querying track points: %s", $e->getMessage()));
        }
    }

    /**
     * 修改包裹预报重量
     *
     * @param float $weightInKg 预报重量(单位:KG)
     * @return array 返回处理结果
     * @throws ExpressException
     */
    public function updateWeight(string $trackingNumber, float $weightInKg): array
    {
        $url = sprintf("%s/api/parcels/%s/customerWeight", $this->host, $trackingNumber);
        $data = [
            'WeightInKg' => $weightInKg,
            'AutoConfirm' => true
        ];
	try {
	 $response = $this->client->put($url, [
                'body' => json_encode($data, true)
            ])->getBody()->getContents();
            return $this->parseExpress((string)$response);
        } catch (\Exception $e) {
            throw new ExpressException(sprintf("修改包裹预报重量失败: %s", $e->getMessage()));
        }
    }

    
}

