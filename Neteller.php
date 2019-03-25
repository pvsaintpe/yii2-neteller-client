<?php

namespace pvsaintpe\neteller;

use yii\base\Exception;
use yii\base\Component;
use Yii;

/**
 * Class Neteller
 * @package pvsaintpe\neteller
 */
class Neteller extends Component
{
    /**
     * @var bool
     */
    protected $initiated = false;

    /**
     * @var string
     */
    private $url;

    /**
     * @var string
     */
    private $clientId;

    /**
     * @var string
     */
    private $clientSecret;

    /**
     * @var string
     */
    private $webhookSecret;

    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var string
     */
    private $tokenType;

    /**
     * @var int
     */
    private $expiresIn;

    /**
     * @var integer
     */
    private $expiresAt;

    /**
     * @var array
     */
    private $statuses = [
        'accepted',
        'pending',
        'declined',
        'cancelled',
        'failed',
    ];

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @return string
     */
    public function getTokenType()
    {
        return $this->tokenType;
    }

    /**
     * @return int
     */
    public function getExpiresAt()
    {
        return $this->expiresAt;
    }

    /**
     * @return int
     */
    public function getExpiresIn()
    {
        return $this->expiresIn;
    }

    /**
     * @param string $url
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @param string $clientId
     * @return $this
     */
    public function setClientId($clientId)
    {
        $this->clientId = $clientId;
        return $this;
    }

    /**
     * @param string $clientSecret
     * @return $this
     */
    public function setClientSecret($clientSecret)
    {
        $this->clientSecret = $clientSecret;
        return $this;
    }

    /**
     * @param $webhookSecret
     * @return $this
     */
    public function setWebhookSecret($webhookSecret)
    {
        $this->webhookSecret = $webhookSecret;
        return $this;
    }

    /**
     * @param array $request
     * @return array
     * @throws Exception
     */
    public function deposit($request)
    {
        if (!$this->auth()) {
            throw new Exception(Yii::t('errors', 'Ошибка подключения'));
        }

        return $this->getResponse(
            '/v1/orders',
            false,
            $request
        );
    }

    /**
     * @param array $request
     * @return array
     * @throws Exception
     */
    public function depositAuto($request)
    {
        if (!$this->auth()) {
            throw new Exception(Yii::t('errors', 'Ошибка подключения'));
        }

        $this->lastResponse =  $this->getResponse(
            '/v1/transferIn',
            false,
            $request
        );

        return $this->extractTransaction();
    }

    /**
     * @param array $request
     * @return array
     * @throws Exception
     */
    public function withdraw($request)
    {
        if (!$this->auth()) {
            throw new Exception(Yii::t('errors', 'Ошибка подключения'));
        }

        return $this->getResponse(
            '/v1/transferOut',
            false,
            $request
        );
    }

    /**
     * @var array
     */
    private $lastResponse;

    /**
     * @return array
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * @param $id
     * @return array|bool
     * @throws Exception
     */
    public function getWithdraw($id)
    {
        if (!$this->auth()) {
            throw new Exception(Yii::t('errors', 'Ошибка подключения'));
        }

        $this->lastResponse = $this->getResponse(
            '/v1/payments/' . $id,
            [
                'refType' => 'merchantRefId',
                'expand' => 'customer',
            ]
        );

        return $this->extractTransaction();
    }

    /**
     * @param $id
     * @return bool
     */
    public function isWithdrawSuccess($id)
    {
        if ($transaction = $this->getWithdraw($id)) {
            return $transaction['status'] == 'accepted';
        }
        return false;
    }

    /**
     * @param $id
     * @return bool
     */
    public function isWithdrawFailed($id)
    {
        if ($transaction = $this->getWithdraw($id)) {
            return in_array($transaction['status'], ['declined', 'failed']);
        }
        return false;
    }

    /**
     * @param $id
     * @return bool
     */
    public function isWithdrawCancelled($id)
    {
        if ($transaction = $this->getWithdraw($id)) {
            return $transaction['status'] == 'cancelled';
        }
        return false;
    }

    /**
     * @param $data
     * @return string
     */
    public function checkEvent($data)
    {
        if ($data['key'] != $this->webhookSecret) {
            return 'FAILED';
        }
        return $data['eventType'];
    }

    /**
     * @param string $id
     * @return mixed
     * @throws Exception
     */
    public function getDeposit($id)
    {
        if (!$this->auth()) {
            throw new Exception(Yii::t('errors', 'Ошибка подключения'));
        }

        return $this->getResponse(
            "/v1/orders/{$id}",
            true
        );
    }

    /**
     * @param $id
     * @return bool
     */
    public function isDepositSuccess($id)
    {
        if ($response = $this->getDeposit($id)) {
            return $response['status'] == 'paid';
        }
        return false;
    }

    /**
     * @param $id
     * @return bool
     */
    public function isDepositExpired($id)
    {
        if ($response = $this->getDeposit($id)) {
            return $response['status'] == 'expired';
        }
        return false;
    }

    /**
     * @param $id
     * @return bool
     */
    public function isDepositFailed($id)
    {
        if ($response = $this->getDeposit($id)) {
            return $response['status'] == 'failed';
        }
        return false;
    }

    /**
     * @param $id
     * @return bool
     */
    public function isDepositCancelled($id)
    {
        if ($response = $this->getDeposit($id)) {
            return $response['status'] == 'cancelled';
        }
        return false;
    }

    /**
     * @todo доделать $feeItem['feeCurrency']
     * @return array|bool
     */
    private function extractTransaction()
    {
        $response = $this->lastResponse;
        if ($response && isset($response['transaction'])) {
            $fee = 0;
            foreach ($response['transaction']['fees'] as $feeItem) {
                $fee += $feeItem['feeAmount'];
            }
            return [
                'id' => $response['transaction']['id'],
                'status' => $response['transaction']['status'],
                'amount' => $response['transaction']['amount'],
                'fee' => $fee,
            ];
        }
        return false;
    }

    /**
     * @return bool
     */
    private function auth()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url . '/v1/oauth2/token?grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERPWD, join(':', [
            $this->clientId,
            $this->clientSecret,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type:application/json",
            "Cache-Control:no-cache",
            "Content-Length: 0",
            "Expect:",
        ]);

        if ($response = curl_exec($ch)) {
            $result = json_decode($response);
            curl_close($ch);

            if (is_object($result) && property_exists($result, 'accessToken')) {
                $this->accessToken = $result->accessToken;
                $this->tokenType = $result->tokenType;
                $this->expiresIn = $result->expiresIn;
                $this->expiresAt = time() + $result->expiresIn;
            } else {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * @param string $url
     * @param array|null $get
     * @param array|null $post
     * @param array $customHeaders
     * @return mixed
     * @throws Exception
     */
    private function getResponse($url, $get = null, $post = null, $customHeaders = [])
    {
        $headers = [];

        if (!$this->auth()) {
            return false;
        }

        if (is_array($get) && count($get) > 0) {
            $url .= '?' . http_build_query($get);
        }

        $url = $this->url . $url;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (is_array($post) && count($post) > 0) {
            $headers[] = "Content-Type: application/json";
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            if (is_array($post) && !empty($post)) {
                $post = array_filter($post, function ($value) {
                    return ($value === null) ? false : true;
                });
                $postdata = json_encode($post);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata); //, JSON_UNESCAPED_UNICODE));
            }
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }

        $headers[] = "Authorization: {$this->tokenType} {$this->accessToken}";
        $headers = array_unique(array_merge($headers, $customHeaders));

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $response = json_decode($response, true);
        curl_close($ch);

        if ($response && is_array($response) && isset($response['message'])) {
            throw new Exception(join(':', [
                $response['status'],
                $response['message'],
            ]));
        }

        if ($response && is_array($response) && isset($response['errorMessage'])) {
            throw new Exception(join(':', [
                $response['errorType'],
                $response['errorMessage'],
            ]));
        }
        return $response;
    }
}
